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

if (!function_exists('url')) {
    function url(string $path = ''): string { return '/' . ltrim($path, '/'); }
}

if (!function_exists('asset')) {
    function asset(string $path): string { return '/assets/' . ltrim($path, '/'); }
}

if (!function_exists('redirect')) {
    function redirect(string $to): void { Response::redirect($to); }
}

if (!function_exists('back')) {
    function back(string $fallback = '/'): void
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        // only allow same-origin redirects
        if ($ref !== '' && $host !== '' && str_contains($ref, $host)) {
            $path = parse_url($ref, PHP_URL_PATH) ?: $fallback;
            Response::redirect($path);
            return;
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
        ], false);
        exit;
    }
}

if (!function_exists('t')) {
    function t(string $key, string $fallback = ''): string { return Lang::get($key, $fallback); }
}

if (!function_exists('view')) {
    /**
     * $asLayout=false renders the page on its own. Several callers already
     * passed it, but the parameter did not exist and PHP silently dropped
     * the argument — so full-document views (the waiting-room TV, the kiosk,
     * public tracking) were nested inside the console shell whenever a staff
     * session existed, producing a document inside a document.
     */
    function view(string $template, array $data = [], bool $asLayout = true): void
    {
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
