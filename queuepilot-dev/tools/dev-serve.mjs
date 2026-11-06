#!/usr/bin/env node
/**
 * DEV SANDBOX server — one port, one signed-in role.
 *
 *   node tools/dev-serve.mjs --port 8080
 *
 * This is a transport shim, exactly like tools/serve.mjs, with one addition:
 * it injects dev/auto-signin.php into the request so the port becomes a
 * specific person without a login form.
 *
 * HOW THE INJECTION STAYS OUT OF THE APP
 * --------------------------------------
 * public/index.php is NOT edited. This script reads it, replaces the single
 * `Session::start();` call with a require of dev/auto-signin.php (which sets a
 * per-port session and then starts it), and writes the result to
 * storage/dev-front-<port>.php. That file sits ONE level below the project
 * root, exactly like public/, so `dirname(__DIR__)` inside it still resolves to
 * the project root and every path in index.php keeps working.
 *
 * The consequence is the point: `app/`, `resources/`, `routes/` and `public/`
 * stay byte-identical to the main system, so tools/promote.mjs is a plain copy
 * and a diff means a real change. Nothing in the app knows this file exists.
 *
 * storage/ is never served statically (only public/ is), so the generated
 * front controller is not reachable over HTTP.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';

const ROOT = path.resolve(process.cwd());

/* ------------------------------------------------------------- identity */
function arg(name, fallback = null) {
  const i = process.argv.indexOf(`--${name}`);
  return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const roster = JSON.parse(fs.readFileSync(path.join(ROOT, 'dev/roles.json'), 'utf8'));
const PORT = Number(arg('port', process.env.PORT || 8080));
const HOST = process.env.HOST || '0.0.0.0';
const entry = roster.ports.find((p) => Number(p.port) === PORT);

if (!entry) {
  console.error(`[dev] port ${PORT} is not in dev/roles.json. Known ports: ${roster.ports.map((p) => p.port).join(', ')}`);
  process.exit(1);
}

/* ------------------------------------------- generated front controller */
const indexPath = path.join(ROOT, 'public/index.php');
const source = fs.readFileSync(indexPath, 'utf8');
const anchor = 'Session::start();';

if (!source.includes(anchor)) {
  console.error(
    `[dev] could not find "${anchor}" in public/index.php.\n` +
    '      The dev harness injects there. index.php has changed — update dev-serve.mjs to match.'
  );
  process.exit(1);
}

const autoSignin = path.join(ROOT, 'dev/auto-signin.php');
const injected = source.replace(
  anchor,
  `require ${JSON.stringify(autoSignin)}; // dev harness: sets a per-port session, then starts it`
);

// MUST be exactly one level below ROOT, like public/ is: index.php resolves
// bootstrap with dirname(__DIR__), so __DIR__ has to be a direct child of the
// project root. storage/dev/front.php would be two levels and break every
// require in the file.
const devDir = path.join(ROOT, 'storage');
fs.mkdirSync(devDir, { recursive: true });
const frontPath = path.join(devDir, `dev-front-${PORT}.php`);
fs.writeFileSync(frontPath, injected);

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

console.log(`[dev:${PORT}] booting PHP 8.3 wasm — ${entry.label} <${entry.email}>`);
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
await useHostFilesystem(php);
await php.run({ code: '<?php echo "warm";' });

/* --------------------------------------------------------- dev banner */
// Injected into HTML responses here, not in a view, so no app file changes.
function banner(html) {
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const bar = `
<div id="qp-dev-banner" style="position:fixed;bottom:0;left:0;right:0;z-index:2147483647;
     background:#7c2d12;color:#ffedd5;font:600 11px/1.5 ui-monospace,Menlo,Consolas,monospace;
     padding:5px 10px;display:flex;gap:10px;align-items:center;border-top:1px solid #fb923c;
     letter-spacing:.02em">
  <span style="background:#fb923c;color:#431407;padding:1px 6px;border-radius:3px;font-weight:800">DEV</span>
  <span>port ${esc(PORT)}</span><span style="opacity:.5">|</span>
  <span>${esc(entry.label)}</span><span style="opacity:.5">|</span>
  <span style="opacity:.85">${esc(entry.email)}</span>
  <span style="margin-left:auto;opacity:.7">queuepilot-dev — not the main system</span>
  <button onclick="this.parentNode.remove()" style="background:none;border:0;color:#ffedd5;
     cursor:pointer;font-size:14px;opacity:.6;padding:0 2px" aria-label="Hide">&times;</button>
</div>`;
  return html.includes('</body>') ? html.replace('</body>', bar + '\n</body>') : html + bar;
}

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
    // Never cache in dev: a stale stylesheet looks like a broken change.
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

    for (const [name, value] of Object.entries(req.headers)) {
      const key = 'HTTP_' + name.toUpperCase().replace(/[^A-Z0-9]/g, '_');
      if (!(key in serverVars) && typeof value === 'string') serverVars[key] = value;
    }

    const env = {
      QP_DEV_MODE: '1',
      QP_DEV_PORT: String(PORT),
      QP_DEV_EMAIL: entry.email,
      QP_DEV_LABEL: entry.label,
      APP_ENV: 'development',
      APP_DEBUG: '1',
    };
    const envPhp = Object.entries(env)
      .map(([k, v]) => `putenv(${JSON.stringify(`${k}=${v}`)});`)
      .join('\n');

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
require ${JSON.stringify(frontPath)};
`;

    const result = await php.run({ code });
    const text = result.text ?? '';
    if (result.errors) console.error(`[dev:${PORT} stderr]`, String(result.errors).slice(0, 1500));

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

    // PHP's default Content-Type is not always present in headers_list(), so an
    // absent header means HTML rather than "not HTML". Case-insensitive because
    // the app and PHP disagree about the capitalisation.
    const ctKey = Object.keys(meta.headers).find((k) => k.toLowerCase() === 'content-type');
    const ctype = String((ctKey && meta.headers[ctKey]) || 'text/html; charset=utf-8');

    let payload = body;
    if (meta.encoding === 'base64') {
      payload = Buffer.from(body, 'base64');
    } else if (ctype.includes('text/html')) {
      payload = banner(body);
    }

    const headers = { ...meta.headers };
    if (!ctKey) headers['Content-Type'] = ctype;
    delete headers['Content-Length'];
    headers['Content-Length'] = Buffer.byteLength(payload);
    headers['Cache-Control'] = 'no-store, must-revalidate';
    res.writeHead(meta.status || 200, headers);
    res.end(req.method === 'HEAD' ? undefined : payload);
    console.log(`[dev:${PORT}] ${req.method} ${url.pathname} → ${meta.status} (${Date.now() - started}ms)`);
  } catch (err) {
    console.error(`[dev:${PORT}] request failed`, err);
    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('Dev server error: ' + String(err?.message || err));
  }
});

server.listen(PORT, HOST, () => {
  console.log(`[dev:${PORT}] ready → http://${HOST}:${PORT}${entry.home}  (${entry.label})`);
});

function shutdown() {
  try { fs.unlinkSync(frontPath); } catch {}
  server.close(() => process.exit(0));
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
