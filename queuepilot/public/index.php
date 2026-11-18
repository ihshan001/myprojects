<?php
declare(strict_types=1);

/**
 * Public front controller.
 *
 * nginx:  try_files $uri /index.php?$query_string;
 * apache: RewriteRule ^ index.php [L]
 * dev:    php -S 0.0.0.0:8080 -t public public/index.php
 */
$config = require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Env;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Tenant;
use App\Core\View;

Response::securityHeaders();

// Which clinic, and which of its two sites? Resolved from the Host header
// before anything else, because the login form, the display board and the
// tracking page all have to know which clinic they belong to before a session
// exists. An unknown host resolves to nothing and is refused below — a
// resolver that guessed would serve one clinic's patients to another.
Tenant::resolve();

Session::start();

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
View::share('debug', $config['app']['debug']);
View::share('tenantClinic', Tenant::clinic());
View::share('tenantKind', Tenant::kind());

/* Two-factor is not optional for the people who can see every clinic.
 *
 * A super admin can read every patient record on the platform. One stolen
 * password is therefore every clinic compromised at once, and a password is
 * the easiest thing in this system to steal: it is typed into shared office
 * machines, reused from other sites, and phished. Enrolment is a one-off
 * thirty seconds with any authenticator app.
 *
 * Enforced here rather than per route, so it covers the admin screens and any
 * future ones. The escape hatches are deliberate: the enrolment page itself,
 * sign-out, health, and the CLI (which has no session at all). A user locked
 * out because they lost their phone needs a human anyway, and the recovery
 * path is `tools/security-check.php` plus a database update by the operator.
 */
if (Auth::check() && Auth::isSuper() && (bool) Env::get('security.require_2fa_super', true)) {
    $u = Auth::user();
    if (empty($u['totp_enabled_at'])) {
        $exempt = ['/settings/security', '/logout', '/locale', '/api/health'];
        if (!in_array(Request::path(), $exempt, true)) {
            Response::redirect('/settings/security?2fa=required');
            exit;
        }
    }
}

/* Tenant binding — the replay guard.
 *
 * A session cookie issued on one clinic's host is still a valid session record
 * in the shared database. What stops it working on another clinic's host is
 * this check: the signed-in user's clinic must be the host's clinic.
 *
 * It runs before routing, so it covers routes that do not exist yet, and it is
 * deliberately a logout rather than a 403 — a cookie presented to the wrong
 * clinic is either a misconfiguration or an attack, and neither deserves a
 * session that keeps working.
 */
if (Auth::check() && !Auth::isSuper() && !Tenant::isRoot()) {
    $u = Auth::user();
    if (!Tenant::bind((int) $u['id'], $u['clinic_id'] !== null ? (int) $u['clinic_id'] : null)) {
        \App\Core\Log::warn('tenant.bind_refused', [
            'user' => (int) $u['id'],
            'user_clinic' => $u['clinic_id'],
            'host_clinic' => Tenant::clinicId(),
            'host' => Tenant::host(),
        ]);
        Session::destroy();
        Response::redirect('/login');
        exit;
    }
}

// The public site carries no staff session. A patient typing the display URL
// must not be shown a console they are not signed in to, and a staff member
// browsing the board should see the board.
if (Auth::check() && !Auth::isSuper() && Tenant::isSite()
    && !str_starts_with(Request::path(), '/logout')) {
    Session::destroy();
    Session::start();
}

// SaaS gate. Three states, three different doors, and one rule: a clinic that
// has not been approved can be signed in but cannot work.
//
//   pending / declined — reach ONLY the status page. A clinic waiting for a
//                        decision should be able to refresh and see where it
//                        stands; it must not reach /app, and it must not sit
//                        on a blank 403 wondering whether signup worked.
//   suspended          — reach billing so it can pay, plus its own status page.
//   closed             — reach the status page only; the data is on a clock.
//
// Everything else is redirected by string comparison on Request::path(), which
// is the same mechanism the suspended rule already used. It is a coarse gate,
// not the authorisation model: roles still decide what an approved user may
// do once they are through.
if (Auth::check() && !Auth::isSuper()) {
    $clinic = Auth::clinic();
    $status = $clinic['billing_status'] ?? 'active';

    $alwaysAllowed = ['/logout', '/locale', '/onboarding-status'];

    if (in_array($status, ['pending', 'declined'], true)) {
        $allowed = array_merge($alwaysAllowed, ['/onboarding-status']);
        if (!in_array(Request::path(), $allowed, true)) {
            Response::redirect('/onboarding-status');
            exit;
        }
    } elseif (in_array($status, ['suspended', 'cancelled'], true)) {
        $allowed = array_merge($alwaysAllowed, ['/billing', '/onboarding-status']);
        if (!in_array(Request::path(), $allowed, true)) {
            Response::redirect('/billing?suspended=1');
            exit;
        }
    } elseif ($status === 'closed') {
        $allowed = array_merge($alwaysAllowed, ['/onboarding-status']);
        if (!in_array(Request::path(), $allowed, true)) {
            Response::redirect('/onboarding-status');
            exit;
        }
    }
}

/* Surface separation — the two sites do different jobs.
 *
 * SITE host (<slug>.queue.lk) is public: the display board, queue position,
 *   tracking links, the booking page. It is handed to patients, printed on
 *   slips, and must work with no session at all.
 * OPS host (unguessable) is staff-only: login, console, settings, reports.
 *
 * A route that needs a session has no business on the site host, and a route
 * that takes no session has no business on the ops host (a login form on an
 * address nobody can guess is fine, but the public board there is dead weight
 * and a wider surface). Enforced here rather than per route, for the same
 * reason as the lifecycle gate: it must cover routes added later.
 */
if (!Tenant::isRoot()) {
    $path = Request::path();
    $publicPaths = ['/login', '/logout', '/locale', '/live', '/board', '/t', '/position',
                    '/demo', '/assets', '/api/display', '/api/queue/board', '/book',
                    '/booking', '/sw.js', '/favicon.ico', '/health'];
    $isPublicPath = false;
    foreach ($publicPaths as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')
            || str_starts_with($path, $prefix . '?') || $path === $prefix) {
            $isPublicPath = true;
            break;
        }
    }
    // The site host refuses anything that is not public; the ops host refuses
    // the public board pages, which belong on the site address.
    if (Tenant::isSite() && !$isPublicPath) {
        abort(404, 'Not found.');
        exit;
    }
}

require QP_ROOT . '/routes/web.php';

Router::dispatch();
