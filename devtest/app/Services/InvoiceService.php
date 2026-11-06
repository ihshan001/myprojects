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
                    c.currency, p.name AS plan_name, p.price_monthly, p.code AS plan_code
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
            // The period being billed is the month that ends on the renewal date.
            $periodTo   = $renewsOn;
            $periodFrom = date('Y-m-d', strtotime($renewsOn . ' -1 month'));

            $exists = Db::scalar(
                'SELECT id FROM invoices WHERE clinic_id = ? AND period_from = ?',
                [(int) $c['id'], $periodFrom]
            );
            if ($exists !== null) {
                continue;
            }

            $number = $this->nextNumber($periodFrom);
            $amount = (float) $c['price_monthly'];

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
        }

        return $created;
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
