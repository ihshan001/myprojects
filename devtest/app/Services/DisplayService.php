<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * The waiting-room payload, in one place.
 *
 * The screen is server-rendered on first paint and then refreshed by a poller.
 * When those two code paths were written separately the poller silently
 * overwrote the rendered markup with a different shape — so both now call this
 * method and cannot drift.
 *
 * Privacy: tokens, room names, doctor names and the doctor's own specialty.
 * Never a patient name, phone number or reason for visit. This payload is
 * served on an unauthenticated URL to a screen anyone in the waiting room can
 * photograph, so "up next" carries a token and its session — never a person.
 */
final class DisplayService
{
    /**
     * @param int|null $departmentId restricts the board to one waiting area
     */
    public function payload(int $clinicId, ?int $departmentId = null): array
    {
        $queues = Db::all(
            "SELECT q.*, d.name AS doctor_name, r.name AS room_name, s.name AS specialty
               FROM queues q
               LEFT JOIN doctors d ON d.id = q.doctor_id
               LEFT JOIN rooms r ON r.id = d.room_id
               LEFT JOIN specialties s ON s.id = d.specialty_id
              WHERE q.clinic_id = ? AND q.session_date = ? AND q.status <> 'closed'"
            . ($departmentId !== null ? ' AND q.department_id = ?' : ''),
            $departmentId !== null
                ? [$clinicId, Db::today(), (int) $departmentId]
                : [$clinicId, Db::today()]
        );

        // The header needs a name, not an id.
        $departmentName = $departmentId !== null
            ? Db::scalar('SELECT name FROM departments WHERE id = ? AND clinic_id = ?', [(int) $departmentId, $clinicId])
            : null;

        $stats = (new ReportService())->daySummary($clinicId);

        $serving = null;
        // A clinic runs several rooms at once. Collapsing them to one token
        // (which `now_serving` alone does) means a patient watching the TV
        // never sees their own room called. `serving` carries every room;
        // `now_serving` is kept as the single headline for older consumers.
        $servingAll = [];
        $upcoming = [];
        $delays = [];
        // One card per open session: the wall shows every doctor at once, each
        // with its own token series, so a patient can find their own column.
        $roomCards = [];
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
                "SELECT e.token_label, e.status, d.name AS doctor_name, r.name AS room_name,
                        s.name AS specialty
                   FROM queue_entries e
                   LEFT JOIN doctors d ON d.id = (SELECT doctor_id FROM queues WHERE id = e.queue_id)
                   LEFT JOIN rooms r ON r.id = d.room_id
                   LEFT JOIN specialties s ON s.id = d.specialty_id
                  WHERE e.queue_id = ? AND e.status IN ('called','in_consultation')
                  ORDER BY CASE e.status WHEN 'called' THEN 0 ELSE 1 END, e.id DESC LIMIT 1",
                [$q['id']]
            );
            if ($now) {
                $row = [
                    'token' => $now['token_label'],
                    'room' => $now['room_name'] ?? $q['name'],
                    'doctor' => $now['doctor_name'],
                    'queue' => (string) $q['name'],
                    // 'called' is the one that must flash: the patient has to
                    // walk. 'in_consultation' is already behind the door.
                    'status' => (string) $now['status'],
                    'specialty' => $now['specialty'] ?? null,
                ];
                $servingAll[] = $row;
                if ($serving === null || $now['status'] === 'called') {
                    $serving = $row;
                }
            }
            $waiting = Db::all(
                "SELECT e.token_label AS token, r.name AS room_name, d.name AS doctor_name
                   FROM queue_entries e
                   LEFT JOIN queues q ON q.id = e.queue_id
                   LEFT JOIN doctors d ON d.id = q.doctor_id
                   LEFT JOIN rooms r ON r.id = d.room_id
                  WHERE e.queue_id = ? AND e.status = 'waiting'
                  ORDER BY e.priority DESC, e.sort_weight, e.id LIMIT 5",
                [$q['id']]
            );
            $roomCards[] = [
                'room'      => $now['room_name'] ?? ($q['room_name'] ?? (string) $q['name']),
                'doctor'    => $now['doctor_name'] ?? ($q['doctor_name'] ?? null),
                'specialty' => $now['specialty'] ?? ($q['specialty'] ?? null),
                'queue'     => (string) $q['name'],
                'token'     => $now['token_label'] ?? null,
                'status'    => $now['status'] ?? null,
                'paused'    => ($q['status'] ?? '') === 'paused',
                'up_next'   => array_map(
                    static fn (array $w): array => ['token' => (string) $w['token'], 'queue' => (string) $q['name']],
                    $waiting
                ),
                'waiting'   => (int) Db::scalar(
                    "SELECT COUNT(*) FROM queue_entries WHERE queue_id = ? AND status = 'waiting'",
                    [$q['id']]
                ),
            ];
            foreach ($waiting as $w) {
                // Every queue restarts its own A-series, so three rooms can
                // all be waiting on "A002". Without the doctor the list is
                // ambiguous and patients walk to the wrong door.
                $upcoming[] = [
                    'token' => $w['token'],
                    'room' => $w['room_name'] ?? $q['name'],
                    'doctor' => $w['doctor_name'],
                    'queue' => (string) $q['name'],
                ];
            }
        }

        return [
            'ok' => true,
            'department' => $departmentName,
            'now_serving' => $serving,
            'serving' => $servingAll,
            'rooms' => $roomCards,
            'upcoming' => array_slice($upcoming, 0, 8),
            'delays' => $delays,
            'paused' => array_values(array_map(
                static fn (array $q): string => (string) $q['name'],
                array_filter($queues, static fn (array $q): bool => ($q['status'] ?? '') === 'paused')
            )),
            'stats' => [
                // The count must match the board above it. A department screen
                // showing four tokens while the footer says nine is the kind of
                // inconsistency that makes staff stop trusting the screen.
                'waiting' => $departmentId !== null
                    ? (int) Db::scalar(
                        "SELECT COUNT(*) FROM queue_entries e JOIN queues q ON q.id = e.queue_id
                          WHERE e.clinic_id = ? AND e.status = 'waiting' AND q.session_date = ?
                            AND q.department_id = ?",
                        [$clinicId, Db::today(), (int) $departmentId]
                    )
                    : (int) Db::scalar(
                        "SELECT COUNT(*) FROM queue_entries e JOIN queues q ON q.id = e.queue_id
                          WHERE e.clinic_id = ? AND e.status = 'waiting' AND q.session_date = ?",
                        [$clinicId, Db::today()]
                    ),
                'completed' => $departmentId !== null
                    ? (int) Db::scalar(
                        "SELECT COUNT(*) FROM queue_entries e JOIN queues q ON q.id = e.queue_id
                          WHERE e.clinic_id = ? AND e.status = 'completed' AND q.session_date = ?
                            AND q.department_id = ?",
                        [$clinicId, Db::today(), (int) $departmentId]
                    )
                    : (int) ($stats['completed'] ?? 0),
            ],
            'server_time' => date('h:i A'),
        ];
    }
}
