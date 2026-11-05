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

        Db::run(
            "UPDATE invoices SET status = 'paid', paid_at = ?, method = ?, reference = ? WHERE id = ? AND clinic_id = ?",
            [Db::now(), 'bank_transfer', $reference ?: null, $invoiceId, $clinicId]
        );
        Db::run("UPDATE clinics SET billing_status = 'active', renews_on = ?, updated_at = ? WHERE id = ?",
            [date('Y-m-d', strtotime('+1 month')), Db::now(), $clinicId]);

        (new AuditService())->log($clinicId, 'billing.payment_recorded', 'invoice', $invoiceId, [
            'amount' => $invoice['amount'], 'reference' => $reference,
        ]);
        flash('success', 'Payment recorded. Thank you — your subscription is active.');
        redirect('/billing');
    }
}
