<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\SubscriptionService;
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
            // The durable queue. `pending` is the invoice view; this is the
            // submission view, and it is what survives a rejection — a clinic
            // can claim, be rejected, and claim again, and both attempts are
            // visible instead of the second silently replacing the first.
            'claimed' => (new SubscriptionService())->claimedPayments(),
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

    /**
     * Confirm a claimed payment and activate the subscription.
     *
     * Accepts a payment id, falling back to "the invoice's newest open claim"
     * so the existing invoice-level links keep working. The decision is
     * recorded against the submission, not the invoice.
     */
    public function verify(string $id): void
    {
        $payment = $this->resolveClaim($id);
        if ($payment === null) {
            redirect('/admin/billing');
            return;
        }

        $result = (new SubscriptionService())->verify((int) $payment['id'], Auth::id(), '');
        if (!$result['ok']) {
            flash('warn', $result['message']);
            redirect('/admin/billing');
            return;
        }

        $invoice = Db::one('SELECT * FROM invoices WHERE id = ?', [(int) $payment['invoice_id']]);
        $clinic  = Db::one('SELECT renews_on FROM clinics WHERE id = ?', [(int) $payment['clinic_id']]);

        (new StaffNotificationService())->operational(
            (int) $payment['clinic_id'],
            'Payment verified — subscription active',
            'Invoice ' . ($invoice['number'] ?? '') . ' was verified. Your subscription runs to '
                . (string) ($clinic['renews_on'] ?? '') . '.',
            'success',
            '/billing'
        );

        flash('success', 'Payment verified. Invoice ' . ($invoice['number'] ?? '') . ' is settled and the subscription is active.');
        redirect('/admin/billing');
    }

    /**
     * The claim an admin action refers to.
     *
     * $id is normally a payment id. Older screens and bookmarks pass an
     * invoice id, so that is resolved to the invoice's newest open claim.
     */
    private function resolveClaim(string $id): ?array
    {
        $payment = Db::one('SELECT * FROM payments WHERE id = ?', [(int) $id]);
        if ($payment !== null) {
            return $payment;
        }

        $invoice = Db::one('SELECT * FROM invoices WHERE id = ?', [(int) $id]);
        if (!$invoice) {
            flash('error', 'That invoice no longer exists.');
            return null;
        }
        if ($invoice['status'] !== 'claimed') {
            flash('warn', 'That invoice is not awaiting verification (status: ' . $invoice['status'] . ').');
            return null;
        }

        $payment = Db::one(
            "SELECT * FROM payments WHERE invoice_id = ? AND status = 'claimed' ORDER BY id DESC",
            [(int) $invoice['id']]
        );
        if ($payment === null) {
            // An invoice claimed before submissions were recorded. Create the
            // row now so the decision still has somewhere to live.
            $paymentId = (new SubscriptionService())->claim(
                (int) $invoice['clinic_id'],
                (int) $invoice['id'],
                (string) ($invoice['reference'] ?? ''),
                (string) ($invoice['method'] ?? 'bank_transfer')
            );
            $payment = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        }

        return $payment;
    }


    /** Reject a claim with a reason the clinic can act on. */
    public function reject(string $id): void
    {
        $reason = trim(Request::string('reason'));
        if (mb_strlen($reason) < 5) {
            flash('error', 'Give a reason — the clinic sees it and needs to know what to fix.');
            redirect('/admin/billing');
            return;
        }

        $payment = $this->resolveClaim($id);
        if ($payment === null) {
            redirect('/admin/billing');
            return;
        }

        // The submission is kept. Only the invoice goes back to unpaid: the
        // clinic has not paid, but it did submit, and a rejection that erases
        // the submission leaves a dispute with no evidence on either side.
        $result = (new SubscriptionService())->reject((int) $payment['id'], Auth::id(), mb_substr($reason, 0, 255));
        if (!$result['ok']) {
            flash('warn', $result['message']);
            redirect('/admin/billing');
            return;
        }

        $invoice = Db::one('SELECT * FROM invoices WHERE id = ?', [(int) $payment['invoice_id']]);

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
