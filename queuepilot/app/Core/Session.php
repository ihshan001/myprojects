<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = (int) Env::get('security.session_lifetime', 28800);
        $secure = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        // Resolved against QP_ROOT, never the process working directory.
        //
        // SESSION_PATH is a relative path in .env. PHP resolves a relative
        // session.save_path against the CWD, which is `public/` under the dev
        // server and the front-controller's directory under some FastCGI
        // setups — so sessions landed in public/storage/sessions, inside the
        // document root, where /storage/sessions/sess_<id> answered 200 and
        // served the session contents. Anything inside the web root is public
        // by definition; a session directory there is a session-hijack
        // primitive, not a configuration detail.
        $savePath = (string) Env::get('security.session_path', '');
        if ($savePath !== '') {
            if (!str_starts_with($savePath, '/')) {
                $savePath = QP_ROOT . '/' . ltrim($savePath, '/');
            }
            if (!is_dir($savePath)) {
                @mkdir($savePath, 0770, true);
            }
            if (is_dir($savePath) && is_writable($savePath)) {
                session_save_path($savePath);
            }
        }

        session_name((string) Env::get('security.session_name', 'qp_session'));
        // SameSite=None lets the session cookie survive cross-origin proxying
        // (e.g. the live preview at https://{port}-{sandboxId}.e2b.app), where
        // Lax would be dropped on the redirect after login. Secure is required
        // for None and is already derived from X-Forwarded-Proto above.
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $secure ? 'None' : 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        @session_start();

        // idle timeout
        $idle = (int) Env::get('security.idle_timeout', 2700);
        $last = $_SESSION['_last_seen'] ?? null;
        if (is_int($last) && (time() - $last) > $idle) {
            self::destroy();
            @session_start();
        }
        $_SESSION['_last_seen'] = time();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function takeFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            @setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) ($params['secure'] ?? false), true);
            @session_destroy();
        }
    }
}
