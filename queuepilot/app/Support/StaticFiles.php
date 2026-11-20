<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Static file resolution for a subdirectory install.
 *
 * Deliberately free of framework dependencies, and deliberately a separate class:
 * a stylesheet must not pay for a bootstrap, a session and a database connection,
 * so `index.php` loads this file and nothing else for an asset request.
 *
 * It exists because of one install shape. In production the document root is
 * `public/`, so Apache and nginx serve `assets/**` directly and no PHP runs at all.
 * When the whole repository is dropped into `htdocs/clinicqueue` — which is how
 * people try the system first — the document root is the repository root and the
 * assets are one level down, so something has to bridge that gap.
 *
 * Doing it here rather than in a rewrite rule is a decision about being able to
 * prove it. A wrong RewriteRule looks like a stale stylesheet and nobody reports
 * it; `resolve()` can be called from the test suite with traversal attempts,
 * missing files and odd extensions, which is the part worth getting right.
 */
final class StaticFiles
{
    /**
     * Content types for the extensions this application actually ships.
     *
     * An allowlist rather than a guess: `application/octet-stream` on a stylesheet
     * makes a browser refuse it under `nosniff`, and a lookup table is one fewer
     * thing that can disagree with what is on disk.
     */
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'map' => 'application/json; charset=utf-8',
    ];

    /**
     * Files served from the root of the public directory by their bare name.
     * The service worker has to be at the root of its scope to control the site.
     */
    private const ROOT_FILES = ['/sw.js', '/manifest.webmanifest', '/favicon.ico'];

    /**
     * Is this path a static file request at all?
     *
     * The distinction matters: `GET /assets/typo.css` should 404 as a file, while
     * `GET /queue` should render a page. Answering a missing asset with the home
     * page makes a broken build look like a working site.
     */
    public static function isStaticRequest(string $path): bool
    {
        if (preg_match('#^/assets/[^/].*$#', $path) === 1) {
            return true;
        }

        return in_array($path, self::ROOT_FILES, true);
    }

    /**
     * The real file to serve, or null when there is nothing to serve.
     *
     * Returns null for a path that escapes the public directory. Callers must
     * treat null as "404, no such file", never as "so try the router instead" —
     * otherwise `/assets/../../.env` would fall through to the front controller
     * and be handled as a route rather than refused as a bad asset.
     *
     * The traversal defence is `realpath()` before the prefix check, so `..` is
     * resolved while the path is still just a string. Comparing the unresolved
     * path instead is the classic way this check gets bypassed.
     */
    public static function resolve(string $path, string $publicRoot): ?string
    {
        if (!self::isStaticRequest($path)) {
            return null;
        }

        $root = realpath($publicRoot);
        if ($root === false) {
            return null;
        }

        $candidate = realpath($root . $path);
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }

        // Must be inside the public directory, as a real resolved path.
        if (!str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $candidate;
    }

    /** The content type for a resolved file, by extension. */
    public static function type(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return self::TYPES[$ext] ?? 'application/octet-stream';
    }

    /**
     * The headers for a resolved file.
     *
     * Split out from sending them so the caching policy can be asserted: hashed
     * build assets are immutable and cached for a year, while the service worker
     * must never be cached, or a deployed fix takes another visit to arrive.
     */
    public static function headers(string $path, string $file): array
    {
        $headers = [
            'Content-Type' => self::type($file),
            'Content-Length' => (string) filesize($file),
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (str_contains($path, '/assets/build/')) {
            $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        } elseif (in_array($path, self::ROOT_FILES, true) && $path !== '/favicon.ico') {
            $headers['Cache-Control'] = 'no-cache';
        } else {
            $headers['Cache-Control'] = 'public, max-age=3600';
        }

        return $headers;
    }
}
