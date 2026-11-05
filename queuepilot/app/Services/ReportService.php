<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Operational reports. Small aggregate queries only — no chart library,
 * no analytics vendor, everything runs on the same DB the queue uses.
 */
final class ReportService
{
    public function daySummary(int $clinicId, ?string $date = null): array
    {
        $date ??= Db::today();
        return Db::one(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status='no_show' THEN 1 ELSE 0 END) AS no_show,
                SUM(CASE WHEN status='skipped' THEN 1 ELSE 0 END) AS skipped,
                SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN priority=1 THEN 1 ELSE 0 END) AS prioritised,
                AVG(wait_minutes) AS avg_wait,
                AVG(consult_minutes) AS avg_consult,
                MAX(wait_minutes) AS max_wait
             FROM queue_entries
             WHERE clinic_id = ? AND substr(joined_at,1,10) = ?",
            [$clinicId, $date]
        ) ?? [];
    }

    public function byHour(int $clinicId, ?string $date = null): array
    {
        $date ??= Db::today();
        return Db::all(
            "SELECT substr(joined_at, 12, 2) AS hour, COUNT(*) AS total,
                    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
                    AVG(wait_minutes) AS avg_wait
               FROM queue_entries
              WHERE clinic_id = ? AND substr(joined_at,1,10) = ?
              GROUP BY substr(joined_at, 12, 2) ORDER BY hour",
            [$clinicId, $date]
        );
    }

    public function byDoctor(int $clinicId, ?string $from = null, ?string $to = null): array
    {
        $from ??= date('Y-m-d', strtotime('-7 days'));
        $to ??= Db::today();
        return Db::all(
            "SELECT d.id, d.name, s.name AS specialty,
                    COUNT(e.id) AS seen,
                    SUM(CASE WHEN e.status='completed' THEN 1 ELSE 0 END) AS completed,
                    AVG(e.wait_minutes) AS avg_wait,
                    AVG(e.consult_minutes) AS avg_consult,
                    SUM(CASE WHEN e.priority=1 THEN 1 ELSE 0 END) AS prioritised
               FROM doctors d
               LEFT JOIN specialties s ON s.id = d.specialty_id
               LEFT JOIN queues q ON q.doctor_id = d.id AND q.clinic_id = d.clinic_id
               LEFT JOIN queue_entries e ON e.queue_id = q.id
                    AND substr(e.joined_at,1,10) BETWEEN ? AND ?
              WHERE d.clinic_id = ?
              GROUP BY d.id, d.name, s.name
              ORDER BY completed DESC, d.name",
            [$from, $to, $clinicId]
        );
    }

    public function csv(int $clinicId, ?string $from = null, ?string $to = null): string
    {
        $from ??= Db::today();
        $to ??= Db::today();
        $rows = Db::all(
            "SELECT e.token_label, p.code AS patient_code, p.full_name, e.status, e.priority,
                    e.joined_at, e.called_at, e.started_at, e.finished_at,
                    e.wait_minutes, e.consult_minutes, d.name AS doctor, q.name AS queue
               FROM queue_entries e
               JOIN patients p ON p.id = e.patient_id
               JOIN queues q ON q.id = e.queue_id
               LEFT JOIN doctors d ON d.id = q.doctor_id
              WHERE e.clinic_id = ? AND substr(e.joined_at,1,10) BETWEEN ? AND ?
              ORDER BY e.joined_at",
            [$clinicId, $from, $to]
        );

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Token', 'Patient code', 'Patient', 'Status', 'Priority', 'Joined',
            'Called', 'Started', 'Finished', 'Wait min', 'Consult min', 'Doctor', 'Queue']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['token_label'], $r['patient_code'], $r['full_name'], $r['status'],
                (int) $r['priority'] === 1 ? 'Priority' : 'Normal',
                $r['joined_at'], $r['called_at'], $r['started_at'], $r['finished_at'],
                $r['wait_minutes'], $r['consult_minutes'], $r['doctor'], $r['queue'],
            ]);
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
