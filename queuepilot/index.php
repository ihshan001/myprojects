<?php
declare(strict_types=1);

/**
 * Front controller for a subdirectory install.
 *
 * Production points the document root at `public/` and never loads this file:
 * nginx uses `try_files`, Apache uses `public/.htaccess`. But the way most people
 * first try the system is to unzip it into `htdocs\clinicqueue` and open
 * `http://localhost/clinicqueue`, and in that layout Apache's document root is
 * this folder — two levels above the code that has to run. Without this file the
 * request reaches a directory listing and the application is never invoked.
 *
 * Static files are handled first, below, because in this layout Apache looks for
 * them here and they live under `public/`. Everything else goes to the real front
 * controller, `public/index.php`, which does the security headers, tenant
 * resolution and lifecycle gates. None of that is duplicated here on purpose: one
 * front controller means one place for a check to live, so a rule cannot end up
 * enforced on one install shape and quietly missing on the other.
 *
 * Set APP_URL to the folder you installed into — `http://localhost/clinicqueue`.
 * The path on APP_URL is what the app uses to strip the prefix from incoming
 * requests and to add it to every link, form action, redirect and asset URL.
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

/**
 * The folder this application is mounted at.
 *
 * Apache reports this file's own URL in SCRIPT_NAME, so its directory is the mount
 * point — derived rather than hardcoded, so the folder can be called anything.
 * APP_URL is the fallback for a server that does not set SCRIPT_NAME.
 */
$mount = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($mount === '.' || $mount === '/') {
    $mount = '';
} elseif ($mount === '' && preg_match('#^https?://[^/]+(/.*)$#', (string) (getenv('APP_URL') ?: ''), $m)) {
    $mount = rtrim($m[1], '/');
}

// Work from the path inside the application, which is what is on disk under public/.
$inner = $path;
if ($mount !== '' && str_starts_with($path, $mount)) {
    $inner = substr($path, strlen($mount)) ?: '/';
}

// Load only what is needed to answer an asset request. A stylesheet should not pay
// for a database connection.
require_once __DIR__ . '/app/Support/StaticFiles.php';
$static = \App\Support\StaticFiles::resolve($inner, __DIR__ . '/public');

if ($static !== null) {
    foreach (\App\Support\StaticFiles::headers($inner, $static) as $name => $value) {
        header($name . ': ' . $value);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($static);
    }
    exit;
}

// `/assets/…` that resolved to nothing is a 404, not a route. Handing a browser
// the HTML of the home page where it asked for a stylesheet is what turns a stale
// build into an unexplainable layout bug.
if (\App\Support\StaticFiles::isStaticRequest($inner)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "Not found.\n";
    exit;
}

// ---- everything else is a route --------------------------------------------
require __DIR__ . '/public/index.php';
