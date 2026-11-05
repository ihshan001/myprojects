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

        view('doctors/index', [
            'title' => 'Doctors & rooms',
            'doctors' => $doctors,
            'specialties' => $service->specialties($clinicId),
            'rooms' => $service->rooms($clinicId),
            'queues' => $queues,
            'canManage' => Auth::can('doctor.manage'),
            'planLimit' => (new ClinicService())->withinLimit($clinicId, 'doctors'),
        ]);
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
        ]);

        (new AuditService())->log($clinicId, 'clinic.settings_updated', 'clinic', $clinicId, ['name' => $name]);
        flash('success', 'Clinic settings saved.');
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
