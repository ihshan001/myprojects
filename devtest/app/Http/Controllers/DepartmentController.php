<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\DepartmentService;
use App\Services\DoctorService;

final class DepartmentController
{
    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Not attached to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();
        $service = new DepartmentService();

        view('departments/index', [
            'title' => 'Departments',
            'departments' => $service->all($clinicId),
            'doctors' => (new DoctorService())->all($clinicId, true),
            // Queues that can be assigned. Open sessions first: assigning a
            // queue that has already closed today is a historical decision,
            // not an operational one.
            'queuesToday' => Db::all(
                'SELECT id, name, doctor_id, department_id, status FROM queues
                  WHERE clinic_id = ? AND session_date = ?
                  ORDER BY status = \'open\' DESC, name',
                [$clinicId, Db::today()]
            ),
            'canManage' => Auth::can('clinic.manage'),
            // Each department's existing display link, if one was issued.
            'displayKeys' => array_reduce(
                $service->all($clinicId),
                function (array $carry, array $d) use ($clinicId): array {
                    $key = $this->displayKeyFor($clinicId, (int) $d['id']);
                    if ($key !== null) {
                        $carry[(int) $d['id']] = rtrim((string) \App\Core\Env::get('app.url', ''), '/') . '/board/' . $key;
                    }
                    return $carry;
                },
                []
            ),
            'newDisplayKey' => \App\Core\Session::takeFlash('department_display_key'),
            'active' => 'departments',
        ]);
    }

    public function store(): void
    {
        $clinicId = $this->clinicId();
        $result = (new DepartmentService())->create($clinicId, [
            'name' => Request::string('name'),
            'code' => Request::string('code'),
            'description' => Request::string('description'),
            'floor' => Request::string('floor'),
            'lead_doctor_id' => Request::int('lead_doctor_id'),
            'sort_order' => Request::int('sort_order'),
        ]);

        if (!$result['ok']) {
            flash('error', $result['error']);
        } else {
            flash('success', 'Department created. Queues and doctors can now be assigned to it.');
        }
        redirect('/departments');
    }

    public function update(string $id): void
    {
        $clinicId = $this->clinicId();
        $result = (new DepartmentService())->update($clinicId, (int) $id, [
            'name' => Request::string('name'),
            'code' => Request::string('code'),
            'description' => Request::string('description'),
            'floor' => Request::string('floor'),
            'lead_doctor_id' => Request::int('lead_doctor_id'),
            'sort_order' => Request::int('sort_order'),
            'is_active' => Request::input('is_active') ? 1 : 0,
        ]);

        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Department updated.' : $result['error']);
        redirect('/departments');
    }

    public function deactivate(string $id): void
    {
        $clinicId = $this->clinicId();
        $result = (new DepartmentService())->deactivate($clinicId, (int) $id);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Department deactivated. Its queues and history are untouched.'
            : $result['error']);
        redirect('/departments');
    }

    /**
     * Assign queues and doctors to departments.
     *
     * A single screen because the two assignments are made together in a
     * clinic manager's head ("this queue belongs to Outpatient, that doctor
     * leads it") and splitting them across two pages invites one half being
     * left unset.
     */
    public function assign(): void
    {
        $clinicId = $this->clinicId();
        $service = new DepartmentService();

        $queueId = Request::int('queue_id');
        $doctorId = Request::int('doctor_id');
        $departmentId = Request::int('department_id');

        if ($departmentId > 0 && !$service->find($clinicId, $departmentId)) {
            flash('error', 'That department does not belong to this clinic.');
            redirect('/departments');
            return;
        }

        $target = $departmentId > 0 ? $departmentId : null;

        if ($queueId > 0) {
            $owned = Db::scalar('SELECT id FROM queues WHERE id = ? AND clinic_id = ?', [$queueId, $clinicId]);
            if ($owned === null) {
                flash('error', 'That queue does not belong to this clinic.');
                redirect('/departments');
                return;
            }
            Db::run('UPDATE queues SET department_id = ? WHERE id = ? AND clinic_id = ?', [$target, $queueId, $clinicId]);
        }

        if ($doctorId > 0) {
            $owned = Db::scalar('SELECT id FROM doctors WHERE id = ? AND clinic_id = ?', [$doctorId, $clinicId]);
            if ($owned === null) {
                flash('error', 'That doctor does not belong to this clinic.');
                redirect('/departments');
                return;
            }
            Db::run('UPDATE doctors SET department_id = ? WHERE id = ? AND clinic_id = ?', [$target, $doctorId, $clinicId]);
        }

        (new \App\Services\AuditService())->log($clinicId, 'department.assigned', 'department', $departmentId ?: null, [
            'queue_id' => $queueId ?: null,
            'doctor_id' => $doctorId ?: null,
        ]);
        flash('success', $target === null ? 'Cleared the department assignment.' : 'Assigned to the department.');
        redirect('/departments');
    }

    /**
     * Issue (or re-issue) the public display link for one department.
     *
     * A separate token per department rather than a query string, because the
     * link is a bearer credential on a TV screen in a public corridor: it must
     * address exactly one department and nothing else.
     *
     * Rotating replaces the value in place; every TV using the old link stops
     * updating, which is the intended behaviour if a screen was stolen or a
     * contractor kept a copy.
     */
    public function issueDisplayKey(string $id): void
    {
        $clinicId = $this->clinicId();
        $service = new DepartmentService();
        $department = $service->find($clinicId, (int) $id);
        if (!$department) {
            abort(404, 'That department does not exist in this clinic.');
            return;
        }

        $keyName = 'display.department.' . (int) $id;
        $token = \App\Core\Str::urlToken(20);

        Db::run(
            "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, ?, ?)
             ON CONFLICT (scope, owner_id, key_name) DO UPDATE SET value = excluded.value",
            [$clinicId, $keyName, $token]
        );

        (new \App\Services\AuditService())->log($clinicId, 'department.display_key_issued', 'department', (int) $id);
        \App\Core\Session::flash('department_display_key', [
            'id' => (int) $id,
            'url' => rtrim((string) \App\Core\Env::get('app.url', ''), '/') . '/board/' . $token,
        ]);

        flash('success', 'Display link issued for ' . $department['name'] . '. The old link, if any, no longer updates.');
        redirect('/departments');
    }

    /**
     * Read the department's current key. Kept in a private method so the
     * template can be handed a URL rather than having to know the settings
     * layout.
     */
    private function displayKeyFor(int $clinicId, int $departmentId): ?string
    {
        $value = Db::scalar(
            "SELECT value FROM settings WHERE scope = 'clinic' AND owner_id = ? AND key_name = ?",
            [$clinicId, 'display.department.' . $departmentId]
        );
        return $value !== null ? (string) $value : null;
    }

    /** Today's board for one department — also drives the per-department display. */
    public function show(string $id): void
    {
        $clinicId = $this->clinicId();
        $service = new DepartmentService();
        $department = $service->find($clinicId, (int) $id);
        if (!$department) {
            abort(404, 'That department does not exist in this clinic.');
            return;
        }

        view('departments/show', [
            'title' => $department['name'],
            'department' => $department,
            'queues' => $service->queues($clinicId, (int) $id),
            'active' => 'departments',
        ]);
    }
}
