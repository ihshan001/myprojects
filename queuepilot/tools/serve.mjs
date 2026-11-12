#!/usr/bin/env node
/**
 * QueuePilot dev/preview server.
 *
 * This sandbox has no native PHP binary, so PHP 8.3 runs inside the official
 * PHP WASM runtime (@php-wasm). This file is a *transport shim only*: it maps
 * HTTP <-> $_SERVER/$_GET/$_POST/$_COOKIE/$_FILES, then hands the request to
 * the exact same public/index.php a normal PHP-FPM/Apache host would execute.
 *
 * On a real server this file is not used at all:
 *     php -S 0.0.0.0:8080 -t public      (dev)
 *     nginx + php-fpm                     (production)
 *
 * The host filesystem is mounted read/write, so the SQLite database, uploads
 * and logs persist between requests exactly like they would on disk.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { expandPhpKeys } from './form-parser.mjs';

const ROOT = path.resolve(process.cwd());
const PORT = Number(process.env.PORT || 8080);
const HOST = process.env.HOST || '0.0.0.0';

const MIME = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
};

console.log('[queuepilot] booting PHP 8.3 wasm runtime…');
const bootStart = Date.now();
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
await useHostFilesystem(php);
console.log(`[queuepilot] runtime ready in ${Date.now() - bootStart}ms`);

// Warm the opcache/autoloader once so the first real request is fast.
await php.run({ code: '<?php echo "warm";' });

/** Serve a static file from public/ when it exists (mirrors nginx try_files). */
function serveStatic(req, res, pathname) {
  if (req.method !== 'GET' && req.method !== 'HEAD') return false;
  if (pathname === '/' || pathname.endsWith('/')) return false;
  const safe = path.normalize(pathname).replace(/^(\.\.[/\\])+/, '');
  if (safe.startsWith('..')) return false;
  const file = path.join(ROOT, 'public', safe);
  if (!file.startsWith(path.join(ROOT, 'public'))) return false;
  if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return false;

  const ext = path.extname(file).toLowerCase();
  const body = fs.readFileSync(file);
  const headers = {
    'Content-Type': MIME[ext] || 'application/octet-stream',
    'Content-Length': body.length,
    'X-Content-Type-Options': 'nosniff',
  };
  // Hashed build assets are immutable; service worker must never be stale-cached.
  if (pathname.startsWith('/assets/build/')) {
    headers['Cache-Control'] = 'public, max-age=31536000, immutable';
  } else if (pathname === '/sw.js' || pathname === '/manifest.webmanifest') {
    headers['Cache-Control'] = 'no-cache';
  } else {
    headers['Cache-Control'] = 'public, max-age=3600';
  }
  res.writeHead(200, headers);
  res.end(req.method === 'HEAD' ? undefined : body);
  return true;
}

function readBody(req) {
  return new Promise((resolve) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks)));
  });
}

const server = http.createServer(async (req, res) => {
  const started = Date.now();
  const url = new URL(req.url, 'http://' + (req.headers.host || 'localhost'));

  try {
    if (serveStatic(req, res, decodeURIComponent(url.pathname))) return;

    const raw = await readBody(req);
    const contentType = (req.headers['content-type'] || '').split(';')[0].trim();
    let post = {};
    let rawBody = '';

    if (contentType === 'application/x-www-form-urlencoded') {
      post = expandPhpKeys([...new URLSearchParams(raw.toString('utf8')).entries()]);
    } else if (contentType === 'application/json') {
      rawBody = raw.toString('utf8');
      try {
        post = JSON.parse(rawBody) || {};
      } catch {
        post = {};
      }
    } else if (raw.length > 0) {
      rawBody = raw.toString('utf8');
    }

    const cookies = {};
    for (const part of (req.headers.cookie || '').split(';')) {
      const i = part.indexOf('=');
      if (i > 0) {
        const k = part.slice(0, i).trim();
        try {
          cookies[k] = decodeURIComponent(part.slice(i + 1).trim());
        } catch {
          cookies[k] = part.slice(i + 1).trim();
        }
      }
    }

    const serverVars = {
      REQUEST_URI: req.url,
      REQUEST_METHOD: req.method,
      QUERY_STRING: url.search.slice(1),
      SCRIPT_NAME: '/index.php',
      PHP_SELF: '/index.php',
      DOCUMENT_ROOT: path.join(ROOT, 'public'),
      SERVER_NAME: url.hostname,
      SERVER_PORT: url.port || (url.protocol === 'https:' ? '443' : '80'),
      HTTP_HOST: req.headers.host || 'localhost',
      HTTP_USER_AGENT: req.headers['user-agent'] || '',
      HTTP_ACCEPT: req.headers.accept || '*/*',
      HTTP_ACCEPT_LANGUAGE: req.headers['accept-language'] || '',
      CONTENT_TYPE: req.headers['content-type'] || '',
      REMOTE_ADDR: req.headers['x-forwarded-for']?.split(',')[0]?.trim() || '127.0.0.1',
      HTTPS: (req.headers['x-forwarded-proto'] || '') === 'https' ? 'on' : '',
      HTTP_X_FORWARDED_PROTO: req.headers['x-forwarded-proto'] || '',
      HTTP_X_REQUESTED_WITH: req.headers['x-requested-with'] || '',
      HTTP_REFERER: req.headers.referer || '',
      REQUEST_TIME: Math.floor(started / 1000),
      QP_RAW_BODY: rawBody,
    };

    // Forward every request header the app may inspect, as the CGI/FastCGI
    // conventions expect (HTTP_X_CSRF_TOKEN, HTTP_X_REQUESTED_WITH, custom
    // client headers ...). Browsers can send anything; PHP sees $_SERVER only
    // for what we copy here, so missing this silently broke JSON + CSRF.
    for (const [name, value] of Object.entries(req.headers)) {
      const key = 'HTTP_' + name.toUpperCase().replace(/[^A-Z0-9]/g, '_');
      if (!(key in serverVars) && typeof value === 'string') {
        serverVars[key] = value;
      }
    }

    const code = `<?php
$_SERVER = json_decode(${JSON.stringify(JSON.stringify(serverVars))}, true);
$_GET = json_decode(${JSON.stringify(JSON.stringify(Object.fromEntries(url.searchParams)))}, true);
$_POST = json_decode(${JSON.stringify(JSON.stringify(post))}, true);
$_COOKIE = json_decode(${JSON.stringify(JSON.stringify(cookies))}, true);
$_REQUEST = array_merge($_GET, $_POST);
$__rawBody = json_decode(${JSON.stringify(JSON.stringify(rawBody))}, true);
$__metaSent = false;

/* Collect status + headers, capture the body, then emit one framed response
   that this shim splits back into real HTTP. */
ob_start();
register_shutdown_function(function () use (&$__metaSent) {
    $body = '';
    while (ob_get_level() > 0) { $body = ob_get_clean() . $body; }
    if (empty($__metaSent)) {
        $meta = ['status' => http_response_code() ?: 200, 'headers' => []];
        foreach (headers_list() as $h) {
            $p = strpos($h, ':');
            if ($p !== false) { $meta['headers'][substr($h, 0, $p)] = trim(substr($h, $p + 1)); }
        }
        /* Binary responses cannot travel through the JS string channel this
           shim uses: wasm->JS conversion replaces every non-UTF-8 byte with
           U+FFFD, which silently corrupts a zip or a photo while still
           reporting the right Content-Length. Anything that is not text is
           base64'd and marked, then decoded on the JS side. */
        $binary = !mb_check_encoding($body, 'UTF-8');
        if ($binary) {
            $meta['encoding'] = 'base64';
            $body = base64_encode($body);
        }
        echo "@@QP_META@@" . json_encode($meta) . "\\n" . $body;
        $__metaSent = true;
    }
});

chdir(${JSON.stringify(ROOT + '/public')});
require ${JSON.stringify(ROOT + '/public/index.php')};
`;
    const result = await php.run({ code });
    const text = result.text ?? '';
    if (result.errors) {
      console.error('[php stderr]', String(result.errors).slice(0, 2000));
    }

    const marker = '@@QP_META@@';
    const idx = text.indexOf(marker);
    let meta = { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } };
    let body = text;
    if (idx === 0) {
      const nl = text.indexOf('\n');
      meta = JSON.parse(text.slice(marker.length, nl));
      body = text.slice(nl + 1);
    } else if (idx > 0) {
      const nl = text.indexOf('\n', idx);
      meta = JSON.parse(text.slice(idx + marker.length, nl));
      body = text.slice(0, idx) + text.slice(nl + 1);
    }

    // A binary body arrives base64'd so the string channel cannot mangle it.
    let payload = body;
    if (meta.encoding === 'base64') payload = Buffer.from(body, 'base64');

    const headers = { ...meta.headers };
    // PHP's implicit text/html never appears in headers_list(), so a page
    // response arrives with NO Content-Type. With X-Content-Type-Options:
    // nosniff (set by Response) the browser then refuses to guess and prints
    // the markup as escaped source. Default it here — tools/serve-role.mjs
    // and serve-landing.mjs already carry this fix; anything genuinely
    // non-HTML (QR png, JSON, zip) sends its own header and keeps it.
    if (!Object.keys(headers).some((k) => k.toLowerCase() === 'content-type')) {
      headers['Content-Type'] = 'text/html; charset=utf-8';
    }
    delete headers['Content-Length'];
    headers['Content-Length'] = Buffer.byteLength(payload);
    res.writeHead(meta.status || 200, headers);
    res.end(req.method === 'HEAD' ? undefined : payload);
    console.log(`${req.method} ${url.pathname}${url.search} → ${meta.status} (${Date.now() - started}ms)`);
  } catch (err) {
    console.error('[queuepilot] request failed', err);
    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('Internal error: ' + String(err && err.message ? err.message : err));
  }
});

server.listen(PORT, HOST, () => {
  console.log(`[queuepilot] listening on http://${HOST}:${PORT}`);
});

process.on('SIGTERM', () => server.close(() => process.exit(0)));
process.on('SIGINT', () => server.close(() => process.exit(0)));
