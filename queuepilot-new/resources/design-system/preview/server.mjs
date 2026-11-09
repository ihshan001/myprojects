/**
 * QueuePilot design preview server.
 *
 * A tiny dependency-free static + render server, so the designed screens can be
 * reviewed in a browser before Phase 1 begins. There is no backend, no database
 * and nothing persists — this renders the design, nothing more.
 *
 *   node server.mjs [port]
 */

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  home, login, receptionist, queueBoard, doctor,
  owner, patient, admin, display, components,
} from './lib/pages.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC_DIR = path.join(__dirname, 'public');
const PORT = Number(process.argv[2] || process.env.PORT || 8090);
const HOST = '0.0.0.0';

const ROUTES = {
  '/': home,
  '/login': login,
  '/receptionist': receptionist,
  '/queue': queueBoard,
  '/doctor': doctor,
  '/owner': owner,
  '/patient': patient,
  '/admin': admin,
  '/display': display,
  '/components': components,
};

const MIME = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.woff2': 'font/woff2',
  '.webmanifest': 'application/manifest+json',
};

function serveAsset(req, res, urlPath) {
  // Contain path traversal: resolve, then confirm it stays under PUBLIC_DIR.
  const rel = urlPath.replace(/^\/public\//, '');
  const target = path.resolve(PUBLIC_DIR, rel);
  if (target !== PUBLIC_DIR && !target.startsWith(PUBLIC_DIR + path.sep)) {
    res.writeHead(403).end('Forbidden');
    return;
  }
  fs.readFile(target, (err, buf) => {
    if (err) {
      res.writeHead(404, { 'content-type': 'text/plain' }).end('Not found');
      return;
    }
    res.writeHead(200, {
      'content-type': MIME[path.extname(target)] || 'application/octet-stream',
      'cache-control': 'no-cache',
    }).end(buf);
  });
}

const server = http.createServer((req, res) => {
  const urlPath = new URL(req.url, 'http://localhost').pathname;

  if (urlPath.startsWith('/public/')) return serveAsset(req, res, urlPath);

  const render = ROUTES[urlPath];
  if (!render) {
    res.writeHead(404, { 'content-type': 'text/html; charset=utf-8' })
      .end('<p>Not found. Try <a href="/">/</a>.</p>');
    return;
  }

  try {
    const html = render();
    res.writeHead(200, {
      'content-type': 'text/html; charset=utf-8',
      'cache-control': 'no-cache',
      // The preview is a design artefact with no user data; permissive framing
      // keeps it viewable in an embedded preview pane.
      'content-security-policy': "default-src 'self'; style-src 'self' 'unsafe-inline'",
    }).end(html);
  } catch (err) {
    console.error(err);
    res.writeHead(500, { 'content-type': 'text/plain' }).end('Render error: ' + err.message);
  }
});

server.listen(PORT, HOST, () => {
  const urls = Object.keys(ROUTES).join('  ');
  console.log(`QueuePilot design preview → http://${HOST}:${PORT}/`);
  console.log(`routes: ${urls}`);
});
