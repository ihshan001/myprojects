<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\StaffNotificationService;

/**
 * Payment verification for the platform team.
 *
 * The clinic claims; this is where a person matches the claim against the
 * bank statement. Split from AdminController because it has a different
 * failure mode: a mistake here activates a subscription that was never paid
 * for, so every action is reversible-by-record and audit-logged with the
 * verifier's id.
 */
final class AdminBillingController
{
    public function index(): void
    {
        $pending = Db::all(
            "SELECT i.*, c.name AS clinic_name, c.slug, c.billing_status, c.renews_on
               FROM invoices i
               JOIN clinics c ON c.id = i.clinic_id
              WHERE i.status = 'claimed'
              ORDER BY i.claimed_at"
        );

        view('admin/billing', [
            'title' => 'Payment verification',
            'pending' => $pending,
            'recent' => Db::all(
                "SELECT i.*, c.name AS clinic_name, u.name AS verifier
                   FROM invoices i
                   JOIN clinics c ON c.id = i.clinic_id
                   LEFT JOIN users u ON u.id = i.verified_by
                  WHERE i.status IN ('paid','rejected')
                  ORDER BY COALESCE(i.verified_at, i.created_at) DESC LIMIT 40"
            ),
            'openCount' => count($pending),
        ]);
    }

    /** Confirm a claimed payment and activate the subscription. */
    public function verify(string $id): void
    {
        $invoice = Db::one('SELECT * FROM invoices WHERE id = ?', [(int) $id]);
        if (!$invoice) {
            flash('error', 'That invoice no longer exists.');
            redirect('/admin/billing');
            return;
        }
        if ($invoice['status'] !== 'claimed') {
            flash('warn', 'That invoice is not awaiting verification (status: ' . $invoice['status'] . ').');
            redirect('/admin/billing');
            return;
        }

        Db::transaction(function () use ($invoice): void {
            Db::run(
                "UPDATE invoices SET status = 'paid', paid_at = ?, verified_at = ?, verified_by = ?, rejection_reason = NULL
                  WHERE id = ?",
                [Db::now(), Db::now(), Auth::id(), (int) $invoice['id']]
            );

            // Renew from the later of today and the current end date, so a
            // clinic that pays early is not penalised by losing the remainder
            // of its current period.
            $clinic = Db::one('SELECT renews_on FROM clinics WHERE id = ?', [(int) $invoice['clinic_id']]);
            $base = $clinic && !empty($clinic['renews_on']) && strtotime((string) $clinic['renews_on']) > time()
                ? strtotime((string) $clinic['renews_on'])
                : time();
            $next = date('Y-m-d', strtotime('+1 month', $base));

            Db::run(
                "UPDATE clinics SET billing_status = 'active', renews_on = ?, suspended_at = NULL,
                        suspension_reason = NULL, updated_at = ? WHERE id = ?",
                [$next, Db::now(), (int) $invoice['clinic_id']]
            );

            (new StaffNotificationService())->operational(
                (int) $invoice['clinic_id'],
                'Payment verified — subscription active',
                'Invoice ' . $invoice['number'] . ' was verified. Your subscription runs to ' . $next . '.',
                'success',
                '/billing'
            );
        });

        (new AuditService())->log((int) $invoice['clinic_id'], 'billing.payment_verified', 'invoice', (int) $invoice['id'], [
            'amount' => $invoice['amount'],
            'reference' => $invoice['reference'],
            'verified_by' => Auth::id(),
        ]);

        flash('success', 'Payment verified. Invoice ' . $invoice['number'] . ' is settled and the subscription is active.');
        redirect('/admin/billing');
    }

    /** Reject a claim with a reason the clinic can act on. */
    public function reject(string $id): void
    {
        $invoice = Db::one('SELECT * FROM invoices WHERE id = ?', [(int) $id]);
        if (!$invoice) {
            flash('error', 'That invoice no longer exists.');
            redirect('/admin/billing');
            return;
        }
        if ($invoice['status'] !== 'claimed') {
            flash('warn', 'That invoice is not awaiting verification.');
            redirect('/admin/billing');
            return;
        }

        $reason = trim(Request::string('reason'));
        if (mb_strlen($reason) < 5) {
            flash('error', 'Give a reason — the clinic sees it and needs to know what to fix.');
            redirect('/admin/billing');
            return;
        }

        Db::run(
            "UPDATE invoices SET status = 'rejected', rejection_reason = ?, verified_at = ?, verified_by = ?
              WHERE id = ?",
            [mb_substr($reason, 0, 255), Db::now(), Auth::id(), (int) $invoice['id']]
        );

        (new StaffNotificationService())->operational(
            (int) $invoice['clinic_id'],
            'Payment could not be matched',
            'Invoice ' . $invoice['number'] . ': ' . $reason . ' Enter the reference again, or contact support.',
            'warning',
            '/billing'
        );

        (new AuditService())->log((int) $invoice['clinic_id'], 'billing.payment_rejected', 'invoice', (int) $invoice['id'], [
            'reason' => $reason,
            'rejected_by' => Auth::id(),
        ]);

        flash('success', 'Claim rejected. The clinic has a notice telling them why.');
        redirect('/admin/billing');
    }
}
