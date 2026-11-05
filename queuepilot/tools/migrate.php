<?php
declare(strict_types=1);

/**
 * Migration runner: applies database/schema/{driver}.sql once, then any
 * database/migrations/*.sql in filename order, recording each in schema_migrations.
 * Idempotent — safe to run on every deploy.
 */
$root = dirname(__DIR__);
$config = require $root . '/app/bootstrap.php';

use App\Core\Db;

$driver = $config['db']['driver'];

Db::exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration TEXT NOT NULL UNIQUE,
    applied_at TEXT NOT NULL
)");

$applied = array_column(Db::all('SELECT migration FROM schema_migrations'), 'migration');

$files = [];
$schemaFile = $root . '/database/schema/' . ($driver === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($schemaFile)) {
    $files['0000_schema'] = $schemaFile;
}
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $f) {
    $files['1_' . basename($f, '.sql')] = $f;
}
ksort($files);

$ran = 0;
foreach ($files as $name => $file) {
    if (in_array($name, $applied, true)) {
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read {$file}\n");
        exit(1);
    }

    echo "→ applying {$name}\n";
    // split on semicolons at end of line, keeping simple statements intact
    $statements = preg_split('/;\s*\n/', $sql) ?: [];
    foreach ($statements as $stmt) {
        // strip comment lines first, then decide whether anything remains
        $lines = array_filter(explode("\n", $stmt), fn ($l) => !str_starts_with(trim($l), '--'));
        $clean = trim(implode("\n", $lines));
        if ($clean === '') {
            continue;
        }
        try {
            Db::pdo()->exec($clean);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                continue;
            }
            fwrite(STDERR, "FAILED in {$name}: {$e->getMessage()}\nSQL: " . substr($clean, 0, 300) . "\n");
            exit(1);
        }
    }
    Db::insert('INSERT INTO schema_migrations (migration, applied_at) VALUES (?,?)', [$name, date('Y-m-d H:i:s')]);
    $ran++;
}

echo $ran === 0 ? "Nothing to migrate.\n" : "Applied {$ran} migration(s).\n";
