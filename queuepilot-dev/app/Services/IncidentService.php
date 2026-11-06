<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Incident records — an append-only timeline rather than a remembered story.
 *
 * When something breaks at 03:00, the things that matter afterwards are: when
 * we knew, when somebody took it, what we did, and why. All four are the sort
 * of detail that feels obvious in the moment and is unreconstructable two
 * weeks later. The timeline is append-only on purpose: an incident log that
 * can be edited is not evidence, it is a narrative. A correction is a new
 * entry that says what it corrects.
 *
 * Time to acknowledge and time to resolve are computed from timestamps, never
 * typed in, so a post-mortem cannot quietly claim a faster response.
 */
final class IncidentService
{
    public const SEVERITIES = [
        'minor'    => 'Minor — degraded, work continues',
        'major'    => 'Major — a core function is down',
        'critical' => 'Critical — patients or money at immediate risk',
    ];

    public const STATUSES = [
        'open'      => 'Open',
        'mitigated' => 'Mitigated — the bleeding stopped, work continues',
        'resolved'  => 'Resolved',
    ];

    public const KINDS = [
        'note'     => 'Note',
        'action'   => 'Action taken',
        'decision' => 'Decision',
        'cause'    => 'Cause found',
        'link'     => 'Reference',
    ];

    /**
     * Open (or recent) incidents, platform-wide and for one clinic.
     */
    public function list(int $clinicId, bool $openOnly = false): array
    {
        $sql = 'SELECT i.*, u.name AS created_by_name,
                       (SELECT COUNT(*) FROM incident_entries e WHERE e.incident_id = i.id) AS entry_count
                  FROM incidents i
                  LEFT JOIN users u ON u.id = i.created_by
                 WHERE (i.clinic_id = ? OR i.clinic_id IS NULL)';
        if ($openOnly) {
            $sql .= " AND i.status <> 'resolved'";
        }
        $sql .= " ORDER BY CASE i.status WHEN 'open' THEN 0 WHEN 'mitigated' THEN 1 ELSE 2 END,
                           CASE i.severity WHEN 'critical' THEN 0 WHEN 'major' THEN 1 ELSE 2 END,
                           i.detected_at DESC, i.id DESC";

        return Db::all($sql, [$clinicId]);
    }

    public function find(int $clinicId, int $incidentId): ?array
    {
        return Db::one(
            'SELECT i.*, u.name AS created_by_name
               FROM incidents i LEFT JOIN users u ON u.id = i.created_by
              WHERE i.id = ? AND (i.clinic_id = ? OR i.clinic_id IS NULL)',
            [$incidentId, $clinicId]
        );
    }

    public function entries(int $incidentId): array
    {
        return Db::all(
            'SELECT e.*, u.name AS actor FROM incident_entries e
               LEFT JOIN users u ON u.id = e.actor_id
              WHERE e.incident_id = ? ORDER BY e.created_at ASC, e.id ASC',
            [$incidentId]
        );
    }

    /** Alerts the platform raised and has not resolved. */
    public function openAlerts(): array
    {
        return Db::all(
            "SELECT id, code, severity, message, created_at FROM system_alerts
              WHERE resolved_at IS NULL ORDER BY created_at DESC LIMIT 20"
        );
    }

    /**
     * Open an incident. The first timeline entry is written automatically and
     * says who opened it and what they saw — the two facts a reader wants
     * first and that nobody remembers to type later.
     */
    public function open(int $clinicId, array $in, ?int $actorId = null): int
    {
        return Db::transaction(function () use ($clinicId, $in, $actorId) {
            $title = trim((string) ($in['title'] ?? ''));
            if (mb_strlen($title) < 4) {
                throw new \InvalidArgumentException('Give the incident a title somebody waking up at 03:00 will understand.');
            }
            $severity = (string) ($in['severity'] ?? 'minor');
            if (!isset(self::SEVERITIES[$severity])) {
                throw new \InvalidArgumentException('Choose a severity.');
            }

            $reference = $this->nextReference();
            // A `datetime-local` input posts "2026-11-05T14:34". Storing that
            // verbatim leaves a T in a column every other writer fills with a
            // space, and strtotime() then reads it as a different instant — a
            // 25-minute response was reported as 355. Normalise on the way in.
            $detectedAt = $this->normaliseTimestamp((string) ($in['detected_at'] ?? '')) ?? Db::now();
            if (strtotime($detectedAt) > time() + 60) {
                throw new \InvalidArgumentException('An incident cannot be detected in the future — set the time it actually started.');
            }

            $id = Db::insert(
                'INSERT INTO incidents (clinic_id, reference, title, severity, status, summary, detected_at, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    $clinicId, $reference, $title, $severity, 'open',
                    trim((string) ($in['summary'] ?? '')) ?: null,
                    $detectedAt, $actorId, Db::now(),
                ]
            );

            $this->addEntry($id, 'note', 'Incident opened. ' . $title, $actorId);

            // Attach whichever open alert the reporter picked, so the timeline
            // carries the platform's own words about what was wrong.
            $alertId = (int) ($in['alert_id'] ?? 0);
            if ($alertId > 0 && Db::scalar('SELECT id FROM system_alerts WHERE id = ?', [$alertId]) !== null) {
                Db::run('UPDATE system_alerts SET incident_id = ? WHERE id = ?', [$id, $alertId]);
                $this->addEntry($id, 'link', 'Linked to system alert #' . $alertId, $actorId);
            }

            return $id;
        });
    }

    public function addEntry(int $incidentId, string $kind, string $body, ?int $actorId = null): int
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Unknown entry kind.');
        }
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('An entry needs something in it.');
        }
        return Db::insert(
            'INSERT INTO incident_entries (incident_id, kind, body, actor_id, created_at) VALUES (?,?,?,?,?)',
            [$incidentId, $kind, mb_substr($body, 0, 2000), $actorId, Db::now()]
        );
    }

    /**
     * Acknowledge: somebody has taken the incident. Recorded once — the time
     * to acknowledge must not reset if a second person clicks too.
     */
    public function acknowledge(int $clinicId, int $incidentId, ?int $actorId = null): bool
    {
        $incident = $this->find($clinicId, $incidentId);
        if ($incident === null) {
            return false;
        }
        if ($incident['acknowledged_at'] !== null) {
            return false;
        }
        Db::run('UPDATE incidents SET acknowledged_at = ? WHERE id = ?', [Db::now(), $incidentId]);
        $this->addEntry($incidentId, 'action', 'Acknowledged — somebody is on it.', $actorId);
        return true;
    }

    public function mitigate(int $clinicId, int $incidentId, string $body, ?int $actorId = null): bool
    {
        $incident = $this->find($clinicId, $incidentId);
        if ($incident === null || $incident['status'] === 'resolved') {
            return false;
        }
        Db::run("UPDATE incidents SET status = 'mitigated' WHERE id = ?", [$incidentId]);
        $this->addEntry($incidentId, 'action', 'Mitigated: ' . trim($body), $actorId);
        return true;
    }

    public function resolve(int $clinicId, int $incidentId, string $body, ?int $actorId = null): bool
    {
        $incident = $this->find($clinicId, $incidentId);
        if ($incident === null || $incident['status'] === 'resolved') {
            return false;
        }
        Db::run("UPDATE incidents SET status = 'resolved', resolved_at = ? WHERE id = ?", [Db::now(), $incidentId]);
        $this->addEntry($incidentId, 'decision', 'Resolved: ' . trim($body), $actorId);
        return true;
    }

    /**
     * Time to acknowledge and to resolve, computed from timestamps. Null when
     * the incident has not reached that point — a blank is honest, an estimate
     * is not.
     *
     * @return array{acknowledge_minutes:?int,resolve_minutes:?int,detected:?string}
     */
    public function timings(array $incident): array
    {
        $detected = strtotime((string) $incident['detected_at']);
        return [
            'detected'            => $incident['detected_at'],
            'acknowledge_minutes' => $incident['acknowledged_at'] !== null
                ? (int) round((strtotime((string) $incident['acknowledged_at']) - $detected) / 60) : null,
            'resolve_minutes'     => $incident['resolved_at'] !== null
                ? (int) round((strtotime((string) $incident['resolved_at']) - $detected) / 60) : null,
        ];
    }

    /** What went wrong over a period, for the operations report. */
    public function stats(int $clinicId, int $days = 90): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $rows = Db::all(
            'SELECT * FROM incidents WHERE (clinic_id = ? OR clinic_id IS NULL) AND detected_at >= ?',
            [$clinicId, $since]
        );
        $ack = [];
        $res = [];
        $bySeverity = ['minor' => 0, 'major' => 0, 'critical' => 0];
        foreach ($rows as $row) {
            $bySeverity[(string) $row['severity']] = ($bySeverity[(string) $row['severity']] ?? 0) + 1;
            $t = $this->timings($row);
            if ($t['acknowledge_minutes'] !== null) {
                $ack[] = $t['acknowledge_minutes'];
            }
            if ($t['resolve_minutes'] !== null) {
                $res[] = $t['resolve_minutes'];
            }
        }
        return [
            'total'       => count($rows),
            'open'        => count(array_filter($rows, static fn ($r) => $r['status'] !== 'resolved')),
            'by_severity' => $bySeverity,
            'avg_ack'     => $ack === [] ? null : (int) round(array_sum($ack) / count($ack)),
            'avg_resolve' => $res === [] ? null : (int) round(array_sum($res) / count($res)),
        ];
    }

    /**
     * Accept the two shapes a browser and a test can produce, and return the
     * one shape the database uses. Null means "not supplied".
     */
    private function normaliseTimestamp(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace('T', ' ', $raw);
        // Drop a trailing seconds-less or timezone suffix the browser may add.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ ](\d{2}:\d{2})(:\d{2})?/', $raw, $m) !== 1) {
            return null;
        }
        $time = $m[2] . (isset($m[3]) && $m[3] !== '' ? $m[3] : ':00');
        return $m[1] . ' ' . $time;
    }

    /** INC-2026-0001 style, sequential and unique. */
    private function nextReference(): string
    {
        $year = date('Y');
        $count = (int) Db::scalar("SELECT COUNT(*) FROM incidents WHERE reference LIKE ?", ['INC-' . $year . '-%']);
        do {
            $count++;
            $candidate = sprintf('INC-%s-%04d', $year, $count);
        } while (Db::scalar('SELECT id FROM incidents WHERE reference = ?', [$candidate]) !== null);
        return $candidate;
    }
}
