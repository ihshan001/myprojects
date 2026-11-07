#!/usr/bin/env node
/**
 * Screenshots Gallery Server
 * Serves screenshots as a live preview gallery for viewing and downloading.
 */

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.cwd();
const PORT = Number(process.env.PORT || 8084);
const HOST = process.env.HOST || '0.0.0.0';

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.png': 'image/png',
  '.webp': 'image/webp',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
};

function listImages(dir) {
  try {
    return fs.readdirSync(dir)
      .filter(f => {
        const ext = path.extname(f).toLowerCase();
        return ['.png', '.webp', '.jpg', '.jpeg'].includes(ext);
      })
      .sort();
  } catch {
    return [];
  }
}

function galleryHtml(images) {
  const items = images.map(img => {
    const ext = path.extname(img).toLowerCase();
    return `
      <div class="card">
        <a href="/view/${encodeURIComponent(img)}">
          <img src="/image/${encodeURIComponent(img)}" alt="${img}" loading="lazy">
        </a>
        <div class="info">
          <div class="name">${img}</div>
          <div class="size">${formatSize(fs.statSync(path.join(ROOT, img)).size)}</div>
          <a href="/download/${encodeURIComponent(img)}" class="download">Download</a>
        </div>
      </div>
    `;
  }).join('');

  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>QueuePilot Screenshots Gallery</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; color: #1f2937; padding: 2rem; }
    header { max-width: 1200px; margin: 0 auto 2rem; }
    h1 { font-size: 1.5rem; font-weight: 700; color: #111827; margin-bottom: 0.5rem; }
    p.subtitle { color: #6b7280; font-size: 0.9rem; }
    .grid { max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
    .card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 0.75rem; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .card:hover { transform: translateY(-4px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
    .card img { width: 100%; height: 200px; object-fit: cover; display: block; }
    .card .info { padding: 1rem; }
    .card .name { font-size: 0.875rem; font-weight: 600; color: #374151; margin-bottom: 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .card .size { font-size: 0.75rem; color: #9ca3af; margin-bottom: 0.75rem; }
    .card .download { display: inline-block; font-size: 0.75rem; font-weight: 600; color: #2563eb; text-decoration: none; border: 1px solid #d1d5db; border-radius: 0.375rem; padding: 0.25rem 0.5rem; }
    .card .download:hover { background: #eff6ff; border-color: #2563eb; }
    .empty { max-width: 1200px; margin: 0 auto; text-align: center; color: #6b7280; padding: 3rem; }
  </style>
</head>
<body>
  <header>
    <h1>📸 QueuePilot Screenshots Gallery</h1>
    <p class="subtitle">Role-based dashboards, patient phone board, lobby TV display, token slips, and more. Click an image to view full size.</p>
  </header>

  ${images.length === 0
    ? `<div class="empty">No screenshots found. Add images to the <code>screenshots/</code> folder.</div>`
    : `<div class="grid">${items}</div>`}
</body>
</html>`;
}

function viewHtml(imgName) {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${imgName} - QueuePilot Screenshots</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #111827; color: #f9fafb; }
    header { padding: 1rem 2rem; border-bottom: 1px solid #374151; display: flex; justify-content: space-between; align-items: center; }
    h1 { font-size: 0.875rem; font-weight: 600; color: #9ca3af; }
    .back { color: #2563eb; text-decoration: none; font-size: 0.875rem; font-weight: 500; }
    .back:hover { text-decoration: underline; }
    .container { max-width: 1200px; margin: 0 auto; padding: 2rem; display: flex; flex-direction: column; align-items: center; }
    .image-wrapper { border: 1px solid #374151; border-radius: 0.5rem; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); max-width: 100%; }
    .image-wrapper img { display: block; max-width: 100%; height: auto; }
    .meta { margin-top: 1rem; text-align: center; }
    .name { color: #f3f4f6; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.25rem; }
    .size { color: #9ca3af; font-size: 0.75rem; }
    .actions { margin-top: 1rem; display: flex; gap: 0.5rem; }
    .btn { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.5rem 1rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 500; text-decoration: none; }
    .btn-primary { background: #2563eb; color: #ffffff; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-secondary { background: #374151; color: #e5e7eb; border: 1px solid #4b5563; }
    .btn-secondary:hover { background: #4b5563; }
  </style>
</head>
<body>
  <header>
    <a href="/" class="back">← Back to gallery</a>
    <h1>${imgName}</h1>
  </header>
  <div class="container">
    <div class="image-wrapper">
      <img src="/image/${encodeURIComponent(imgName)}" alt="${imgName}">
    </div>
    <div class="meta">
      <div class="name">${imgName}</div>
      <div class="size">${formatSize(fs.statSync(path.join(ROOT, imgName)).size)} bytes</div>
    </div>
    <div class="actions">
      <a href="/image/${encodeURIComponent(imgName)}" class="btn btn-primary">View Image</a>
      <a href="/download/${encodeURIComponent(imgName)}" class="btn btn-primary">Download</a>
      <a href="/" class="btn btn-secondary">Back to Gallery</a>
    </div>
  </div>
</body>
</html>`;
}

function formatSize(bytes) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);
  const pathname = url.pathname;

  if (pathname === '/') {
    const images = listImages(ROOT);
    const html = galleryHtml(images);
    res.writeHead(200, { 'Content-Type': MIME['.html'] });
    res.end(html);
    return;
  }

  if (pathname.startsWith('/image/')) {
    const imgName = decodeURIComponent(pathname.slice(7));
    const filePath = path.join(ROOT, imgName);
    try {
      const stat = fs.statSync(filePath);
      const ext = path.extname(imgName).toLowerCase();
      res.writeHead(200, {
        'Content-Type': MIME[ext] || 'application/octet-stream',
        'Content-Length': stat.size,
        'Cache-Control': 'public, max-age=3600',
      });
      fs.createReadStream(filePath).pipe(res);
    } catch {
      res.writeHead(404, { 'Content-Type': MIME['.html'] });
      res.end('Image not found');
    }
    return;
  }

  if (pathname.startsWith('/view/')) {
    const imgName = decodeURIComponent(pathname.slice(6));
    const filePath = path.join(ROOT, imgName);
    try {
      fs.statSync(filePath);
      res.writeHead(200, { 'Content-Type': MIME['.html'] });
      res.end(viewHtml(imgName));
    } catch {
      res.writeHead(404, { 'Content-Type': MIME['.html'] });
      res.end('Image not found');
    }
    return;
  }

  if (pathname.startsWith('/download/')) {
    const imgName = decodeURIComponent(pathname.slice(10));
    const filePath = path.join(ROOT, imgName);
    try {
      const stat = fs.statSync(filePath);
      const ext = path.extname(imgName).toLowerCase();
      res.writeHead(200, {
        'Content-Type': MIME[ext] || 'application/octet-stream',
        'Content-Disposition': `attachment; filename="${imgName}"`,
        'Content-Length': stat.size,
      });
      fs.createReadStream(filePath).pipe(res);
    } catch {
      res.writeHead(404, { 'Content-Type': MIME['.html'] });
      res.end('Image not found');
    }
    return;
  }

  res.writeHead(404, { 'Content-Type': MIME['.html'] });
  res.end('Not found');
});

server.listen(PORT, HOST, () => {
  console.log(`Screenshot gallery server listening on http://${HOST}:${PORT}`);
  console.log(`Serving images from: ${ROOT}`);
});
