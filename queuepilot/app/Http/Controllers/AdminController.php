<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\LifecycleService;

/** Platform (super admin) console: tenants, plans, revenue. */
final class AdminController
{
    public function index(): void
    {
        $clinics = Db::all(
            "SELECT c.*, p.name AS plan_name, p.price_monthly,
                    (SELECT COUNT(*) FROM queue_entries e
                      WHERE e.clinic_id = c.id AND substr(e.joined_at,1,10) = ?) AS today_entries,
                    (SELECT COUNT(*) FROM users u WHERE u.clinic_id = c.id) AS staff_count
               FROM clinics c LEFT JOIN plans p ON p.id = c.plan_id
              ORDER BY c.created_at DESC",
            [Db::today()]
        );

        $mrr = 0.0;
        foreach ($clinics as $c) {
            if ($c['billing_status'] === 'active' && $c['billing_status'] !== 'suspended') {
                $mrr += (float) ($c['price_monthly'] ?? 0);
            }
        }

        view('admin/index', [
            'title' => 'Platform overview',
            'clinics' => $clinics,
            'mrr' => $mrr,
            // Monitoring is only useful if a person sees it. The same checks
            // the /api/health probe exposes are shown here as alerts, so an
            // operator who never curls the endpoint still learns that the
            // worker died or a backup is stale.
            'alerts' => (new \App\Services\MonitorService())->openAlerts(),
            'health' => (new \App\Services\MonitorService())->run(),
            'totals' => [
                'clinics' => count($clinics),
                'active' => count(array_filter($clinics, fn ($c) => $c['billing_status'] === 'active')),
                'trial' => count(array_filter($clinics, fn ($c) => $c['billing_status'] === 'trial')),
                'suspended' => count(array_filter($clinics, fn ($c) => $c['billing_status'] === 'suspended')),
                'patients_today' => (int) Db::scalar(
                    'SELECT COUNT(*) FROM queue_entries WHERE substr(joined_at,1,10) = ?', [Db::today()]
                ),
            ],
        ]);
    }

    public function clinics(): void
    {
        $clinics = Db::all(
            "SELECT c.*, p.name AS plan_name, p.price_monthly,
                    (SELECT COUNT(*) FROM users u WHERE u.clinic_id = c.id) AS staff,
                    (SELECT COUNT(*) FROM patients pt WHERE pt.clinic_id = c.id) AS patients,
                    (SELECT COUNT(*) FROM queue_entries e WHERE e.clinic_id = c.id) AS entries
               FROM clinics c LEFT JOIN plans p ON p.id = c.plan_id
              ORDER BY c.name"
        );
        view('admin/clinics', [
            'title' => 'Clinics',
            'clinics' => $clinics,
            'plans' => Db::all('SELECT * FROM plans ORDER BY sort_order'),
        ]);
    }

    public function updateClinic(string $id): void
    {
        $clinicId = (int) $id;
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            abort(404, 'Clinic not found.');
            return;
        }

        $action = Request::string('action');
        if ($action === 'suspend' || $action === 'activate') {
            $status = $action === 'suspend' ? 'suspended' : 'active';
            // Data is preserved in both directions — suspension is a billing
            // state, never a deletion event.
            Db::run('UPDATE clinics SET billing_status = ?, updated_at = ? WHERE id = ?',
                [$status, Db::now(), $clinicId]);
            (new AuditService())->log($clinicId, 'platform.clinic_' . $action, 'clinic', $clinicId);
            flash('success', $clinic['name'] . ' is now ' . $status . '. Queue history is untouched.');
            redirect('/admin/clinics');
            return;
        }

        if ($action === 'plan') {
            $planId = Request::int('plan_id');
            $plan = Db::one('SELECT * FROM plans WHERE id = ?', [$planId]);
            if (!$plan) {
                flash('error', 'Unknown plan.');
                redirect('/admin/clinics');
                return;
            }
            Db::run('UPDATE clinics SET plan_id = ?, updated_at = ? WHERE id = ?', [$planId, Db::now(), $clinicId]);
            (new AuditService())->log($clinicId, 'platform.plan_assigned', 'clinic', $clinicId, ['plan' => $plan['code']]);
            flash('success', $clinic['name'] . ' moved to ' . $plan['name'] . '.');
        }
        redirect('/admin/clinics');
    }

    /**
     * Suspend or reinstate a clinic.
     *
     * Suspension stops token issuing and darkens the public surfaces; it does
     * not delete anything and does not sign staff out, because a billing
     * dispute should not cost a clinic its day's work.
     */
    public function suspendClinic(string $id): void
    {
        $result = (new LifecycleService())->suspend(
            (int) $id,
            Request::string('reason'),
            Auth::id()
        );
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Clinic suspended. New tokens are refused; nothing was deleted.'
            : $result['error']);
        redirect('/admin/clinics');
    }

    public function reinstateClinic(string $id): void
    {
        $result = (new LifecycleService())->reinstate((int) $id, Auth::id());
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Clinic reinstated.' : $result['error']);
        redirect('/admin/clinics');
    }

    /** The clinic's own request to close. Starts the retention clock. */
    public function requestClosure(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Platform administrators do not own a clinic subscription.');
            return;
        }

        // Typing the clinic's name is the confirmation. A checkbox is too easy
        // to click through for an action that signs out every colleague.
        $typed = trim(Request::string('confirm_name'));
        $clinic = Auth::clinic();
        if (mb_strtolower($typed) !== mb_strtolower((string) $clinic['name'])) {
            flash('error', 'Type the clinic name exactly to confirm. Nothing has been changed.');
            redirect('/settings#account');
            return;
        }

        $result = (new LifecycleService())->requestClosure($clinicId);
        if (!$result['ok']) {
            flash('error', $result['error']);
            redirect('/settings#account');
            return;
        }

        \App\Core\Session::destroy();
        flash('success', 'Closure requested. Staff have been signed out and the clinic is hidden. '
            . 'Data is kept until ' . substr((string) $result['purge_after'], 0, 10)
            . ' — contact support before then to change your mind.');
        redirect('/login');
    }

    public function plans(): void
    {
        view('admin/plans', [
            'title' => 'Plans & billing',
            'plans' => Db::all('SELECT * FROM plans ORDER BY sort_order'),
            'revenue' => Db::all(
                "SELECT status, COUNT(*) AS n, SUM(amount) AS total FROM invoices GROUP BY status"
            ),
            'invoices' => Db::all(
                'SELECT i.*, c.name AS clinic_name FROM invoices i JOIN clinics c ON c.id = i.clinic_id
                  ORDER BY i.created_at DESC LIMIT 40'
            ),
        ]);
    }

    public function savePlan(): void
    {
        $id = Request::int('id');
        $data = [
            'name' => trim(Request::string('name')),
            'price_monthly' => (float) Request::string('price_monthly', '0'),
            'max_doctors' => Request::int('max_doctors', 1),
            'max_staff' => Request::int('max_staff', 1),
            'max_queues' => Request::int('max_queues', 2),
            'features' => Request::string('features'),
            'is_active' => Request::bool('is_active') ? 1 : 0,
        ];

        // A price and a limit are both things a negative number can silently
        // corrupt: a negative price reduces reported MRR, and a negative
        // doctor limit makes `withinLimit()` refuse every doctor for the plan.
        // Reject rather than clamp, so the operator notices the mistake.
        if (mb_strlen($data['name']) < 2) {
            flash('error', 'Give the plan a name.');
            redirect('/admin/plans');
            return;
        }
        if ($data['price_monthly'] < 0) {
            flash('error', 'A plan price cannot be negative.');
            redirect('/admin/plans');
            return;
        }
        foreach (['max_doctors', 'max_staff', 'max_queues'] as $limit) {
            if ($data[$limit] < 1) {
                flash('error', 'Plan limits must be at least 1. Use the "active" flag to retire a plan instead.');
                redirect('/admin/plans');
                return;
            }
        }

        if ($id > 0) {
            Db::run(
                'UPDATE plans SET name=?, price_monthly=?, max_doctors=?, max_staff=?, max_queues=?, features=?, is_active=?
                  WHERE id = ?',
                [$data['name'], $data['price_monthly'], $data['max_doctors'], $data['max_staff'],
                 $data['max_queues'], $data['features'], $data['is_active'], $id]
            );
            (new AuditService())->log(null, 'platform.plan_updated', 'plan', $id, $data);
            flash('success', 'Plan updated.');
        }
        redirect('/admin/plans');
    }
}
