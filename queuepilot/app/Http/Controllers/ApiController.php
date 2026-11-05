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
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!\App\Core\Csrf::check($payload['_token'] ?? $headerToken)) {
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
        if (!$clinicId) {
            json(['ok' => false, 'error' => 'not_found'], 404);
            return;
        }
        $clinicId = (int) $clinicId;

        $queues = Db::all(
            "SELECT * FROM queues WHERE clinic_id = ? AND session_date = ? AND status <> 'closed'",
            [$clinicId, Db::today()]
        );

        $serving = null;
        $upcoming = [];
        $delays = [];
        foreach ($queues as $q) {
            // An announced delay is public information and belongs on the TV.
            if ((int) ($q['delay_minutes'] ?? 0) > 0) {
                $delays[] = [
                    'queue' => (string) $q['name'],
                    'minutes' => (int) $q['delay_minutes'],
                    'note' => (string) ($q['delay_note'] ?? ''),
                ];
            }
            $now = Db::one(
                "SELECT e.token_label, e.status, d.name AS doctor_name, r.name AS room_name
                   FROM queue_entries e
                   LEFT JOIN doctors d ON d.id = (SELECT doctor_id FROM queues WHERE id = e.queue_id)
                   LEFT JOIN rooms r ON r.id = d.room_id
                  WHERE e.queue_id = ? AND e.status IN ('called','in_consultation')
                  ORDER BY CASE e.status WHEN 'called' THEN 0 ELSE 1 END, e.id DESC LIMIT 1",
                [$q['id']]
            );
            if ($now && ($serving === null || $now['status'] === 'called')) {
                $serving = [
                    'token' => $now['token_label'],
                    'room' => $now['room_name'] ?? $q['name'],
                    'doctor' => $now['doctor_name'],
                ];
            }
            $waiting = Db::all(
                "SELECT e.token_label AS token, r.name AS room_name
                   FROM queue_entries e
                   LEFT JOIN queues q ON q.id = e.queue_id
                   LEFT JOIN doctors d ON d.id = q.doctor_id
                   LEFT JOIN rooms r ON r.id = d.room_id
                  WHERE e.queue_id = ? AND e.status = 'waiting'
                  ORDER BY e.priority DESC, e.sort_weight, e.id LIMIT 5",
                [$q['id']]
            );
            foreach ($waiting as $w) {
                $upcoming[] = ['token' => $w['token'], 'room' => $w['room_name'] ?? $q['name']];
            }
        }

        $stats = (new \App\Services\ReportService())->daySummary($clinicId);

        json([
            'ok' => true,
            'now_serving' => $serving,
            'upcoming' => array_slice($upcoming, 0, 6),
            'delays' => $delays,
            'paused' => array_values(array_map(
                static fn (array $q): string => (string) $q['name'],
                array_filter($queues, static fn (array $q): bool => ($q['status'] ?? '') === 'paused')
            )),
            'stats' => [
                'waiting' => (int) Db::scalar(
                    "SELECT COUNT(*) FROM queue_entries e JOIN queues q ON q.id = e.queue_id
                      WHERE e.clinic_id = ? AND e.status = 'waiting' AND q.session_date = ?",
                    [$clinicId, Db::today()]
                ),
                'completed' => (int) ($stats['completed'] ?? 0),
            ],
            'server_time' => date('h:i A'),
        ]);
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
