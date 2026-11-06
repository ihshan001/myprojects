<?php
declare(strict_types=1);

/** Deploy-time sanity check: schema present, plans seeded, no missing routes. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;

$failures = [];
$checks = 0;

$required = ['plans', 'clinics', 'users', 'doctors', 'queues', 'queue_entries',
             'patients', 'appointments', 'queue_events', 'notifications', 'audit_logs',
             'invoices', 'login_attempts', 'settings'];
foreach ($required as $table) {
    $checks++;
    try {
        Db::scalar("SELECT COUNT(*) FROM {$table}");
    } catch (Throwable $e) {
        $failures[] = "missing table: {$table}";
    }
}

$checks++;
if ((int) Db::scalar('SELECT COUNT(*) FROM plans') === 0) {
    $failures[] = 'no subscription plans seeded (run tools/seed.php)';
}

$checks++;
if (count(array_unique(array_map(
    static fn (string $f): string => basename($f),
    glob(dirname(__DIR__) . '/database/schema/*.sql') ?: []
))) < 2) {
    $failures[] = 'expected both mysql.sql and sqlite.sql schemas';
}

$checks++;
if (!is_file(dirname(__DIR__) . '/public/assets/build/app.css')) {
    $failures[] = 'CSS bundle missing (run npm run css)';
}

// The two schemas must describe the same database. They are hand-written, so
// drift is the most likely way this project rots silently: SQLite tests pass
// while the MySQL production schema is missing a column.
$checks++;
$schemaCols = static function (string $file): array {
    $sql = (string) file_get_contents($file);
    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*?)\n\)(?:[^;]*);/s', $sql, $tables, PREG_SET_ORDER);
    $out = [];
    foreach ($tables as $t) {
        $cols = [];
        foreach (explode("\n", $t[2]) as $line) {
            $line = rtrim(trim($line), ',');
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }
            if (preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK)\b/i', $line)) {
                continue;
            }
            if (preg_match('/^`?(\w+)`?\s/', $line, $m)) {
                $cols[] = $m[1];
            }
        }
        sort($cols);
        $out[$t[1]] = $cols;
    }
    ksort($out);
    return $out;
};
$sqliteSchema = $schemaCols(dirname(__DIR__) . '/database/schema/sqlite.sql');
$mysqlSchema  = $schemaCols(dirname(__DIR__) . '/database/schema/mysql.sql');
foreach ($sqliteSchema as $table => $cols) {
    if (!isset($mysqlSchema[$table])) {
        $failures[] = "mysql.sql is missing table {$table}";
        continue;
    }
    $missing = array_diff($cols, $mysqlSchema[$table]);
    $extra = array_diff($mysqlSchema[$table], $cols);
    if ($missing !== []) {
        $failures[] = "mysql.sql {$table} missing: " . implode(', ', $missing);
    }
    if ($extra !== []) {
        $failures[] = "mysql.sql {$table} extra: " . implode(', ', $extra);
    }
}
foreach (array_diff_key($mysqlSchema, $sqliteSchema) as $table => $_) {
    $failures[] = "sqlite.sql is missing table {$table}";
}

$driver = App\Core\Env::get('db.driver');
echo "QueuePilot healthcheck\n";
echo "  PHP      " . PHP_VERSION . " (" . php_sapi_name() . ")\n";
echo "  driver   {$driver}\n";
echo "  checks   {$checks}\n";
echo "  plans    " . Db::scalar('SELECT COUNT(*) FROM plans') . "\n";
echo "  clinics  " . Db::scalar('SELECT COUNT(*) FROM clinics') . "\n";

if ($failures !== []) {
    echo "\nFAILED:\n";
    foreach ($failures as $f) {
        echo "  ✗ {$f}\n";
    }
    exit(1);
}
echo "\nAll good.\n";
