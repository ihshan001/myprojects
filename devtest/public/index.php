<?php
declare(strict_types=1);

/**
 * DEVTEST front controller.
 *
 * No landing page and no authentication. The port decides the identity
 * (app/Core/DevIdentity + dev/roles.json); "/" goes straight to that role's
 * workspace. Sessions still exist, but only for locale, flash messages and
 * CSRF — never for who you are.
 */
$config = require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\DevIdentity;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

Response::securityHeaders();

Session::start();

// DEVTEST: fail loudly and legibly if the database has not been seeded, rather
// than throwing a null-user error somewhere deep inside a view.
if (DevIdentity::user() === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    $e = DevIdentity::portEntry();
    echo "DEVTEST: no active user '" . ($e['email'] ?? '?') . "' for port " . ($e['port'] ?? '?') . ".\n\n"
        . "Seed the database first:\n    npm run migrate && npm run seed\n";
    exit;
}

// locale: session preference, else clinic default, else app default
$locale = $_SESSION['locale'] ?? null;
if ($locale === null && Auth::clinic()) {
    $locale = Auth::clinic()['locale'] ?? null;
}
Lang::load($locale ?: $config['app']['default_locale']);

$requestId = bin2hex(random_bytes(6));
header('X-Request-Id: ' . $requestId);

// Shared view data
View::share('appName', $config['app']['name']);
View::share('tagline', $config['app']['tagline']);
View::share('user', Auth::user());
View::share('clinic', Auth::clinic());
View::share('locale', $locale ?: $config['app']['default_locale']);
View::share('locales', $config['app']['locales']);
View::share('path', Request::path());

// The branch switcher, resolved once per request. Shared only when there is a
// genuine choice to offer: a single-branch clinic should never see a control
// that exists to solve somebody else's problem.
$branchChoices = [];
if (Auth::check() && !Auth::isSuper()) {
    $u = Auth::user();
    $branchChoices = (new \App\Services\BranchService())->allowedFor((int) $u['id'], $u['clinic_id'] !== null ? (int) $u['clinic_id'] : null);
}
View::share('branchChoices', $branchChoices);
View::share('activeBranchId', Auth::clinicId());
View::share('requestId', $requestId);
View::share('devPort', DevIdentity::port());
View::share('devRole', DevIdentity::portEntry());
View::share('debug', $config['app']['debug']);

// SaaS gate: a suspended clinic can sign in to billing, nothing else.
if (Auth::check() && !Auth::isSuper()) {
    $clinic = Auth::clinic();
    if ($clinic && in_array($clinic['billing_status'], ['suspended', 'cancelled'], true)) {
        $allowed = ['/billing', '/logout', '/locale'];
        if (!in_array(Request::path(), $allowed, true)) {
            Response::redirect('/billing?suspended=1');
            exit;
        }
    }
}

require QP_ROOT . '/routes/web.php';

Router::dispatch();
