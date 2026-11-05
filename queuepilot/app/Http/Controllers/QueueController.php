<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Domain\Queue\QueueRepository;
use App\Domain\Queue\QueueService;
use App\Domain\Queue\QueueStateMachine;
use App\Services\AppointmentService;
use App\Services\AuditService;
use App\Services\DoctorService;
use App\Services\PatientService;
use App\Services\ScanService;

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

            // Redirect after issuing, never render the receipt straight from
            // the POST. A receptionist who refreshes the confirmation page —
            // which people do when the printer jams — would otherwise resubmit
            // the form and mint a second token for the same patient.
            Session::put('issued_token', [
                'result' => $result,
                'patient_id' => (int) $patient['id'],
                'queue_id' => $queueId,
            ]);
            redirect('/queue/token-issued');
        } catch (\Throwable $e) {
            \App\Core\Log::error('token.issue.failed', ['error' => $e->getMessage(), 'user' => Auth::id()]);
            flash('error', $e->getMessage());
            redirect('/queue/new');
        }
    }

    /**
     * Receipt for the token just issued.
     *
     * Reads the one-time payload out of the session, so a refresh shows the
     * same receipt and never issues anything. If there is no payload (someone
     * bookmarked it, or reloaded a day later) it sends them back to the desk.
     */
    public function tokenIssued(): void
    {
        $clinicId = $this->guard();
        $payload = Session::get('issued_token');
        if (!is_array($payload) || empty($payload['result'])) {
            flash('warn', 'That token receipt has expired. Issue a new token or open the live board.');
            redirect('/app');
            return;
        }

        $result = $payload['result'];
        $patient = (new \App\Services\PatientService())->find($clinicId, (int) $payload['patient_id']);
        $queue = Db::one('SELECT * FROM queues WHERE id = ? AND clinic_id = ?', [(int) $payload['queue_id'], $clinicId]);
        $entry = Db::one('SELECT * FROM queue_entries WHERE id = ? AND clinic_id = ?', [(int) $result['entry_id'], $clinicId]);

        if (!$patient || !$queue || !$entry) {
            Session::forget('issued_token');
            flash('warn', 'That token is no longer available.');
            redirect('/app');
            return;
        }

        view('queue/token_issued', [
            'title' => 'Token ' . $result['token'],
            'result' => $result,
            'patient' => $patient,
            'queue' => $queue,
            'fullLink' => rtrim((string) \App\Core\Env::get('app.url', ''), '/') . $result['track_url'],
            'position' => (new QueueService())->positionFor($entry, $clinicId),
        ]);
    }

    /**
     * Thermal slip for one entry.
     *
     * Scoped to the clinic like every other entry point: an id from another
     * tenant produces a 404, not somebody else's patient name.
     */
    public function slip(string $id): void
    {
        $data = $this->slipData((int) $id);
        header('Content-Type: text/html; charset=utf-8');
        echo \App\Support\ThermalSlip::html($data);
        exit;
    }

    /** The same slip as an ESC/POS byte stream, for a wired printer. */
    public function slipEscpos(string $id): void
    {
        $data = $this->slipData((int) $id);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="token-' . $data['token'] . '.bin"');
        echo \App\Support\ThermalSlip::escpos($data);
        exit;
    }

    /** @return array<string, mixed> */
    private function slipData(int $entryId): array
    {
        $clinicId = $this->guard();
        $entry = Db::one(
            'SELECT e.*, q.name AS queue_name,
                    d.name AS doctor_name, r.name AS room_name, c.name AS clinic_name
               FROM queue_entries e
               JOIN queues q ON q.id = e.queue_id
               JOIN clinics c ON c.id = e.clinic_id
               LEFT JOIN doctors d ON d.id = q.doctor_id
               LEFT JOIN rooms r ON r.id = d.room_id
              WHERE e.id = ? AND e.clinic_id = ?',
            [$entryId, $clinicId]
        );
        if (!$entry) {
            abort(404, 'That token does not exist for this clinic.');
            return [];
        }

        return [
            'clinic' => (string) $entry['clinic_name'],
            'queue' => (string) $entry['queue_name'],
            'token' => (string) $entry['token_label'],
            'doctor' => (string) ($entry['doctor_name'] ?? ''),
            'room' => (string) ($entry['room_name'] ?? ''),
            'issued_at' => date('j M Y, g:i A', strtotime((string) $entry['joined_at'])),
            'url' => rtrim((string) \App\Core\Env::get('app.url', ''), '/') . '/t/' . (string) $entry['track_token'],
        ];
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
            'session' => $service->sessionState($queue),
            'queues' => $repo->liveQueues($clinicId),
            'rows' => $repo->board($clinicId, $queueId),
            'serving' => $service->nowServing($clinicId, $queueId),
            'canOperate' => Auth::can('queue.operate'),
        ]);
    }

    /**
     * Accept a scanned code and go straight to that visit.
     *
     * GET, because a barcode reader is a keyboard: it types the code and
     * presses Enter, so a form POST would need a tab stop and a click in the
     * middle of a queue. Nothing is mutated here — the scan finds a visit,
     * and any action on it still goes through the normal POST + CSRF path.
     *
     * The scanned value can be a whole `.../t/<token>` URL or the bare token;
     * `ScanService` does the parsing and, importantly, the clinic scoping. A
     * code from another clinic is refused with the same sentence as a code
     * that does not exist, so a scanner-holder learns nothing either way.
     */
    public function scan(): void
    {
        $clinicId = $this->guard();
        $code = Request::string('code');

        // A scanner in a busy lobby is a machine gun of GETs; a person typing
        // is not. Generous per minute, useless for enumeration.
        RateLimiter::enforce('scan:ip:' . Request::ip(), 90, 60);
        RateLimiter::enforce('scan:clinic:' . $clinicId, 400, 60);

        $result = (new ScanService())->resolve($clinicId, $code);
        if (!($result['ok'] ?? false)) {
            flash('error', (string) ($result['reason'] ?? 'That code was not recognised.'));
            $fallback = Request::string('queue_id') !== '' ? '?queue_id=' . Request::int('queue_id') : '';
            redirect('/queue' . $fallback);
            return;
        }

        // A scan is a lookup, not an action. Recorded so the desk can see the
        // difference between "the patient was called" and "somebody waved a
        // slip at the scanner", but nothing about the visit changes.
        (new AuditService())->log($clinicId, 'queue.scanned', 'queue_entry', (int) $result['entry_id'], [
            'how' => (string) $result['how'],
        ]);

        redirect('/queue/entry?id=' . (int) $result['entry_id']);
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
            // The steps this service requires. Empty for a clinic that has
            // configured none, which is the product's previous behaviour.
            'steps' => (new \App\Services\WorkflowService())->progress($clinicId, $entry),
        ]);
    }

    /**
     * Tick a workflow step on an entry. Kept separate from `apply()` because
     * a step is not a status: it records that part of the service happened
     * without moving the patient anywhere.
     */
    public function markStep(): void
    {
        $clinicId = $this->guard();
        $entryId = Request::int('entry_id');
        $stepId = Request::int('step_id');
        $undo = Request::string('undo') === '1';

        $workflows = new \App\Services\WorkflowService();
        try {
            if ($undo) {
                $workflows->undoStep($clinicId, $entryId, $stepId);
                $changed = true;
            } else {
                $changed = $workflows->markDone($clinicId, $entryId, $stepId, Auth::id());
            }
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/queue/entry?id=' . $entryId);
            return;
        }

        if ($changed) {
            (new AuditService())->log($clinicId, $undo ? 'queue.step_undone' : 'queue.step_done', 'queue_entry', $entryId, [
                'step_id' => $stepId,
            ]);
        }
        flash('success', $undo ? 'Step reopened.' : 'Step recorded.');
        redirect('/queue/entry?id=' . $entryId);
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

    /**
     * Announce a delay ("Dr Silva is 20 minutes behind").
     *
     * A delay is communication, not a state change: tokens keep flowing and
     * the queue is not paused. It feeds three surfaces — the waiting-room
     * screen, the patient's estimate, and the audit log.
     */
    public function announceDelay(): void
    {
        $clinicId = $this->guard();
        $queueId = Request::int('queue_id');
        $minutes = max(0, min(240, Request::int('delay_minutes')));
        $note = mb_substr(Request::string('delay_note'), 0, 180);

        $queue = Db::one('SELECT id, name FROM queues WHERE id = ? AND clinic_id = ?', [$queueId, $clinicId]);
        if (!$queue) {
            abort(404, 'Queue not found.');
            return;
        }

        Db::run(
            'UPDATE queues SET delay_minutes = ?, delay_note = ? WHERE id = ? AND clinic_id = ?',
            [$minutes, $note !== '' ? $note : null, $queueId, $clinicId]
        );
        (new AuditService())->log($clinicId, 'queue.delay_announced', 'queue', $queueId, [
            'minutes' => $minutes, 'note' => $note,
        ]);

        // The board shows the delay, but a patient who went for a walk is not
        // looking at the board. Message everyone still waiting. Idempotent per
        // delay length, and nothing at all when the delay is being cleared.
        $messaged = 0;
        try {
            $messaged = (new \App\Services\NotificationService())->notifyDelay($clinicId, $queueId, $minutes, $note);
        } catch (\Throwable $e) {
            // A messaging failure must never undo the announcement itself.
            \App\Core\Log::warn('queue.delay_notify_failed', ['queue_id' => $queueId, 'error' => $e->getMessage()]);
        }

        if ($minutes === 0) {
            flash('success', 'Delay cleared — estimates back to normal.');
        } else {
            flash('success', sprintf(
                '%s is now shown as %d minutes behind.%s',
                $queue['name'],
                $minutes,
                $messaged > 0
                    ? sprintf(' %d waiting patient%s told by message.', $messaged, $messaged === 1 ? '' : 's')
                    : ' No new messages: everyone waiting has already been told this length.'
            ));
        }
        back('/app');
    }

    /** Open / pause / close a session for today. */
    /**
     * The doctor's "I am starting now".
     *
     * Authorised narrowly: a doctor may start only their own session, while
     * reception and admins may start any session in their clinic — because
     * the common real case is a doctor phoning to say they have arrived and
     * the desk pressing it for them.
     */
    public function startSession(): void
    {
        $clinicId = $this->guard();
        $queueId = Request::int('queue_id');

        $queue = Db::one('SELECT * FROM queues WHERE id = ? AND clinic_id = ?', [$queueId, $clinicId]);
        if ($queue === null) {
            abort(404, 'That session does not exist.');
            return;
        }

        if (Auth::role() === Auth::ROLE_DOCTOR) {
            $own = (int) Db::scalar('SELECT doctor_id FROM users WHERE id = ?', [(int) Auth::id()]);
            if ($own === 0 || (int) $queue['doctor_id'] !== $own) {
                abort(403, 'You can only start your own session.');
                return;
            }
        }

        $result = (new QueueService())->startSession($clinicId, $queueId, (int) Auth::id());
        if ($result['ok']) {
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        back('/queue?queue_id=' . $queueId);
    }

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

        // Closing a session is not the same as pausing it. Pausing keeps every
        // waiting patient and stops new calls; closing ends the day. If people
        // are still waiting when the day is closed, they must be *resolved*,
        // not orphaned — a row left 'waiting' on a closed session is a patient
        // the clinic has quietly forgotten, and it corrupts tomorrow's board.
        // The operator is told how many, and must acknowledge.
        $stillWaiting = 0;
        if ($status === 'closed') {
            $stillWaiting = (int) Db::scalar(
                "SELECT COUNT(*) FROM queue_entries
                  WHERE clinic_id = ? AND queue_id = ? AND status IN ('waiting','called','on_hold')",
                [$clinicId, $queueId]
            );
            $acknowledged = Request::string('resolve') === 'acknowledge';
            if ($stillWaiting > 0 && !$acknowledged) {
                Session::flash('pending_close', [
                    'queue_id' => $queueId,
                    'queue_name' => (string) $queue['name'],
                    'waiting' => $stillWaiting,
                ]);
                flash('warn', sprintf(
                    '%d patient%s still in %s. Closing will mark them as not seen — confirm to continue, or call/cancel them first.',
                    $stillWaiting,
                    $stillWaiting === 1 ? '' : 's',
                    $queue['name']
                ));
                back('/app');
                return;
            }
        }

        Db::transaction(function () use ($clinicId, $queueId, $status, $queue, $stillWaiting): void {
            Db::run('UPDATE queues SET status = ? WHERE id = ? AND clinic_id = ?', [$status, $queueId, $clinicId]);

            if ($status === 'closed' && $stillWaiting > 0) {
                // The honest terminal state for someone who was never seen is
                // 'no_show' at the session level: they kept their place and the
                // clinic closed. Marking them 'cancelled' would blame them.
                $entries = Db::all(
                    "SELECT id, status, token_label FROM queue_entries
                      WHERE clinic_id = ? AND queue_id = ? AND status IN ('waiting','called','on_hold')",
                    [$clinicId, $queueId]
                );
                foreach ($entries as $entry) {
                    Db::run(
                        'UPDATE queue_entries SET status = ?, finished_at = ?, version = version + 1,
                                note = ? WHERE id = ? AND clinic_id = ?',
                        ['no_show', Db::now(), 'Session closed before this patient was seen', (int) $entry['id'], $clinicId]
                    );
                    Db::insert(
                        'INSERT INTO queue_events (clinic_id, queue_id, entry_id, actor_id, action, from_status, to_status, meta_json, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?)',
                        [
                            $clinicId, $queueId, (int) $entry['id'], Auth::id(),
                            'entry.session_closed', $entry['status'], 'no_show',
                            json_encode(['reason' => 'session closed']), Db::now(),
                        ]
                    );
                }
            }
        });

        (new AuditService())->log($clinicId, 'queue.session_' . $status, 'queue', $queueId, [
            'queue' => $queue['name'],
            'unseen_patients_closed' => $status === 'closed' ? $stillWaiting : 0,
        ]);

        if ($status === 'closed' && $stillWaiting > 0) {
            flash('warn', sprintf(
                'Session closed. %d patient%s marked as not seen and kept in the record.',
                $stillWaiting, $stillWaiting === 1 ? '' : 's'
            ));
        } else {
            flash('success', 'Session ' . $status . ' for ' . $queue['name'] . '.');
        }
        back('/app');
    }

    /** Create today's queues for every active doctor (idempotent). */
    private function openDefaultSession(int $clinicId): void
    {
        $clinic = Auth::clinic();
        $doctors = (new DoctorService())->all($clinicId);

        // The pattern, type and padding come from Queue settings, so a laboratory
        // is not forced to call its queue "OPD" and a pharmacy can issue its own
        // token series. Defaults reproduce the previous behaviour exactly.
        $pattern = (string) (Db::scalar(
            "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='queue.name_pattern'",
            [$clinicId]
        ) ?: 'OPD — {doctor}');
        $kind = (string) (Db::scalar(
            "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='queue.default_kind'",
            [$clinicId]
        ) ?: 'consultation');
        $prefixSetting = (string) (Db::scalar(
            "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='queue.default_prefix'",
            [$clinicId]
        ) ?? '');
        $padding = (int) (Db::scalar(
            "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='queue.token_padding'",
            [$clinicId]
        ) ?: 3);

        $counter = 0;
        foreach ($doctors as $doc) {
            $existing = Db::scalar(
                'SELECT id FROM queues WHERE clinic_id = ? AND doctor_id = ? AND session_date = ?',
                [$clinicId, $doc['id'], Db::today()]
            );
            if ($existing) {
                continue;
            }

            $counter++;
            $name = str_replace(
                ['{doctor}', '{n}'],
                [(string) $doc['name'], (string) $counter],
                $pattern
            );

            // An explicit clinic prefix wins; otherwise the doctor's initial, as
            // before. The prefix must be one letter group, not the whole name.
            $prefix = $prefixSetting !== ''
                ? $prefixSetting
                : substr(strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $doc['name']) ?: 'A'), 0, 1);

            Db::insert(
                "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
                 VALUES (?,?,?,?,'open',?,?,0,?,?)",
                [
                    $clinicId, $doc['id'], $name,
                    $kind, Db::today(), $prefix,
                    max(1, min(6, $padding)),
                    Db::now(),
                ]
            );
        }
    }
}
