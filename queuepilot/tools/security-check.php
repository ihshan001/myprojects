<?php
declare(strict_types=1);

/**
 * Security posture — the checks a person would otherwise do by memory.
 *
 * This is deliberately **not** a penetration test and does not pretend to be
 * one. It is the small set of facts that must be true of the running
 * application and that are cheap to verify mechanically: dependency
 * advisories, dangerous PHP settings, secrets leaking into the repository,
 * debug mode in production, and the unauthenticated surface actually
 * reachable.
 *
 * Usage:
 *   node tools/php.mjs tools/security-check.php            # report
 *   node tools/php.mjs tools/security-check.php --strict   # exit 1 on any failure
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Core\Env;
use App\Core\Router;

$root = dirname(__DIR__);
$strict = in_array('--strict', $_SERVER['argv'] ?? [], true);
$failures = 0;
$warnings = 0;

function heading(string $text): void
{
    echo "\n\033[1m" . $text . "\033[0m\n";
}

function pass(string $text): void
{
    echo "  \033[32m✓\033[0m " . $text . "\n";
}

function warn(string $text): void
{
    global $warnings;
    $warnings++;
    echo "  \033[33m!\033[0m " . $text . "\n";
}

function fail(string $text): void
{
    global $failures;
    $failures++;
    echo "  \033[31m✗\033[0m " . $text . "\n";
}

/* ------------------------------------------------------------------ */
/* 1. Dependency advisories                                            */
/* ------------------------------------------------------------------ */

heading('1. Dependency advisories (npm audit)');

// shell_exec() does not exist under php-wasm, which is what this project
// runs on. tools/security-check.mjs gathers the facts that need a shell and
// hands them here as JSON; every judgement below is still made in PHP.
$factsFile = $root . '/storage/security-facts.json';
$facts = is_file($factsFile) ? json_decode((string) file_get_contents($factsFile), true) : null;
$audit = is_array($facts) ? ($facts['audit'] ?? null) : null;
$envTracked = (bool) ($facts['env_tracked'] ?? false);

if (!is_array($audit) || !isset($audit['metadata']['vulnerabilities'])) {
    warn('npm audit could not be run in this environment — dependency status unknown, not "clean".');
} else {
    $v = $audit['metadata']['vulnerabilities'];
    $totalDeps = (int) ($audit['metadata']['dependencies']['total'] ?? 0);
    $line = sprintf(
        '%d dependencies scanned: %d critical, %d high, %d moderate, %d low',
        $totalDeps, (int) $v['critical'], (int) $v['high'], (int) $v['moderate'], (int) $v['low']
    );
    if ((int) $v['critical'] > 0 || (int) $v['high'] > 0) {
        fail($line);
    } elseif ((int) $v['moderate'] > 0 || (int) $v['low'] > 0) {
        warn($line);
    } else {
        pass($line);
    }

    // An advisory list is only as good as the lockfile behind it.
    if (!is_file($root . '/package-lock.json')) {
        fail('No package-lock.json: "no advisories" would be meaningless without a pinned tree.');
    } else {
        pass('package-lock.json pins the tree the audit ran against');
    }
}

/* ------------------------------------------------------------------ */
/* 2. PHP runtime settings that turn a bug into a breach                */
/* ------------------------------------------------------------------ */

heading('2. Runtime settings');

$displayErrors = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL);
$debug = filter_var(Env::get('app.debug', false), FILTER_VALIDATE_BOOL);
if ($debug && $displayErrors) {
    fail('app.debug and display_errors are both on: stack traces and file paths can reach a patient’s browser.');
} elseif ($debug) {
    warn('app.debug is on (fine for this demo, must be off in production — details in docs/OPERATIONS.md).');
} else {
    pass('Debug mode is off.');
}

foreach ([
    'allow_url_include' => 'Remote code inclusion is possible.',
    'expose_php'        => 'The PHP version is advertised in every response header.',
] as $setting => $why) {
    $value = filter_var(ini_get($setting), FILTER_VALIDATE_BOOL);
    if ($setting === 'expose_php' ? $value : $value) {
        // php-wasm cannot change these; report them as environment advice.
        warn($setting . ' is on — ' . $why);
    } else {
        pass($setting . ' is off');
    }
}

$sessionCookie = (string) ini_get('session.cookie_httponly');
$sessionCookie ? pass('Session cookies are HttpOnly') : warn('session.cookie_httponly is off — a script could read the session cookie.');

/* ------------------------------------------------------------------ */
/* 3. Secrets must not be in the repository                            */
/* ------------------------------------------------------------------ */

heading('3. Secrets in the working tree');

$gitignore = is_file($root . '/.gitignore') ? (string) file_get_contents($root . '/.gitignore') : '';
if (preg_match('/^\.env$/m', $gitignore) === 1) {
    pass('.env is git-ignored');
} else {
    fail('.env is NOT git-ignored — the first commit that includes it publishes every secret.');
}

if ($envTracked) {
    fail('.env is tracked by git. Rotate every credential in it.');
} else {
    pass('.env is not tracked');
}

// The running install's own key. Everything else in this file is a
// configuration detail; this one decides whether a password hash, a session
// cookie or a signed link can be forged by anyone who has read the source.
//
// The check is a FAILURE in production and a warning in development, because
// the fallback key is committed and therefore public: an install that ships
// it to a real clinic can be impersonated by anyone who has seen the
// repository.
$liveKey = (string) (getenv('APP_KEY') ?: (Env::get('app.key') ?? ''));
$keyIsFallback = $liveKey === '' || str_starts_with($liveKey, 'dev-only-key-change-me');
$appEnv = strtolower((string) (Env::get('app.env') ?? 'production'));
if ($keyIsFallback) {
    if ($appEnv === 'production') {
        fail('APP_KEY is unset or still the committed development placeholder. Generate one: php -r "echo bin2hex(random_bytes(32));"');
    } else {
        warn('APP_KEY is the committed development placeholder (fine for APP_ENV=development, fatal in production).');
    }
} elseif (strlen($liveKey) < 32) {
    warn('APP_KEY is shorter than 32 characters — use 32+ random bytes, hex encoded.');
} else {
    pass('APP_KEY is set and is not the committed placeholder');
}

// A committed example file must not carry a real key.
$example = is_file($root . '/.env.example') ? (string) file_get_contents($root . '/.env.example') : '';
if (preg_match('/^APP_KEY=([^\s]+)/m', $example, $m) === 1 && strlen(trim($m[1])) > 16) {
    warn('.env.example carries what looks like a real APP_KEY. It should be a placeholder.');
} elseif ($example !== '') {
    pass('.env.example carries no real key');
}

/* ------------------------------------------------------------------ */
/* 4. Unauthenticated surface                                          */
/* ------------------------------------------------------------------ */

heading('4. Routes reachable without signing in');

// Read the route file as source rather than asking the router: this audits
// what is actually declared, including a route that forgot its auth flag,
// without adding a public accessor just for the checker.
$routeSource = (string) file_get_contents($root . '/routes/web.php');
$public = [];
$mutating = [];
$total = 0;
foreach (token_get_all($routeSource) as $i => $token) {
    // handled below via regex on the source, which is simpler and exact enough
}
if (preg_match_all(
    "/Router::(get|post)\(\s*'([^']+)'\s*,\s*([^;]+?)\);/s",
    $routeSource,
    $matches,
    PREG_SET_ORDER
) > 0) {
    $total = count($matches);
    foreach ($matches as $m) {
        $method = strtoupper($m[1]);
        $path = $m[2];
        $tail = $m[3];
        $guarded = str_contains($tail, "'auth' => true");
        if (!$guarded) {
            $line = $method . ' ' . $path;
            $public[] = $line;
            if ($method === 'POST') {
                $mutating[] = $line;
            }
        }
    }
}

echo '  ' . $total . " routes declared; " . count($public) . " reachable without signing in ("
    . count($mutating) . " of them POST).\n";
foreach ($public as $route) {
    echo '    · ' . $route . "\n";
}

// A public POST is not automatically wrong — login, register, booking and the
// patient's own cancel link all have to be reachable — but every one must be
// in the list above deliberately. Flag only the ones that touch clinic data.
// Each of these is reachable precisely because the person using it is not
// signed in yet: signing in (including the second factor), creating an
// account, booking, the patient's own cancel and reschedule links, the signed
// booking link, password recovery, the invitation that creates the account,
// and the two public health/service endpoints. A POST appearing here that is
// not deliberate is the finding.
//
// The three patient-owned routes are safe to expose for the same reason and
// are worth naming: the credential is either a 128-bit queue token or an
// HMAC over the appointment id and its current time. Neither can be guessed,
// neither can be re-pointed at another patient, and the signed link stops
// working the moment the booking moves. Rate limits are per credential, so a
// shared phone cannot exhaust another patient's allowance.
$expectedPublicPosts = [
    '/login', '/register', '/book/{slug}', '/t/{token}/cancel', '/t/{token}/reschedule',
    // A visit rating: same 128-bit token, one answer per visit enforced by a
    // unique index, and it can only ever write a rating for the visit that
    // token belongs to. It cannot read anything.
    '/t/{token}/rate',
    '/a/{token}/reschedule', '/a/{token}/cancel',
    // The lobby kiosk: a 192-bit key, CSRF enforced by the router, and it can
    // only add a walk-in at the default weight — it cannot read, call, move or
    // prioritise anything that already exists.
    '/kiosk/{key}',
    '/forgot-password', '/reset-password/{token}', '/invite/{token}',
    '/login/2fa', '/login/2fa/cancel', '/logout', '/locale',
];
$unexpected = array_values(array_filter($mutating, static function (string $route) use ($expectedPublicPosts): bool {
    $path = explode(' ', $route, 2)[1] ?? '';
    foreach ($expectedPublicPosts as $allowed) {
        if (str_contains($allowed, '{')) {
            $pattern = '#^' . str_replace(['/', '{slug}', '{token}'], ['\/', '[^/]+', '[^/]+'], $allowed) . '$#';
            // `[^/]+` already includes '.', which is what a signed booking link
            // needs (`/a/13.4c5c…`). Spelled out here so the next person does
            // not "tighten" it to `[A-Za-z0-9_-]+` and break the link.
            if (preg_match($pattern, $path) === 1) {
                return false;
            }
        } elseif ($path === $allowed) {
            return false;
        }
    }
    return true;
}));
if ($unexpected !== []) {
    fail('Unexpected unauthenticated POST route(s): ' . implode(', ', $unexpected));
} else {
    pass('Every unauthenticated POST is one of the deliberately public ones');
}

/* ------------------------------------------------------------------ */
/* 5. Data leaving the building                                        */
/* ------------------------------------------------------------------ */

heading('5. Notification payloads');

// A raw number may live in the payload only while a retry could still need
// it. Anything sent, logged or abandoned is finished business and must have
// had it removed — otherwise a database dump carries every number this
// clinic ever messaged, which is the opposite of the masking the history
// screen relies on.
$rawNumbers = 0;
$retrying = 0;
try {
    foreach (Db::all("SELECT status, payload_json FROM notifications WHERE payload_json IS NOT NULL AND payload_json <> ''") as $row) {
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload) || ($payload['to'] ?? '') === '') {
            continue;
        }
        $digits = (string) preg_replace('/\D/', '', (string) $payload['to']);
        if (strlen($digits) < 9) {
            continue; // already masked
        }
        if (in_array((string) $row['status'], ['sent', 'logged', 'abandoned'], true)) {
            $rawNumbers++;
        } else {
            $retrying++;
        }
    }
} catch (\Throwable) {
    warn('Could not inspect notification payloads.');
}
if ($rawNumbers > 0) {
    fail($rawNumbers . ' finished notification(s) still carry a deliverable phone number in their payload.');
} else {
    pass('No finished notification keeps a deliverable number');
}
if ($retrying > 0) {
    pass($retrying . ' queued notification(s) hold a number, which a retry still needs');
}

/* ------------------------------------------------------------------ */

echo "\n";
if ($failures > 0) {
    echo "\033[31m" . $failures . " failure(s)\033[0m";
    echo $warnings > 0 ? ", " . $warnings . " warning(s)" : '';
    echo ".\n";
} elseif ($warnings > 0) {
    echo "\033[33mNo failures, " . $warnings . " warning(s) to review.\033[0m\n";
} else {
    echo "\033[32mNo findings.\033[0m\n";
}

if ($strict && $failures > 0) {
    exit(1);
}
