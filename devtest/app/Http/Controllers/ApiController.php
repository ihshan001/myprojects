<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Env;
use App\Core\Log;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Domain\Queue\QueueRepository;
use App\Domain\Queue\QueueService;
use App\Domain\Queue\QueueStateMachine;

/**
 * JSON endpoints used by the live surfaces. Everything is tenant-scoped via
 * the session; the client never sends a clinic id.
 */
final class ApiController
{
    /** CSRF for JSON endpoints, which carry the token in a header. */
    private function checkApiCsrf(): void
    {
        $payload = Request::payload();
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!\App\Core\Csrf::check($payload['_token'] ?? $headerToken)) {
            json(['ok' => false, 'error' => 'csrf_expired', 'message' => 'Your session expired. Reload the page.'], 419);
        }
    }

    public function board(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            json(['ok' => false, 'error' => 'unauthenticated'], 401);
            return;
        }

        $queueId = Request::int('queue_id');
        if ($queueId === 0) {
            // whole-clinic view (reception dashboard)
            $this->clinicOverview($clinicId);
            return;
        }

        $repo = new QueueRepository();
        $service = new QueueService();
        $queue = $repo->queue($clinicId, $queueId);
        if (!$queue) {
            json(['ok' => false, 'error' => 'not_found'], 404);
            return;
        }

        $rows = $repo->board($clinicId, $queueId);
        $position = 0;
        $out = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'waiting') {
                $position++;
            }
            $meta = QueueStateMachine::meta((string) $row['status']);
            $actions = Auth::can('queue.operate') ? QueueStateMachine::actions((string) $row['status']) : [];
            // priority is a clinical action: only staff with the ability get it
            if (!Auth::can('queue.priority')) {
                $actions = array_values(array_diff($actions, ['priority']));
            }
            $out[] = [
                'id' => (int) $row['id'],
                'version' => (int) $row['version'],
                'token' => $row['token_label'],
                'status' => $row['status'],
                'status_label' => $meta['label'],
                'badge_class' => 'badge-' . $meta['tone'],
                'position' => $row['status'] === 'waiting' ? $position : 0,
                'priority' => (int) $row['priority'],
                'call_count' => (int) $row['call_count'],
                'full_name' => $row['full_name'],
                'patient_code' => $row['patient_code'],
                'joined' => date('g:i A', strtotime((string) $row['joined_at'])),
                'actions' => $actions,
            ];
        }

        $now = $service->nowServing($clinicId, $queueId);
        $summary = (new \App\Services\ReportService())->daySummary($clinicId);

        json([
            'ok' => true,
            'queue' => ['id' => (int) $queue['id'], 'name' => $queue['name'], 'status' => $queue['status']],
            'rows' => $out,
            'now_serving' => $now ? [
                'token_label' => $now['token_label'],
                'full_name' => $now['full_name'],
                'status' => $now['status'],
            ] : null,
            'stats' => [
                'waiting' => (int) Db::scalar(
                    "SELECT COUNT(*) FROM queue_entries WHERE clinic_id = ? AND queue_id = ? AND status = 'waiting'",
                    [$clinicId, $queueId]
                ),
                'in_room' => (int) Db::scalar(
                    "SELECT COUNT(*) FROM queue_entries WHERE clinic_id = ? AND queue_id = ? AND status = 'in_consultation'",
                    [$clinicId, $queueId]
                ),
                'completed' => (int) ($summary['completed'] ?? 0),
                'total' => (int) ($summary['total'] ?? 0),
            ],
            'server_time' => date('h:i:s A'),
        ]);
    }

    private function clinicOverview(int $clinicId): void
    {
        $repo = new QueueRepository();
        $service = new QueueService();
        $board = $repo->clinicBoard($clinicId);

        $rows = [];
        foreach ($board as $r) {
            $meta = QueueStateMachine::meta((string) $r['status']);
            $rows[] = [
                'id' => (int) $r['id'],
                'version' => (int) $r['version'],
                'token' => $r['token_label'],
                'status' => $r['status'],
                'status_label' => $meta['label'],
                'badge_class' => 'badge-' . $meta['tone'],
                'position' => 0,
                'priority' => (int) $r['priority'],
                'call_count' => (int) $r['call_count'],
                'full_name' => $r['full_name'],
                'patient_code' => $r['patient_code'],
                'queue' => $r['queue_name'],
                'joined' => date('g:i A', strtotime((string) $r['joined_at'])),
                'actions' => [],
            ];
        }
        json(['ok' => true, 'rows' => $rows, 'server_time' => date('h:i:s A')]);
    }

    public function action(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            json(['ok' => false, 'error' => 'unauthenticated'], 401);
            return;
        }

        $payload = Request::payload();
        // JSON requests carry the CSRF token in a header rather than a field
        if (!\App\Core\Csrf::check($payload['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            json(['ok' => false, 'error' => 'csrf_expired', 'message' => 'Your session expired. Reload the page.'], 419);
            return;
        }

        $action = (string) ($payload['action'] ?? '');
        $entryId = (int) ($payload['entry_id'] ?? 0);
        $version = isset($payload['version']) ? (int) $payload['version'] : null;
        $reason = isset($payload['reason']) && $payload['reason'] !== null
            ? mb_substr((string) $payload['reason'], 0, 180) : null;
        $toQueueId = isset($payload['to_queue_id']) && $payload['to_queue_id'] !== null
            ? (int) $payload['to_queue_id'] : null;

        if ($entryId <= 0 || $action === '') {
            json(['ok' => false, 'error' => 'bad_request', 'message' => 'Missing entry or action.'], 422);
            return;
        }

        // Per-action ability gate. This applies to EVERY role, not just
        // doctors: hiding a button in the UI is not authorisation, and a
        // receptionist with curl must hit the same wall as a receptionist
        // with a mouse.
        $ability = match ($action) {
            'call', 'recall' => 'queue.call',
            'start', 'complete' => 'queue.consult',
            'priority' => 'queue.priority',
            default => 'queue.operate',
        };
        if (!Auth::can('queue.operate') || !Auth::can($ability)) {
            json([
                'ok' => false,
                'error' => 'forbidden',
                'message' => 'Your role cannot perform that action.',
            ], 403);
            return;
        }

        $service = new QueueService();
        $result = $service->apply($clinicId, $entryId, $action, [
            'version' => $version,
            'reason' => $reason,
            'to_queue_id' => $toQueueId,
        ]);

        $status = $result['ok']
            ? 200
            : (!empty($result['forbidden']) ? 403
                : (!empty($result['stale']) ? 409 : 422));
        json($result, $status);
    }

    /**
     * Set aside a block of ticket numbers for a desk that may go offline.
     *
     * The point of doing this on the server, while the connection is up, is
     * that numbering stays the server's job. A browser that invents its own
     * numbers produces two patients holding A031, and the clinic finds out at
     * the door.
     */
    public function reserveOfflineBlock(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            json(['ok' => false, 'error' => 'unauthenticated'], 401);
            return;
        }
        $this->checkApiCsrf();
        if (!Auth::can('queue.issue')) {
            json(['ok' => false, 'error' => 'forbidden', 'message' => 'Your role cannot issue tokens.'], 403);
            return;
        }

        $payload = Request::payload();
        $queueId = (int) ($payload['queue_id'] ?? 0);
        $size = (int) ($payload['size'] ?? 10);
        if ($queueId <= 0) {
            json(['ok' => false, 'error' => 'bad_request', 'message' => 'Choose a queue first.'], 422);
            return;
        }

        $result = (new \App\Services\OfflineService())->reserveBlock($clinicId, $queueId, $size, Auth::id());
        json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Replay the reservations a desk made while it was offline.
     *
     * Idempotent: the same payload twice produces one patient and one ticket,
     * because each reservation id is unique in the database. That is also why
     * the browser may retry as often as it likes.
     */
    public function reconcileOffline(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            json(['ok' => false, 'error' => 'unauthenticated'], 401);
            return;
        }
        $this->checkApiCsrf();
        if (!Auth::can('queue.issue')) {
            json(['ok' => false, 'error' => 'forbidden', 'message' => 'Your role cannot issue tokens.'], 403);
            return;
        }

        $payload = Request::payload();
        $items = $payload['items'] ?? [];
        if (!is_array($items)) {
            json(['ok' => false, 'error' => 'bad_request', 'message' => 'Nothing to sync.'], 422);
            return;
        }
        if (count($items) > 200) {
            // A backlog larger than this is a morning of paper, not a browser
            // queue; refusing beats a minute-long request that times out and
            // gets retried forever.
            json(['ok' => false, 'error' => 'too_many', 'message' => 'Sync at most 200 reservations at a time.'], 422);
            return;
        }

        json((new \App\Services\OfflineService())->reconcile($clinicId, $items));
    }

    /** Public patient tracking. Rate-limited, minimal fields, no PII. */
    public function track(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
            json(['ok' => false, 'error' => 'invalid'], 404);
            return;
        }

        // Durable per-IP throttle. The previous version counted in $_SESSION,
        // which a client resets by dropping its cookie — i.e. it limited
        // nobody who was actually trying. The bucket is the IP, never the
        // token, so a shared clinic Wi-Fi is not punished for one phone.
        RateLimiter::enforce(
            'track:ip:' . Request::ip(),
            (int) Env::get('security.track_token_per_min', 30),
            60
        );

        $service = new QueueService();
        $entry = (new QueueRepository())->entryByToken($token);

        if (!$entry) {
            json(['ok' => false, 'error' => 'not_found', 'message' => 'This tracking link is not valid.'], 404);
            return;
        }
        if ($entry['track_expires_at'] !== null && strtotime((string) $entry['track_expires_at']) < time()) {
            json(['ok' => false, 'error' => 'expired', 'message' => 'This tracking link has expired. Please ask the clinic.'], 410);
            return;
        }

        $snapshot = $service->publicSnapshot($entry);
        $snapshot['ok'] = true;
        $snapshot['status_message'] = match ((string) $entry['status']) {
            'waiting' => 'You are in the queue. Please stay nearby.',
            'called' => 'It is your turn — go to ' . ($entry['room_name'] ?? 'the room') . ' now.',
            'in_consultation' => 'You are with the doctor.',
            'completed' => 'Your visit is complete.',
            'skipped' => 'You were marked absent. Please speak to reception.',
            'no_show' => 'You were marked as not present.',
            'cancelled' => 'This queue entry was cancelled.',
            'transferred' => 'You have been transferred to another service.',
            default => '',
        };
        json($snapshot);
    }

    /** Waiting-room display: read-only, short-lived token, no names. */
    /**
     * Which department, if any, this display key is scoped to.
     *
     * Department keys live in `settings` under `display.department.<id>`, one
     * row each, so rotating one screen does not disturb the others.
     */
    private function departmentForKey(int $clinicId, string $key): ?int
    {
        $rows = Db::all(
            "SELECT key_name, value FROM settings
              WHERE scope = 'clinic' AND owner_id = ? AND key_name LIKE 'display.department.%'",
            [$clinicId]
        );
        foreach ($rows as $row) {
            if (hash_equals((string) $row['value'], $key)) {
                return (int) substr((string) $row['key_name'], strlen('display.department.'));
            }
        }
        return null;
    }

    public function display(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{12,64}$/', $key)) {
            json(['ok' => false, 'error' => 'invalid'], 404);
            return;
        }

        // The display key is a bearer credential on an unauthenticated URL:
        // anyone who photographs the lobby screen has it. Two limits apply —
        // a per-key ceiling that stops enumeration and a per-IP ceiling that
        // stops one broken TV from spinning the database.
        $perMin = (int) Env::get('security.display_key_per_min', 90);
        RateLimiter::enforce('display:key:' . $key, $perMin, 60);
        RateLimiter::enforce('display:ip:' . Request::ip(), $perMin * 2, 60);

        $clinicId = Db::scalar(
            "SELECT owner_id FROM settings WHERE scope='clinic' AND key_name='display_token' AND value = ?",
            [$key]
        );

        // A department display key is a token of its own rather than the clinic
        // key plus a parameter. Try it when the clinic key misses, so a screen
        // issued for one waiting area keeps working.
        $scopedDepartment = null;
        if (!$clinicId) {
            $row = Db::one(
                "SELECT owner_id, key_name FROM settings
                  WHERE scope='clinic' AND key_name LIKE 'display.department.%' AND value = ?",
                [$key]
            );
            if ($row) {
                $clinicId = (int) $row['owner_id'];
                $scopedDepartment = (int) substr((string) $row['key_name'], strlen('display.department.'));
            }
        }
        if (!$clinicId) {
            json(['ok' => false, 'error' => 'not_found'], 404);
            return;
        }
        $clinicId = (int) $clinicId;

        // A display may be scoped to one department, which is what makes a
        // second TV in a separate waiting area show the right board instead of
        // the whole clinic.
        //
        // The scope is looked up from the key itself: a department key is its
        // own unguessable token stored alongside the clinic key. Reading the
        // scope from the query string would let anybody holding one screen's
        // URL display another department's patients.
        if ($scopedDepartment === null) {
            $scopedDepartment = $this->departmentForKey($clinicId, $key);
        }

        $payload = (new \App\Services\DisplayService())->payload($clinicId, $scopedDepartment);
        json($payload);
    }

    /**
     * Patient lookup for the token desk.
     *
     * The desk page used to require a full form submit to search, which cost
     * a page load per keystroke and made "four keystrokes to a token" a lie.
     * This returns the same rows the full page would have, as JSON, so the
     * search can run as the receptionist types.
     *
     * Deliberately narrow: name, phone and code only. It is a picker, not a
     * patient record view, so it does not return notes or visit history.
     */
    public function patientSearch(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            json(['ok' => false, 'error' => 'unauthenticated'], 401);
            return;
        }
        if (!Auth::can('patient.manage')) {
            json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }

        $term = trim((string) Request::string('q'));
        // Two characters is the floor: a single character matches most of the
        // book and tells the desk nothing.
        if (mb_strlen($term) < 2) {
            json(['ok' => true, 'patients' => []]);
            return;
        }

        $out = [];
        foreach ((new \App\Services\PatientService())->search($clinicId, $term, 8) as $p) {
            $out[] = [
                'id' => (int) $p['id'],
                'name' => (string) $p['full_name'],
                'phone' => (string) ($p['phone'] ?? ''),
                'code' => (string) ($p['code'] ?? ''),
                'birth_year' => $p['birth_year'] !== null ? (int) $p['birth_year'] : null,
                // Whether this patient is ALREADY in a queue today. Issuing a
                // second token is refused with an error AFTER the desk has
                // filled the form in; saying so in the result list saves the
                // dead-end. Carries the token so the row can say which one.
                'active' => $this->activeEntry($clinicId, (int) $p['id']),
            ];
        }

        json(['ok' => true, 'patients' => $out]);
    }

    /**
     * The live token this patient already holds today, if any.
     *
     * Same status list as QueueService's pre-issue check, so the picker and
     * the desk cannot disagree about whether someone is already queued.
     *
     * @return array{token: string, queue: string}|null
     */
    private function activeEntry(int $clinicId, int $patientId): ?array
    {
        $row = Db::one(
            "SELECT e.token_label, q.name AS queue_name
               FROM queue_entries e
               JOIN queues q ON q.id = e.queue_id
              WHERE e.clinic_id = ? AND e.patient_id = ? AND q.session_date = ?
                AND e.status IN ('waiting','called','in_consultation','on_hold')
              LIMIT 1",
            [$clinicId, $patientId, Db::today()]
        );

        if (!$row) {
            return null;
        }

        return ['token' => (string) $row['token_label'], 'queue' => (string) $row['queue_name']];
    }

    public function summary(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            json(['ok' => false, 'error' => 'unauthenticated'], 401);
            return;
        }
        // Today's open queues, for the transfer picker. Scoped by clinic id
        // like every other read — the front end never supplies a clinic.
        $queues = [];
        foreach ((new QueueRepository())->liveQueues($clinicId) as $q) {
            if (($q['status'] ?? 'open') !== 'open') {
                continue;
            }
            $queues[] = [
                'id' => (int) $q['id'],
                'name' => (string) $q['name'],
                'doctor' => (string) ($q['doctor_name'] ?? ''),
                'waiting' => (int) ($q['waiting_count'] ?? 0),
            ];
        }

        json([
            'ok' => true,
            'summary' => (new \App\Services\ReportService())->daySummary($clinicId),
            'notifications' => (new \App\Services\NotificationService())->stats($clinicId),
            'queues' => $queues,
        ]);
    }
}
