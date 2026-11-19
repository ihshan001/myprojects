<?php
declare(strict_types=1);

/**
 * Check that each migration is written in the dialect it claims to be.
 *
 * Every migration ships as a pair: `NNNN_name.sqlite.sql` and
 * `NNNN_name.mysql.sql`. SQLite is what the test suite and the dev box run;
 * MySQL is what the clinic's host runs. Nothing in the test suite ever executes
 * the MySQL half — there is no MySQL server in CI — so a MySQL migration can be
 * textually a copy of the SQLite one and nobody finds out until it is applied
 * to a paying clinic's database at 08:50 on a Monday.
 *
 * That is exactly what had happened: `0015_session_start.mysql.sql` used
 * SQLite's `datetime('now')`, which MySQL does not have. It would have thrown
 * on the one host we cannot roll back easily.
 *
 * This is a linter, not a database. It catches the substitutions people
 * actually make when copying a migration across:
 *
 *   SQLite-isms in a .mysql.sql   datetime('now'), AUTOINCREMENT,
 *                                 INSERT OR IGNORE/REPLACE, PRAGMA, ||
 *   MySQL-isms in a .sqlite.sql   AUTO_INCREMENT, ENGINE=, UNSIGNED,
 *                                 VARCHAR(n), NOW(), IFNULL()
 *
 * It cannot prove a migration will run. It stops the copy-paste class of bug,
 * which is the one that happens. Only a real MySQL host proves the rest — see
 * docs/RUNBOOK.md §"Before the first clinic".
 *
 *   php tools/dialect-check.php            # check, exit 1 on a problem
 *   php tools/dialect-check.php --verbose  # list every file as it is checked
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$verbose = App\Support\Argv::has('--verbose');
$dir = dirname(__DIR__) . '/database/migrations';
$problems = [];
$files = 0;

/** @var array<string, list<string>> dialect => [pattern => explanation] */
$sqliteOnly = [
    // Both quote styles, and the no-argument form. PHP strings make
    // datetime("now") as easy to write as datetime('now'), and it has the same
    // effect: a fatal error on MySQL.
    "/\bdatetime\s*\(\s*['\"]now['\"]\s*\)/i" => "datetime('now') is SQLite; MySQL uses NOW() or CURRENT_TIMESTAMP",
    '/\bjulianday\s*\(/i' => 'julianday() is SQLite-only; compute the difference in PHP',
    '/\bunixepoch\s*\(/i' => 'unixepoch() is SQLite-only',
    '/\bdate\s*\(\s*\?\s*,/i' => "date(x, 'modifier') is SQLite-only syntax",
    // The exact form that shipped on two pages: date('now', '-7 day').
    "/\bdate\s*\(\s*['\"]now['\"]\s*,/i" => "date('now', modifier) is SQLite-only; bind a PHP date instead",
    // MySQL 5.7 (a documented requirement) has no CAST ... AS REAL.
    '/\bCAST\s*\([^)]*\bAS\s+REAL\b/i' => 'CAST(x AS REAL) needs MySQL 8.0.17; use DOUBLE',
    '/\bAUTOINCREMENT\b/i' => 'AUTOINCREMENT is SQLite; MySQL uses AUTO_INCREMENT',
    '/\bINSERT\s+OR\s+(IGNORE|REPLACE)\b/i' => 'INSERT OR IGNORE/REPLACE is SQLite; MySQL uses ON DUPLICATE KEY UPDATE',
    '/\bPRAGMA\b/i' => 'PRAGMA is SQLite-only',
    '/\bWITHOUT\s+ROWID\b/i' => 'WITHOUT ROWID is SQLite-only',
    // SQL `GLOB` the operator only. PHP's glob() and $GLOBALS are everywhere in
    // this codebase and are obviously not SQLite syntax, so a bare \bGLOB\b
    // matches half the tools directory.
    '/\bGLOB\s+[\w\.\*\?\[]/i' => 'GLOB is a SQLite-only operator',
    '/\bstrftime\s*\(/i' => 'strftime() is SQLite-only',
    '/\bIFNULL\s*\(/i' => 'IFNULL works in both, but prefer COALESCE',
];

$mysqlOnly = [
    '/\bAUTO_INCREMENT\b/i' => 'AUTO_INCREMENT is MySQL; the SQLite schema uses INTEGER PRIMARY KEY AUTOINCREMENT',
    '/\bENGINE\s*=/i' => 'ENGINE= is MySQL table syntax',
    '/\bUNSIGNED\b/i' => 'UNSIGNED is MySQL-only',
    // DATETIME and VARCHAR(n) are *not* errors: SQLite accepts any type name as
    // an affinity, and the existing schema uses both deliberately so the two
    // dialects stay readable side by side.
    '/\bNOW\s*\(\s*\)/i' => 'NOW() is MySQL; SQLite uses datetime(\'now\')',
    '/\bTINYINT\b|\bMEDIUMINT\b|\bBIGINT\b/i' => 'MySQL integer widths are SQLite-only noise',
    '/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i' => 'MySQL column attribute; SQLite has no equivalent',
    '/\bAUTO_INCREMENT\b/i' => 'MySQL-only',
];

function scan(string $path, array $rules, string $dialect): array
{
    $sql = (string) file_get_contents($path);
    $hits = [];
    // Strip comments: a migration's prose legitimately mentions the other
    // dialect ("SQLite cannot do this, so MySQL uses X") and that is not a bug.
    $code = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;
    $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
    foreach ($rules as $pattern => $why) {
        if (preg_match($pattern, $code)) {
            $hits[] = $why;
        }
    }
    return $hits;
}

$entries = glob($dir . '/*.sql') ?: [];
sort($entries);

// The base schema pair is checked too. It was not, and that is where the
// largest block of SQL lives: database/schema/{sqlite,mysql}.sql creates the
// first sixteen tables, and a SQLite-ism there breaks every install rather
// than one upgrade.
foreach (['sqlite', 'mysql'] as $dialect) {
    $schemaPath = dirname(__DIR__) . '/database/schema/' . $dialect . '.sql';
    if (!is_file($schemaPath)) {
        $problems[] = "database/schema/{$dialect}.sql is missing";
        continue;
    }
    $files++;
    if ($verbose) {
        echo "  checking database/schema/{$dialect}.sql\n";
    }
    $hits = $dialect === 'mysql'
        ? scan($schemaPath, $sqliteOnly, 'mysql')
        : scan($schemaPath, $mysqlOnly, 'sqlite');
    foreach ($hits as $why) {
        $problems[] = "schema/{$dialect}.sql: " . $why;
    }
}

/*
 * PHP source, not just .sql files.
 *
 * The migration files were covered from the start, but the app's own queries
 * were not — and that is where the worst dialect bug in the project lived.
 * Three SQLite-only constructs sat in production code:
 *
 *   LifecycleService::awaitingDecision()  julianday()  -> the admin decision
 *                                                     queue threw a fatal
 *                                                     error on MySQL
 *   ReportService (x2)                    julianday()  -> the reports page
 *                                                     threw on MySQL
 *   FeatureService (x4)               datetime("now")  -> toggling a feature
 *                                                     threw on MySQL
 *
 * All four read perfectly and passed the entire suite, because the suite runs
 * on SQLite. The product ships on MySQL. So the PHP that talks to the database
 * is scanned with the MySQL rule set: any SQLite-only function reachable from
 * application code is a defect, because production is never SQLite.
 *
 * Files that legitimately contain both dialects (the migration runner picks its
 * DDL by driver) opt out with an explicit marker on the line.
 */
$phpRoots = ['app', 'tools'];
$phpFiles = 0;
foreach ($phpRoots as $root) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $root, FilesystemIterator::SKIP_DOTS)
    );
    /** @var SplFileInfo $file */
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (str_ends_with($path, 'dialect-check.php')) {
            continue; // this file defines the patterns
        }
        $phpFiles++;
        $raw = (string) file_get_contents($path);

        // The opt-out marker lives in a comment, so it must be read from the
        // raw source before the comment stripper removes it.
        $dualDialect = str_contains($raw, '@dialect-check: dual-dialect-guarded');

        // Strip PHP comments so prose about SQLite does not trip the linter.
        $code = preg_replace('#/\*.*?\*/#s', '', $raw) ?? $raw;
        $code = preg_replace('#(^|\s)//[^\n]*#', ' ', $code) ?? $code;
        $code = preg_replace('/#\s[^\n]*/', ' ', $code) ?? $code;

        $hits = [];
        foreach ($sqliteOnly as $pattern => $why) {
            if (preg_match($pattern, $code)) {
                $hits[] = $why;
            }
        }
        // A file that legitimately carries both dialects — the connection layer
        // applying SQLite PRAGMAs, the backup tool validating a SQLite scratch
        // copy — declares it, and its hits are not errors. The marker has to be
        // explicit per file so nothing opts out by accident.
        if ($dualDialect) {
            $hits = [];
        }
        // PRAGMA in a PHP string is usually the SQLite bootstrap; flag it too
        // unless the file declares itself dual-dialect.
        $rel = str_replace(dirname(__DIR__) . '/', '', $path);
        foreach ($hits as $why) {
            if (str_contains($why, 'prefer COALESCE')) {
                continue; // IFNULL is valid in both; not a defect
            }
            $problems[] = "{$rel}: {$why} — this runs on MySQL, not SQLite";
        }
    }
}

$byName = [];
foreach ($entries as $path) {
    $base = basename($path);
    if (preg_match('/^(\d{4}[a-z]?_[a-z0-9_]+)\.(sqlite|mysql)\.sql$/', $base, $m)) {
        $byName[$m[1]][$m[2]] = $path;
    } else {
        $problems[] = "{$base}: filename does not follow NNNN_name.{sqlite,mysql}.sql";
    }
}

foreach ($byName as $name => $pair) {
    foreach (['sqlite', 'mysql'] as $required) {
        if (!isset($pair[$required])) {
            $problems[] = "{$name}: missing the .{$required}.sql half — one dialect will silently disagree with the other";
        }
    }
    foreach ($pair as $dialect => $path) {
        $files++;
        if ($verbose) {
            echo "  checking " . basename($path) . "\n";
        }
        $hits = $dialect === 'mysql'
            ? scan($path, $sqliteOnly, 'mysql')
            : scan($path, $mysqlOnly, 'sqlite');
        foreach ($hits as $why) {
            $problems[] = basename($path) . ': ' . $why;
        }
    }
}

echo "Dialect check — {$files} SQL files (" . count($byName) . " migration pairs + the schema pair)"
    . " and {$phpFiles} PHP files of application SQL\n";
echo str_repeat('-', 60) . "\n";

if ($problems === []) {
    echo "\033[32mPASS\033[0m — every migration is written in the dialect it names.\n";
    echo "\nThis proves spelling, not behaviour. The first time each migration\n";
    echo "actually executes against MySQL is on the host: see\n";
    echo "docs/RUNBOOK.md §\"Before the first clinic\".\n";
    exit(0);
}

echo "\033[31m" . count($problems) . " problem(s)\033[0m:\n";
foreach ($problems as $p) {
    echo "  · {$p}\n";
}
echo "\nThese only fail on the target database, so they would not show up in\n";
echo "any other check in this repository.\n";
exit(1);
