/**
 * Preview the real QueuePilot homepage (and its login/register/pricing links)
 * on its own port, WITHOUT editing queuepilot/.
 *
 * queuepilot is the pristine reference app. Its `Response.php` quite correctly
 * sends `X-Frame-Options: SAMEORIGIN` and `frame-ancestors 'self'`, which is
 * exactly what you want in production — but the Arena preview renders the app
 * in an iframe on a different origin, so those two headers blank the preview.
 *
 * This shim changes the TRANSPORT only: it chdirs into queuepilot, strips the
 * two framing headers on the way out, and then runs queuepilot's own unmodified
 * serve.mjs. No queuepilot source, view, or asset is touched, and the real
 * deployment keeps its clickjacking protection.
 *
 *   PORT=8085 node tools/serve-landing.mjs      (from devtest/)
 */
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REFERENCE = path.resolve(HERE, '..', '..', 'queuepilot');

process.env.PORT = process.env.PORT || '8085';
// Must be reachable from outside the sandbox for the preview proxy.
process.env.HOST = process.env.HOST || '0.0.0.0';
process.chdir(REFERENCE);

const strip = (headers) => {
  if (!headers) return headers;
  // queuepilot's own serve.mjs never defaults Content-Type: PHP's implicit
  // text/html does not appear in headers_list(), so the response goes out with
  // no Content-Type at all. Combined with `X-Content-Type-Options: nosniff` the
  // browser refuses to guess and prints the page as escaped source. devtest's
  // serve-role.mjs already carries this fix; apply it here too, transport-only.
  const hasType = (names) => names.some((n) => String(n).toLowerCase() === 'content-type');
  if (Array.isArray(headers)) {
    // writeHead can be given a flat [name, value, name, value, ...] array.
    const out = [];
    for (let i = 0; i < headers.length; i += 2) {
      const name = String(headers[i]).toLowerCase();
      if (name === 'x-frame-options') continue;
      if (name === 'content-security-policy') {
        out.push(headers[i], String(headers[i + 1]).replace(/frame-ancestors[^;]*(;|$)/gi, ''));
        continue;
      }
      out.push(headers[i], headers[i + 1]);
    }
    if (!hasType(out.filter((_, i) => i % 2 === 0))) out.push('Content-Type', 'text/html; charset=utf-8');
    return out;
  }
  if (typeof headers === 'object') {
    if (!hasType(Object.keys(headers))) headers['Content-Type'] = 'text/html; charset=utf-8';
    for (const key of Object.keys(headers)) {
      const name = key.toLowerCase();
      if (name === 'x-frame-options') delete headers[key];
      else if (name === 'content-security-policy') {
        headers[key] = String(headers[key]).replace(/frame-ancestors[^;]*(;|$)/gi, '');
      }
    }
  }
  return headers;
};

const originalWriteHead = http.ServerResponse.prototype.writeHead;
http.ServerResponse.prototype.writeHead = function (status, a, b) {
  // (status, headers) or (status, message, headers)
  if (typeof a === 'string') return originalWriteHead.call(this, status, a, strip(b));
  return originalWriteHead.call(this, status, strip(a), b);
};

const originalSetHeader = http.ServerResponse.prototype.setHeader;
http.ServerResponse.prototype.setHeader = function (name, value) {
  const lower = String(name).toLowerCase();
  if (lower === 'x-frame-options') return this;
  if (lower === 'content-security-policy') {
    value = String(value).replace(/frame-ancestors[^;]*(;|$)/gi, '');
  }
  return originalSetHeader.call(this, name, value);
};

console.log('[landing] previewing the unmodified queuepilot app for the homepage');
console.log('[landing] framing headers relaxed for the preview proxy only');
await import(pathToFileURL(path.join(REFERENCE, 'tools', 'serve.mjs')).href);
