/*
 * QueuePilot service worker.
 *
 * Two jobs, and the second one is the reason this file exists:
 *
 *   1. Keep the shell (CSS, JS, icons) on the device, so a display or a
 *      reception tablet that loses its connection still renders the page it
 *      was on instead of a browser error. A lobby screen showing the browser's
 *      dinosaur is not a queue board.
 *
 *   2. Never serve a cached API response. A patient looking at "you are next"
 *      from forty minutes ago is worse than a patient seeing an honest
 *      "connection lost" — one is merely unhelpful, the other is false. Every
 *      /api/ request goes to the network, and a failure is a failure.
 *
 * HTML pages are network-first with a short-lived cache fallback, so a stale
 * page is possible but the staleness signalling on the page itself (the amber
 * banner and the Live pill) tells the truth about it.
 *
 * Shell assets are network-first too, NOT cache-first. Cache-first plus a
 * VERSION nobody bumps meant a rebuilt stylesheet never reached a browser
 * that had visited before: the server sent new markup, the worker answered
 * with old CSS, and the board rendered unstyled. asset() now fingerprints
 * every asset by content, and this ordering means a new build wins immediately
 * while an offline screen still falls back to the last copy it actually saw.
 */

const VERSION = 'qp-v3';
const SHELL = 'qp-shell-' + VERSION;
const PAGES = 'qp-pages-' + VERSION;

const SHELL_ASSETS = [
  '/assets/build/app.css',
  '/assets/js/app.js',
  '/assets/img/icon.svg',
  '/assets/img/icon-192.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL).then((cache) => cache.addAll(SHELL_ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys
        .filter((k) => k.startsWith('qp-') && k !== SHELL && k !== PAGES)
        .map((k) => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Cross-origin: leave it alone entirely.
  if (url.origin !== self.location.origin) return;

  // The rule that matters. Any request for data is live or it is nothing.
  if (url.pathname.startsWith('/api/')) {
    return; // no respondWith = straight to the network, no cache read
  }

  // Shell assets: network-first. A rebuild must win the moment it lands.
  // Offline, fall back to this exact version if we have it, otherwise to any
  // copy of the same path — a stale stylesheet beats an unstyled board, and
  // the page's own offline banner is already telling the truth about it.
  if (SHELL_ASSETS.includes(url.pathname)) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(SHELL).then((c) => c.put(req, copy));
          }
          return res;
        })
        .catch(async () => {
          const exact = await caches.match(req);
          if (exact) return exact;
          const anyVersion = await caches.match(url.pathname);
          return anyVersion || Response.error();
        })
    );
    return;
  }

  // Pages: network-first, fall back to the last copy, and say nothing about
  // freshness — the page's own poller owns that conversation.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(PAGES).then((c) => c.put(req, copy));
          return res;
        })
        .catch(() => caches.match(req))
    );
  }
});
