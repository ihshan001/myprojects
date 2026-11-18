<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader.
 *
 * Deliberately tiny: KEY=value, optional quotes, # comments, no expansion or
 * nested variables. Real environments (nginx/php-fpm, systemd, Docker) should
 * set real environment variables; this exists so `cp .env.example .env` works
 * for a developer or a shared-host install where there is no process manager.
 *
 * Existing environment variables always win — a container's `docker run -e`
 * must never be silently overridden by a stale file on disk.
 */
final class EnvFile
{
    /** @return array<string, string> */
    public static function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $loaded = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            // tolerate a leading `export `
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (!preg_match('/^[A-Z0-9_]+$/i', $key)) {
                continue;
            }

            $value = trim($value);
            // strip a trailing inline comment that is not inside quotes
            if (preg_match('/^(["\'])(.*)\1$/', $value, $m) === 1) {
                $value = $m[2];
            } else {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }

            if (getenv($key) !== false) {
                // A second definition of the same key is a trap: the first one
                // silently wins, so somebody appending APP_NAME= to the bottom
                // of their .env changes nothing and cannot see why. Say it out
                // loud rather than leaving them to guess.
                if (isset($loaded[$key])) {
                    error_log(
                        "[env] {$key} is defined more than once in {$path}; "
                        . "the first value ({$loaded[$key]}) wins and this one ({$value}) is ignored."
                    );
                }
                continue; // real environment wins
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            $loaded[$key] = $value;
        }

        return $loaded;
    }
}
