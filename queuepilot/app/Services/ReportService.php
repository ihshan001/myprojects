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
                    SUM(CASE WHEN e.priority=1 THEN 1 ELSE 0 END) AS prioritised,
                    -- how often this doctor/queue announced a delay, from the
                    -- audit trail: a performance signal, not a patient record
                    (SELECT COUNT(*) FROM audit_logs a
                      WHERE a.clinic_id = d.clinic_id
                        AND a.action = 'queue.delay_announced'
                        AND a.entity_id = q.id
                        AND substr(a.created_at,1,10) BETWEEN ? AND ?) AS delays
               FROM doctors d
               LEFT JOIN specialties s ON s.id = d.specialty_id
               LEFT JOIN queues q ON q.doctor_id = d.id AND q.clinic_id = d.clinic_id
               LEFT JOIN queue_entries e ON e.queue_id = q.id
                    AND substr(e.joined_at,1,10) BETWEEN ? AND ?
              WHERE d.clinic_id = ?
              GROUP BY d.id, d.name, s.name
              ORDER BY completed DESC, d.name",
            [$from, $to, $from, $to, $clinicId]
        );
    }

    /**
     * Consultation revenue over a range — what the clinic's own fees add up to.
     *
     * Read the name carefully: this is **billed**, not collected. QueuePilot
     * does not take payments and never has; money changes hands at the desk or
     * by transfer, and the system only knows each doctor's `consult_fee`. So
     * the honest figure is "what these completed consultations are worth at
     * your fees", and the report says that in those words rather than
     * presenting a number a manager would take for a bank balance.
     *
     * Completed visits only. A cancelled appointment or a no-show earns
     * nothing, and counting called-but-unfinished visits would inflate the
     * number on a busy morning. A doctor with no fee set is excluded from the
     * money column but still counted in the visit column, and the report
     * names those doctors — a silently missing row is how a revenue report
     * becomes a lie.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: float, currency: string, by_day: array<string, float>, missing_fee: array<int, string>, completed: int}
     */
    public function revenue(int $clinicId, string $from, string $to): array
    {
        $rows = Db::all(
            "SELECT d.id, d.name, d.consult_fee,
                    COUNT(e.id) AS completed,
                    SUM(COALESCE(d.consult_fee, 0)) AS billed,
                    SUM(CASE WHEN d.consult_fee IS NULL OR d.consult_fee = 0 THEN e.id END) AS no_fee_visits
               FROM queue_entries e
               JOIN queues q ON q.id = e.queue_id
               JOIN doctors d ON d.id = q.doctor_id
              WHERE e.clinic_id = ?
                AND e.status = 'completed'
                AND substr(COALESCE(e.finished_at, e.joined_at), 1, 10) BETWEEN ? AND ?
              GROUP BY d.id, d.name, d.consult_fee
              ORDER BY billed DESC, d.name",
            [$clinicId, $from, $to]
        );

        $byDay = [];
        foreach (Db::all(
            "SELECT substr(COALESCE(e.finished_at, e.joined_at), 1, 10) AS day,
                    SUM(COALESCE(d.consult_fee, 0)) AS billed,
                    COUNT(e.id) AS completed
               FROM queue_entries e
               JOIN queues q ON q.id = e.queue_id
               JOIN doctors d ON d.id = q.doctor_id
              WHERE e.clinic_id = ?
                AND e.status = 'completed'
                AND substr(COALESCE(e.finished_at, e.joined_at), 1, 10) BETWEEN ? AND ?
              GROUP BY day ORDER BY day",
            [$clinicId, $from, $to]
        ) as $row) {
            $byDay[(string) $row['day']] = (float) $row['billed'];
        }

        $total = 0.0;
        $completed = 0;
        $missingFee = [];
        foreach ($rows as $i => $row) {
            $rows[$i]['billed'] = (float) $row['billed'];
            $total += $rows[$i]['billed'];
            $completed += (int) $row['completed'];
            if ((float) ($row['consult_fee'] ?? 0) <= 0.0 && (int) $row['completed'] > 0) {
                $missingFee[] = (string) $row['name'];
            }
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'currency' => (string) Db::scalar('SELECT currency FROM clinics WHERE id = ?', [$clinicId]),
            'by_day' => $byDay,
            'missing_fee' => $missingFee,
            'completed' => $completed,
        ];
    }

    /**
     * Outcomes over a range: completed, no-show, cancelled, transferred.
     *
     * Reported together because the ratios are what a clinic acts on — a
     * cancellation rate climbing while completions fall means the queue is
     * mis-set, not that patients changed.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, int>, rate: float}
     */
    public function outcomes(int $clinicId, string $from, string $to): array
    {
        $rows = Db::all(
            "SELECT substr(e.joined_at, 1, 10) AS day,
                    SUM(CASE WHEN e.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN e.status = 'no_show' THEN 1 ELSE 0 END) AS no_show,
                    SUM(CASE WHEN e.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                    SUM(CASE WHEN e.status = 'skipped' THEN 1 ELSE 0 END) AS skipped,
                    SUM(CASE WHEN e.status = 'transferred' THEN 1 ELSE 0 END) AS transferred,
                    COUNT(*) AS total
               FROM queue_entries e
              WHERE e.clinic_id = ? AND e.joined_at >= ? AND e.joined_at < ?
              GROUP BY day ORDER BY day",
            [$clinicId, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        );

        $totals = ['completed' => 0, 'no_show' => 0, 'cancelled' => 0, 'skipped' => 0, 'transferred' => 0, 'total' => 0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += (int) $r[$k];
            }
        }
        $lost = $totals['no_show'] + $totals['cancelled'];
        $rate = $totals['total'] > 0 ? round($lost / $totals['total'] * 100, 1) : 0.0;

        return ['rows' => $rows, 'totals' => $totals, 'rate' => $rate];
    }

    /**
     * Staff activity from the audit log.
     *
     * This is the report a manager actually uses ("who cancelled twelve
     * appointments on Friday?"), so it groups by person and action rather
     * than dumping every row. It reads the audit log, which means it can only
     * show what was audited — the page says so rather than implying total
     * coverage.
     *
     * @return array<int, array<string, mixed>>
     */
    public function staffActivity(int $clinicId, string $from, string $to): array
    {
        return Db::all(
            "SELECT u.id, u.name, u.role,
                    COUNT(*) AS actions,
                    SUM(CASE WHEN a.action LIKE 'queue.%' THEN 1 ELSE 0 END) AS queue_actions,
                    SUM(CASE WHEN a.action LIKE 'patient.%' THEN 1 ELSE 0 END) AS patient_actions,
                    SUM(CASE WHEN a.action LIKE '%cancel%' OR a.action LIKE '%no_show%' THEN 1 ELSE 0 END) AS destructive,
                    MIN(a.created_at) AS first_seen,
                    MAX(a.created_at) AS last_seen
               FROM audit_logs a
               JOIN users u ON u.id = a.actor_id
              WHERE a.clinic_id = ? AND a.created_at >= ? AND a.created_at < ?
              GROUP BY u.id, u.name, u.role
              ORDER BY actions DESC",
            [$clinicId, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        );
    }

    /**
     * Doctor delay behaviour: how often a session was paused for lateness and
     * for how long. A doctor whose queue pauses every Tuesday at 09:10 has a
     * scheduling problem, not a discipline problem, and only the pattern
     * shows it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function delays(int $clinicId, string $from, string $to): array
    {
        return Db::all(
            "SELECT d.name AS doctor,
                    COUNT(*) AS delay_events,
                    ROUND(AVG(CAST(json_extract(e.meta_json, '$.minutes') AS REAL)), 1) AS avg_minutes,
                    MAX(CAST(json_extract(e.meta_json, '$.minutes') AS REAL)) AS worst_minutes
               FROM queue_events e
               JOIN queues q ON q.id = e.queue_id
               JOIN doctors d ON d.id = q.doctor_id
              WHERE e.clinic_id = ?
                AND e.action IN ('queue.delayed', 'queue.paused')
                AND e.created_at >= ? AND e.created_at < ?
              GROUP BY d.id, d.name
              ORDER BY delay_events DESC",
            [$clinicId, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        );
    }

    /**
     * Department throughput. Queues with no department are reported under
     * "(unassigned)" rather than hidden — an owner needs to see how much of
     * the clinic is not yet organised.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDepartment(int $clinicId, string $from, string $to): array
    {
        return Db::all(
            "SELECT COALESCE(dept.name, '(unassigned)') AS department,
                    COUNT(e.id) AS seen,
                    SUM(CASE WHEN e.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    ROUND(AVG(
                        CASE WHEN e.called_at IS NOT NULL AND e.joined_at IS NOT NULL
                             THEN (julianday(e.called_at) - julianday(e.joined_at)) * 1440 END
                    ), 1) AS avg_wait_minutes
               FROM queue_entries e
               JOIN queues q ON q.id = e.queue_id
               LEFT JOIN departments dept ON dept.id = q.department_id
              WHERE e.clinic_id = ? AND e.joined_at >= ? AND e.joined_at < ?
              GROUP BY dept.id, department
              ORDER BY seen DESC",
            [$clinicId, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        );
    }

    /**
     * Waiting-time percentiles. An average is the wrong statistic for waits:
     * one 90-minute outlier hides that most people waited four minutes, and
     * the 90th percentile is what a patient actually complains about.
     *
     * @return array{p50: float, p90: float, p99: float, max: float, count: int}
     */
    public function waitPercentiles(int $clinicId, string $from, string $to): array
    {
        $waits = array_map(
            static fn ($r): float => (float) $r['minutes'],
            Db::all(
                "SELECT (julianday(called_at) - julianday(joined_at)) * 1440 AS minutes
                   FROM queue_entries
                  WHERE clinic_id = ? AND called_at IS NOT NULL AND joined_at IS NOT NULL
                    AND joined_at >= ? AND joined_at < ?",
                [$clinicId, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
            )
        );

        if ($waits === []) {
            return ['p50' => 0.0, 'p90' => 0.0, 'p99' => 0.0, 'max' => 0.0, 'count' => 0];
        }

        sort($waits);
        $pick = static function (float $q) use ($waits): float {
            $index = (int) floor($q * (count($waits) - 1));
            return round($waits[$index], 1);
        };

        return [
            'p50' => $pick(0.50),
            'p90' => $pick(0.90),
            'p99' => $pick(0.99),
            'max' => round(max($waits), 1),
            'count' => count($waits),
        ];
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
