<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;
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

        // open today's queue immediately so reception can issue tokens
        Db::insert(
            "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
             VALUES (?,?,?,'consultation','open',?,?,0,3,?)",
            [$clinicId, $id, 'OPD — ' . $name, Db::today(),
             strtoupper(substr(Auth::clinic()['token_prefix'] ?: $name, 0, 1)) ?: 'A', Db::now()]
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

    public function settings(): void
    {
        $clinicId = $this->clinicId();
        $clinic = Auth::clinic();

        view('settings/index', [
            'title' => 'Settings',
            'clinicRecord' => $clinic,
            'staff' => Db::all(
                'SELECT id, name, email, role, is_active, last_login_at FROM users WHERE clinic_id = ? ORDER BY role, name',
                [$clinicId]
            ),
            'displayToken' => (string) Db::scalar(
                "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='display_token'",
                [$clinicId]
            ),
            'canManage' => Auth::can('clinic.manage'),
            'plan' => (new ClinicService())->plan($clinic['plan_id'] !== null ? (int) $clinic['plan_id'] : null),
            'notifStats' => (new \App\Services\NotificationService())->stats($clinicId),
            'invites' => (new \App\Services\StaffInviteService())->pending($clinicId),
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

    /**
     * Invite a staff member.
     *
     * Replaces the old "admin types a temporary password" flow. The admin
     * never learns the password, and the invitation is a single-use token
     * stored only as a hash.
     */
    public function inviteStaff(): void
    {
        $clinicId = $this->clinicId();
        if (!(new ClinicService())->withinLimit($clinicId, 'staff')) {
            flash('error', 'Your plan is at its staff limit. Upgrade to add more accounts.');
            redirect('/settings');
            return;
        }

        $result = (new \App\Services\StaffInviteService())->invite(
            $clinicId,
            Request::string('email'),
            Request::string('role', 'receptionist'),
            trim(Request::string('name'))
        );
        if (!$result['ok']) {
            flash('error', $result['error']);
            redirect('/settings');
            return;
        }

        if ($result['delivered']) {
            flash('success', 'Invitation emailed to ' . $result['email'] . '. It expires ' . ago($result['expires_at']) . ' from now.');
        } else {
            // No transport configured: say so, and hand over the link. This is
            // the honest version of "we sent an email".
            \App\Core\Session::flash('last_invite_link', '/invite/' . $result['token']);
            flash('warn', 'No mail transport is configured, so the invitation was not emailed. Copy the link shown on this page and pass it on.');
        }
        redirect('/settings');
    }

    public function revokeInvite(string $id): void
    {
        $clinicId = $this->clinicId();
        if ((new \App\Services\StaffInviteService())->revoke($clinicId, (int) $id)) {
            flash('success', 'Invitation revoked. The link no longer works.');
        } else {
            flash('error', 'That invitation was not found or has already been used.');
        }
        redirect('/settings');
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
}
