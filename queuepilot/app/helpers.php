<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Core\View;

if (!function_exists('e')) {
    function e(?string $v): string { return Str::e($v); }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string { return Csrf::token(); }
}

if (!function_exists('clinic_url')) {
    /**
     * An absolute URL on the clinic's own address, for anything a patient or
     * a staff member will click.
     *
     * Every outbound link used to be `APP_URL . path`, which was correct while
     * one deployment served one host. With twenty clinics on twenty
     * subdomains it is wrong in a way that is easy to miss and expensive to
     * live with: a tracking link emailed from Serenity's desk would point at
     * the platform root, where there is no clinic — so the patient's "where am
     * I in the queue" link opens a signup page.
     *
     * $surface is 'site' (patient-facing, default) or 'ops' (staff-facing).
     * Falls back to APP_URL when the clinic has no host yet, which is the
     * honest answer for a clinic that has not been provisioned.
     */
    function clinic_url(int $clinicId, string $path = '/', string $surface = 'site'): string
    {
        static $cache = [];
        if (!array_key_exists($clinicId, $cache)) {
            $cache[$clinicId] = App\Core\Db::one(
                'SELECT site_host, ops_host FROM clinics WHERE id = ?', [$clinicId]
            );
        }
        $row = $cache[$clinicId];
        if ($row === null) {
            return rtrim((string) App\Core\Env::get('app.url', ''), '/') . '/' . ltrim($path, '/');
        }
        $base = $surface === 'ops'
            ? App\Core\Tenant::opsUrl($row)
            : App\Core\Tenant::siteUrl($row);
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('base_path')) {
    /**
     * The URL path the application is mounted at, or '' when it owns the root.
     *
     * One setting — the path on APP_URL — decides this, so an install at
     * http://localhost/clinicqueue and one at https://queue.lk behave
     * identically everywhere else. Everything that emits a URL or a Location
     * header goes through here, because a subfolder install is a real and
     * common way to run this (XAMPP, a shared-host subdirectory) and a single
     * missed prefix means the user is bounced out of the application into
     * whatever else lives on that host.
     *
     * Cached: it is read on every link rendered, and APP_URL cannot change
     * within one request.
     */
    function base_path(): string
    {
        // Memoised on the APP_URL it was derived from rather than on nothing:
        // APP_URL cannot change within a request, but it does change between
        // them in the test suite and in anything that re-initialises Env, and a
        // cache that ignored that would answer for the previous install.
        static $cachedUrl = null;
        static $cachedBase = '';
        $appUrl = (string) \App\Core\Env::get('app.url', '');
        if ($cachedUrl === $appUrl) {
            return $cachedBase;
        }
        $path = parse_url($appUrl, PHP_URL_PATH);
        $cachedBase = is_string($path) && $path !== '' && $path !== '/'
            ? rtrim($path, '/')
            : '';
        $cachedUrl = $appUrl;
        return $cachedBase;
    }
}

if (!function_exists('url')) {
    /**
     * A URL inside the application, including the mount prefix.
     *
     *   url('/login')  ->  /clinicqueue/login   on a subfolder install
     *                  ->  /login              at the domain root
     *
     * Absolute URLs (http://, https://, //) and anchors are returned untouched,
     * so this is safe to wrap around anything.
     */
    function url(string $path = ''): string
    {
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }
        return base_path() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        // Honest about where the app is mounted. On production the document root
        // is `public/`, so the prefix is empty and this is `/assets/...`. On a
        // local XAMPP install served from htdocs/queuepilot it is
        // `/queuepilot/assets/...`, because a root-relative `/assets/` there
        // points at the XAMPP dashboard and every page renders unstyled — which
        // looks like a broken application rather than a wrong URL.
        //
        // Derived from APP_URL when it has a path, so one setting controls it
        // and nothing has to be recomputed per request.
        $url = base_path() . '/assets/' . $path;
        static $hashes = [];
        if (!array_key_exists($path, $hashes)) {
            $file = QP_ROOT . '/public/assets/' . $path;
            $hashes[$path] = is_file($file) ? substr(md5_file($file), 0, 10) : null;
        }
        return $hashes[$path] !== null ? $url . '?v=' . $hashes[$path] : $url;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $to): never { Response::redirect($to); }
}

if (!function_exists('back')) {
    function back(string $fallback = '/'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        // only allow same-origin redirects
        if ($ref !== '' && $host !== '' && str_contains($ref, $host)) {
            $path = parse_url($ref, PHP_URL_PATH) ?: $fallback;
            Response::redirect($path);
        }
        Response::redirect($fallback);
    }
}

if (!function_exists('abort')) {
    function abort(int $status, string $message = ''): void
    {
        http_response_code($status);
        if (Request::wantsJson()) {
            Response::json(['ok' => false, 'error' => strtolower(str_replace(' ', '_', $message ?: 'error'))], $status);
            exit;
        }
        echo View::render('errors/error', [
            'title'   => $status === 403 ? 'Access denied' : 'Something went wrong',
            'status'  => $status,
            'message' => $message,
        ]);
        exit;
    }
}

if (!function_exists('t')) {
    function t(string $key, string $fallback = ''): string { return Lang::get($key, $fallback); }
}

if (!function_exists('view')) {
    function view(string $template, array $data = [], bool $asLayout = true): void
    {
        // The third argument must reach View::render. Nine callers pass `false`
        // because their view is already a complete document; dropping it wrapped
        // them in the console layout anyway, producing two <html> tags. Browsers
        // keep the first, so the page's own <html>/<body> classes were discarded.
        echo View::render($template, $data, $asLayout);
    }
}

if (!function_exists('json')) {
    function json(array $data, int $status = 200): void { Response::json($data, $status); }
}

if (!function_exists('money')) {
    function money(float|int|string|null $amount, string $currency = 'LKR'): string
    {
        $symbol = $currency === 'LKR' ? 'Rs' : $currency;
        return $symbol . ' ' . number_format((float) $amount, 2);
    }
}

if (!function_exists('fmt_time')) {
    function fmt_time(?string $dt, string $format = 'h:i A'): string
    {
        if (!$dt) return '—';
        $ts = strtotime($dt);
        return $ts ? date($format, $ts) : '—';
    }
}

if (!function_exists('minutes_between')) {
    function minutes_between(?string $from, ?string $to = null): ?int
    {
        if (!$from) return null;
        $a = strtotime($from);
        $b = $to ? strtotime($to) : time();
        if ($a === false || $b === false) return null;
        return max(0, (int) round(($b - $a) / 60));
    }
}

if (!function_exists('ago')) {
    function ago(?string $dt): string
    {
        $m = minutes_between($dt);
        if ($m === null) return '—';
        if ($m < 1) return 'just now';
        if ($m < 60) return $m . ' min ago';
        $h = intdiv($m, 60);
        return $h . ' hr ' . ($m % 60) . ' min ago';
    }
}

if (!function_exists('human_minutes')) {
    /**
     * Minutes as something a person would say out loud.
     *
     * A receptionist checking in an appointment booked three weeks out was
     * told "Checked in 27126 min early". Minutes are the right unit for a
     * queue and the wrong one for anything longer than a couple of hours.
     */
    function human_minutes(int $minutes): string
    {
        $m = abs($minutes);
        if ($m < 60) {
            return $m . ' min';
        }
        if ($m < 60 * 24) {
            $h = intdiv($m, 60);
            $rest = $m % 60;
            return $h . ' hr' . ($rest ? ' ' . $rest . ' min' : '');
        }
        $d = intdiv($m, 60 * 24);
        $h = intdiv($m % (60 * 24), 60);
        return $d . ' day' . ($d === 1 ? '' : 's') . ($h ? ' ' . $h . ' hr' : '');
    }
}

if (!function_exists('in_time')) {
    /**
     * How long until a moment in the future, phrased forwards.
     *
     * `ago()` counts backwards — it is right for "created 5 min ago" and wrong
     * for an invitation that expires next week, which came out as "expires just
     * now from now". Kept separate rather than bolting a direction onto `ago()`
     * so every existing caller keeps its past-tense meaning.
     */
    function in_time(?string $dt): string
    {
        if (!$dt) return '—';
        $then = strtotime($dt);
        if ($then === false) return '—';
        $m = (int) round(($then - time()) / 60);
        if ($m <= 0) return 'already passed';
        if ($m < 60) return 'in ' . $m . ' min';
        $h = intdiv($m, 60);
        if ($h < 24) return 'in ' . $h . ' hr' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '');
        $d = intdiv($h, 24);
        return 'in ' . $d . ' day' . ($d === 1 ? '' : 's');
    }
}

if (!function_exists('old')) {
    function old(string $key, string $default = ''): string
    {
        $old = \App\Core\Session::takeFlash('old');
        return is_array($old) && isset($old[$key]) ? (string) $old[$key] : $default;
    }
}

if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        \App\Core\Session::flash('notice', ['type' => $type, 'message' => $message]);
    }
}

if (!function_exists('notice')) {
    function notice(): ?array
    {
        $n = \App\Core\Session::takeFlash('notice');
        return is_array($n) ? $n : null;
    }
}

if (!function_exists('current_user')) {
    function current_user(): ?array { return Auth::user(); }
}

if (!function_exists('clinic_id')) {
    function clinic_id(): ?int { return Auth::clinicId(); }
}

if (!function_exists('db')) {
    function db(): \PDO { return Db::pdo(); }
}

if (!function_exists('now_iso')) {
    function now_iso(): string { return Db::now(); }
}

if (!function_exists('err')) {
    /**
     * Write to STDERR where there is one.
     *
     * CLI tools used `fwrite(STDERR, ...)` directly, which dies with
     * "Undefined constant STDERR" anywhere STDERR is not defined — the
     * wasm runtime used for local testing, and any SAPIs that do not open the
     * three standard streams. Failing on the error path is worse than the
     * error being reported, so everything goes through here.
     */
    function err(string $message): void
    {
        $stream = defined('STDERR') ? constant('STDERR') : @fopen('php://stderr', 'w');
        if ($stream === false || $stream === null) {
            echo $message;
            return;
        }
        fwrite($stream, $message);
    }
}
