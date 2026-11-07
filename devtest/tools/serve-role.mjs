#!/usr/bin/env node
/**
 * DEVTEST server — one port, one role. No sign-in anywhere in this build.
 *
 *   node tools/serve-role.mjs --port 8081
 *
 * The only job of this file beyond the standard transport shim is to export
 * QP_PORT into the PHP process. app/Core/DevIdentity reads it, looks the port up
 * in dev/roles.json, and loads that user. Nothing else decides who you are.
 *
 * Unlike the previous sandbox, public/index.php here IS the devtest front
 * controller — the auth removal is a real edit to this copy, not an injection.
 * That is deliberate: devtest is a separate system, not a patch over the main one.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';

const ROOT = path.resolve(process.cwd());

function arg(name, fallback = null) {
  const i = process.argv.indexOf(`--${name}`);
  return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const roster = JSON.parse(fs.readFileSync(path.join(ROOT, 'dev/roles.json'), 'utf8'));
const PORT = Number(arg('port', process.env.PORT || 8080));
const HOST = process.env.HOST || '0.0.0.0';
const entry = roster.ports.find((p) => Number(p.port) === PORT);

if (!entry) {
  console.error(`[devtest] port ${PORT} is not in dev/roles.json. Known: ${roster.ports.map((p) => p.port).join(', ')}`);
  process.exit(1);
}

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

console.log(`[devtest:${PORT}] booting PHP 8.3 — ${entry.label} <${entry.email}>`);
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
await useHostFilesystem(php);
await php.run({ code: '<?php echo "warm";' });

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
  res.writeHead(200, {
    'Content-Type': MIME[ext] || 'application/octet-stream',
    'Content-Length': body.length,
    'X-Content-Type-Options': 'nosniff',
    'Cache-Control': 'no-store, must-revalidate',
  });
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
      post = Object.fromEntries(new URLSearchParams(raw.toString('utf8')));
    } else if (contentType === 'application/json') {
      rawBody = raw.toString('utf8');
      try { post = JSON.parse(rawBody) || {}; } catch { post = {}; }
    } else if (raw.length > 0) {
      rawBody = raw.toString('utf8');
    }

    const cookies = {};
    for (const part of (req.headers.cookie || '').split(';')) {
      const i = part.indexOf('=');
      if (i > 0) {
        const k = part.slice(0, i).trim();
        try { cookies[k] = decodeURIComponent(part.slice(i + 1).trim()); }
        catch { cookies[k] = part.slice(i + 1).trim(); }
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
      SERVER_PORT: String(PORT),
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

    for (const [name, value] of Object.entries(req.headers)) {
      const key = 'HTTP_' + name.toUpperCase().replace(/[^A-Z0-9]/g, '_');
      if (!(key in serverVars) && typeof value === 'string') serverVars[key] = value;
    }

    // QP_PORT is the whole identity mechanism. APP_ENV/DEBUG make errors legible.
    const envPhp = [
      `putenv("QP_PORT=${PORT}");`,
      'putenv("APP_ENV=development");',
      'putenv("APP_DEBUG=1");',
    ].join('\n');

    const code = `<?php
${envPhp}
$_SERVER = json_decode(${JSON.stringify(JSON.stringify(serverVars))}, true);
$_GET = json_decode(${JSON.stringify(JSON.stringify(Object.fromEntries(url.searchParams)))}, true);
$_POST = json_decode(${JSON.stringify(JSON.stringify(post))}, true);
$_COOKIE = json_decode(${JSON.stringify(JSON.stringify(cookies))}, true);
$_REQUEST = array_merge($_GET, $_POST);
$__metaSent = false;

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
        $binary = !mb_check_encoding($body, 'UTF-8');
        if ($binary) { $meta['encoding'] = 'base64'; $body = base64_encode($body); }
        echo "@@QP_META@@" . json_encode($meta) . "\\n" . $body;
        $__metaSent = true;
    }
});

chdir(${JSON.stringify(ROOT + '/public')});
require ${JSON.stringify(ROOT + '/public/index.php')};
`;

    const result = await php.run({ code });
    const text = result.text ?? '';
    if (result.errors) console.error(`[devtest:${PORT} stderr]`, String(result.errors).slice(0, 1500));

    const marker = '@@QP_META@@';
    const idx = text.indexOf(marker);
    let meta = { status: 200, headers: {} };
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

    const payload = meta.encoding === 'base64' ? Buffer.from(body, 'base64') : body;
    const headers = { ...meta.headers };
    if (!Object.keys(headers).some((k) => k.toLowerCase() === 'content-type')) {
      headers['Content-Type'] = 'text/html; charset=utf-8';
    }
    delete headers['Content-Length'];
    headers['Content-Length'] = Buffer.byteLength(payload);
    headers['Cache-Control'] = 'no-store, must-revalidate';
    res.writeHead(meta.status || 200, headers);
    res.end(req.method === 'HEAD' ? undefined : payload);
    console.log(`[devtest:${PORT}] ${req.method} ${url.pathname} → ${meta.status} (${Date.now() - started}ms)`);
  } catch (err) {
    console.error(`[devtest:${PORT}] request failed`, err);
    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('devtest error: ' + String(err?.message || err));
  }
});

server.listen(PORT, HOST, () => {
  console.log(`[devtest:${PORT}] ready → http://${HOST}:${PORT}/  ${entry.label} (${entry.role})`);
});

process.on('SIGTERM', () => server.close(() => process.exit(0)));
process.on('SIGINT', () => server.close(() => process.exit(0)));
