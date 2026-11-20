<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Env;
use App\Core\Log;
use App\Core\Tenant;

/**
 * Tell a clinic about money by email.
 *
 * Until this existed the only email in the product was the welcome link, and
 * the Subscription page promised "Automatic reminder by email" to clinics that
 * would never receive one. A platform that bills by bank transfer and then
 * never says a word when the invoice is raised is a platform whose customers
 * find out they owe money when the service stops.
 *
 * Three moments, and they are deliberately different:
 *
 *   issued   the invoice is due. The amount, the period, when it is due, and
 *            where to pay. Nothing about suspension — this is the first notice.
 *   receipt  a payment was checked and matched. Two facts matter and no more:
 *            what was paid and how long the service now runs.
 *   reminder the grace period is ending. Names the date the service stops, so
 *            "we might suspend you" is never the message — a date is.
 *
 * Every send reports whether it actually left. `MAIL_TRANSPORT=none` is a
 * supported configuration (it is the default), so the caller has to be able to
 * say "no email went out" instead of assuming.
 */
final class InvoiceMailer
{
    /**
     * The invoice has been raised and is waiting to be paid.
     *
     * @return array{ok:bool, sent:bool, reason?:string}
     */
    public function issued(int $clinicId, int $invoiceId): array
    {
        $invoice = Db::one('SELECT * FROM invoices WHERE id = ? AND clinic_id = ?', [$invoiceId, $clinicId]);
        if ($invoice === null) {
            return ['ok' => false, 'sent' => false, 'reason' => 'No such invoice.'];
        }

        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if ($clinic === null) {
            return ['ok' => false, 'sent' => false, 'reason' => 'No such clinic.'];
        }

        $to = $this->billingAddress($clinic);
        if ($to === '') {
            return ['ok' => false, 'sent' => false, 'reason' => 'The clinic has no email address.'];
        }

        $amount   = money((float) $invoice['amount']);
        // There is no due_on column: the period being billed ends on the due
        // date, and the grace period runs from there. Quoting a date that is
        // computed rather than stored keeps the two in step — the same
        // arithmetic LifecycleService uses when it starts the clock.
        $due      = (string) $invoice['period_to'];
        $stopsOn  = $this->stopDate($clinic);
        $dueLine  = $due !== ''
            ? "Due: {$due}" . ($stopsOn !== '' ? "  (service stops {$stopsOn} if unpaid)" : '')
            : 'Payment is due now.';
        $period   = "{$invoice['period_from']} to {$invoice['period_to']}";
        $payUrl   = rtrim(Tenant::opsUrl($clinic), '/') . '/billing';
        $howToPay = $this->bankLines();

        $body = "Hello {$clinic['name']},\n\n"
            . "Invoice {$invoice['number']} for {$amount} is ready.\n\n"
            . "  Period:   {$period}\n"
            . "  Amount:   {$amount}\n"
            . "  {$dueLine}\n\n"
            . "The invoice covers the period above, which has already been served —\n"
            . "we bill for time used, not in advance.\n\n"
            . ($howToPay !== '' ? "How to pay\n----------\n{$howToPay}\n\n"
               : "Our bank details are not on this installation yet. Reply to this\n"
               . "message and we will send them. Do not transfer money to details\n"
               . "given to you in any other message.\n\n")
            . "When you have paid, record the transfer reference here:\n\n"
            . "  {$payUrl}\n\n"
            . "We check transfers by hand, usually within one working day. You will\n"
            . "get a receipt when it is matched.\n\n"
            . "This address is also where you can download the invoice as a PDF-ready\n"
            . "page, and see every invoice you have had.\n\n"
            . "— " . $this->productName();

        $sent = (new MailService())->send($to, 'Invoice ' . $invoice['number'] . ' from ' . $this->productName(), $body);
        Log::info('billing.invoice_emailed', [
            'clinic' => $clinicId, 'invoice' => $invoiceId, 'to' => $to, 'sent' => $sent,
        ]);

        return $sent
            ? ['ok' => true, 'sent' => true]
            : ['ok' => true, 'sent' => false, 'reason' => 'Mail is not configured, so the invoice was not emailed.'];
    }

    /**
     * A payment matched. This is the receipt, and it is what makes the
     * suspension clock stop in the customer's head.
     *
     * @return array{ok:bool, sent:bool, reason?:string}
     */
    public function receipt(int $clinicId, int $invoiceId): array
    {
        $invoice = Db::one('SELECT * FROM invoices WHERE id = ? AND clinic_id = ?', [$invoiceId, $clinicId]);
        $clinic  = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if ($invoice === null || $clinic === null) {
            return ['ok' => false, 'sent' => false, 'reason' => 'No such invoice.'];
        }

        $to = $this->billingAddress($clinic);
        if ($to === '') {
            return ['ok' => false, 'sent' => false, 'reason' => 'The clinic has no email address.'];
        }

        $paidOn   = substr((string) ($invoice['paid_at'] ?? Db::now()), 0, 10);
        $renewsOn = (string) ($clinic['renews_on'] ?? '');
        $runs     = $renewsOn !== '' ? "Your subscription now runs to {$renewsOn}." : 'Your account is active.';
        $amount   = money((float) $invoice['amount']);
        $opsUrl   = rtrim(Tenant::opsUrl($clinic), '/') . '/billing';

        $body = "Hello {$clinic['name']},\n\n"
            . "Thank you — we have matched your payment.\n\n"
            . "  Invoice:  {$invoice['number']}\n"
            . "  Amount:   {$amount}\n"
            . "  Received: {$paidOn}\n\n"
            . "{$runs}\n\n"
            . "Every invoice, and this receipt, is on your Subscription page:\n\n"
            . "  {$opsUrl}\n\n"
            . "— " . $this->productName();

        $sent = (new MailService())->send($to, 'Payment received — ' . $invoice['number'], $body);
        Log::info('billing.receipt_emailed', [
            'clinic' => $clinicId, 'invoice' => $invoiceId, 'to' => $to, 'sent' => $sent,
        ]);

        return $sent
            ? ['ok' => true, 'sent' => true]
            : ['ok' => true, 'sent' => false, 'reason' => 'Mail is not configured, so the receipt was not emailed.'];
    }

    /**
     * The service is about to stop.
     *
     * Sends the actual date, because "your account may be suspended" is a
     * threat and "the service stops on the 24th" is information somebody can
     * act on.
     *
     * @return array{ok:bool, sent:bool, reason?:string}
     */
    public function paymentDue(int $clinicId): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if ($clinic === null) {
            return ['ok' => false, 'sent' => false, 'reason' => 'No such clinic.'];
        }

        $to = $this->billingAddress($clinic);
        if ($to === '') {
            return ['ok' => false, 'sent' => false, 'reason' => 'The clinic has no email address.'];
        }

        $outstanding = Db::one(
            "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n
               FROM invoices WHERE clinic_id = ? AND status = 'unpaid'",
            [$clinicId]
        );
        if ((int) ($outstanding['n'] ?? 0) === 0) {
            return ['ok' => false, 'sent' => false, 'reason' => 'Nothing is outstanding.'];
        }

        $total   = money((float) $outstanding['total']);
        $stopsOn = $this->stopDate($clinic);
        $payUrl  = rtrim(Tenant::opsUrl($clinic), '/') . '/billing';

        $body = "Hello {$clinic['name']},\n\n"
            . "There is {$total} outstanding on your account.\n\n"
            . ($stopsOn !== ''
                ? "The service stops on {$stopsOn} unless it is settled. Nothing is deleted\n"
                  . "when that happens — the queue, your patients and your history all stay,\n"
                  . "and access comes straight back when a payment is matched.\n\n"
                : "Please settle it as soon as you can.\n\n")
            . "Bank details, and the reference field to record your transfer, are here:\n\n"
            . "  {$payUrl}\n\n"
            . "If something has gone wrong at your end, reply to this message rather\n"
            . "than letting it lapse. We would rather sort it out than switch you off.\n\n"
            . "— " . $this->productName();

        $sent = (new MailService())->send($to, 'Payment due on your ' . $this->productName() . ' account', $body);
        Log::info('billing.payment_due_emailed', [
            'clinic' => $clinicId, 'to' => $to, 'sent' => $sent, 'total' => $total,
        ]);

        return $sent
            ? ['ok' => true, 'sent' => true]
            : ['ok' => true, 'sent' => false, 'reason' => 'Mail is not configured, so the reminder was not emailed.'];
    }

    /**
     * The date the service actually stops.
     *
     * Once grace has begun the clinic has a stored `grace_until` and that is
     * authoritative — a cron that ran late must not move the deadline. Before
     * grace begins, the same arithmetic that will be used when it does:
     * the renewal date plus the grace window.
     */
    private function stopDate(array $clinic): string
    {
        $grace = (string) ($clinic['grace_until'] ?? '');
        if ($grace !== '') {
            return substr($grace, 0, 10);
        }
        $due = substr((string) ($clinic['renews_on'] ?? ''), 0, 10);
        if ($due === '') {
            return '';
        }
        return date('Y-m-d', strtotime($due . ' +' . \App\Services\LifecycleService::GRACE_DAYS . ' days'));
    }

    /**
     * Where to send money notices.
     *
     * `billing_email` is what the clinic typed for invoices; the account email
     * is the fallback, because an invoice that goes nowhere is worse than one
     * that goes to the owner's inbox.
     */
    private function billingAddress(array $clinic): string
    {
        foreach (['billing_email', 'email'] as $field) {
            $value = trim((string) ($clinic[$field] ?? ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }
        return '';
    }

    /** The bank details block, or '' when the platform has not set them. */
    private function bankLines(): string
    {
        $bank    = trim((string) Env::get('company.bank', ''));
        $account = trim((string) Env::get('company.bank_account_no', ''));
        if ($bank === '' || $account === '') {
            return '';
        }

        $lines = ['  Bank:     ' . $bank];
        $branch = trim((string) Env::get('company.bank_branch', ''));
        if ($branch !== '') {
            $lines[] = '  Branch:   ' . $branch;
        }
        $name = trim((string) Env::get('company.bank_account_name', ''));
        if ($name !== '') {
            $lines[] = '  Name:     ' . $name;
        }
        $lines[] = '  Account:  ' . $account;
        $swift = trim((string) Env::get('company.bank_swift', ''));
        if ($swift !== '') {
            $lines[] = '  SWIFT:    ' . $swift;
        }
        $lines[] = '';
        $lines[] = 'Put the invoice number in the reference field so we can match it.';

        return implode("\n", $lines);
    }

    private function productName(): string
    {
        return (string) Env::get('app.name', 'QueuePilot');
    }
}
