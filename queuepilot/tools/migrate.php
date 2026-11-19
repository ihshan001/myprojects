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

// The ledger DDL below is chosen by driver on purpose, so this file mentions
// both dialects. The SQLite half never runs on MySQL.
// @dialect-check: dual-dialect-guarded

// The ledger table has to be created in the dialect that is actually
// connected. It used to be written in SQLite syntax unconditionally, which
// meant `php tools/migrate.php` -- the documented install command for MySQL --
// died on its very first statement with ERROR 1064 (AUTOINCREMENT) before it
// applied a single migration. The schema it was about to load was fine; only
// the bookkeeping table was wrong, and it was wrong on every MySQL host.
if ($driver === 'mysql') {
    Db::exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(190) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} else {
    Db::exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        migration TEXT NOT NULL UNIQUE,
        applied_at TEXT NOT NULL
    )");
}

$applied = array_column(Db::all('SELECT migration FROM schema_migrations'), 'migration');

$files = [];
$schemaFile = $root . '/database/schema/' . ($driver === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($schemaFile)) {
    $files['0000_schema'] = $schemaFile;
}
// Migrations may be dialect-specific: `foo.sqlite.sql` applies only to SQLite,
// `foo.mysql.sql` only to MySQL, and a plain `foo.sql` applies to both.
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $f) {
    $base = basename($f, '.sql');
    if (str_ends_with($base, '.sqlite') && $driver !== 'sqlite') {
        continue;
    }
    if (str_ends_with($base, '.mysql') && $driver !== 'mysql') {
        continue;
    }
    $files['1_' . $base] = $f;
}
ksort($files);

$ran = 0;
foreach ($files as $name => $file) {
    if (in_array($name, $applied, true)) {
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(defined('STDERR') ? STDERR : fopen('php://stderr','w'), "Cannot read {$file}\n");
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
            fwrite(defined('STDERR') ? STDERR : fopen('php://stderr','w'), "FAILED in {$name}: {$e->getMessage()}\nSQL: " . substr($clean, 0, 300) . "\n");
            exit(1);
        }
    }
    Db::insert('INSERT INTO schema_migrations (migration, applied_at) VALUES (?,?)', [$name, date('Y-m-d H:i:s')]);
    $ran++;
}

echo $ran === 0 ? "Nothing to migrate.\n" : "Applied {$ran} migration(s).\n";

// Plans are data, but the app cannot run without them: no plan row means no
// price, and a clinic on a plan-less install cannot be invoiced or even show a
// subscription page. They used to require a *second* command
// (`seed.php --plans-only`), which is one instruction too many: the migration
// runner prints "Applied 31 migration(s)" and looks finished, and an install
// that looks finished is where the plans get forgotten.
//
// This writes the same four rows as seed.php --plans-only, idempotently, so it
// is safe on every deploy. It deliberately does not touch clinics, users or
// patients: demo data does not belong in a production migrate.
$planRows = [
    ['starter', 'Starter', 3500, 1, 2, 2, 'Single doctor, one queue, live tracking links', 1],
    ['professional', 'Professional', 7900, 5, 8, 6, 'Multi-doctor, appointments, SMS credits, reports', 2],
    ['clinic_plus', 'Clinic Plus', 14900, 15, 25, 20, 'Priority support, WhatsApp, custom branding, display screens', 3],
    ['enterprise', 'Enterprise', 0, 999, 999, 999, 'Multi-branch, SSO, dedicated onboarding, API access', 4],
];
$discountPct = 20;
$plansWritten = 0;
foreach ($planRows as [$code, $pname, $price, $docs, $staff, $queues, $features, $sort]) {
    // Derived, never typed twice: a hand-entered yearly figure next to a monthly
    // one drifts, and the drift is invisible until somebody is charged wrongly.
    $yearly = round($price * 12 * (1 - $discountPct / 100), 2);
    $existing = Db::scalar('SELECT id FROM plans WHERE code = ?', [$code]);
    if ($existing === null) {
        Db::insert(
            'INSERT INTO plans (code, name, price_monthly, price_yearly, yearly_discount_pct,
                                currency, max_doctors, max_staff, max_queues, features, sort_order, is_active, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)',
            [$code, $pname, $price, $yearly, $discountPct, 'LKR', $docs, $staff, $queues, $features, $sort, Db::now()]
        );
        $plansWritten++;
    } else {
        // Keep an existing install in step when the discount changes, without
        // overwriting an operator's hand-edited caps or features.
        Db::run('UPDATE plans SET price_yearly = ?, yearly_discount_pct = ? WHERE code = ?',
            [$yearly, $discountPct, $code]);
    }
}
$planCount = (int) Db::scalar('SELECT COUNT(*) FROM plans');
echo $plansWritten > 0
    ? "Seeded {$plansWritten} subscription plan(s); {$planCount} now available.\n"
    : "Subscription plans already present ({$planCount}).\n";

