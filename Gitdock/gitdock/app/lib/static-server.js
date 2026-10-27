#!/usr/bin/env node
/**
 * Minimal static file server used as the "static site" project runtime.
 * usage: node static-server.js <root-dir> <port>
 */
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');

const root = path.resolve(process.argv[2] || '.');
const port = Number(process.argv[3] || 8100);

const MIME = {
  '.html': 'text/html; charset=utf-8', '.htm': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.gif': 'image/gif', '.webp': 'image/webp', '.avif': 'image/avif',
  '.ico': 'image/x-icon', '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf',
  '.txt': 'text/plain; charset=utf-8', '.md': 'text/plain; charset=utf-8',
  '.xml': 'application/xml', '.pdf': 'application/pdf', '.wasm': 'application/wasm',
  '.mp4': 'video/mp4', '.webm': 'video/webm', '.mp3': 'audio/mpeg',
};

const server = http.createServer((req, res) => {
  let rel;
  try {
    rel = decodeURIComponent(new URL(req.url, 'http://localhost').pathname);
  } catch {
    res.writeHead(400).end('bad request');
    return;
  }
  let target = path.join(root, rel);
  if (!path.resolve(target).startsWith(root)) {
    res.writeHead(403).end('forbidden');
    return;
  }
  fs.stat(target, (err, st) => {
    if (!err && st.isDirectory()) target = path.join(target, 'index.html');
    fs.stat(target, (err2, st2) => {
      if (err2 || !st2.isFile()) {
        // SPA-style fallback only for extension-less routes (/users/42), never for real files
        const looksLikeRoute = !path.extname(rel.replace(/\/$/, ''));
        const fallback = path.join(root, 'index.html');
        if (looksLikeRoute && fs.existsSync(fallback)) return send(fallback, res);
        res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
        return res.end(`404 — ${rel} not found\n`);
      }
      send(target, res);
    });
  });
});

function send(file, res) {
  const type = MIME[path.extname(file).toLowerCase()] || 'application/octet-stream';
  res.writeHead(200, { 'content-type': type, 'cache-control': 'no-store' });
  fs.createReadStream(file).pipe(res);
}

server.listen(port, '0.0.0.0', () => {
  console.log(`static server: ${root} on http://0.0.0.0:${port}`);
});
