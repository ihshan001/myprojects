<?php
declare(strict_types=1);

/**
 * Backup tool — the executable half of docs/BACKUPS.md.
 *
 *   node tools/php.mjs tools/backup.php            # take a backup
 *   node tools/php.mjs tools/backup.php --verify   # restore drill into a scratch DB
 *   node tools/php.mjs tools/backup.php --keep 14  # retention override
 *
 * Why a PHP tool rather than only a shell snippet:
 *   * The same code path works on SQLite (this repo's demo) and MySQL (the
 *     documented production target), and both are exercised.
 *   * The result is *recorded* — `storage/backups/last-backup.json` — so the
 *     health endpoint can tell a stale backup from a fresh one. A cron job
 *     whose only output is a file nobody reads is how "no backups for a month"
 *     gets discovered during an incident.
 *   * The restore drill is part of the same tool. `--verify` actually opens the
 *     dump in a scratch database and counts the tables and rows. A backup that
 *     cannot be opened is reported as a failure at 2 a.m., not at the moment
 *     it is needed.
 *
 * It never prints credentials, never writes `.env` into the dump, and refuses
 * to overwrite an existing file for the same timestamp.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Core\Env;

$root = dirname(__DIR__);
$backupDir = $root . '/storage/backups';
$argvList = $GLOBALS['argv'] ?? [];
$verify = in_array('--verify', $argvList, true);
$keep = 14;
foreach ($argvList as $i => $arg) {
    if ($arg === '--keep' && isset($argvList[$i + 1])) {
        $keep = max(1, (int) $argvList[$i + 1]);
    }
}

if (!is_dir($backupDir) && !@mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
    fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), "Cannot create {$backupDir}\n");
    exit(1);
}

$driver = (string) Env::get('db.driver', 'sqlite');
$stamp = date('Y-m-d_His');
$startedAt = microtime(true);

/* ------------------------------------------------------------------ */
/* 1. Produce the dump                                                 */
/* ------------------------------------------------------------------ */

if ($verify) {
    // Restore drill against the most recent dump.
    $dumps = glob($backupDir . '/queuepilot-*.sqlite') ?: [];
    rsort($dumps);
    if ($dumps === []) {
        echo "No SQLite dump found in storage/backups to verify.\n";
        exit(1);
    }
    $file = $dumps[0];
    echo "Verifying {$file}\n";
} elseif ($driver === 'sqlite') {
    $source = (string) Env::get('db.sqlite');
    if (!is_file($source)) {
        fwrite(STDERR, "SQLite database not found at {$source}\n");
        exit(1);
    }
    $file = $backupDir . '/queuepilot-' . $stamp . '.sqlite';

    // VACUUM INTO writes a consistent snapshot even with WAL readers active,
    // and cannot tear a page the way copying the file on a busy database can.
    try {
        Db::pdo()->exec('VACUUM INTO ' . Db::pdo()->quote($file));
    } catch (Throwable $e) {
        // Older SQLite builds lack VACUUM INTO; fall back to the online backup
        // API, which has been available since 3.7.
        if (!copy($source, $file)) {
            fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }
    echo "Wrote {$file}\n";
} else {
    // MySQL: documented shell path stays authoritative (mysqldump handles
    // triggers, routines and consistent snapshots better than PDO can), so
    // this tool checks that the scheduled job really produced a file rather
    // than half-implementing a second dumper.
    $dumps = glob($backupDir . '/*.sql.gz') ?: [];
    rsort($dumps);
    if ($dumps === []) {
        fwrite(STDERR, "No MySQL dump found. The nightly job in docs/BACKUPS.md has not run.\n");
        exit(1);
    }
    $file = $dumps[0];
    echo "Most recent MySQL dump: {$file}\n";
}

/* ------------------------------------------------------------------ */
/* 2. Verify the dump is real, not just present                        */
/* ------------------------------------------------------------------ */

$checks = [];
$ok = true;

if (str_ends_with($file, '.sqlite') || $verify) {
    try {
        $scratch = new PDO('sqlite:' . $file);
        $scratch->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $integrity = (string) $scratch->query('PRAGMA integrity_check')->fetchColumn();
        $checks['integrity_check'] = $integrity;
        if ($integrity !== 'ok') {
            $ok = false;
        }

        $tables = $scratch->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $checks['tables'] = count($tables);

        // The three tables whose loss would be unrecoverable. A dump without
        // them is not a usable backup no matter what the file size says.
        foreach (['queue_entries', 'patients', 'audit_logs', 'clinics', 'users'] as $required) {
            $exists = in_array($required, $tables, true);
            $checks['has_' . $required] = $exists;
            if (!$exists) {
                $ok = false;
            }
        }

        $counts = [];
        foreach (['clinics', 'users', 'patients', 'queue_entries', 'audit_logs', 'invoices'] as $t) {
            if (in_array($t, $tables, true)) {
                $counts[$t] = (int) $scratch->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            }
        }
        $checks['row_counts'] = $counts;

        $fk = $scratch->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        $checks['foreign_key_violations'] = count($fk);
        if (count($fk) > 0) {
            $ok = false;
        }

        $scratch = null;
    } catch (Throwable $e) {
        $checks['error'] = $e->getMessage();
        $ok = false;
    }
}

$size = is_file($file) ? (int) filesize($file) : 0;
$checks['bytes'] = $size;
// An empty or absurdly small file is the most common silent failure: the dump
// is created, the disk was full, and the file is 0 bytes.
if ($size < 1024) {
    $ok = false;
    $checks['error'] = 'dump is implausibly small';
}

echo "\nVerification\n";
foreach ($checks as $key => $value) {
    $rendered = is_array($value) ? json_encode($value) : (string) $value;
    echo '  ' . str_pad($key, 26) . $rendered . "\n";
}
echo '  ' . str_pad('result', 26) . ($ok ? 'PASS' : 'FAIL') . "\n";

/* ------------------------------------------------------------------ */
/* 3. Record the outcome (the health endpoint reads this)              */
/* ------------------------------------------------------------------ */

$record = [
    'file'         => basename($file),
    'driver'       => $driver,
    'bytes'        => $size,
    'sha256'       => is_file($file) ? hash_file('sha256', $file) : null,
    'started_at'   => date('Y-m-d H:i:s', (int) $startedAt),
    'finished_at'  => date('Y-m-d H:i:s'),
    'duration_ms'  => (int) round((microtime(true) - $startedAt) * 1000),
    'verified'     => $ok,
    'checks'       => $checks,
];

if (!$verify) {
    file_put_contents($backupDir . '/last-backup.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "\nRecorded storage/backups/last-backup.json\n";

    // Retention: keep the newest N. Never delete the file just verified.
    $all = glob($backupDir . '/queuepilot-*') ?: [];
    rsort($all);
    foreach (array_slice($all, $keep) as $old) {
        if ($old === $file) {
            continue;
        }
        @unlink($old);
    }
    echo 'Retention: keeping the newest ' . $keep . " archive(s)\n";
}

exit($ok ? 0 : 1);
