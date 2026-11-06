<?php
declare(strict_types=1);

/**
 * DEV SANDBOX ONLY — per-port auto-sign-in.
 *
 * This file is NEVER required by public/index.php, by the router, or by any
 * application code. It is injected into the request in memory by
 * tools/dev-serve.mjs, and only when QP_DEV_MODE=1. Under nginx/php-fpm or the
 * plain `npm run dev` server it does not exist as far as the app is concerned.
 *
 * That is the whole safety design: the main system can never accidentally
 * inherit a login bypass, because the bypass is not reachable from any code
 * path the main system executes. Promoting dev -> queuepilot copies app files
 * only; dev/ and tools/dev-serve.mjs are excluded by tools/promote.mjs.
 *
 * Where it runs: immediately BEFORE Session::start() in public/index.php, so
 * it can set a per-port session name and path first. Cookies are scoped by
 * host, NOT by port, so without this all five ports would fight over one
 * `qp_session` cookie and sign each other out. Each port gets its own cookie
 * name and its own session directory, which is what makes five simultaneous
 * identities in one browser actually work.
 *
 * Requires $config (the array returned by app/bootstrap.php) to be in scope.
 */

use App\Core\Auth;
use App\Core\Db;
use App\Core\Env;
use App\Core\Session;

$devPort  = (string) (getenv('QP_DEV_PORT') ?: '0');
$devEmail = (string) (getenv('QP_DEV_EMAIL') ?: '');
$devLabel = (string) (getenv('QP_DEV_LABEL') ?: 'dev');

if ($devEmail === '') {
    throw new RuntimeException(
        'QP_DEV_EMAIL is not set. Start this server with tools/dev-serve.mjs, not serve.mjs.'
    );
}

/* ---------------------------------------------------- per-port session --
 * A distinct cookie name AND a distinct save path per port. The name keeps
 * the browser from sending port 8080's cookie to 8081; the path keeps one
 * port's session files from being read or garbage-collected by another.
 */
$suffix = preg_replace('/[^a-zA-Z0-9]/', '', $devPort) ?: 'x';
$devSessionPath = QP_ROOT . '/storage/sessions/dev-' . $suffix;
if (!is_dir($devSessionPath)) {
    @mkdir($devSessionPath, 0775, true);
}

$config['security']['session_name'] = 'qp_dev_' . $suffix;
$config['security']['session_path'] = $devSessionPath;
// A dev fleet sits open for hours while you click between ports; the 45-minute
// idle logout would sign you out mid-review and look like a bug in the change
// you are actually testing.
$config['security']['idle_timeout'] = 60 * 60 * 12;
Env::init($config);

Session::start();

/* ------------------------------------------------------------- sign in --
 * Resolve by email, not by a hardcoded id: ids differ between a fresh seed
 * and an existing database, and a stale id would silently sign in as the
 * wrong person — the one failure mode a role-testing harness must not have.
 */
$devUser = Db::one('SELECT * FROM users WHERE email = ? AND is_active = 1', [strtolower($devEmail)]);

if ($devUser === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dev sandbox: no active user '{$devEmail}'.\n\n"
        . "The database has not been seeded yet. Run:\n"
        . "    npm run migrate && npm run seed\n";
    exit;
}

// Re-pin the identity on every request. If you sign out, or a session file is
// GC'd, the port returns to its assigned person rather than to a login form.
// Signing in as somebody else via /dev/role still works until the next
// request, which is the honest trade-off for a port that means "be this role".
if ((int) Session::get('user_id', 0) !== (int) $devUser['id']) {
    Session::regenerate();
    Session::put('user_id', (int) $devUser['id']);
    Session::put('clinic_id', $devUser['clinic_id'] !== null ? (int) $devUser['clinic_id'] : null);
    Session::put('started_at', Db::now());
    Session::put('_dev_port', $devPort);
    Auth::forgetBranch();
    Auth::forgetClinicCache();
}

// A visible, unmissable marker on every page. A dev screenshot pasted into a
// bug report should say which port and which role produced it.
$GLOBALS['qpDevBanner'] = [
    'port'  => $devPort,
    'label' => $devLabel,
    'email' => $devEmail,
    'role'  => $devUser['role'],
];
