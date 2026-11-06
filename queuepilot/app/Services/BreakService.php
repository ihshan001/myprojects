<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Breaks and pauses, as facts rather than free text.
 *
 * A pause used to be a status change with a note on the queue row. That was
 * enough to stop the board calling the next patient and not enough to answer
 * the question a clinic manager actually asks: how much of the day went on
 * pauses, and were they the ones we planned?
 *
 * Every completed pause lands in `queue_breaks` with both ends, a category and
 * a duration measured by the clock rather than reconstructed from timestamps at
 * report time. Planned breaks live in `scheduled_breaks` and never appear in the
 * actual-break history, because a lunch that never happened is not an event.
 */
final class BreakService
{
    public const KINDS = [
        'lunch'       => 'Lunch',
        'tea'         => 'Tea break',
        'prayer'      => 'Prayer',
        'unscheduled' => 'Unscheduled pause',
        'emergency'   => 'Emergency',
        'equipment'   => 'Equipment problem',
        'end_of_day'  => 'End of day',
        'unspecified' => 'Unspecified',
    ];

    public function isValidKind(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }

    /**
     * Start a break on a queue.
     *
     * Idempotent in the sense that matters: if a break is already open on this
     * queue, the second call does not open a second one. Two receptionists
     * pressing "pause" a second apart must not produce overlapping breaks, or
     * the day's paused minutes would be counted twice.
     */
    public function start(int $clinicId, int $queueId, string $kind, string $reason, ?int $userId = null): array
    {
        if (!$this->isValidKind($kind)) {
            return ['ok' => false, 'error' => 'That is not a break type.'];
        }

        $queue = Db::one('SELECT id, name, status, break_started_at FROM queues WHERE id = ? AND clinic_id = ?', [$queueId, $clinicId]);
        if (!$queue) {
            return ['ok' => false, 'error' => 'Queue not found for this clinic.'];
        }
        if ($queue['break_started_at'] !== null) {
            return ['ok' => false, 'error' => sprintf(
                '%s already has a break open since %s. End it before starting another.',
                $queue['name'],
                (string) $queue['break_started_at']
            )];
        }

        $now = Db::now();
        $breakId = Db::insert(
            'INSERT INTO queue_breaks (clinic_id, queue_id, kind, reason, started_at, created_by, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$clinicId, $queueId, $kind, $reason !== '' ? $reason : null, $now, $userId, $now]
        );
        Db::run(
            "UPDATE queues SET status = 'paused', break_started_at = ?, break_kind = ? WHERE id = ? AND clinic_id = ?",
            [$now, $kind, $queueId, $clinicId]
        );

        (new AuditService())->log($clinicId, 'queue.break_started', 'queue', $queueId, [
            'kind' => $kind, 'reason' => $reason,
        ]);

        // Tell staff why the board stopped moving, so the pause is not read as a fault.
        (new StaffNotificationService())->operational(
            $clinicId,
            $queue['name'] . ' paused — ' . (self::KINDS[$kind] ?? $kind),
            $reason !== '' ? $reason : 'No reason given.',
            'info',
            '/app'
        );

        return ['ok' => true, 'id' => $breakId, 'started_at' => $now];
    }

    /** End the open break, recording its measured duration. */
    public function end(int $clinicId, int $queueId): array
    {
        $queue = Db::one('SELECT id, name, break_started_at FROM queues WHERE id = ? AND clinic_id = ?', [$queueId, $clinicId]);
        if (!$queue) {
            return ['ok' => false, 'error' => 'Queue not found for this clinic.'];
        }
        if ($queue['break_started_at'] === null) {
            return ['ok' => false, 'error' => 'That queue is not on a break.'];
        }

        $now = Db::now();
        $started = (string) $queue['break_started_at'];
        $minutes = max(0, (int) round((strtotime($now) - strtotime($started)) / 60));

        $open = Db::one(
            'SELECT id FROM queue_breaks WHERE queue_id = ? AND clinic_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1',
            [$queueId, $clinicId]
        );
        if ($open) {
            Db::run(
                'UPDATE queue_breaks SET ended_at = ?, minutes = ? WHERE id = ?',
                [$now, $minutes, (int) $open['id']]
            );
        }

        Db::run(
            "UPDATE queues SET status = 'open', break_started_at = NULL, break_kind = NULL WHERE id = ? AND clinic_id = ?",
            [$queueId, $clinicId]
        );
        (new AuditService())->log($clinicId, 'queue.break_ended', 'queue', $queueId, ['minutes' => $minutes]);

        return ['ok' => true, 'minutes' => $minutes];
    }

    /** Today's breaks for a clinic, newest first. */
    public function today(int $clinicId, ?string $date = null): array
    {
        $date = $date ?? Db::today();
        return Db::all(
            "SELECT b.*, q.name AS queue_name
               FROM queue_breaks b JOIN queues q ON q.id = b.queue_id
              WHERE b.clinic_id = ? AND substr(b.started_at, 1, 10) = ?
              ORDER BY b.started_at DESC",
            [$clinicId, $date]
        );
    }

    /**
     * Totals per kind for a range. Only counted for breaks that ended, because
     * an open break has no duration yet and counting it as zero would quietly
     * understate the day.
     */
    public function summary(int $clinicId, string $from, string $to): array
    {
        $rows = Db::all(
            "SELECT kind, COUNT(*) AS breaks, COALESCE(SUM(minutes), 0) AS minutes, COALESCE(AVG(minutes), 0) AS avg_minutes
               FROM queue_breaks
              WHERE clinic_id = ? AND ended_at IS NOT NULL
                AND substr(started_at, 1, 10) BETWEEN ? AND ?
              GROUP BY kind
              ORDER BY minutes DESC",
            [$clinicId, $from, $to]
        );
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r['minutes'];
        }
        return ['rows' => $rows, 'total_minutes' => $total];
    }

    /** Planned breaks for a weekday, so the board can say "back at 14:00". */
    public function scheduledFor(int $clinicId, int $weekday, ?int $doctorId = null): array
    {
        return Db::all(
            'SELECT s.*, d.name AS doctor_name
               FROM scheduled_breaks s LEFT JOIN doctors d ON d.id = s.doctor_id
              WHERE s.clinic_id = ? AND s.weekday = ? AND s.is_active = 1
                AND (s.doctor_id IS NULL OR s.doctor_id = ?)
              ORDER BY s.starts_at',
            [$clinicId, $weekday, $doctorId ?? 0]
        );
    }

    public function addScheduled(int $clinicId, ?int $doctorId, int $weekday, string $from, string $to, string $kind): array
    {
        if ($weekday < 0 || $weekday > 6) {
            return ['ok' => false, 'error' => 'Pick a day of the week.'];
        }
        if (!$this->isValidKind($kind)) {
            return ['ok' => false, 'error' => 'That is not a break type.'];
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to)) {
            return ['ok' => false, 'error' => 'Times need to look like 13:00.'];
        }
        if ($to <= $from) {
            return ['ok' => false, 'error' => 'The break has to end after it starts.'];
        }

        $id = Db::insert(
            'INSERT INTO scheduled_breaks (clinic_id, doctor_id, weekday, starts_at, ends_at, kind, is_active, created_at)
             VALUES (?,?,?,?,?,?,1,?)',
            [$clinicId, $doctorId, $weekday, $from, $to, $kind, Db::now()]
        );
        (new AuditService())->log($clinicId, 'break.scheduled', 'scheduled_break', $id, [
            'weekday' => $weekday, 'from' => $from, 'to' => $to, 'kind' => $kind,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    public function removeScheduled(int $clinicId, int $id): bool
    {
        $row = Db::one('SELECT id FROM scheduled_breaks WHERE id = ? AND clinic_id = ?', [$id, $clinicId]);
        if (!$row) {
            return false;
        }
        Db::run('DELETE FROM scheduled_breaks WHERE id = ? AND clinic_id = ?', [$id, $clinicId]);
        (new AuditService())->log($clinicId, 'break.unscheduled', 'scheduled_break', $id, []);
        return true;
    }
}
