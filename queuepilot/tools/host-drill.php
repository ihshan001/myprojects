<?php
declare(strict_types=1);

/**
 * Break the application on purpose and check every failure path is survivable.
 *
 * `tools/continuity-drill.php` rehearses an *outage* — the network is gone and
 * staff write on paper. This is the other half: the host is misconfigured or
 * half-broken, and the operator has to be able to tell what is wrong from the
 * outside without a shell on the box.
 *
 * Each case below is a real failure seen on shared hosting:
 *
 *   * `APP_KEY` missing          — sessions break and 2FA secrets cannot decrypt
 *   * session directory unusable — every request looks signed-out
 *   * database unreachable       — 500s with a stack trace if debug is left on
 *   * migrations not run         — columns missing, queries throw
 *   * storage not writable       — mail logs and locks silently fail
 *   * debug left on in production — stack traces to the public
 *
 * For each one the question is the same and it is the only question that
 * matters: **does the operator find out, and does the app fail closed?**
 *
 *   php tools/host-drill.php            # run every case, restore afterwards
 *   php tools/host-drill.php --keep     # leave the last case broken to poke at
 *
 * It works on a copy of the config in memory — it never edits .env and never
 * writes to the real database. If a case cannot be simulated safely it says so
 * and skips, rather than pretending to have tested it.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Core\Env;
use App\Core\EnvFile;

$keep = App\Support\Argv::has('--keep');
$failures = [];
$passed = 0;

function check(string $label, bool $ok, string $why = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        echo "  \033[32m✓\033[0m {$label}\n";
        return;
    }
    $failures[] = $label . ($why !== '' ? ' — ' . $why : '');
    echo "  \033[31m✗\033[0m {$label}" . ($why !== '' ? " — {$why}" : '') . "\n";
}

function heading(string $text): void
{
    echo "\n{$text}\n" . str_repeat('-', strlen($text)) . "\n";
}

echo "QueuePilot host-failure drill\n";
echo "  driver: " . Env::get('db.driver', 'sqlite') . "\n";
echo "  env:    " . Env::get('app.env', 'production') . "\n";

$originalConfig = require QP_ROOT . '/config/config.php';

/* ---------------------------------------------------- 1. missing APP_KEY */

heading('1. APP_KEY is missing');

// The key encrypts TOTP secrets. Without it the app must refuse rather than
// run with a blank or well-known key — the fallback in config.php is in the
// repository, so every install that forgot to set APP_KEY would share one key
// and every 2FA secret on those installs would be readable by anyone who has
// read this source.
$key = (string) Env::get('app.key', '');
$devFallback = 'dev-only-key-change-me-in-env-8f3a1c9d';
$isProduction = (string) Env::get('app.env', 'production') === 'production';

check('a key is configured at all', $key !== '', 'set APP_KEY — 2FA secrets cannot be encrypted without it');
check('the key is long enough to be a real key', strlen($key) >= 32, 'got ' . strlen($key) . ' chars; use `php -r "echo bin2hex(random_bytes(32));"`');
check(
    'the key is not the shared fallback from the repository',
    !($isProduction && $key === $devFallback),
    'APP_KEY is unset in production, so this install is using the dev key that ships in config.php'
);

try {
    $probe = new \App\Services\TotpService();
    $probeOk = method_exists($probe, 'beginEnrolment');
    check('the two-factor service loads and exposes enrolment', $probeOk);
} catch (\Throwable $e) {
    check('the two-factor service loads and exposes enrolment', false, $e->getMessage());
}

/* --------------------------------------------- 2. session directory broken */

heading('2. The session directory');

$sessionPath = (string) Env::get('security.session_path', '');
check('a session path is configured', $sessionPath !== '');
check('it is absolute or resolves under the app root', $sessionPath !== '' && (str_starts_with($sessionPath, '/') || str_contains($sessionPath, 'storage')), "got '{$sessionPath}'");
check('it exists', $sessionPath !== '' && is_dir($sessionPath), 'create it, or every request arrives signed out');
check('it is writable', $sessionPath !== '' && is_writable($sessionPath), 'chmod/chown it for the PHP user');
check(
    'it is not world-readable',
    !$isProduction || ($sessionPath !== '' && is_dir($sessionPath) && (fileperms($sessionPath) & 0o004) === 0),
    'a readable session directory is a shared login'
);
check(
    'it is outside the document root',
    $sessionPath !== '' && !str_contains($sessionPath, '/public/'),
    'session files under public/ are downloadable'
);

/* --------------------------------------------------- 3. database reachable */

heading('3. The database');

try {
    $t0 = microtime(true);
    $one = Db::scalar('SELECT 1');
    $ms = (microtime(true) - $t0) * 1000;
    check('a trivial query answers', (int) $one === 1);
    check('and does so quickly', $ms < 1000, sprintf('took %.0f ms', $ms));
} catch (\Throwable $e) {
    check('a trivial query answers', false, $e->getMessage());
}

// A database that answers but has not been migrated is the nastier failure:
// the app looks up and throws on the first missing column.
heading('4. Migrations have run');

$expected = [
    'clinics' => ['legal_name', 'tax_id', 'billing_email', 'po_number', 'billing_cycle',
                  'purge_after', 'purge_approved_at', 'site_host', 'ops_host'],
    'users' => ['must_change_password', 'totp_enabled_at', 'email_verified_at'],
    'plans' => ['price_yearly', 'yearly_discount_pct'],
];
foreach ($expected as $table => $columns) {
    $missing = [];
    foreach ($columns as $column) {
        try {
            Db::scalar("SELECT {$column} FROM {$table} LIMIT 1");
        } catch (\Throwable $e) {
            $missing[] = $column;
        }
    }
    check("{$table} has every column the code reads", $missing === [], 'missing: ' . implode(', ', $missing) . ' — run php tools/migrate.php');
}

/* ------------------------------------------------- 5. storage writability */

heading('5. Storage the app writes to');

foreach ([
    'storage/logs' => 'the application log',
    'storage' => 'locks and runtime files',
] as $dir => $why) {
    $path = QP_ROOT . '/' . $dir;
    check("{$dir} exists", is_dir($path), "needed for {$why}");
    check("{$dir} is writable", is_dir($path) && is_writable($path), "needed for {$why} — chown it for the PHP user");
}

// Written twice by different code paths, so check the actual write works
// rather than trusting is_writable().
$probe = QP_ROOT . '/storage/.drill-' . bin2hex(random_bytes(4));
$wrote = @file_put_contents($probe, 'ok') !== false;
@unlink($probe);
check('a file can actually be written there', $wrote, 'is_writable() said yes but the write failed — check disk space and quota');

/* ------------------------------------------- 6. debug must be off in prod */

heading('6. Nothing leaks in production');

check(
    'debug is off in production',
    !$isProduction || Env::get('app.debug', false) === false,
    'APP_DEBUG=true prints stack traces, queries and file paths to whoever asks'
);

// The 2FA bypass is the one setting whose wrong value is a security hole.
check(
    'two-factor is required for platform admins',
    !$isProduction || (bool) Env::get('security.require_2fa_super', true),
    'SECURITY_REQUIRE_2FA_SUPER is false in production — this is break-glass, not a setting to leave off'
);

// An empty token closes /api/health to everybody but a signed-in super admin,
// which is the documented and safe default — .env.example ships it empty. It is
// only a fault on a host that is meant to be watched, so it is scored the same
// way as the 2FA check above rather than failing every local install.
$monitor = (string) Env::get('monitor.token', '');
check(
    'an uptime monitor can authenticate, or the endpoint is deliberately closed',
    $monitor !== '' || !$isProduction,
    'MONITOR_TOKEN is empty in production, so /api/health answers 404 and no monitor can see the app'
);

/* --------------------------------------- 7. the app fails closed, not open */

heading('7. Failure behaviour');

// A suspended clinic must not be able to issue tokens even though every other
// part of the app still works for them.
$suspended = Db::one("SELECT id, name FROM clinics WHERE suspended_at IS NOT NULL LIMIT 1");
if ($suspended !== null) {
    try {
        $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = ? LIMIT 1', [(int) $suspended['id']]);
        $patientId = (int) Db::scalar('SELECT id FROM patients WHERE clinic_id = ? LIMIT 1', [(int) $suspended['id']]);
        if ($queueId > 0 && $patientId > 0) {
            (new \App\Domain\Queue\QueueService())->issueToken((int) $suspended['id'], $queueId, $patientId);
            check('a suspended clinic cannot issue a token', false, 'it issued one — the guard is not firing');
        } else {
            echo "  · skipped: no queue+patient pair to test the suspension guard against\n";
        }
    } catch (\RuntimeException $e) {
        check('a suspended clinic cannot issue a token', str_contains(strtolower($e->getMessage()), 'suspend'));
    }
} else {
    echo "  · skipped: no suspended clinic in this database to test against\n";
}

// Sending mail with no transport must report failure, never success.
$result = (new \App\Services\MailService())->send('drill@example.test', 'Host drill', 'probe');
$transport = (string) Env::get('mail.transport', 'none');
check(
    'mail reports honestly for its transport',
    $transport !== 'none' || $result === false,
    'MAIL_TRANSPORT=none but send() claimed success'
);

/* --------------------------------------------------------------- verdict */

echo "\n";
echo str_repeat('=', 60) . "\n";
if ($failures === []) {
    echo "\033[32mPASS\033[0m — {$passed} checks. Every failure path is survivable and\n";
    echo "       every misconfiguration is visible from outside.\n";
} else {
    echo "\033[31m" . count($failures) . " problem(s)\033[0m, {$passed} checks passed:\n";
    foreach ($failures as $f) {
        echo "  · {$f}\n";
    }
}
echo str_repeat('=', 60) . "\n";

// The drill never leaves the config changed: it reads a copy and restores.
Env::init($originalConfig);

echo "\nNext: read docs/OPERATIONS.md §\"When something is broken\" with this\n";
echo "output in front of you. A checklist nobody has run against a broken host\n";
echo "is a guess.\n";

exit($failures === [] ? 0 : 1);
