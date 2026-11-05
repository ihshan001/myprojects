<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\ClinicService;

/**
 * Subscription billing.
 *
 * Deliberate separation of concerns required by the product rules:
 *   • the PLAN is a recurring subscription (doctors/staff/queue limits)
 *   • SMS/WhatsApp usage, hardware and onboarding are billed separately and
 *     never bundled silently into the plan price
 *   • suspending a clinic blocks new sessions but NEVER deletes data
 */
final class BillingController
{
    public function index(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Platform administrators do not have a clinic subscription.');
        }
        $clinic = Auth::clinic();
        $service = new ClinicService();
        $plan = $service->plan($clinic['plan_id'] !== null ? (int) $clinic['plan_id'] : null);

        // usage vs plan limits
        $usage = [
            'doctors' => (int) Db::scalar('SELECT COUNT(*) FROM doctors WHERE clinic_id = ? AND is_active = 1', [$clinicId]),
            'staff' => (int) Db::scalar("SELECT COUNT(*) FROM users WHERE clinic_id = ? AND role <> 'doctor'", [$clinicId]),
            'queues' => (int) Db::scalar('SELECT COUNT(*) FROM queues WHERE clinic_id = ? AND session_date = ?', [$clinicId, Db::today()]),
        ];

        view('billing/index', [
            'title' => 'Subscription',
            'clinicRecord' => $clinic,
            'plan' => $plan,
            'plans' => $service->plans(),
            'invoices' => Db::all('SELECT * FROM invoices WHERE clinic_id = ? ORDER BY id DESC LIMIT 24', [$clinicId]),
            'usage' => $usage,
            'notifStats' => (new \App\Services\NotificationService())->stats($clinicId),
            'suspended' => (bool) Request::int('suspended'),
            // Only owners/admins may change the subscription; receptionists can
            // see the plan and the invoice history but not re-plan the clinic.
            'canManage' => Auth::can('clinic.manage'),
        ]);
    }

    public function changePlan(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Not available.');
        }
        $planId = Request::int('plan_id');
        $plan = Db::one('SELECT * FROM plans WHERE id = ? AND is_active = 1', [$planId]);
        if (!$plan) {
            flash('error', 'Choose one of the listed plans.');
            redirect('/billing');
            return;
        }

        // Downgrades must not silently break a clinic: check current usage.
        $doctors = (int) Db::scalar('SELECT COUNT(*) FROM doctors WHERE clinic_id = ? AND is_active = 1', [$clinicId]);
        if ((int) $plan['max_doctors'] < $doctors) {
            flash('error', sprintf(
                'You have %d active doctors; the %s plan allows %d. Deactivate a doctor first.',
                $doctors, $plan['name'], (int) $plan['max_doctors']
            ));
            redirect('/billing');
            return;
        }

        Db::run('UPDATE clinics SET plan_id = ?, updated_at = ? WHERE id = ?', [$planId, Db::now(), $clinicId]);
        (new AuditService())->log($clinicId, 'billing.plan_changed', 'clinic', $clinicId, [
            'plan' => $plan['code'], 'price' => $plan['price_monthly'],
        ]);
        flash('success', 'Plan changed to ' . $plan['name'] . '. It takes effect at the next renewal.');
        redirect('/billing');
    }

    /**
     * Record a payment against an invoice. This is a manual/offline-friendly
     * flow (bank transfer, cash at the desk) because clinic SaaS in this market
     * rarely has a working card gateway on day one. A gateway can be dropped in
     * behind the same method later without touching the UI.
     */
    public function pay(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Not available.');
        }
        $invoiceId = Request::int('invoice_id');
        $reference = Request::string('reference');

        $invoice = Db::one('SELECT * FROM invoices WHERE id = ? AND clinic_id = ?', [$invoiceId, $clinicId]);
        if (!$invoice) {
            abort(404, 'Invoice not found.');
            return;
        }
        if ($invoice['status'] === 'paid') {
            flash('warn', 'That invoice is already settled.');
            redirect('/billing');
            return;
        }
        if ($invoice['status'] === 'claimed') {
            flash('warn', 'Your payment reference is already with us for checking. We will email you when it clears.');
            redirect('/billing');
            return;
        }

        if (mb_strlen(trim($reference)) < 4) {
            flash('error', 'Enter the bank reference or slip number so we can match your payment.');
            redirect('/billing');
            return;
        }

        // The clinic claims a payment; it does not settle the invoice.
        //
        // Settling activates the subscription, so letting the payer do it
        // means any clinic can reactivate itself by typing eight characters.
        // A platform administrator matches the claim against the bank and
        // only then does billing_status change.
        Db::run(
            "UPDATE invoices SET status = 'claimed', method = ?, reference = ?, claimed_at = ?
              WHERE id = ? AND clinic_id = ?",
            ['bank_transfer', $reference, Db::now(), $invoiceId, $clinicId]
        );

        (new AuditService())->log($clinicId, 'billing.payment_claimed', 'invoice', $invoiceId, [
            'amount' => $invoice['amount'], 'reference' => $reference,
        ]);

        // Every platform administrator sees it, so a claim cannot sit
        // unnoticed behind one person's login.
        foreach (Db::all("SELECT id FROM users WHERE role = 'super_admin' AND is_active = 1") as $admin) {
            (new \App\Services\StaffNotificationService())->notify(
                $clinicId,
                (int) $admin['id'],
                'Payment claim needs checking',
                Auth::clinic()['name'] . ' claims ' . number_format((float) $invoice['amount'], 2)
                    . ' for invoice ' . $invoice['number'] . ' (ref ' . $reference . ').',
                'warning',
                '/admin/billing'
            );
        }

        flash('success', 'Payment reference received. We will verify it against the bank and activate the subscription — usually within one working day.');
        redirect('/billing');
    }

    /**
     * Withdraw a claim that was entered by mistake, before it is verified.
     * Without this, a typo would freeze the invoice until a human cleared it.
     */
    public function withdrawClaim(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Not available.');
            return;
        }
        $invoiceId = Request::int('invoice_id');

        $invoice = Db::one('SELECT * FROM invoices WHERE id = ? AND clinic_id = ?', [$invoiceId, $clinicId]);
        if (!$invoice || $invoice['status'] !== 'claimed') {
            flash('error', 'There is no pending claim on that invoice.');
            redirect('/billing');
            return;
        }

        Db::run(
            "UPDATE invoices SET status = 'unpaid', reference = NULL, claimed_at = NULL
              WHERE id = ? AND clinic_id = ?",
            [$invoiceId, $clinicId]
        );
        (new AuditService())->log($clinicId, 'billing.payment_claim_withdrawn', 'invoice', $invoiceId);
        flash('success', 'Claim withdrawn. You can enter the correct reference.');
        redirect('/billing');
    }
}
