<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

/**
 * Subscription history and payment submissions.
 *
 * Both of these used to live in one mutable row each — the clinic's plan
 * columns, and the invoice's status. That is fine for answering "what is true
 * now" and useless for answering "what happened", which is the question a
 * billing dispute always turns into.
 *
 * The PDF lists `subscriptions` ("Clinic subscription") and `payments`
 * ("Submitted and verified payments") as distinct entities, and the platform
 * admin is asked to "review payment submissions" — a review needs something to
 * review, so a rejected claim has to survive the rejection.
 */
final class SubscriptionService
{
    /* ------------------------------------------------------------------ */
    /* Payments                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Record a clinic's claim that it has paid an invoice.
     *
     * This does not settle anything. A claim is a statement by the clinic;
     * only a platform admin can turn it into a payment. Storing the claim
     * separately means the invoice can go back to unpaid without the claim
     * disappearing with it.
     */
    public function claim(
        int $clinicId,
        int $invoiceId,
        string $reference,
        string $method = 'bank_transfer'
    ): int {
        $invoice = Db::one(
            'SELECT id, amount, currency FROM invoices WHERE id = ? AND clinic_id = ?',
            [$invoiceId, $clinicId]
        );
        if ($invoice === null) {
            throw new \RuntimeException('That invoice is not available.');
        }

        return Db::insert(
            'INSERT INTO payments (clinic_id, invoice_id, amount, currency, method, reference,
                                   status, submitted_by, submitted_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $clinicId,
                $invoiceId,
                (float) $invoice['amount'],
                (string) ($invoice['currency'] ?: 'LKR'),
                $method,
                $reference,
                'claimed',
                Auth::id(),
                Db::now(),
                Db::now(),
            ]
        );
    }

    /**
     * Accept a claimed payment: settle the invoice and activate the clinic.
     */
    public function verify(int $paymentId, ?int $decidedBy = null, string $note = ''): array
    {
        return $this->decide($paymentId, 'verified', $decidedBy, $note);
    }

    /**
     * Reject a claimed payment.
     *
     * The invoice returns to unpaid, but the claim stays on file. A clinic
     * whose reference did not match can be told what happened instead of
     * being silently reset to a blank invoice.
     */
    public function reject(int $paymentId, ?int $decidedBy = null, string $note = ''): array
    {
        return $this->decide($paymentId, 'rejected', $decidedBy, $note);
    }

    private function decide(int $paymentId, string $status, ?int $decidedBy, string $note): array
    {
        $payment = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if ($payment === null) {
            return ['ok' => false, 'message' => 'That payment submission no longer exists.'];
        }
        if ($payment['status'] !== 'claimed') {
            return [
                'ok' => false,
                'message' => 'That submission was already ' . $payment['status'] . '.',
            ];
        }

        $decidedBy ??= Auth::id();
        $now = Db::now();
        $clinicId = (int) $payment['clinic_id'];

        Db::transaction(function () use ($payment, $status, $decidedBy, $note, $now, $clinicId): void {
            Db::run(
                'UPDATE payments SET status = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?',
                [$status, $decidedBy, $now, $note !== '' ? $note : null, (int) $payment['id']]
            );

            $newInvoiceStatus = $status === 'verified' ? 'paid' : 'unpaid';
            Db::run(
                'UPDATE invoices SET status = ?, paid_at = ?, method = ?, reference = ? WHERE id = ? AND clinic_id = ?',
                [
                    $newInvoiceStatus,
                    $status === 'verified' ? $now : null,
                    $status === 'verified' ? $payment['method'] : null,
                    $status === 'verified' ? $payment['reference'] : null,
                    (int) $payment['invoice_id'],
                    $clinicId,
                ]
            );

            // Only a verified payment clears the billing gate. Rejecting
            // leaves whatever suspension is in place alone — the invoice is
            // unpaid again, which is the truth of it.
            if ($status === 'verified') {
                Db::run(
                    "UPDATE clinics SET billing_status = 'active', suspended_at = NULL, suspension_reason = NULL,
                            renews_on = date(?, '+1 month')
                      WHERE id = ? AND billing_status <> 'active'",
                    [$now, $clinicId]
                );
            }
        });

        (new AuditService())->log($clinicId, 'billing.payment_' . $status, 'payment', $paymentId, [
            'invoice' => (int) $payment['invoice_id'],
            'amount' => (float) $payment['amount'],
            'reference' => (string) $payment['reference'],
            'note' => $note !== '' ? $note : null,
        ]);

        return [
            'ok' => true,
            'message' => $status === 'verified'
                ? 'Payment verified and the invoice is settled.'
                : 'Payment rejected. The submission is kept on file.',
        ];
    }

    /** Submissions waiting on a decision (platform admin queue). */
    public function claimedPayments(int $limit = 100): array
    {
        return Db::all(
            "SELECT p.*, c.name AS clinic_name, c.slug, i.number AS invoice_number, i.period_from, i.period_to
               FROM payments p
               JOIN clinics c ON c.id = p.clinic_id
               JOIN invoices i ON i.id = p.invoice_id
              WHERE p.status = 'claimed'
              ORDER BY p.submitted_at
              LIMIT " . max(1, $limit)
        );
    }

    /** Everything a clinic has submitted, newest first. */
    public function paymentsFor(int $clinicId, int $limit = 50): array
    {
        return Db::all(
            'SELECT p.*, i.number AS invoice_number
               FROM payments p
               JOIN invoices i ON i.id = p.invoice_id
              WHERE p.clinic_id = ?
              ORDER BY p.submitted_at DESC, p.id DESC
              LIMIT ' . max(1, $limit),
            [$clinicId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Subscriptions                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Open a subscription row for a clinic's current plan.
     *
     * Called when a clinic signs up and whenever the plan changes. The
     * previous row is closed rather than deleted, so "what were we on before"
     * stays answerable.
     */
    public function open(int $clinicId, ?int $planId, string $status, ?string $note = null): int
    {
        $clinic = Db::one('SELECT trial_ends_on, renews_on FROM clinics WHERE id = ?', [$clinicId]);
        $now = Db::now();

        return Db::transaction(function () use ($clinicId, $planId, $status, $note, $clinic, $now): int {
            Db::run(
                'UPDATE subscriptions SET ended_at = ? WHERE clinic_id = ? AND ended_at IS NULL',
                [$now, $clinicId]
            );

            return Db::insert(
                'INSERT INTO subscriptions (clinic_id, plan_id, status, trial_ends_on, renews_on,
                                            started_at, ended_at, created_by, note, created_at)
                 VALUES (?,?,?,?,?,?,NULL,?,?,?)',
                [
                    $clinicId,
                    $planId,
                    $status,
                    $clinic['trial_ends_on'] ?? null,
                    $clinic['renews_on'] ?? null,
                    $now,
                    Auth::id(),
                    $note,
                    $now,
                ]
            );
        });
    }

    /** The clinic's current (open) subscription row, if any. */
    public function current(int $clinicId): ?array
    {
        return Db::one(
            'SELECT s.*, p.name AS plan_name, p.price_monthly, p.currency
               FROM subscriptions s
               LEFT JOIN plans p ON p.id = s.plan_id
              WHERE s.clinic_id = ? AND s.ended_at IS NULL
              ORDER BY s.id DESC',
            [$clinicId]
        );
    }

    /** Full plan history, newest first. */
    public function history(int $clinicId, int $limit = 24): array
    {
        return Db::all(
            'SELECT s.*, p.name AS plan_name, p.price_monthly
               FROM subscriptions s
               LEFT JOIN plans p ON p.id = s.plan_id
              WHERE s.clinic_id = ?
              ORDER BY s.id DESC
              LIMIT ' . max(1, $limit),
            [$clinicId]
        );
    }

    /**
     * Backfill an opening row for clinics created before this table existed.
     *
     * Without this, an established clinic would show an empty subscription
     * history and the screen would look broken rather than merely brief.
     */
    public function ensureOpen(int $clinicId): void
    {
        if ($this->current($clinicId) !== null) {
            return;
        }
        $clinic = Db::one('SELECT plan_id, billing_status, created_at FROM clinics WHERE id = ?', [$clinicId]);
        if ($clinic === null) {
            return;
        }
        $this->open(
            $clinicId,
            $clinic['plan_id'] !== null ? (int) $clinic['plan_id'] : null,
            (string) ($clinic['billing_status'] ?: 'active'),
            'Opened from the clinic\'s existing plan.'
        );
    }
}
