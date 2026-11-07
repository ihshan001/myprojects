<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return '/' . trim($path, '/');
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_string($v) ? trim($v) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function bool(string $key): bool
    {
        return in_array((string) self::input($key, ''), ['1', 'on', 'true', 'yes'], true);
    }

    /** Raw request body (JSON APIs). The transport shim supplies it when
     *  php://input is not wired up by the SAPI. */
    public static function rawBody(): string
    {
        if (isset($_SERVER['QP_RAW_BODY']) && $_SERVER['QP_RAW_BODY'] !== '') {
            return (string) $_SERVER['QP_RAW_BODY'];
        }
        $body = @file_get_contents('php://input');
        return is_string($body) ? $body : '';
    }

    /** JSON body merged with form fields (JSON wins on conflict). */
    public static function json(): array
    {
        $raw = trim(self::rawBody());
        if ($raw === '' || !str_starts_with($raw, '{')) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Unified payload for API handlers: form + JSON. */
    public static function payload(): array
    {
        return array_merge($_POST, self::json());
    }

    public static function ip(): string
    {
        // behind a trusted proxy the platform injects X-Forwarded-For
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            return filter_var($first, FILTER_VALIDATE_IP) ?: '0.0.0.0';
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    }
}
