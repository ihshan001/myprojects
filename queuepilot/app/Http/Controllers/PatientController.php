<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\PatientService;

final class PatientController
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
        $q = Request::string('q');
        $service = new PatientService();

        $patients = $q !== ''
            ? $service->search($clinicId, $q, 40)
            : Db::all(
                'SELECT p.*,
                        (SELECT COUNT(*) FROM queue_entries e WHERE e.patient_id = p.id) AS visits,
                        (SELECT MAX(e.joined_at) FROM queue_entries e WHERE e.patient_id = p.id) AS last_seen
                   FROM patients p WHERE p.clinic_id = ?
                  ORDER BY last_seen DESC NULLS LAST, p.full_name LIMIT 40',
                [$clinicId]
            );

        view('patients/index', [
            'title' => 'Patients',
            'patients' => $patients,
            'q' => $q,
            'total' => (int) Db::scalar('SELECT COUNT(*) FROM patients WHERE clinic_id = ?', [$clinicId]),
        ]);
    }

    /**
     * Add someone to the register without putting them in today's queue.
     *
     * The token desk (POST /queue/new) still does both in one step, which is
     * what a walk-in wants. This is the other half: a clinic that takes a
     * booking over the phone, or keeps a register ahead of the day, had no
     * way to record a person without also issuing them a token now.
     */
    public function store(): void
    {
        $clinicId = $this->clinicId();

        $name = trim(Request::string('full_name'));
        $phone = trim(Request::string('phone'));

        if (mb_strlen($name) < 2) {
            flash('error', 'Enter the patient name (at least 2 characters).');
            back('/patients');
            return;
        }
        if ($phone !== '' && strlen(preg_replace('/\D/', '', $phone) ?? '') < 9) {
            flash('error', 'That phone number looks too short. Use 07X XXX XXXX or leave it blank.');
            back('/patients');
            return;
        }

        $service = new PatientService();

        // A phone that is already on the register returns that person rather
        // than creating a second record under a slightly different spelling.
        $existing = $phone !== '' ? $service->findByPhone($clinicId, $phone) : null;
        if ($existing !== null) {
            flash('info', $existing['full_name'] . ' is already on the register under this number.');
            back('/patients?q=' . urlencode($phone));
            return;
        }

        $patient = $service->findOrCreate($clinicId, [
            'full_name' => $name,
            'phone' => $phone,
            'gender' => Request::string('gender'),
            'birth_year' => Request::int('birth_year'),
            'notes' => Request::string('notes'),
        ]);

        (new AuditService())->log($clinicId, 'patient.created', 'patient', (int) $patient['id'], [
            'name' => $patient['full_name'],
        ]);

        flash('success', $patient['full_name'] . ' added to the register.');
        back('/patients?q=' . urlencode($patient['full_name']));
    }

    public function show(string $id): void
    {
        $clinicId = $this->clinicId();
        $service = new PatientService();
        $patient = $service->find($clinicId, (int) $id);
        if (!$patient) {
            abort(404, 'Patient not found in this clinic.');
            return;
        }

        $history = Db::all(
            'SELECT e.*, q.name AS queue_name, d.name AS doctor_name
               FROM queue_entries e
               JOIN queues q ON q.id = e.queue_id
               LEFT JOIN doctors d ON d.id = q.doctor_id
              WHERE e.patient_id = ? AND e.clinic_id = ?
              ORDER BY e.id DESC LIMIT 40',
            [(int) $id, $clinicId]
        );

        view('patients/show', [
            'title' => $patient['full_name'],
            'patient' => $patient,
            'history' => $history,
            'stats' => [
                'visits' => count($history),
                'completed' => count(array_filter($history, fn ($h) => $h['status'] === 'completed')),
                'avg_wait' => $this->avg(array_column(array_filter($history, fn ($h) => $h['wait_minutes'] !== null), 'wait_minutes')),
            ],
        ]);
    }

    public function update(string $id): void
    {
        $clinicId = $this->clinicId();
        $patient = (new PatientService())->find($clinicId, (int) $id);
        if (!$patient) {
            abort(404, 'Patient not found.');
            return;
        }

        $name = Request::string('full_name');
        if (mb_strlen($name) < 2) {
            flash('error', 'Patient name is too short.');
            redirect('/patients/' . (int) $id);
            return;
        }

        Db::run(
            'UPDATE patients SET full_name = ?, phone = ?, gender = ?, birth_year = ?, notes = ?, updated_at = ?
              WHERE id = ? AND clinic_id = ?',
            [
                $name,
                Request::string('phone') ?: null,
                Request::string('gender') ?: null,
                Request::int('birth_year') ?: null,
                Request::string('notes') ?: null,
                Db::now(), (int) $id, $clinicId,
            ]
        );

        (new AuditService())->log($clinicId, 'patient.updated', 'patient', (int) $id, [
            'name' => $name,
        ]);
        flash('success', 'Patient record updated.');
        redirect('/patients/' . (int) $id);
    }

    /** JSON search for the token desk's live type-ahead. */
    public function search(): void
    {
        $clinicId = $this->clinicId();
        $q = Request::string('q');
        $rows = (new PatientService())->search($clinicId, $q, 8);

        // minimal fields: no notes or full history in a type-ahead payload
        json([
            'ok' => true,
            'results' => array_map(static fn (array $p): array => [
                'id' => (int) $p['id'],
                'name' => $p['full_name'],
                'phone' => $p['phone'],
                'code' => $p['code'],
                'gender' => $p['gender'],
            ], $rows),
        ]);
    }

    private function avg(array $values): ?int
    {
        $values = array_filter($values, 'is_numeric');
        return $values === [] ? null : (int) round(array_sum($values) / count($values));
    }
}
