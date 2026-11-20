<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\BranchService;
use App\Services\TemplateService;
use App\Services\ClinicService;
use App\Services\DoctorService;

final class ClinicController
{
    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Not attached to a clinic.');
        }
        return $id;
    }

    public function doctors(): void
    {
        $clinicId = $this->clinicId();
        $service = new DoctorService();
        $today = Db::today();

        $doctors = $service->all($clinicId, false);
        $queues = [];
        foreach (Db::all('SELECT * FROM queues WHERE clinic_id = ? AND session_date = ?', [$clinicId, $today]) as $q) {
            $queues[(int) $q['doctor_id']] = $q;
        }

        // Leave today is a first-class fact on this page: reception needs to
        // see "away until Thursday" next to the doctor, not hunt for it.
        $awayToday = $service->onLeaveIds($clinicId, $today, $today);

        view('doctors/index', [
            'title' => 'Doctors & rooms',
            'doctors' => $doctors,
            'specialties' => $service->specialties($clinicId),
            'rooms' => $service->rooms($clinicId),
            'queues' => $queues,
            'awayToday' => $awayToday,
            'leave' => $service->leave($clinicId, $today),
            'canManage' => Auth::can('doctor.manage'),
            'planLimit' => (new ClinicService())->withinLimit($clinicId, 'doctors'),
        ]);
    }

    public function addLeave(): void
    {
        $clinicId = $this->clinicId();
        $result = (new DoctorService())->addLeave(
            $clinicId,
            Request::int('doctor_id'),
            Request::string('starts_on'),
            Request::string('ends_on', Request::string('starts_on')),
            trim(Request::string('reason')),
            Auth::id()
        );
        if (!$result['ok']) {
            flash('error', $result['error']);
            redirect('/doctors');
            return;
        }

        $affected = $result['affected'] ?? [];
        if ($affected !== []) {
            // Nothing is cancelled automatically. Reception gets the list and
            // decides: move them, call them, or leave them alone.
            flash('warn', sprintf(
                '%d appointment%s falls inside this leave. Review them and contact the patients — nothing was cancelled automatically.',
                count($affected),
                count($affected) === 1 ? '' : 's'
            ));
        } else {
            flash('success', 'Leave recorded. The booking form will not offer this doctor on those dates.');
        }
        redirect('/doctors');
    }

    public function removeLeave(string $id): void
    {
        $clinicId = $this->clinicId();
        if ((new DoctorService())->removeLeave($clinicId, (int) $id)) {
            flash('success', 'Leave removed — the doctor is bookable again on those dates.');
        } else {
            flash('error', 'That leave record was not found.');
        }
        redirect('/doctors');
    }

    public function storeDoctor(): void
    {
        $clinicId = $this->clinicId();
        if (!(new ClinicService())->withinLimit($clinicId, 'doctors')) {
            flash('error', 'Your current plan is at its doctor limit. Upgrade to add more.');
            redirect('/doctors');
            return;
        }
        $name = Request::string('name');
        if (mb_strlen($name) < 3) {
            flash('error', 'Enter the doctor\'s name.');
            redirect('/doctors');
            return;
        }

        $id = (new DoctorService())->create($clinicId, [
            'name' => $name,
            'specialty_id' => Request::int('specialty_id'),
            'room_id' => Request::int('room_id'),
            'consult_fee' => Request::string('consult_fee', '0'),
            'avg_consult_minutes' => Request::int('avg_consult_minutes', 10),
            'reg_no' => Request::string('reg_no'),
        ]);

        // Open today's queue immediately so reception can issue tokens. The
        // series letter comes from the room, not the clinic default, so two
        // doctors never share "A".
        $prefix = (new \App\Domain\Queue\QueueService())->suggestPrefix($clinicId, Request::int('room_id') ?: null);
        Db::insert(
            "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
             VALUES (?,?,?,'consultation','open',?,?,0,3,?)",
            [$clinicId, $id, 'OPD — ' . $name, Db::today(), $prefix, Db::now()]
        );

        (new AuditService())->log($clinicId, 'doctor.created', 'doctor', $id, ['name' => $name]);
        flash('success', $name . ' added and today\'s queue is open.');
        redirect('/doctors');
    }

    public function updateDoctor(string $id): void
    {
        $clinicId = $this->clinicId();
        $doctor = (new DoctorService())->find($clinicId, (int) $id);
        if (!$doctor) {
            abort(404, 'Doctor not found in this clinic.');
            return;
        }
        (new DoctorService())->update($clinicId, (int) $id, [
            'name' => Request::string('name', $doctor['name']),
            'specialty_id' => Request::int('specialty_id'),
            'room_id' => Request::int('room_id'),
            'consult_fee' => Request::string('consult_fee', (string) $doctor['consult_fee']),
            'avg_consult_minutes' => Request::int('avg_consult_minutes', (int) $doctor['avg_consult_minutes']),
            'is_active' => Request::bool('is_active'),
        ]);
        (new AuditService())->log($clinicId, 'doctor.updated', 'doctor', (int) $id);
        flash('success', 'Doctor updated.');
        redirect('/doctors');
    }

    /** The settings tabs, in the order the strip shows them. */
    private const SETTINGS_TABS = ['clinic', 'team', 'notifications', 'integrations', 'billing'];

    public function settings(): void
    {
        $clinicId = $this->clinicId();
        $clinic = Auth::clinic();

        // Unknown tabs fall back to the first one rather than erroring: a
        // bookmarked ?tab=… that a later release renamed should still open
        // somewhere sensible.
        $tab = Request::string('tab', 'clinic');
        if (!in_array($tab, self::SETTINGS_TABS, true)) {
            $tab = 'clinic';
        }

        $prefs = new \App\Services\PreferenceService();
        $integrations = new \App\Services\IntegrationService();

        view('settings/index', [
            'title' => 'Settings',
            'tab' => $tab,
            'clinicRecord' => $clinic,
            'staff' => Db::all(
                'SELECT id, name, email, role, is_active, last_login_at FROM users WHERE clinic_id = ? ORDER BY role, name',
                [$clinicId]
            ),
            // Pending invitations are staff that do not exist yet, so they are
            // not in `users`. Showing them in the same table as real accounts
            // is the point: "we invited a receptionist" is a staffing fact.
            'invites' => Db::all(
                'SELECT email, role, created_at, expires_at FROM staff_invites
                 WHERE clinic_id = ? AND accepted_at IS NULL AND expires_at > ?
                 ORDER BY created_at DESC',
                [$clinicId, Db::now()]
            ),
            'notifications' => $prefs->notifications($clinicId),
            'timezones' => $prefs->timezones(),
            'locales' => $prefs->locales(),
            'integrations' => $integrations->listFor($clinicId),
            'connectedCount' => $integrations->connectedCount($clinicId),
            // The purge form must not post the clinic name from the client:
            // the confirmation phrase is checked against the SERVER's copy.
            'purgePhrase' => (string) $clinic['name'],
            'clinicId' => $clinicId,
            // Only holidays that have not happened yet belong under the
            // "upcoming" heading — a list of last year's closures reads as a
            // stale page rather than as a schedule.
            'holidays' => Db::all(
                'SELECT date, name FROM holidays WHERE clinic_id = ? AND date >= ? ORDER BY date ASC LIMIT 4',
                [$clinicId, Db::today()]
            ),
            'holidayTotal' => (int) Db::scalar(
                'SELECT COUNT(*) FROM holidays WHERE clinic_id = ? AND date >= ?',
                [$clinicId, Db::today()]
            ),
            'displayToken' => (string) Db::scalar(
                "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='display_token'",
                [$clinicId]
            ),
            'canManage' => Auth::can('clinic.manage'),
            'plan' => (new ClinicService())->plan($clinic['plan_id'] !== null ? (int) $clinic['plan_id'] : null),
            'notifStats' => (new \App\Services\NotificationService())->stats($clinicId),
            // When no mail transport is configured the invitation link is the
            // only way the invite reaches the person, so it is shown once,
            // here, to the administrator who created it.
            'inviteLink' => \App\Core\Session::takeFlash('last_invite_link'),
            // Opening hours drive the public booking grid. Read here so the
            // settings page can show what a patient is actually being offered.
            'weekHours' => $this->weekHours($clinicId),
            'horizon' => (new \App\Services\SlotService())->horizonDays($clinicId),
        ]);
    }

    public function saveSettings(): void
    {
        $clinicId = $this->clinicId();
        $name = Request::string('name');
        if (mb_strlen($name) < 3) {
            flash('error', 'Clinic name is too short.');
            redirect('/settings');
            return;
        }

        $color = Request::string('brand_color', '#0F766E');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#0F766E';
        }

        (new ClinicService())->updateBranding($clinicId, [
            'name' => $name,
            'phone' => Request::string('phone'),
            'email' => Request::string('email'),
            'address_line' => Request::string('address_line'),
            'city' => Request::string('city'),
            'district' => Request::string('district'),
            'brand_color' => $color,
            'locale' => Request::string('locale', 'en'),
            'token_prefix' => Request::string('token_prefix'),
            'late_grace_minutes' => Request::int('late_grace_minutes', 15),
            // Opt-in, and the checkbox is the only way it becomes 1 — an
            // unchecked box sends nothing, which is the safe default.
            'notify_approach' => Request::input('notify_approach') ? 1 : 0,
            'approach_threshold' => max(1, min(10, Request::int('approach_threshold', 2))),
        ]);

        (new AuditService())->log($clinicId, 'clinic.settings_updated', 'clinic', $clinicId, ['name' => $name]);
        flash('success', 'Clinic settings saved.');
        redirect('/settings');
    }

    /**
     * Save the weekly opening hours that the public booking page uses.
     *
     * Separate from saveSettings on purpose: hours are submitted as a grid of
     * seven rows, and a half-filled grid should be rejected as a whole rather
     * than silently saving three days of a four-day change.
     */
    /**
     * Save language and timezone from the right-hand rail.
     */
    public function savePreferences(): void
    {
        $clinicId = $this->clinicId();

        $errors = (new \App\Services\PreferenceService())->saveRegion(
            $clinicId,
            Request::string('locale', 'en'),
            Request::string('timezone', 'Asia/Colombo')
        );

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('/settings?tab=clinic');
            return;
        }

        (new AuditService())->log($clinicId, 'clinic.preferences_updated', 'clinic', $clinicId, [
            'locale' => Request::string('locale', 'en'),
            'timezone' => Request::string('timezone', 'Asia/Colombo'),
        ]);
        flash('success', 'Preferences saved.');
        redirect('/settings?tab=clinic');
    }

    /**
     * Save the three notification switches.
     */
    public function saveNotifications(): void
    {
        $clinicId = $this->clinicId();

        $on = (new \App\Services\PreferenceService())->saveNotifications($clinicId, $_POST);

        (new AuditService())->log($clinicId, 'clinic.notifications_updated', 'clinic', $clinicId, ['on' => $on]);
        flash('success', $on === 0
            ? 'Notifications saved. All three are switched off.'
            : 'Notifications saved. ' . $on . ' of 3 switched on.');
        redirect('/settings?tab=notifications');
    }

    /**
     * Switch an integration on for this clinic.
     */
    public function connectIntegration(): void
    {
        $clinicId = $this->clinicId();
        $provider = Request::string('provider');

        if (!(new \App\Services\IntegrationService())->connect($clinicId, $provider, (array) Request::input('config'))) {
            flash('error', 'That integration is not available.');
            redirect('/settings?tab=integrations');
            return;
        }

        (new AuditService())->log($clinicId, 'integration.connected', 'integration', null, ['provider' => $provider]);
        flash('success', 'Integration connected.');
        redirect('/settings?tab=integrations');
    }

    /**
     * Switch an integration off, keeping its settings.
     */
    public function disconnectIntegration(): void
    {
        $clinicId = $this->clinicId();
        $provider = Request::string('provider');

        (new \App\Services\IntegrationService())->disconnect($clinicId, $provider);
        (new AuditService())->log($clinicId, 'integration.disconnected', 'integration', null, ['provider' => $provider]);
        flash('success', 'Integration switched off.');
        redirect('/settings?tab=integrations');
    }

    /**
     * Delete this clinic's operational data. Owner only, double-confirmed.
     *
     * Three things make this safe enough to ship:
     *
     *  1. Only a clinic owner can reach it (`clinic_admin`), not any manager.
     *  2. The confirmation phrase is compared against the clinic name read
     *     from the database, never against anything the browser supplied, so
     *     a crafted POST cannot talk its way past the check.
     *  3. Staff accounts and the clinic record survive. Deleting those would
     *     lock the owner out of an empty clinic, which is a support ticket
     *     rather than a feature.
     *
     * Everything else that belongs to the clinic — patients, tokens,
     * appointments, notifications, audit history — goes.
     */
    public function purgeClinicData(): void
    {
        $clinicId = $this->clinicId();

        if (Auth::role() !== 'clinic_admin') {
            abort(403, 'Only the clinic owner can clear clinic data.');
        }

        $clinic = Auth::clinic();
        $phrase = Request::string('confirm_phrase');

        // Trim and collapse whitespace, and compare case-insensitively: the
        // point is that the human typed the name, not that they matched its
        // capitalisation exactly.
        $normalise = static fn (string $s): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? ''));

        if ($phrase === '' || !hash_equals($normalise((string) $clinic['name']), $normalise($phrase))) {
            flash('error', 'Type the clinic name exactly to confirm. Nothing was deleted.');
            redirect('/settings?tab=clinic');
            return;
        }

        $deleted = $this->purgeOperationalData($clinicId);

        (new AuditService())->log($clinicId, 'clinic.data_purged', 'clinic', $clinicId, ['rows' => $deleted]);
        flash('success', 'Clinic data cleared — ' . number_format($deleted) . ' rows removed. Staff accounts were kept.');
        redirect('/settings?tab=clinic');
    }

    /**
     * Remove every clinic-scoped operational row.
     *
     * Tables are listed explicitly rather than discovered, so that adding a
     * table to the schema cannot silently add it to a destructive path. A new
     * table is kept here on purpose, by someone who has thought about whether
     * it holds patient data.
     *
     * @return int total rows removed
     */
    private function purgeOperationalData(int $clinicId): int
    {
        // WHAT GOES: the clinic's day-to-day history — patients, tokens,
        // appointments, and the notifications and feedback attached to them.
        //
        // WHAT STAYS, deliberately:
        //   - staff accounts and pending invites (otherwise the owner is
        //     locked out of an empty clinic);
        //   - configuration the clinic set up on purpose — priority rules,
        //     departments, rooms, doctors, holidays, break schedules;
        //   - the audit log. Deleting the audit log would erase the only
        //     record that a purge happened, which is exactly the trail an
        //     owner needs if this was a mistake.
        // A purge that destroyed its own evidence would be indistinguishable
        // from one that never happened.
        //
        // Ordered children-first so even SQLite, which does not enforce the
        // foreign keys here, stays consistent, and so a partial failure
        // cannot leave parents pointing at nothing.
        $tables = [
            'queue_events', 'entry_step_done', 'queue_entries', 'queues',
            'appointments', 'patients', 'notifications', 'staff_notifications',
            // `incidents` is NOT in this list: it is handled below, because
            // its child rows have to be removed first.
            'visit_feedback',
            'offline_reservations', 'offline_blocks', 'sms_usage',
        ];

        $deleted = 0;
        Db::transaction(static function () use ($clinicId, $tables, &$deleted): void {
            foreach ($tables as $table) {
                // run() hands back the statement, so the affected-row count
                // has to be asked for. Tables that hold nothing for this
                // clinic simply contribute zero.
                $deleted += Db::run('DELETE FROM ' . $table . ' WHERE clinic_id = ?', [$clinicId])->rowCount();
            }

            // incident_entries hangs off incidents rather than the clinic, so
            // it is reached through its parent. Left as orphans these rows
            // would be invisible in the UI but still patient-adjacent data.
            $deleted += Db::run(
                'DELETE FROM incident_entries WHERE incident_id IN (SELECT id FROM incidents WHERE clinic_id = ?)',
                [$clinicId]
            )->rowCount();
            $deleted += Db::run(
                'DELETE FROM incidents WHERE clinic_id = ?',
                [$clinicId]
            )->rowCount();
        });

        return $deleted;
    }

    public function saveHours(): void
    {
        $clinicId = $this->clinicId();
        $week = [];
        $errors = [];

        for ($dow = 0; $dow <= 6; $dow++) {
            $closed = Request::input('closed_' . $dow) !== null;
            if ($closed) {
                $week[$dow] = 'closed';
                continue;
            }
            $open = Request::string('open_' . $dow);
            $close = Request::string('close_' . $dow);
            if ($open === '' && $close === '') {
                continue; // untouched row keeps its previous value
            }
            if (preg_match('/^\d{2}:\d{2}$/', $open) !== 1 || preg_match('/^\d{2}:\d{2}$/', $close) !== 1) {
                $errors[] = 'Use times like 08:30 on every open day.';
                continue;
            }
            if ($close <= $open) {
                $errors[] = 'Closing time must be after opening time (' . date('l', strtotime('next sunday +' . $dow . ' days')) . ').';
                continue;
            }
            $week[$dow] = $open . '-' . $close;
        }

        if ($errors !== []) {
            flash('error', implode(' ', array_unique($errors)));
            redirect('/settings');
            return;
        }

        (new \App\Services\SlotService())->saveHours($clinicId, $week);

        $horizon = max(1, min(180, Request::int('horizon_days', 30)));
        Db::run(
            "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, 'booking.horizon_days', ?)
             ON CONFLICT (scope, owner_id, key_name) DO UPDATE SET value = excluded.value",
            [$clinicId, (string) $horizon]
        );

        (new AuditService())->log($clinicId, 'clinic.hours_updated', 'clinic', $clinicId, ['week' => $week]);
        flash('success', 'Opening hours saved. The booking page now uses them.');
        redirect('/settings');
    }

    /** @return array<int, array{open: ?string, close: ?string, closed: bool, configured: bool}> */
    private function weekHours(int $clinicId): array
    {
        $service = new \App\Services\SlotService();
        $out = [];
        for ($dow = 0; $dow <= 6; $dow++) {
            // "Closed" is detected by asking the service about a date that
            // falls on that weekday, so the page and the booking grid can
            // never disagree about what a row means.
            $date = date('Y-m-d', strtotime('next sunday +' . $dow . ' days'));
            $hours = $service->hoursFor($clinicId, $date);
            $out[$dow] = [
                'open' => $hours['open'],
                'close' => $hours['close'],
                'closed' => $hours['open'] === null,
                'configured' => $hours['configured'],
            ];
        }
        return $out;
    }

    /**
     * Group view for a clinic that belongs to an organisation.
     *
     * Only a clinic administrator may see it, and it is a *summary*: no
     * patient name, no token, nothing that would let one branch's manager
     * identify another branch's patient. That restriction is the difference
     * between a useful roll-up and a privacy incident.
     */
    /**
     * Branches: which staff reach which branch, and the owner's grant screen.
     *
     * Presenting this as "who can work in branch X" rather than "user
     * permissions" is deliberate — that is the question a practice manager
     * actually asks, and it makes an accidental grant visible as a person's
     * name in the wrong column.
     */
    /**
     * Edit the wording patients actually receive.
     *
     * Sits under Settings rather than under Notifications because the person
     * who owns it is the practice manager, and the question they ask is
     * "can we say this in our own words?" — not "where is the notification
     * subsystem".
     */
    public function templates(): void
    {
        $clinicId = $this->clinicId();
        $service = new TemplateService();

        view('settings/templates', [
            'title' => 'Message wording',
            'active' => 'settings',
            'templates' => $service->all($clinicId),
            'saved' => Session::takeFlash('template_saved'),
            'error' => Session::takeFlash('template_error'),
            'errors' => Session::takeFlash('template_errors') ?? [],
            'old' => Session::takeFlash('template_old') ?? [],
        ]);
    }

    public function saveTemplate(): void
    {
        $clinicId = $this->clinicId();
        $template = Request::string('template');
        $body = Request::payload()['body'] ?? '';

        try {
            (new TemplateService())->save($clinicId, $template, (string) $body, (int) Auth::id());
        } catch (\InvalidArgumentException $e) {
            Session::flash('template_error', $e->getMessage());
            Session::flash('template_old', ['template' => $template, 'body' => (string) $body]);
            redirect('/settings/templates#' . $template);
            return;
        }

        flash('success', 'Saved. New messages will use your wording from now on.');
        redirect('/settings/templates');
    }

    public function resetTemplate(): void
    {
        $clinicId = $this->clinicId();
        $template = Request::string('template');
        $done = (new TemplateService())->reset($clinicId, $template);
        flash($done ? 'success' : 'warn', $done
            ? 'Back to the default wording.'
            : 'That message was already using the default.');
        redirect('/settings/templates');
    }

    public function branches(): void
    {
        $clinicId = $this->clinicId();
        $branches = new BranchService();

        // The full row, because the layout renders the branch name, brand
        // colour and billing state from it — selecting three columns 500s the page.
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        $organisationId = $clinic['organisation_id'] ?? null;

        // A branch owner sees every branch in their group, because granting
        // across a group is the whole point of the feature. A clinic with no
        // group sees only itself, and the page still works.
        $siblings = $organisationId !== null
            ? Db::all(
                'SELECT id, name, slug, city FROM clinics WHERE organisation_id = ? AND is_active = 1 ORDER BY name',
                [(int) $organisationId]
            )
            : [$clinic];

        $grants = Db::all(
            "SELECT g.id, g.user_id, g.clinic_id, g.created_at, u.name AS user_name, u.email, c.name AS clinic_name
               FROM branch_grants g
               JOIN users u ON u.id = g.user_id
               JOIN clinics c ON c.id = g.clinic_id
              WHERE c.id = ? OR c.organisation_id = ?
              ORDER BY c.name, u.name",
            [$clinicId, $organisationId]
        );

        view('settings/branches', [
            'title' => 'Branches',
            'active' => 'settings',
            'clinic' => $clinic,
            'branches' => $siblings,
            'grants' => $grants,
            'staff' => $branches->staffFor($clinicId),
            'canManage' => Auth::can('staff.manage'),
        ]);
    }

    public function grantBranch(): void
    {
        $clinicId = $this->clinicId();
        $userId = Request::int('user_id');
        $branchId = Request::int('clinic_id');

        $user = Db::one('SELECT id, name, clinic_id FROM users WHERE id = ? AND is_active = 1', [$userId]);
        if (!$user) {
            flash('error', 'That staff member does not exist.');
            redirect('/settings/branches');
            return;
        }

        try {
            // Permission to grant is derived from the acting user's own
            // reach: a manager of branch A cannot hand out branch B.
            $this->assertBranchReachable($clinicId, $branchId);
            (new BranchService())->grant($userId, $branchId, (int) Auth::id());
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/settings/branches');
            return;
        }

        (new AuditService())->log($clinicId, 'branch.granted', 'user', $userId, [
            'clinic_id' => $branchId,
        ]);
        flash('success', $user['name'] . ' can now work in that branch. They choose it from the switcher.');
        redirect('/settings/branches');
    }

    public function revokeBranch(): void
    {
        $clinicId = $this->clinicId();
        $userId = Request::int('user_id');
        $branchId = Request::int('clinic_id');

        try {
            $this->assertBranchReachable($clinicId, $branchId);
            $home = (int) Db::scalar('SELECT clinic_id FROM users WHERE id = ?', [$userId]);
            (new BranchService())->revoke($userId, $branchId, $home);
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/settings/branches');
            return;
        }

        (new AuditService())->log($clinicId, 'branch.revoked', 'user', $userId, [
            'clinic_id' => $branchId,
        ]);
        flash('success', 'Access to that branch removed. If they were working in it, they are back on their home branch now.');
        redirect('/settings/branches');
    }

    /**
     * Move this session to another branch.
     *
     * The posted id is re-validated by BranchService; the controller never
     * trusts it. A failed switch lands back on the page with a sentence, not
     * a 500.
     */
    public function switchBranch(): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect('/login');
            return;
        }
        $home = $user['clinic_id'] !== null ? (int) $user['clinic_id'] : null;
        try {
            (new BranchService())->switchTo((int) $user['id'], $home, Request::int('clinic_id'));
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect(Request::string('back', '/app'));
            return;
        }

        $name = Db::scalar('SELECT name FROM clinics WHERE id = ?', [Request::int('clinic_id')]);
        flash('success', 'Now working in ' . (string) $name . '.');
        redirect(Request::string('back', '/app'));
    }

    /**
     * A branch may be granted only from a branch the actor can itself reach,
     * and only within one organisation. Without this, a manager could widen
     * their own group by inviting themselves into a branch they were never
     * given.
     */
    private function assertBranchReachable(int $actingClinicId, int $branchId): void
    {
        if ($branchId === $actingClinicId) {
            return;
        }
        $actingOrg = Db::scalar('SELECT organisation_id FROM clinics WHERE id = ?', [$actingClinicId]);
        $targetOrg = Db::scalar('SELECT organisation_id FROM clinics WHERE id = ? AND is_active = 1', [$branchId]);
        if ($targetOrg === null) {
            throw new \RuntimeException('That branch does not exist or is not active.');
        }
        if ($actingOrg === null || (int) $actingOrg !== (int) $targetOrg) {
            throw new \RuntimeException('You can only manage branches in your own group.');
        }
    }

    public function organisation(): void
    {
        $clinicId = $this->clinicId();
        $service = new \App\Services\OrganisationService();
        $org = $service->forClinic($clinicId);
        if (!$org) {
            view('organisation/index', [
                'title' => 'Group',
                'org' => null,
                'branches' => [],
                'rollup' => null,
            ]);
            return;
        }

        // A branch admin sees the group roll-up but cannot administer it.
        if (!Auth::can('clinic.manage')) {
            abort(403, 'Only a clinic owner can view the group. Ask your administrator.');
            return;
        }

        view('organisation/index', [
            'title' => $org['name'],
            'org' => $org,
            'branches' => $service->branches((int) $org['id']),
            'rollup' => $service->rollup((int) $org['id']),
            'currentClinicId' => $clinicId,
        ]);
    }



    public function storeStaff(): void
    {
        $clinicId = $this->clinicId();
        $role = Request::string('role', 'receptionist');
        if (!in_array($role, ['receptionist', 'doctor', 'clinic_admin'], true)) {
            flash('error', 'Choose a valid role.');
            redirect('/settings');
            return;
        }
        if (!(new ClinicService())->withinLimit($clinicId, 'staff')) {
            flash('error', 'Your plan is at its staff limit. Upgrade to add more accounts.');
            redirect('/settings');
            return;
        }

        $email = mb_strtolower(Request::string('email'));
        $password = (string) Request::input('password', '');
        $name = Request::string('name');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) < 3) {
            flash('error', 'Enter a name and a valid email.');
            redirect('/settings');
            return;
        }
        if (mb_strlen($password) < (int) \App\Core\Env::get('security.password_min', 10)) {
            flash('error', 'Use a password of at least 10 characters.');
            redirect('/settings');
            return;
        }
        if (Db::scalar('SELECT id FROM users WHERE email = ?', [$email])) {
            flash('error', 'That email is already in use.');
            redirect('/settings');
            return;
        }

        $doctorId = null;
        if ($role === 'doctor') {
            $doctorId = Request::int('doctor_id') ?: null;
            if ($doctorId === null) {
                flash('error', 'Link a doctor record to this account.');
                redirect('/settings');
                return;
            }
            $owns = Db::scalar('SELECT id FROM doctors WHERE id = ? AND clinic_id = ?', [$doctorId, $clinicId]);
            if (!$owns) {
                flash('error', 'That doctor belongs to another clinic.');
                redirect('/settings');
                return;
            }
        }

        Db::insert(
            'INSERT INTO users (clinic_id, role, name, email, password_hash, doctor_id, locale, is_active, created_at)
             VALUES (?,?,?,?,?,?,?,1,?)',
            [$clinicId, $role, $name, $email, password_hash($password, PASSWORD_DEFAULT), $doctorId,
             Request::string('locale', 'en'), Db::now()]
        );

        (new AuditService())->log($clinicId, 'staff.created', 'user', null, ['email' => $email, 'role' => $role]);
        flash('success', $name . ' can now sign in as ' . str_replace('_', ' ', $role) . '.');
        redirect('/settings');
    }

    /** The reconciliation report: blocks held, numbers used, what is outstanding. */
    public function offline(): void
    {
        $clinicId = $this->clinicId();
        $service = new \App\Services\OfflineService();

        view('settings/offline', [
            'title' => 'Offline desks',
            'queues' => Db::all(
                "SELECT id, name, token_prefix, token_padding, status FROM queues
                 WHERE clinic_id = ? AND status = 'open' ORDER BY name",
                [$clinicId]
            ),
            'report' => $service->outstanding($clinicId),
        ]);
    }

    /** The export page: what is in the archive, what is left out, and why. */
    public function export(): void
    {
        $clinicId = $this->clinicId();

        view('settings/export', [
            'title' => 'Export clinic data',
            'clinic' => Auth::clinic(),
            'counts' => (new \App\Services\ExportService())->counts($clinicId),
            'lastExport' => Db::one(
                "SELECT created_at, ip, meta_json FROM audit_logs WHERE clinic_id = ? AND action = 'clinic.exported' ORDER BY id DESC LIMIT 1",
                [$clinicId]
            ),
        ]);
    }

    /**
     * Build and hand over the archive.
     *
     * A plain link would let a manager's browser download the archive days
     * later from history, after the session was meant to have ended. A POST
     * that streams the file keeps the download tied to a fresh, explicit
     * act — and to the audit row that records who took a copy of every
     * patient record in the clinic.
     */
    public function downloadExport(): void
    {
        $clinicId = $this->clinicId();
        $includeCredentials = Request::string('screen_config') === '1';

        $result = (new \App\Services\ExportService())->build($clinicId, $includeCredentials);

        if (!$result['ok']) {
            flash('error', (string) $result['error']);
            redirect('/settings/export');
            return;
        }

        (new AuditService())->log($clinicId, 'clinic.exported', 'clinic', $clinicId, [
            'file' => $result['name'],
            'bytes' => $result['bytes'],
            'tables' => $result['tables'],
            'rows' => $result['rows'],
            'included_credentials' => $includeCredentials,
            'redacted' => count($result['redacted']),
        ]);

        $path = (string) $result['path'];
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $result['name'] . '"');
        header('Content-Length: ' . (string) @filesize($path));
        header('Cache-Control: no-store, private');
        // The archive is a one-shot file; it should not linger in a shared
        // hosting account's public directory. It is written outside the docroot
        // anyway, so this is belt and braces.
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        @unlink($path);
    }

    /**
     * Language preference. Moved here from the deleted AuthController because
     * it is a UI preference, not part of signing in.
     */
    public function locale(): void
    {
        $locale = \App\Core\Request::string('locale', 'en');
        $locale = in_array($locale, ['en', 'si', 'ta'], true) ? $locale : 'en';
        \App\Core\Session::put('locale', $locale);
        \App\Core\Lang::load($locale);
        back('/app');
    }
}
