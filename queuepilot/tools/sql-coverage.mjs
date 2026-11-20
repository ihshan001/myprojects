/**
 * Exercise every route against a running server and report SQL that failed.
 *
 * The route sweep answers "did the page 500?". This answers a different and
 * harder question: "did the query work?". Several controllers catch \Throwable
 * so a broken statement degrades to a friendly message, and the response is a
 * perfectly healthy 200. Db::run() logs those to storage/logs/app.log as
 * db.statement_failed, so this clears the log, walks the whole route table, and
 * then reports what the database layer complained about.
 *
 * It also lists POST routes that ran no SQL at all — a write path that touches
 * nothing is either a silent no-op or a route that refuses before it gets that
 * far, and both are worth seeing.
 *
 *   node tools/serve.mjs          # in one terminal
 *   node tools/sql-coverage.mjs   # in another
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.QP_BASE || 'http://127.0.0.1:8080';
const ROOT = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const LOG = path.join(ROOT, 'storage/logs/app.log');

const CLINIC = { email: 'admin@serenity.lk', password: 'Serenity#2026' };
const PLATFORM = { email: 'owner@queuepilot.app', password: 'QueuePilot#2026' };

class Client {
  constructor() { this.jar = new Map(); }
  cookie() { return [...this.jar].map(([k, v]) => `${k}=${v}`).join('; '); }
  absorb(r) {
    for (const c of (r.headers.getSetCookie?.() || [])) {
      const [p] = c.split(';'); const i = p.indexOf('=');
      if (i > 0) this.jar.set(p.slice(0, i).trim(), p.slice(i + 1).trim());
    }
  }
  async req(p, o = {}) {
    const r = await fetch(BASE + p, { ...o, redirect: 'manual', headers: { ...(o.headers || {}), cookie: this.cookie() } });
    this.absorb(r); return r;
  }
  async get(p) { const r = await this.req(p); return { status: r.status, html: await r.text() }; }
  async csrf(p) { const { html } = await this.get(p); return (html.match(/name="_token" value="([^"]+)"/) || [])[1] || ''; }
  async login(email, password) {
    const t = await this.csrf('/login');
    const r = await this.req('/login', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _token: t, email, password }).toString() });
    return r.status;
  }
  async post(p, fields = {}) {
    const t = await this.csrf('/app');
    const r = await this.req(p, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _token: t, ...fields }).toString() });
    return r.status;
  }
}

// POST /logout ends the session. Sweeping it mid-run would make every later
// route anonymous, and an anonymous POST stops at the CSRF/auth gate — which
// is exactly how a previous version of this tool reported "0 failures" while
// never executing a single write path. It is swept last, on its own client.
const LOGOUT = '/logout';

function routeTable() {
  const src = fs.readFileSync(path.join(ROOT, 'routes/web.php'), 'utf8');
  const out = [];
  for (const m of src.matchAll(/Router::(get|post)\(\s*'([^']+)'/g)) {
    out.push({ method: m[1].toUpperCase(), path: m[2] });
  }
  return out;
}

// Ids that really exist, so parameterised routes reach their SQL instead of
// returning "not found" before the query.
async function realIds(c) {
  const ids = { id: [] };
  const page = async (p) => (await c.get(p)).html;
  const push = (s, re) => { for (const m of s.matchAll(re)) if (!ids.id.includes(m[1])) ids.id.push(m[1]); };
  push(await page('/queue/entry?id=1'), /entry\?id=(\d+)/g);
  push(await page('/patients'), /href="\/patients\/(\d+)"/g);
  push(await page('/appointments'), /appointments\/(\d+)\/checkin/g);
  push(await page('/billing'), /invoices\/(\d+)/g);
  push(await page('/support'), /support\/(\d+)/g);
  return ids.id.slice(0, 6);
}

const logSize = () => { try { return fs.statSync(LOG).size; } catch { return 0; } };

function failedStatements(fromByte) {
  let text = '';
  try {
    const fd = fs.openSync(LOG, 'r');
    const size = fs.fstatSync(fd).size;
    const buf = Buffer.alloc(Math.max(0, size - fromByte));
    fs.readSync(fd, buf, 0, buf.length, fromByte);
    fs.closeSync(fd);
    text = buf.toString('utf8');
  } catch { return []; }
  const out = [];
  for (const line of text.split('\n')) {
    if (!line.includes('db.statement_failed')) continue;
    out.push(line.replace(/^\[[^\]]+\]\s*ERROR:\s*/, '').slice(0, 260));
  }
  return out;
}

const c = new Client();
const loginStatus = await c.login(CLINIC.email, CLINIC.password);
if (![200, 302].includes(loginStatus)) {
  console.log(`Could not sign in (${loginStatus}). Is the server running and seeded?`);
  process.exit(2);
}

const routes = routeTable();
const ids = await realIds(c);
console.log(`SQL coverage — ${routes.length} routes, ${ids.length} real ids available`);
console.log('-'.repeat(74));

const mark = logSize();
let get = 0, post = 0, failed = 0;
const noSqlPost = [];

async function signIn() {
  const st = await c.login(CLINIC.email, CLINIC.password);
  if (![200, 302].includes(st)) {
    console.log(`Lost the session and could not sign back in (${st}).`);
    process.exit(2);
  }
}

for (const r of routes) {
  if (r.method === 'POST' && r.path === LOGOUT) continue; // swept last
  if (r.method === 'GET') {
    if (r.path.includes('{')) continue;            // GETs with ids are done below
    const res = await c.get(r.path);
    if (process.env.QP_DEBUG_STATUS) console.log(`  GET ${r.path} -> ${res.status}`);
    if (res.status >= 500) console.log(`  5xx  GET ${r.path} -> ${res.status}`);
    get++;
  } else {
    // A POST with an empty body: the app must refuse, but the SQL it reaches
    // still runs and is what we are recording.
    if ((await c.get('/app')).status !== 200) await signIn();
    const before = logSize();
    const status = await c.post(r.path, {});
    if (process.env.QP_DEBUG_STATUS) console.log(`  POST ${r.path} -> ${status}`);
    if (status >= 500) console.log(`  5xx  POST ${r.path} -> ${status}`);
    const anySql = logSize() !== before;
    if (!anySql && !r.path.includes('{')) noSqlPost.push(r.path);
    post++;
  }
}

// Parameterised GETs, with ids that exist.
for (const id of ids) {
  for (const r of routes) {
    if (r.method !== 'GET' || !r.path.includes('{')) continue;
    const concrete = r.path.replace(/\{[a-zA-Z_]+\}/g, id);
    const res = await c.get(concrete);
    if (res.status >= 500) console.log(`  5xx  GET ${concrete} -> ${res.status}`);
    get++;
  }
}

// POST /logout last, on its own client: it must end a session, and it must not
// end the one the rest of the sweep was using.
{
  const side = new Client();
  const st = await side.login(CLINIC.email, CLINIC.password);
  if ([200, 302].includes(st)) {
    const r = await side.req(LOGOUT, {
      method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _token: await side.csrf('/app') }).toString(),
    });
    if (r.status >= 500) console.log(`  5xx  POST ${LOGOUT} -> ${r.status}`);
    post++;
  }
}

const failures = failedStatements(mark);
failed = failures.length;

console.log(`GET requests:  ${get}`);
console.log(`POST requests: ${post}`);
console.log(`SQL statements that threw: ${failed}`);
if (failures.length) {
  console.log('\nFAILED STATEMENTS:');
  for (const f of failures.slice(0, 25)) console.log('  ' + f);
} else {
  console.log('\nNo statement logged a failure — every query any route ran was accepted by the database.');
}
if (noSqlPost.length) {
  console.log('\nPOST routes that ran no SQL (refusals are fine; silent no-ops are not):');
  for (const p of noSqlPost.slice(0, 20)) console.log('  ' + p);
}
console.log('');
process.exit(failures.length ? 1 : 0);
