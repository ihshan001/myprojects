<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Explicit, dependency-free router with `{param}` support.
 *
 * Every route declares its auth requirement, ability and CSRF expectation,
 * so authorisation is auditable from one file.
 */
final class Router
{
    /** @var array<int, array<string, mixed>> */
    private static array $routes = [];
    private static array $params = [];

    public static function get(string $path, callable|array $handler, array $opts = []): void
    {
        self::add('GET', $path, $handler, $opts);
    }

    public static function post(string $path, callable|array $handler, array $opts = []): void
    {
        self::add('POST', $path, $handler, $opts);
    }

    private static function add(string $method, string $path, callable|array $handler, array $opts): void
    {
        // Compile /queue/{id} into a regex, capturing named params.
        //
        // `.` is in the charset because a self-service link carries its
        // signature in the same segment — `/a/13.4c5c…` — so the patient sees
        // one usable address rather than two segments to keep together. Every
        // handler still validates its own format (`/t/{token}` rejects a dot),
        // so widening the router does not widen what any route accepts.
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_]+)\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[A-Za-z0-9_.-]{1,128})',
            $path
        );
        self::$routes[] = [
            'method'  => $method,
            'path'    => $path,
            'regex'   => '#^' . $regex . '$#',
            'handler' => $handler,
            'auth'    => $opts['auth'] ?? false,
            'ability' => $opts['ability'] ?? null,
            'roles'   => $opts['roles'] ?? null,
            'csrf'    => $opts['csrf'] ?? ($method === 'POST'),
        ];
    }

    /** Route parameter resolved during dispatch (e.g. {token}). */
    public static function param(string $key, ?string $default = null): ?string
    {
        return self::$params[$key] ?? $default;
    }

    public static function dispatch(): void
    {
        $method = Request::method();
        $path = Request::path();
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $route = null;
        $matched = [];
        foreach (self::$routes as $candidate) {
            if ($candidate['method'] !== $method) {
                continue;
            }
            if (preg_match($candidate['regex'], $path, $m) === 1) {
                $route = $candidate;
                $matched = $m;
                break;
            }
        }

        if ($route === null) {
            self::notFound();
            return;
        }

        // named params only
        self::$params = [];
        foreach ($matched as $key => $value) {
            if (is_string($key)) {
                self::$params[$key] = $value;
            }
        }

        if ($route['csrf'] && !Csrf::check($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            Log::warn('csrf.rejected', ['path' => $path, 'ip' => Request::ip()]);
            if (Request::wantsJson()) {
                Response::json(['ok' => false, 'error' => 'csrf_expired',
                    'message' => 'Your session expired. Reload the page and try again.'], 419);
                return;
            }
            http_response_code(419);
            echo View::render('errors/error', [
                'title' => 'Session expired',
                'status' => 419,
                'message' => 'Your page was open for too long. Reload and try again — nothing was changed.',
            ], false);
            return;
        }

        if ($route['auth'] && !Auth::check()) {
            if (Request::wantsJson()) {
                Response::json(['ok' => false, 'error' => 'unauthenticated'], 401);
                return;
            }
            // DEVTEST: there is no sign-in page. Identity is pinned per port,
            // so this is unreachable in normal use; if the database is unseeded
            // it sends you to the workspace root, which reports the problem.
            Response::redirect('/');
            return;
        }

        if ($route['roles'] !== null && !in_array(Auth::role(), $route['roles'], true)) {
            abort(403, 'This area is restricted to platform administrators.');
            return;
        }

        if ($route['ability'] !== null) {
            Auth::requireAbility($route['ability']);
        }

        $handler = $route['handler'];
        if (is_array($handler)) {
            [$class, $methodName] = $handler;
            $instance = new $class();
            $instance->{$methodName}(...array_values(self::$params));
            return;
        }
        $handler(...array_values(self::$params));
    }

    private static function notFound(): void
    {
        if (Request::wantsJson()) {
            Response::json(['ok' => false, 'error' => 'not_found'], 404);
            return;
        }
        http_response_code(404);
        echo View::render('errors/error', [
            'title' => 'Page not found',
            'status' => 404,
            'message' => 'We could not find that page. It may have moved, or the link may be incomplete.',
        ], false);
    }
}
