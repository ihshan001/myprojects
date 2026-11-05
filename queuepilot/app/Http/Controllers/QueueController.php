<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Domain\Queue\QueueRepository;
use App\Domain\Queue\QueueService;
use App\Domain\Queue\QueueStateMachine;
use App\Services\AppointmentService;
use App\Services\AuditService;
use App\Services\DoctorService;
use App\Services\PatientService;

final class QueueController
{
    private function guard(): int
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'This account is not attached to a clinic.');
        }
        return $clinicId;
    }

    /** Token desk: search an existing patient or register a walk-in. */
    public function newPatient(): void
    {
        $clinicId = $this->guard();
        $repo = new QueueRepository();
        $doctors = new DoctorService();

        $queues = $repo->liveQueues($clinicId);
        if ($queues === []) {
            // nothing open yet today → open the standard session automatically
            $this->openDefaultSession($clinicId);
            $queues = $repo->liveQueues($clinicId);
        }

        view('queue/new', [
            'title' => 'New patient',
            'queues' => $queues,
            'doctors' => $doctors->all($clinicId),
            'search' => Request::string('q'),
            'results' => Request::string('q') !== ''
                ? (new PatientService())->search($clinicId, Request::string('q'))
                : [],
            'preselect' => Request::int('patient'),
            'appointments' => (new AppointmentService())->forDay($clinicId, null),
        ]);
    }

    /** Issue a token (walk-in or appointment check-in). */
    public function createEntry(): void
    {
        $clinicId = $this->guard();
        $service = new QueueService();
        $patients = new PatientService();

        $queueId = Request::int('queue_id');
        $patientId = Request::int('patient_id');

        try {
            if ($patientId === 0) {
                $name = Request::string('full_name');
                $phone = Request::string('phone');
                if ($name === '' || mb_strlen($name) < 2) {
                    flash('error', 'Enter the patient name (at least 2 characters).');
                    redirect('/queue/new');
                    return;
                }
                if ($phone !== '' && strlen(preg_replace('/\D/', '', $phone) ?? '') < 9) {
                    flash('error', 'That phone number looks too short. Use 07X XXX XXXX or leave it blank.');
                    redirect('/queue/new');
                    return;
                }
                $patient = $patients->findOrCreate($clinicId, [
                    'full_name' => $name,
                    'phone' => $phone,
                    'gender' => Request::string('gender'),
                    'birth_year' => Request::int('birth_year'),
                ]);
                $patientId = (int) $patient['id'];
            }

            $patient = $patients->find($clinicId, $patientId);
            if (!$patient) {
                flash('error', 'That patient could not be found in this clinic.');
                redirect('/queue/new');
                return;
            }
            if ((int) $patient['is_blocked'] === 1) {
                flash('error', 'This patient record is blocked. Ask a clinic admin to review it.');
                redirect('/queue/new');
                return;
            }

            $appointmentId = Request::int('appointment_id');
            $source = $appointmentId > 0 ? 'appointment' : 'walkin';

            $result = $service->issueToken($clinicId, $queueId, $patientId, [
                'source' => $source,
                'appointment_id' => $appointmentId > 0 ? $appointmentId : null,
                'note' => Request::string('note'),
            ]);

            if ($appointmentId > 0) {
                (new AppointmentService())->markCheckedIn($clinicId, $appointmentId);
            }

            $link = rtrim((string) \App\Core\Env::get('app.url', ''), '/') . $result['track_url'];

            // Reception gets straight to the printable/sendable confirmation.
            view('queue/token_issued', [
                'title' => 'Token ' . $result['token'],
                'result' => $result,
                'patient' => $patient,
                'queue' => Db::one('SELECT * FROM queues WHERE id = ? AND clinic_id = ?', [$queueId, $clinicId]),
                'fullLink' => $link,
                'position' => $service->positionFor(
                    Db::one('SELECT * FROM queue_entries WHERE id = ?', [$result['entry_id']]),
                    $clinicId
                ),
            ]);
        } catch (\Throwable $e) {
            \App\Core\Log::error('token.issue.failed', ['error' => $e->getMessage(), 'user' => Auth::id()]);
            flash('error', $e->getMessage());
            redirect('/queue/new');
        }
    }

    /** Single queue board. */
    public function show(): void
    {
        $clinicId = $this->guard();
        $repo = new QueueRepository();
        $service = new QueueService();

        $queueId = Request::int('queue_id');
        if ($queueId === 0) {
            $queues = $repo->liveQueues($clinicId);
            $queueId = (int) ($queues[0]['id'] ?? 0);
        }
        $queue = $repo->queue($clinicId, $queueId);
        if (!$queue) {
            abort(404, 'That queue does not belong to your clinic.');
            return;
        }

        view('queue/board', [
            'title' => $queue['name'],
            'queue' => $queue,
            'queues' => $repo->liveQueues($clinicId),
            'rows' => $repo->board($clinicId, $queueId),
            'serving' => $service->nowServing($clinicId, $queueId),
            'canOperate' => Auth::can('queue.operate'),
        ]);
    }

    public function entry(): void
    {
        $clinicId = $this->guard();
        $entryId = Request::int('id');
        $service = new QueueService();
        $repo = new QueueRepository();
        $entry = $repo->entry($clinicId, $entryId);
        if (!$entry) {
            abort(404, 'Entry not found in your clinic.');
            return;
        }
        $events = Db::all(
            'SELECT e.*, u.name AS actor FROM queue_events e LEFT JOIN users u ON u.id = e.actor_id
              WHERE e.entry_id = ? AND e.clinic_id = ? ORDER BY e.id DESC LIMIT 40',
            [$entryId, $clinicId]
        );
        view('queue/entry', [
            'title' => 'Entry ' . $entry['token_label'],
            'entry' => $entry,
            'events' => $events,
            'position' => $service->positionFor($entry, $clinicId),
            'canOperate' => Auth::can('queue.operate'),
        ]);
    }

    /** Form-posted queue action (used when JS is unavailable). */
    public function action(): void
    {
        $clinicId = $this->guard();
        $service = new QueueService();
        $result = $service->apply(
            $clinicId,
            Request::int('entry_id'),
            Request::string('action'),
            ['reason' => Request::string('reason'), 'version' => Request::int('version')]
        );
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        back('/app');
    }

    /** Open / pause / close a session for today. */
    public function sessionControl(): void
    {
        $clinicId = $this->guard();
        $queueId = Request::int('queue_id');
        $status = Request::string('status');
        if (!in_array($status, ['open', 'paused', 'closed'], true)) {
            flash('error', 'Unknown session state.');
            back('/app');
            return;
        }

        $queue = Db::one('SELECT * FROM queues WHERE id = ? AND clinic_id = ?', [$queueId, $clinicId]);
        if (!$queue) {
            abort(404, 'Queue not found.');
            return;
        }

        Db::run('UPDATE queues SET status = ? WHERE id = ? AND clinic_id = ?', [$status, $queueId, $clinicId]);
        (new AuditService())->log($clinicId, 'queue.session_' . $status, 'queue', $queueId, [
            'queue' => $queue['name'],
        ]);
        flash('success', 'Session ' . $status . ' for ' . $queue['name'] . '.');
        back('/app');
    }

    /** Create today's queues for every active doctor (idempotent). */
    private function openDefaultSession(int $clinicId): void
    {
        $clinic = Auth::clinic();
        $doctors = (new DoctorService())->all($clinicId);
        foreach ($doctors as $doc) {
            $existing = Db::scalar(
                'SELECT id FROM queues WHERE clinic_id = ? AND doctor_id = ? AND session_date = ?',
                [$clinicId, $doc['id'], Db::today()]
            );
            if ($existing) {
                continue;
            }
            Db::insert(
                "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
                 VALUES (?,?,?,?,'open',?,?,0,3,?)",
                [
                    $clinicId, $doc['id'], 'OPD — ' . $doc['name'],
                    'consultation', Db::today(),
                    $clinic['token_prefix'] ?: substr(strtoupper($doc['name']), 0, 1),
                    Db::now(),
                ]
            );
        }
    }
}
