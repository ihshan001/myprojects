<?php
declare(strict_types=1);

namespace App\Domain\Queue;

use App\Core\Db;

/**
 * Tenant-scoped data access for queues and entries.
 *
 * Every method takes $clinicId as its first argument and injects it into the
 * WHERE clause. There is no method that returns rows without a clinic scope,
 * which makes cross-tenant leakage a compile-time-ish mistake rather than a
 * runtime one.
 */
final class QueueRepository
{
    /**
     * Today's queues.
     *
     * Pass a doctor id to scope the result to that clinician's own queues. A
     * doctor must not see the names of another doctor's patients, so the
     * dashboard passes their id; reception passes null and sees the clinic.
     */
    public function liveQueues(int $clinicId, ?string $date = null, ?int $doctorId = null): array
    {
        $date ??= Db::today();
        $params = [$clinicId, $date];
        $scope = '';
        if ($doctorId !== null) {
            $scope = ' AND q.doctor_id = ?';
            $params[] = $doctorId;
        }

        return Db::all(
            "SELECT q.*, d.name AS doctor_name, d.avg_consult_minutes, s.name AS specialty,
                    r.name AS room_name,
                    (SELECT COUNT(*) FROM queue_entries e
                      WHERE e.queue_id = q.id AND e.status = 'waiting') AS waiting_count,
                    (SELECT COUNT(*) FROM queue_entries e
                      WHERE e.queue_id = q.id AND e.status IN ('called','in_consultation')) AS active_count
               FROM queues q
               LEFT JOIN doctors d ON d.id = q.doctor_id
               LEFT JOIN specialties s ON s.id = d.specialty_id
               LEFT JOIN rooms r ON r.id = d.room_id
              WHERE q.clinic_id = ? AND q.session_date = ?{$scope}
              ORDER BY q.status = 'open' DESC, q.id",
            $params
        );
    }

    public function queue(int $clinicId, int $queueId): ?array
    {
        return Db::one(
            "SELECT q.*, d.name AS doctor_name, d.avg_consult_minutes, d.consult_fee,
                    s.name AS specialty, r.name AS room_name
               FROM queues q
               LEFT JOIN doctors d ON d.id = q.doctor_id
               LEFT JOIN specialties s ON s.id = d.specialty_id
               LEFT JOIN rooms r ON r.id = d.room_id
              WHERE q.id = ? AND q.clinic_id = ?",
            [$queueId, $clinicId]
        );
    }

    public function queueForDoctor(int $clinicId, int $doctorId, ?string $date = null): ?array
    {
        $date ??= Db::today();
        return Db::one(
            "SELECT * FROM queues WHERE clinic_id = ? AND doctor_id = ? AND session_date = ?
              ORDER BY status = 'closed', id DESC",
            [$clinicId, $doctorId, $date]
        );
    }

    /** Live board for one queue, ordered by clinical priority then wait weight. */
    public function board(int $clinicId, int $queueId): array
    {
        return Db::all(
            "SELECT e.*, p.full_name, p.phone, p.gender, p.code AS patient_code
               FROM queue_entries e
               JOIN patients p ON p.id = e.patient_id
              WHERE e.clinic_id = ? AND e.queue_id = ?
                AND e.status IN ('waiting','called','in_consultation','skipped')
              ORDER BY CASE e.status WHEN 'in_consultation' THEN 0 WHEN 'called' THEN 1 ELSE 2 END,
                       e.priority DESC, e.sort_weight ASC, e.id ASC",
            [$clinicId, $queueId]
        );
    }

    /** The whole clinic's live work in one query (reception overview). */
    public function clinicBoard(int $clinicId): array
    {
        return Db::all(
            "SELECT e.*, p.full_name, p.code AS patient_code, q.name AS queue_name,
                    q.doctor_id, d.name AS doctor_name
               FROM queue_entries e
               JOIN patients p ON p.id = e.patient_id
               JOIN queues q ON q.id = e.queue_id
               LEFT JOIN doctors d ON d.id = q.doctor_id
              WHERE e.clinic_id = ? AND e.status IN ('waiting','called','in_consultation','skipped')
              ORDER BY q.id, e.priority DESC, e.sort_weight ASC, e.id",
            [$clinicId]
        );
    }

    /** Row lock for mutation. SQLite ignores FOR UPDATE; MySQL takes it. */
    public function lockEntry(int $clinicId, int $entryId): ?array
    {
        $suffix = \App\Core\Env::isSqlite() ? '' : ' FOR UPDATE';
        return Db::one(
            "SELECT * FROM queue_entries WHERE id = ? AND clinic_id = ?" . $suffix,
            [$entryId, $clinicId]
        );
    }

    public function entry(int $clinicId, int $entryId): ?array
    {
        return Db::one(
            "SELECT e.*, p.full_name, p.phone, q.name AS queue_name, q.doctor_id,
                    d.name AS doctor_name, d.avg_consult_minutes, s.name AS specialty,
                    r.name AS room_name, c.name AS clinic_name, c.phone AS clinic_phone
               FROM queue_entries e
               JOIN patients p ON p.id = e.patient_id
               JOIN queues q ON q.id = e.queue_id
               JOIN clinics c ON c.id = e.clinic_id
               LEFT JOIN doctors d ON d.id = q.doctor_id
               LEFT JOIN specialties s ON s.id = d.specialty_id
               LEFT JOIN rooms r ON r.id = d.room_id
              WHERE e.id = ? AND e.clinic_id = ?",
            [$entryId, $clinicId]
        );
    }

    /** Public tracking: token + clinic scope, no tenant information in the URL. */
    public function entryByToken(string $token): ?array
    {
        return Db::one(
            "SELECT e.*, q.name AS queue_name, q.status AS queue_status, q.doctor_id,
                    d.name AS doctor_name, d.avg_consult_minutes, s.name AS specialty,
                    r.name AS room_name, c.name AS clinic_name, c.phone AS clinic_phone,
                    c.brand_color, c.locale
               FROM queue_entries e
               JOIN queues q ON q.id = e.queue_id
               JOIN clinics c ON c.id = e.clinic_id
               LEFT JOIN doctors d ON d.id = q.doctor_id
               LEFT JOIN specialties s ON s.id = d.specialty_id
               LEFT JOIN rooms r ON r.id = d.room_id
              WHERE e.track_token = ?",
            [$token]
        );
    }

    public function position(int $queueId, int $entryId, int $priority, int $weight): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM queue_entries
              WHERE queue_id = ? AND status = 'waiting' AND id <> ?
                AND (priority > ? OR (priority = ? AND sort_weight < ?)
                     OR (priority = ? AND sort_weight = ? AND id < ?))",
            [$queueId, $entryId, $priority, $priority, $weight, $priority, $weight, $entryId]
        ) + 1;
    }

    /** Rolling average consultation length, used for wait ranges. */
    public function averageConsultMinutes(int $clinicId, ?int $doctorId, ?string $since = null): ?float
    {
        $since ??= date('Y-m-d 00:00:00', strtotime('-30 days'));
        $sql = "SELECT AVG(consult_minutes) AS avg_minutes, COUNT(*) AS samples
                  FROM queue_entries
                 WHERE clinic_id = ? AND status = 'completed'
                   AND consult_minutes IS NOT NULL AND finished_at >= ?";
        $params = [$clinicId, $since];
        if ($doctorId !== null) {
            $sql .= " AND queue_id IN (SELECT id FROM queues WHERE doctor_id = ?)";
            $params[] = $doctorId;
        }
        $row = Db::one($sql, $params);
        if (!$row || $row['avg_minutes'] === null || (int) $row['samples'] < 1) {
            return null;
        }
        return (float) $row['avg_minutes'];
    }

    public function todayEntries(int $clinicId, ?string $date = null): array
    {
        $date ??= Db::today();
        return Db::all(
            "SELECT e.*, p.full_name, q.name AS queue_name, d.name AS doctor_name
               FROM queue_entries e
               JOIN patients p ON p.id = e.patient_id
               JOIN queues q ON q.id = e.queue_id
               LEFT JOIN doctors d ON d.id = q.doctor_id
              WHERE e.clinic_id = ? AND substr(e.joined_at, 1, 10) = ?
              ORDER BY e.id DESC",
            [$clinicId, $date]
        );
    }
}
