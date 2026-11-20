<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;

/**
 * Monthly invoicing.
 *
 * Billing worked only when somebody remembered to seed an invoice. That is fine
 * for a demo and wrong for a business: a subscription that renews silently
 * without an invoice is revenue nobody asked for, and a renewal that does not
 * happen because nobody made a row is a clinic that loses access for a clerical
 * reason.
 *
 * The rules this implements:
 *
 *   * One invoice per clinic per billing period. Enforced in the database by a
 *     unique index on (clinic_id, period_from), so running the generator twice
 *     in a day, or from two servers at once, cannot double-bill.
 *   * An invoice is raised when the clinic's `renews_on` date is reached, for
 *     the month that just ended. The amount comes from the plan at that moment,
 *     not from a value copied onto the invoice earlier.
 *   * Trial clinics are not invoiced. A trial that ends is a conversation, not
 *     a debt.
 *   * Suspended and closed clinics keep getting invoices — the money is still
 *     owed — but nothing about their access changes here.
 *   * Generating an invoice never extends the subscription. Only a verified
 *     payment does (AdminBillingController), which is the rule that was already
 *     in place and must not be quietly bypassed by a scheduled job.
 *
 * This is deliberately a runnable tool rather than a cron entry: the app has no
 * scheduler, and pretending otherwise by generating invoices inside a web
 * request would bill a clinic when somebody happened to load a page.
 */
final class InvoiceService
{
    /**
     * Raise any invoices that are due.
     *
     * @param bool $dryRun list what would be created without writing
     * @return array<int, array{clinic_id:int, clinic:string, number:string, amount:float, period_from:string, period_to:string}>
     */
    public function generateDue(bool $dryRun = false, ?string $today = null): array
    {
        $today = $today ?? Db::today();
        $created = [];

        $clinics = Db::all(
            "SELECT c.id, c.name, c.billing_status, c.renews_on, c.trial_ends_on,
                    c.approved_at, c.created_at,
                    c.currency, p.name AS plan_name, p.price_monthly, p.price_yearly,
                    p.code AS plan_code, c.billing_cycle
               FROM clinics c
               LEFT JOIN plans p ON p.id = c.plan_id
              WHERE c.closed_at IS NULL
                AND c.renews_on IS NOT NULL
                AND c.renews_on <> ''
                AND substr(c.renews_on, 1, 10) <= ?
              ORDER BY c.id",
            [$today]
        );

        foreach ($clinics as $c) {
            // A trial has no invoice; the clinic is not paying yet.
            if ((string) $c['billing_status'] === 'trial') {
                continue;
            }
            if ($c['price_monthly'] === null) {
                Log::warn('billing.invoice_skipped_no_plan', ['clinic_id' => (int) $c['id']]);
                continue;
            }

            $renewsOn = substr((string) $c['renews_on'], 0, 10);
            $yearly   = ((string) ($c['billing_cycle'] ?? 'monthly')) === 'yearly';

            // The period being billed ends on the renewal date. That is arrears,
            // and it is deliberate: the clinic pays for the time it has actually
            // had, so a clinic that never used the platform is not charged for a
            // month it could not reach. A yearly clinic gets one invoice for the
            // twelve months behind it, not twelve monthly ones — the whole point
            // of the discount is that it is billed once.
            $periodTo   = $renewsOn;
            $periodFrom = $yearly
                ? date('Y-m-d', strtotime($renewsOn . ' -1 year'))
                : date('Y-m-d', strtotime($renewsOn . ' -1 month'));

            // A clinic's first invoice must not bill a period before the clinic
            // existed. Approval sets renews_on one cycle out, so when that first
            // cycle closes the arithmetic above reaches back to a date before
            // signup — for a clinic approved mid-month that is up to a month of
            // imaginary service at full price, shown to the customer on their
            // first ever invoice. Clamp the start to the day the clinic went
            // live, which is also the day they were promised the service from.
            $wentLive = substr((string) ($c['approved_at'] ?: $c['created_at'] ?: ''), 0, 10);
            if ($wentLive !== '' && $periodFrom < $wentLive) {
                $periodFrom = $wentLive;
            }

            $exists = Db::scalar(
                'SELECT id FROM invoices WHERE clinic_id = ? AND period_from = ?',
                [(int) $c['id'], $periodFrom]
            );
            if ($exists !== null) {
                continue;
            }

            $number = $this->nextNumber($periodFrom);
            // A yearly clinic is invoiced once for twelve months at the yearly
            // price — twelve monthly invoices would waste the discount's whole
            // point and produce twelve chances to suspend a paying customer.
            $amount = $yearly
                ? (float) ($c['price_yearly'] ?? $c['price_monthly'] * 12)
                : (float) $c['price_monthly'];

            $created[] = [
                'clinic_id'   => (int) $c['id'],
                'clinic'      => (string) $c['name'],
                'number'      => $number,
                'amount'      => $amount,
                'period_from' => $periodFrom,
                'period_to'   => $periodTo,
            ];

            if ($dryRun) {
                continue;
            }

            try {
                Db::insert(
                    'INSERT INTO invoices (clinic_id, number, period_from, period_to, amount, currency, status, created_at)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [(int) $c['id'], $number, $periodFrom, $periodTo, $amount,
                     (string) ($c['currency'] ?: 'LKR'), 'unpaid', Db::now()]
                );
            } catch (\PDOException $e) {
                // Two generators raced. The unique index won; nothing to do.
                if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                    array_pop($created);
                    continue;
                }
                throw $e;
            }

            (new AuditService())->log((int) $c['id'], 'billing.invoice_generated', 'invoice', null, [
                'number' => $number, 'amount' => $amount, 'period_from' => $periodFrom,
            ]);
            (new StaffNotificationService())->operational(
                (int) $c['id'],
                'Invoice ' . $number . ' is due',
                money($amount) . ' for ' . $periodFrom . ' to ' . $periodTo . '. Settle it from Subscription.',
                'warning',
                '/billing'
            );

            // In-app only reaches staff who sign in. The person who approves the
            // bank transfer is the owner, and they need it in their inbox —
            // otherwise the first they hear of it is when the service stops.
            $invoiceId = (int) (Db::scalar(
                'SELECT id FROM invoices WHERE clinic_id = ? AND number = ?',
                [(int) $c['id'], $number]
            ) ?: 0);
            if ($invoiceId > 0) {
                try {
                    (new InvoiceMailer())->issued((int) $c['id'], $invoiceId);
                } catch (\Throwable $e) {
                    // A mail failure must not roll back the invoice. The invoice
                    // is the record; the email is a courtesy.
                    Log::warn('billing.invoice_email_failed', [
                        'clinic' => (int) $c['id'], 'invoice' => $invoiceId, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $created;
    }

    /**
     * Raise the invoice for a clinic that has just been activated on a paid
     * plan, and email it.
     *
     * The cron run only invoices clinics whose renewal date has *passed*, and a
     * newly approved clinic has a renewal date one cycle in the future. So the
     * first invoice never existed at the moment the admin clicked Approve — the
     * clinic went live with nothing to pay, and the operator had to wait for the
     * next daily run, or generate it by hand, to send the thing the customer is
     * supposed to pay. A trial is correctly exempt: no invoice at all.
     *
     * The period starts on the day the clinic was approved, not a cycle before
     * it, for the same reason the cron clamps: an invoice must never bill for
     * service that predates the clinic.
     *
     * @return array{ok:bool, invoice_id?:int, number?:string, amount?:float, error?:string}
     */
    public function raiseFirst(int $clinicId, ?string $today = null): array
    {
        $today = $today ?? Db::today();
        $c = Db::one(
            "SELECT c.id, c.name, c.billing_status, c.approved_at, c.renews_on, c.billing_cycle,
                    c.currency, p.price_monthly, p.price_yearly
               FROM clinics c LEFT JOIN plans p ON p.id = c.plan_id
              WHERE c.id = ?",
            [$clinicId]
        );
        if ($c === null) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ((string) $c['billing_status'] !== 'active') {
            // A trial has no invoice; that is the whole point of a trial.
            return ['ok' => false, 'error' => 'Only an active clinic is invoiced.'];
        }
        if ($c['price_monthly'] === null) {
            return ['ok' => false, 'error' => 'That clinic has no plan, so there is nothing to invoice.'];
        }

        $yearly = ((string) ($c['billing_cycle'] ?? 'monthly')) === 'yearly';
        $periodFrom = substr((string) ($c['approved_at'] ?: $today), 0, 10) ?: $today;
        $periodTo = (string) ($c['renews_on'] ?? '') !== ''
            ? substr((string) $c['renews_on'], 0, 10)
            : ($yearly
                ? date('Y-m-d', strtotime($periodFrom . ' +1 year'))
                : date('Y-m-d', strtotime($periodFrom . ' +1 month')));

        // Idempotent on the period start, the same key the cron run uses, so a
        // double-click on Approve cannot bill the clinic twice.
        $existing = Db::one(
            'SELECT id, number, amount FROM invoices WHERE clinic_id = ? AND period_from = ?',
            [$clinicId, $periodFrom]
        );
        if ($existing !== null) {
            return [
                'ok' => true,
                'invoice_id' => (int) $existing['id'],
                'number' => (string) $existing['number'],
                'amount' => (float) $existing['amount'],
            ];
        }

        $amount = $yearly
            ? (float) ($c['price_yearly'] ?? ((float) $c['price_monthly'] * 12))
            : (float) $c['price_monthly'];
        $number = $this->nextNumber($periodFrom);

        Db::insert(
            'INSERT INTO invoices (clinic_id, number, period_from, period_to, amount, currency, status, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$clinicId, $number, $periodFrom, $periodTo, $amount,
             (string) ($c['currency'] ?: 'LKR'), 'unpaid', Db::now()]
        );
        $invoiceId = (int) (Db::scalar(
            'SELECT id FROM invoices WHERE clinic_id = ? AND number = ?',
            [$clinicId, $number]
        ) ?: 0);

        (new AuditService())->log($clinicId, 'billing.invoice_raised_on_activation', 'invoice', $invoiceId ?: null, [
            'number' => $number, 'amount' => $amount, 'period_from' => $periodFrom,
        ]);

        // The email is a courtesy; the invoice is the record. A mail failure must
        // not undo the activation the admin just performed.
        if ($invoiceId > 0) {
            try {
                (new InvoiceMailer())->issued($clinicId, $invoiceId);
            } catch (\Throwable $e) {
                Log::warn('billing.activation_invoice_email_failed', [
                    'clinic' => $clinicId, 'invoice' => $invoiceId, 'error' => $e->getMessage(),
                ]);
            }
        }

        return ['ok' => true, 'invoice_id' => $invoiceId, 'number' => $number, 'amount' => $amount];
    }

    /**
     * Next invoice number for a period.
     *
     * Readable and sortable: INV-202611-0007. Sequential per month, derived
     * from the highest existing number rather than COUNT(*), because a deleted
     * invoice would otherwise make the next number collide.
     */
    private function nextNumber(string $periodFrom): string
    {
        $prefix = 'INV-' . date('Ym', strtotime($periodFrom)) . '-';
        $last = Db::scalar(
            'SELECT number FROM invoices WHERE number LIKE ? ORDER BY number DESC LIMIT 1',
            [$prefix . '%']
        );
        $next = $last !== null ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        // A tiny retry loop rather than trusting one read: the number is unique
        // and two generators can pick the same one between the read and the
        // insert.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $prefix . str_pad((string) ($next + $attempt), 4, '0', STR_PAD_LEFT);
            if (Db::scalar('SELECT id FROM invoices WHERE number = ?', [$candidate]) === null) {
                return $candidate;
            }
        }
        return $prefix . str_pad((string) random_int(9000, 9999), 4, '0', STR_PAD_LEFT);
    }
}
