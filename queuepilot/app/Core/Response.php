<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Redirect and stop.
     *
     * This terminates. It did not use to, and every caller in the codebase
     * assumed it did — a controller that hit a validation error, redirected,
     * and then carried on to the write below it would perform the write on a
     * request the user was told had failed. That is exactly what happened in
     * the password-change handler: a wrong current password redirected and
     * then went on to set the new one anyway.
     *
     * Every one of the ~200 call sites intends "and do nothing else", and a
     * redirect whose script continues must return 302 rather than 200 anyway,
     * so exiting is the only correct behaviour. A caller that genuinely wants
     * to do more work should rebuild the response explicitly.
     */
    public static function redirect(string $to, int $status = 302): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . self::location($to));
        }
        exit;
    }

    /**
     * Turn an application path into a Location that resolves on this install.
     *
     * There are ~250 `redirect('/somewhere')` calls and every one of them means
     * "somewhere inside the application". On an install mounted under a path —
     * http://localhost/clinicqueue — sending `Location: /somewhere` bounces the
     * user out of the app to whatever else is on that host, and on XAMPP that is
     * the dashboard. Prefixing here rather than at 250 call sites means a caller
     * can keep writing the path it means.
     *
     * Absolute URLs, protocol-relative URLs and the already-prefixed case are
     * left alone, so `redirect('https://elsewhere')` and a path computed with
     * url() both still work.
     */
    public static function location(string $to): string
    {
        if ($to === '') {
            return base_path() . '/';
        }
        // Anything with a scheme, or starting //, is already absolute.
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $to)) {
            return $to;
        }
        if ($to[0] !== '/') {
            return base_path() . '/' . $to;
        }
        $base = base_path();
        if ($base !== '' && ($to === $base || str_starts_with($to, $base . '/'))) {
            return $to;   // already carries the prefix
        }
        return $base . $to;
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-XSS-Protection: 1; mode=block');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        // No CDNs: everything is served locally, so the policy can stay tight.
        header(
            "Content-Security-Policy: default-src 'self'; " .
            "img-src 'self' data:; style-src 'self' 'unsafe-inline'; " .
            "script-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'"
        );
    }
}
