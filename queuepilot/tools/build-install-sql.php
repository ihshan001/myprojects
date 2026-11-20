<?php
declare(strict_types=1);

/**
 * Build install.sql — one file that creates the whole MySQL database.
 *
 * The repository ships its schema as `database/schema/mysql.sql` plus thirty
 * numbered migrations, because that is the only way to upgrade an install that
 * already has data. Somebody installing for the first time should not have to
 * import thirty-one files in the right order and hand-insert thirty-one
 * `schema_migrations` rows — that is thirty-one chances to get it wrong, and the
 * failure mode is a half-built database that boots and then throws.
 *
 * This concatenates them into one reviewable file, in the order the migrate
 * runner applies them, and writes the `schema_migrations` bookkeeping at the
 * end. The result is what a `phpMyAdmin → Import` should get.
 *
 *   php tools/build-install-sql.php            # write database/install.sql
 *   php tools/build-install-sql.php --check    # fail if it is out of date
 *
 * `--check` is what keeps it honest: it is in the test suite, so the moment a
 * migration is added without rebuilding, the suite fails instead of shipping a
 * stale install file that silently omits a table.
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use App\Support\Argv;
use App\Core\Db;

$out = $root . '/database/install.sql';

/* ------------------------------------------------------- what goes in it */

$parts = [];
$applied = ['0000_schema'];

$schema = $root . '/database/schema/mysql.sql';
if (!is_file($schema)) {
    echo "Cannot find database/schema/mysql.sql\n";
    exit(1);
}
$parts[] = ['0000_schema', (string) file_get_contents($schema)];

// Exactly the order tools/migrate.php uses: plain `foo.sql` applies everywhere,
// `foo.mysql.sql` only to MySQL. This file is MySQL-only, so sqlite halves are
// skipped.
$migrations = [];
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
    $base = basename($file);
    if (str_ends_with($base, '.sqlite.sql')) {
        continue;
    }
    $migrations[$base] = $file;
}
ksort($migrations, SORT_STRING);

foreach ($migrations as $base => $path) {
    $name = '1_' . preg_replace('/\.mysql\.sql$|\.sql$/', '', $base) . '.mysql';
    $parts[] = [$name, (string) file_get_contents($path)];
    $applied[] = $name;
}

/* ------------------------------------------------------------- the plans */

// Plans are data, not schema, but the application cannot run without them: no
// plan means no price, and a clinic cannot be billed or approved. seed.php
// --plans-only creates the same four rows; this is the same data expressed as
// SQL so a single import produces a working install.
// These must stay identical to tools/seed.php: a host installed from this file
// and a host installed by `seed.php --plans-only` must end up with the same four
// rows, or the admin plan list looks different depending on how you installed.
// sort_order is 1..4 there, and AdminController orders the plan list by it.
$yearlyDiscountPct = 20;
$planRows = [
    ['starter', 'Starter', 3500, 1, 2, 2, 'Single doctor, one queue, live tracking links', 1],
    ['professional', 'Professional', 7900, 5, 8, 6, 'Multi-doctor, appointments, SMS credits, reports', 2],
    ['clinic_plus', 'Clinic Plus', 14900, 15, 25, 20, 'Priority support, WhatsApp, custom branding, display screens', 3],
    ['enterprise', 'Enterprise', 0, 999, 999, 999, 'Multi-branch, SSO, dedicated onboarding, API access', 4],
];
// Yearly is derived, never typed twice. Two hand-entered numbers drift apart,
// and the drift is invisible until a clinic is charged the wrong amount once a
// year. seed.php derives it the same way.
foreach ($planRows as $i => $row) {
    $planRows[$i][] = round($row[2] * 12 * (1 - $yearlyDiscountPct / 100), 2);
}

$planSql = "-- ---------------------------------------------------------------- plans\n"
    . "-- The four subscription plans. Yearly prices are the monthly rate times\n"
    . "-- twelve less 20%, which is the discount the pricing page advertises.\n"
    . "-- 'enterprise' is priced on application, so 0 is deliberate.\n"
    . "INSERT INTO `plans`\n"
    . "  (`code`, `name`, `price_monthly`, `currency`, `max_doctors`, `max_staff`,\n"
    . "   `max_queues`, `features`, `sort_order`, `is_active`, `price_yearly`,\n"
    . "   `yearly_discount_pct`, `created_at`)\nVALUES\n";

$rows = [];
foreach ($planRows as [$code, $name, $monthly, $docs, $staff, $queues, $features, $sort, $yearly]) {
    $rows[] = sprintf(
        "  ('%s', '%s', %d, 'LKR', %d, %d, %d, '%s', %d, 1, %d, 20, NOW())",
        $code,
        str_replace("'", "''", $name),
        $monthly,
        $docs,
        $staff,
        $queues,
        str_replace("'", "''", $features),
        $sort,
        $yearly
    );
}
$planSql .= implode(",\n", $rows) . "\n"
    . "ON DUPLICATE KEY UPDATE\n"
    . "  `name` = VALUES(`name`), `price_monthly` = VALUES(`price_monthly`),\n"
    . "  `price_yearly` = VALUES(`price_yearly`), `max_doctors` = VALUES(`max_doctors`),\n"
    . "  `max_staff` = VALUES(`max_staff`), `max_queues` = VALUES(`max_queues`),\n"
    . "  `features` = VALUES(`features`), `sort_order` = VALUES(`sort_order`);\n";

/* ------------------------------------------------------ schema_migrations */

$bookkeeping = "-- --------------------------------------------------- migration bookkeeping\n"
    . "-- tools/migrate.php reads this to decide what is already applied. Without\n"
    . "-- these rows it re-applies every migration on the next deploy and dies on\n"
    . "-- the first duplicate column.\n"
    . "CREATE TABLE IF NOT EXISTS `schema_migrations` (\n"
    . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
    . "  `migration` VARCHAR(191) NOT NULL,\n"
    . "  `applied_at` DATETIME NOT NULL,\n"
    . "  PRIMARY KEY (`id`),\n"
    . "  UNIQUE KEY `ux_schema_migrations` (`migration`)\n"
    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";

$values = [];
foreach ($applied as $name) {
    $values[] = "  ('" . $name . "', NOW())";
}
$bookkeeping .= "INSERT INTO `schema_migrations` (`migration`, `applied_at`) VALUES\n"
    . implode(",\n", $values) . "\n"
    . "ON DUPLICATE KEY UPDATE `migration` = VALUES(`migration`);\n";

/* ----------------------------------------------------------- assemble it */

$schemaCount = count($parts);

$header = <<<SQL
-- ============================================================================
--  QueuePilot — complete MySQL schema and seed data
-- ============================================================================
--
--  ONE FILE. Import it into an empty database and the application runs.
--
--      mysql -u USER -p DATABASE_NAME < database/install.sql
--
--  or phpMyAdmin -> select the database -> Import -> choose this file -> Go.
--
--  What it does, in order:
--    1. Creates the base schema (16 tables)
--    2. Applies all 30 migrations (ALTERs + the other 36 tables)
--    3. Inserts the four subscription plans
--    4. Records what was applied, so tools/migrate.php will not re-run it
--
--  Result: 52 tables. It creates NO clinic, NO patient and NO user — an
--  install with demo data in it is not a production install. Create your
--  platform admin afterwards with:
--
--      php tools/make-admin.php --email=you@example.com
--
--  Requirements: MySQL 5.7+ / MariaDB 10.3+, utf8mb4, InnoDB.
--  Not meant to be re-run on a populated database. Every CREATE TABLE is
--  IF NOT EXISTS and every INSERT is idempotent, but CREATE INDEX and ALTER
--  TABLE have no such guard in MySQL (CREATE INDEX ... IF NOT EXISTS only
--  arrived in MySQL 8.0.29 and never reached MariaDB) — so a second run stops
--  at the first duplicate index or column. That is the correct outcome: it
--  fails immediately instead of half-applying over live data.
--
--  To build a second copy, import into an empty database.
--
--  GENERATED FILE. Built by tools/build-install-sql.php from the schema and
--  migrations. Do not edit; edit the migration and rebuild.
--
--  Built from: {$schemaCount} source files
-- ============================================================================

SET NAMES utf8mb4;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

SQL;

$body = '';
foreach ($parts as [$name, $sql]) {
    $body .= "\n-- ==========================================================================\n"
        . "--  {$name}\n"
        . "-- ==========================================================================\n\n";
    $body .= trim($sql) . "\n";
}

$footer = <<<SQL


-- ============================================================================
--  Done. Verify:
--
--    SELECT COUNT(*) FROM information_schema.tables
--     WHERE table_schema = DATABASE();     -- expect 52
--    SELECT COUNT(*) FROM plans;           -- expect 4
--    SELECT COUNT(*) FROM schema_migrations;  -- expect 31
--
--  Then create the platform admin:
--    php tools/make-admin.php --email=you@example.com
--
--  And check the host is configured correctly:
--    php tools/host-drill.php
-- ============================================================================

SET SQL_MODE = @OLD_SQL_MODE;
SQL;

$file = $header . $body . $planSql . "\n" . $bookkeeping . $footer;

/* --------------------------------------------------------------- write it */

if (Argv::has('--check')) {
    $current = is_file($out) ? (string) file_get_contents($out) : '';
    if ($current !== $file) {
        echo "\033[31mFAIL\033[0m database/install.sql is out of date.\n";
        echo "      Rebuild it: php tools/build-install-sql.php\n";
        echo "      (a migration was added or changed without regenerating)\n";
        exit(1);
    }
    echo "\033[32mOK\033[0m database/install.sql matches the schema and migrations.\n";
    exit(0);
}

file_put_contents($out, $file);

$tables = 16;
echo "Wrote database/install.sql\n";
echo "  " . number_format(strlen($file)) . " bytes, " . number_format(substr_count($file, "\n") + 1) . " lines\n";
echo "  " . count($parts) . " source files (base schema + " . (count($parts) - 1) . " migrations)\n";
echo "  " . count($planRows) . " plans, " . count($applied) . " schema_migrations rows\n";
echo "\nVerify it parses:  python3 tools/sql-validate.py database/install.sql\n";
