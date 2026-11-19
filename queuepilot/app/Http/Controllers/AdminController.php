<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\FeatureService;
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
            // Work waiting on a human, shown above the tenant table. A clinic
            // that applied on Friday and is still pending on Tuesday was not
            // declined, it was missed — and until an admin opens /admin/clinics
            // this is the only place that says so.
            'notices' => (new \App\Services\PlatformAlertService())->unread((int) (Auth::id() ?? 0)),
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

    /**
     * Platform health, as a page.
     *
     * Every other admin screen answers "is the business OK"; this one answers
     * "is the software OK" — the same checks /api/health returns, readable by
     * a person instead of a probe.
     *
     * Two reasons to look at it here rather than curl the endpoint. A human
     * can judge whether 340 ms of database latency is actually a problem
     * right now, and a probe cannot. And a failing check can be read next to
     * the incident the team already opened about it, instead of in
     * isolation — which is why the recent incidents are on the same screen.
     *
     * Super admin only: these checks name tenants, queue depth and staff.
     */
    public function monitor(): void
    {
        $service = new \App\Services\MonitorService();

        // ?clinic=<id> narrows the same checks to one tenant, which is the
        // view to open when a single branch reports trouble.
        $clinicId = Request::int('clinic');
        $scoped   = null;
        $result   = $service->run();
        if ($clinicId > 0) {
            $clinic = Db::one('SELECT id, name FROM clinics WHERE id = ?', [$clinicId]);
            if ($clinic !== null) {
                $scoped = $clinic;
                $result = $service->run($clinicId);
            }
        }

        view('admin/monitor', [
            'title'     => 'Platform health',
            'status'    => $result['status'],
            'checks'    => $result['checks'],
            'generated' => $result['generated_at'] ?? null,
            'scoped'    => $scoped,
            'alerts'    => $service->openAlerts(),
            'incidents' => $this->recentIncidents(),
            'clinics'   => Db::all('SELECT id, name FROM clinics ORDER BY name'),
        ]);
    }

    /**
     * The eight most recent incidents, whatever state they are in.
     *
     * Guarded because a health page that 500s on a database missing the
     * incidents table is worse than one that simply shows none: health
     * tooling is exactly what gets opened while the platform is already
     * unwell.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentIncidents(): array
    {
        try {
            return Db::all(
                'SELECT id, reference, title, severity, status, clinic_id, detected_at
                   FROM incidents ORDER BY detected_at DESC LIMIT 8'
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function clinics(): void
    {
        $clinics = Db::all(
            "SELECT c.*, p.name AS plan_name, p.price_monthly,
                    (SELECT COUNT(*) FROM users u WHERE u.clinic_id = c.id) AS staff,
                    (SELECT COUNT(*) FROM patients pt WHERE pt.clinic_id = c.id) AS patients,
                    (SELECT COUNT(*) FROM queue_entries e WHERE e.clinic_id = c.id) AS entries,
                    -- Who owns the account and whether they have proved the
                    -- address. A clinic that cannot issue tokens because mail
                    -- never arrived is a support call, and this is the screen
                    -- where it gets fixed.
                    (SELECT u.email FROM users u WHERE u.clinic_id = c.id AND u.role = 'clinic_admin' ORDER BY u.id LIMIT 1) AS owner_email,
                    (SELECT u.id FROM users u WHERE u.clinic_id = c.id AND u.role = 'clinic_admin' ORDER BY u.id LIMIT 1) AS owner_id,
                    (SELECT u.email_verified_at FROM users u WHERE u.clinic_id = c.id AND u.role = 'clinic_admin' ORDER BY u.id LIMIT 1) AS owner_verified_at
               FROM clinics c LEFT JOIN plans p ON p.id = c.plan_id
              ORDER BY c.name"
        );
        view('admin/clinics', [
            'title' => 'Clinics',
            'clinics' => $clinics,
            'plans' => Db::all('SELECT * FROM plans ORDER BY sort_order'),
            // The decision queue, oldest first. A separate list rather than a
            // filter on the table above: work to be done should not be mixed
            // in with the record of work already done.
            'awaiting' => (new LifecycleService())->awaitingDecision(),
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

        // Approval and refusal. These are the only way a clinic leaves
        // 'pending', so they are handled before the edit actions below — an
        // unapproved clinic has nothing worth editing yet.
        if (in_array($action, ['approve', 'trial', 'decline'], true)) {
            $lifecycle = new LifecycleService();
            $result = match ($action) {
                'approve' => $lifecycle->approve(
                    $clinicId,
                    Auth::id(),
                    null,
                    Request::string('billing_cycle') !== '' ? Request::string('billing_cycle') : null
                ),
                'trial'   => $lifecycle->grantTrial($clinicId, (int) Request::input('trial_days', 14), Auth::id()),
                'decline' => $lifecycle->decline($clinicId, (string) Request::input('reason', ''), Auth::id()),
            };
            $message = $result['ok']
                ? match ($action) {
                    'approve' => $clinic['name'] . ' is approved and live on '
                        . ($result['billing_cycle'] === 'yearly' ? 'a yearly' : 'a monthly') . ' plan.',
                    'trial'   => $clinic['name'] . ' is on a trial until ' . ($result['trial_ends_on'] ?? '') . '.',
                    'decline' => $clinic['name'] . ' was declined. They can see the reason you gave.',
                }
                : $result['error'];

            // Sending the owner their addresses and a password link is the
            // last step of approval, and the one that is easiest to forget —
            // an approved clinic nobody told about is a support call tomorrow.
            // Reported honestly: if mail is not configured the clinic is still
            // approved, and the operator needs to know the email never left so
            // they can pass the link on by hand.
            // A paid activation owes money from the day it goes live. Raising
            // the invoice here — and emailing it — is what makes "activate the
            // monthly package" produce the invoice the customer is meant to pay.
            // The cron run cannot do it: it only bills clinics whose renewal
            // date has passed, and a clinic approved today renews in a month. A
            // trial is deliberately skipped; a trial has no invoice.
            if ($result['ok'] && $action === 'approve') {
                try {
                    $invoice = (new \App\Services\InvoiceService())->raiseFirst($clinicId);
                    if ($invoice['ok']) {
                        $message .= ' Invoice ' . $invoice['number'] . ' for '
                            . money((float) $invoice['amount']) . ' raised and emailed.';
                    } else {
                        $message .= ' No invoice raised: ' . $invoice['error'];
                    }
                } catch (\Throwable $e) {
                    \App\Core\Log::warn('billing.activation_invoice_failed', [
                        'clinic' => $clinicId, 'error' => $e->getMessage(),
                    ]);
                    $message .= ' The clinic is live, but the first invoice could not be raised — see the log.';
                }
            }

            if ($result['ok'] && in_array($action, ['approve', 'trial'], true)) {
                $welcome = new \App\Services\WelcomeService();
                $issued = $welcome->issue($clinicId, Auth::id());
                if ($issued['ok']) {
                    $sent = $welcome->send(
                        Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]) ?? $clinic,
                        (string) $issued['token'],
                        (string) $issued['email'],
                        (string) $issued['name']
                    );
                    $message .= $sent['sent']
                        ? ' A welcome email with both addresses has been sent.'
                        : ' Mail is not configured, so no welcome email was sent — copy the link from the clinic page.';
                }
            }

            flash($result['ok'] ? 'success' : 'error', $message);
            redirect('/admin/clinics');
            return;
        }

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
    /**
     * Send the owner a fresh welcome link.
     *
     * Needed whenever the first one did not arrive: mail was not configured
     * yet, it landed in spam, or it expired before they got to it. Issuing a
     * new link supersedes the old one, which is the safe direction — a link
     * that was sitting in a mailbox for three weeks stops working when a new
     * one is sent.
     */
    public function resendWelcome(string $id): void
    {
        $clinicId = (int) $id;
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if ($clinic === null) {
            abort(404, 'That clinic does not exist.');
            return;
        }
        if (($clinic['approved_at'] ?? null) === null && ($clinic['trial_ends_on'] ?? null) === null) {
            flash('error', 'That clinic has not been approved yet, so there is no account to set up.');
            redirect('/admin/clinics');
        }

        $welcome = new \App\Services\WelcomeService();
        $issued = $welcome->issue($clinicId, Auth::id());
        if (!$issued['ok']) {
            flash('error', $issued['error']);
            redirect('/admin/clinics');
        }

        $sent = $welcome->send($clinic, (string) $issued['token'], (string) $issued['email'], (string) $issued['name']);
        if (!$sent['sent']) {
            // The token is only ever returned once, so it is shown here rather
            // than lost. The operator can pass it on by phone.
            $ops = \App\Core\Tenant::opsUrl($clinic);
            Session::flash('welcome_link', rtrim($ops, '/') . '/welcome/' . $issued['token']);
            flash('error', 'Mail is not configured, so nothing was sent. Copy the link below and pass it on.');
            redirect('/admin/clinics');
        }

        flash('success', 'A new welcome link has been emailed to ' . $issued['email'] . '. The previous one no longer works.');
        redirect('/admin/clinics');
    }

    /**
     * Record, or take back, a decision to delete a closed clinic's data.
     *
     * Deliberately a decision and not an action: this writes a flag. The
     * deletion is a separate command run by an operator, because it cannot be
     * undone from a web page and the wrong click here destroys a clinic's
     * entire patient history.
     */
    public function purgeApproval(string $id): void
    {
        $clinicId = (int) $id;
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if ($clinic === null) {
            abort(404, 'That clinic does not exist.');
            return;
        }

        $lifecycle = new \App\Services\LifecycleService();
        $decision = (string) Request::input('decision', '');

        if ($decision === 'revoke') {
            $result = $lifecycle->revokePurgeApproval($clinicId, Auth::id());
            flash('success', $clinic['name'] . ' is no longer marked for deletion. Nothing was removed.');
            redirect('/admin/clinics');
        }

        // The note is the audit trail. Refuse a blank one here rather than
        // letting the service reject it, so the message appears next to the
        // field the operator was looking at.
        $note = trim((string) Request::input('note', ''));
        if (mb_strlen($note) < 10) {
            flash('error', 'Record how you contacted them and what they said — at least a sentence. '
                . 'It is the only record of why this clinic\'s data was deleted.');
            redirect('/admin/clinics');
        }

        $result = $lifecycle->approvePurge($clinicId, $note, Auth::id());
        if (!$result['ok']) {
            flash('error', $result['error']);
            redirect('/admin/clinics');
        }

        flash('success', $clinic['name'] . ' is marked for deletion. Nothing is removed until an operator '
            . 'runs the purge tool, and it can be reversed until then.');
        redirect('/admin/clinics');
    }

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

    /**
     * Feature entitlement management: the platform operator decides which
     * clinic gets which feature. Each clinic row shows every known feature
     * with its effective state and whether the operator has overridden it.
     */
    public function features(): void
    {
        $clinics = Db::all('SELECT * FROM clinics ORDER BY name');
        $features = FeatureService::KNOWN;

        $clinicFeatures = [];
        foreach ($clinics as $clinic) {
            $clinicFeatures[(int) $clinic['id']] = (new FeatureService())->list((int) $clinic['id']);
        }

        view('admin/features', [
            'title' => 'Feature entitlements',
            'clinics' => $clinics,
            'features' => $features,
            'clinicFeatures' => $clinicFeatures,
        ]);
    }

    /** Operator sets or clears a feature for a clinic. */
    public function saveFeature(string $clinicId): void
    {
        $feature = Request::string('feature');
        $enabled = Request::bool('enabled');

        if (!isset(FeatureService::KNOWN[$feature])) {
            flash('error', 'Unknown feature.');
            redirect('/admin/features');
            return;
        }

        (new FeatureService())->set((int) $clinicId, $feature, $enabled);
        (new AuditService())->log(null, 'platform.feature_set', 'clinic_feature', $clinicId,
            ['feature' => $feature, 'enabled' => $enabled]);
        flash('success', 'Feature updated for clinic ' . $clinicId . '.');
        redirect('/admin/features');
    }

    /** Operator removes an override so the plan default applies again. */
    public function unsetFeature(string $clinicId): void
    {
        $feature = Request::string('feature');

        if (!isset(FeatureService::KNOWN[$feature])) {
            flash('error', 'Unknown feature.');
            redirect('/admin/features');
            return;
        }

        (new FeatureService())->unset((int) $clinicId, $feature);
        (new AuditService())->log(null, 'platform.feature_unset', 'clinic_feature', $clinicId,
            ['feature' => $feature]);
        flash('success', 'Override removed — plan default now applies for clinic ' . $clinicId . '.');
        redirect('/admin/features');
    }
}
