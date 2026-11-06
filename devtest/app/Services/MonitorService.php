<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Env;

/**
 * Operational health, expressed as a list of checks rather than a boolean.
 *
 * The distinction that matters: "the site returns 200" is not health. A queue
 * system fails softly — the process is up, the page renders, and nobody is
 * being called because the notification worker died an hour ago. So each check
 * answers a question a clinic would actually ask:
 *
 *   db            can we read and write?
 *   worker        are queued messages draining, or piling up?
 *   stale_queues  is there an open session nobody has touched?
 *   waiting_alone someone has waited far past their estimate
 *   disk          will the write of the next token succeed?
 *   backups       is there a recent successful backup on record?
 *   versions      do the two schemas still describe the same database?
 *
 * Every check returns severity `ok | warning | critical`. The endpoint is
 * protected by a bearer secret (`MONITOR_TOKEN`) that is *not* the display key,
 * and when no secret is configured it refuses to run publicly at all rather
 * than exposing an inventory of the platform.
 */
final class MonitorService
{
    /** @return array{status: string, checks: array<int, array<string, mixed>>, generated_at: string} */
    public function run(?int $clinicId = null): array
    {
        $checks = [];
        $checks[] = $this->checkDatabase();

        // Platform-wide checks are only meaningful with visibility of all
        // tenants, so they are gated on a clinic scope being absent.
        if ($clinicId === null) {
            $checks[] = $this->checkWorker();
            $checks[] = $this->checkBackups();
            $checks[] = $this->checkSchemaParity();
        }
        $checks[] = $this->checkStaleQueues($clinicId);
        $checks[] = $this->checkLongWaits($clinicId);
        $checks[] = $this->checkDisk();

        $status = 'ok';
        foreach ($checks as $check) {
            if ($check['severity'] === 'critical') {
                $status = 'critical';
                break;
            }
            if ($check['severity'] === 'warning') {
                $status = 'warning';
            }
        }

        $this->record($checks, $clinicId);

        return [
            'status'       => $status,
            'checks'       => $checks,
            'generated_at' => Db::now(),
        ];
    }

    /* ------------------------------------------------------------------ */

    private function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            Db::scalar('SELECT 1');
            $ms = (int) round((microtime(true) - $start) * 1000);
            return [
                'key' => 'database', 'severity' => $ms > 500 ? 'warning' : 'ok',
                'summary' => "Readable in {$ms} ms",
                'detail' => ['latency_ms' => $ms, 'driver' => Env::get('db.driver')],
            ];
        } catch (\Throwable $e) {
            return [
                'key' => 'database', 'severity' => 'critical',
                'summary' => 'Database is not readable',
                'detail' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * A queue of unsent messages is normal for a minute. A queue that has not
     * moved in fifteen minutes means the worker is dead, and no patient has
     * been told their turn — which is the failure this whole system exists to
     * prevent.
     */
    private function checkWorker(): array
    {
        $pending = (int) Db::scalar("SELECT COUNT(*) FROM notifications WHERE status = 'queued'");
        $oldest = Db::scalar(
            "SELECT MIN(scheduled_at) FROM notifications WHERE status = 'queued' AND scheduled_at <= ?",
            [Db::now()]
        );

        if ($pending === 0) {
            return ['key' => 'notification_worker', 'severity' => 'ok', 'summary' => 'Outbox empty', 'detail' => []];
        }

        $overdueMinutes = $oldest !== null
            ? (int) floor((time() - (int) strtotime((string) $oldest)) / 60)
            : 0;

        $severity = 'ok';
        if ($overdueMinutes >= 30) {
            $severity = 'critical';
        } elseif ($overdueMinutes >= 10) {
            $severity = 'warning';
        }

        return [
            'key' => 'notification_worker',
            'severity' => $severity,
            'summary' => $severity === 'ok'
                ? "{$pending} message(s) waiting, worker is keeping up"
                : "{$pending} message(s) waiting; the oldest is {$overdueMinutes} minutes overdue — check the worker",
            'detail' => ['pending' => $pending, 'oldest_due' => $oldest, 'overdue_minutes' => $overdueMinutes],
        ];
    }

    private function checkStaleQueues(?int $clinicId): array
    {
        // An "open" session with a waiting patient but no activity for two
        // hours is the classic abandoned queue: someone forgot to close the
        // day, and tomorrow's numbers start wrong.
        $sql = "SELECT q.id, q.name, q.session_date, c.name AS clinic_name,
                       (SELECT MAX(e.joined_at) FROM queue_entries e WHERE e.queue_id = q.id) AS last_join
                  FROM queues q
                  JOIN clinics c ON c.id = q.clinic_id
                 WHERE q.status = 'open' AND q.session_date < ?";
        $params = [Db::today()];
        if ($clinicId !== null) {
            $sql .= ' AND q.clinic_id = ?';
            $params[] = $clinicId;
        }
        $stale = Db::all($sql . ' LIMIT 20', $params);

        if ($stale === []) {
            return ['key' => 'stale_queues', 'severity' => 'ok', 'summary' => 'No sessions left open from a previous day', 'detail' => []];
        }

        return [
            'key' => 'stale_queues',
            'severity' => count($stale) > 5 ? 'warning' : 'ok',
            'summary' => count($stale) . ' session(s) still open from an earlier date',
            'detail' => ['queues' => array_map(static fn (array $q): array => [
                'id' => (int) $q['id'], 'name' => $q['name'], 'date' => $q['session_date'],
                'clinic' => $q['clinic_name'],
            ], $stale)],
        ];
    }

    /**
     * Someone waiting much longer than the clinic's own average is a service
     * failure even though nothing has thrown an exception. 45 minutes is
     * deliberately generous: this is an alert, not a nag.
     */
    private function checkLongWaits(?int $clinicId): array
    {
        $sql = "SELECT e.id, e.token_label, e.joined_at, q.name AS queue_name, c.name AS clinic_name
                  FROM queue_entries e
                  JOIN queues q ON q.id = e.queue_id
                  JOIN clinics c ON c.id = e.clinic_id
                 WHERE e.status = 'waiting' AND e.joined_at <= ?";
        // Placeholders are positional, so the clinic predicate goes *after*
        // the timestamp it was written around. Building both in order avoids
        // the classic str_replace-then-shuffle-params mistake.
        $params = [date('Y-m-d H:i:s', time() - 45 * 60)];
        if ($clinicId !== null) {
            $sql .= ' AND e.clinic_id = ?';
            $params[] = $clinicId;
        }
        $long = Db::all($sql . ' LIMIT 20', $params);

        if ($long === []) {
            return ['key' => 'long_waits', 'severity' => 'ok', 'summary' => 'Nobody has waited over 45 minutes', 'detail' => []];
        }

        return [
            'key' => 'long_waits',
            'severity' => 'warning',
            'summary' => count($long) . ' patient(s) waiting over 45 minutes',
            'detail' => ['entries' => array_map(static fn (array $e): array => [
                'id' => (int) $e['id'], 'token' => $e['token_label'],
                'queue' => $e['queue_name'], 'clinic' => $e['clinic_name'],
                'minutes' => (int) floor((time() - (int) strtotime((string) $e['joined_at'])) / 60),
            ], $long)],
        ];
    }

    private function checkDisk(): array
    {
        $path = QP_ROOT . '/storage';
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return ['key' => 'disk', 'severity' => 'ok', 'summary' => 'Disk usage not reported by this platform', 'detail' => []];
        }
        $pct = (int) round(($total - $free) / $total * 100);
        $severity = $pct >= 95 ? 'critical' : ($pct >= 85 ? 'warning' : 'ok');
        return [
            'key' => 'disk',
            'severity' => $severity,
            'summary' => "{$pct}% of disk used",
            'detail' => ['used_percent' => $pct, 'free_mb' => (int) round($free / 1048576)],
        ];
    }

    /**
     * A backup you have not checked is a rumour. This reads the record the
     * backup tool writes (see tools/backup.php) and complains if it is absent
     * or old — the check fails closed, not open.
     */
    private function checkBackups(): array
    {
        $file = QP_ROOT . '/storage/backups/last-backup.json';
        if (!is_file($file)) {
            return [
                'key' => 'backups', 'severity' => 'warning',
                'summary' => 'No backup has ever been recorded on this host',
                'detail' => ['expected' => 'storage/backups/last-backup.json'],
            ];
        }
        $meta = json_decode((string) file_get_contents($file), true) ?: [];
        $finished = (string) ($meta['finished_at'] ?? '');
        $ageHours = $finished !== '' ? (int) floor((time() - (int) strtotime($finished)) / 3600) : PHP_INT_MAX;

        $maxAge = (int) Env::get('backup.max_age_hours', 48);
        $severity = $ageHours > $maxAge ? 'critical' : ($ageHours > (int) ($maxAge / 2) ? 'warning' : 'ok');

        return [
            'key' => 'backups',
            'severity' => $severity,
            'summary' => is_finite((float) $ageHours) && $ageHours !== PHP_INT_MAX
                ? "Last backup finished {$ageHours} hours ago"
                : 'Backup record exists but has no completion time',
            'detail' => ['age_hours' => $ageHours === PHP_INT_MAX ? null : $ageHours, 'file' => $meta['file'] ?? null],
        ];
    }

    private function checkSchemaParity(): array
    {
        $sqlite = QP_ROOT . '/database/schema/sqlite.sql';
        $mysql = QP_ROOT . '/database/schema/mysql.sql';
        if (!is_file($sqlite) || !is_file($mysql)) {
            return ['key' => 'schema_parity', 'severity' => 'warning', 'summary' => 'One of the schema files is missing', 'detail' => []];
        }
        $cols = static function (string $file): array {
            $sql = (string) file_get_contents($file);
            preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*?)\n\)(?:[^;]*);/s', $sql, $tables, PREG_SET_ORDER);
            $out = [];
            foreach ($tables as $t) {
                $names = [];
                foreach (explode("\n", $t[2]) as $line) {
                    $line = rtrim(trim($line), ',');
                    if ($line === '' || str_starts_with($line, '--')) {
                        continue;
                    }
                    if (preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK)\b/i', $line)) {
                        continue;
                    }
                    if (preg_match('/^`?(\w+)`?\s/', $line, $m)) {
                        $names[] = $m[1];
                    }
                }
                sort($names);
                $out[$t[1]] = $names;
            }
            ksort($out);
            return $out;
        };

        $a = $cols($sqlite);
        $b = $cols($mysql);
        $drift = [];
        foreach ($a as $table => $names) {
            if (!isset($b[$table])) {
                $drift[] = "mysql missing table {$table}";
                continue;
            }
            $diff = array_merge(array_diff($names, $b[$table]), array_diff($b[$table], $names));
            if ($diff !== []) {
                $drift[] = "{$table}: " . implode(',', $diff);
            }
        }

        return [
            'key' => 'schema_parity',
            'severity' => $drift === [] ? 'ok' : 'critical',
            'summary' => $drift === [] ? 'Both schemas describe the same database' : count($drift) . ' schema difference(s)',
            'detail' => ['drift' => array_slice($drift, 0, 10)],
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Persist non-ok findings so the admin dashboard can show them, and so a
     * transient failure that resolved itself is still visible in hindsight.
     * Identical open alerts are coalesced rather than duplicated on every poll.
     */
    private function record(array $checks, ?int $clinicId): void
    {
        foreach ($checks as $check) {
            if ($check['severity'] === 'ok') {
                // Clear a previous alert for the same key once it recovers.
                Db::run(
                    'UPDATE system_alerts SET resolved_at = ? WHERE code = ? AND resolved_at IS NULL
                      AND (clinic_id IS NULL OR clinic_id = ?)',
                    [Db::now(), (string) $check['key'], $clinicId ?? 0]
                );
                continue;
            }
            $open = Db::scalar(
                'SELECT id FROM system_alerts WHERE code = ? AND resolved_at IS NULL AND clinic_id IS ?',
                [(string) $check['key'], $clinicId]
            );
            if (!$open) {
                Db::insert(
                    'INSERT INTO system_alerts (clinic_id, severity, code, message, context_json, created_at)
                     VALUES (?,?,?,?,?,?)',
                    [
                        $clinicId, (string) $check['severity'], (string) $check['key'],
                        (string) $check['summary'],
                        json_encode($check['detail'], JSON_UNESCAPED_UNICODE),
                        Db::now(),
                    ]
                );
            }
        }
    }

    /** Open alerts for the admin dashboard. */
    public function openAlerts(): array
    {
        return Db::all(
            'SELECT * FROM system_alerts WHERE resolved_at IS NULL ORDER BY
                CASE severity WHEN \'critical\' THEN 0 ELSE 1 END, created_at DESC LIMIT 20'
        );
    }
}
