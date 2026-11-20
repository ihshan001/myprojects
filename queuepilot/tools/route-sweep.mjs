/**
 * Walk every route in the table against a running server.
 *
 *   node tools/serve.mjs            # in one terminal
 *   node tools/route-sweep.mjs      # in another
 *
 * Two checks, and they catch different things:
 *
 *   GET   every parameterless GET route, as a clinic owner and as a platform
 *         admin. A 5xx is a crash; 403 and 404 are decisions and pass.
 *   POST  every parameterless POST route, with an empty body. The correct
 *         answer to an empty form is a refusal — a validation error, a
 *         redirect, a 403 — never a 500. A 500 here means a write path
 *         dereferences something that is not there.
 *
 * This lives outside tests/acceptance.mjs on purpose: it makes roughly 130
 * requests, and the php-wasm harness the suite runs on exhausts its file
 * descriptors long before that. It needs a real server, which is also the only
 * place a 500 means what you think it means.
 *
 *   QP_BASE=http://127.0.0.1:8080 node tools/route-sweep.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.QP_BASE || 'http://127.0.0.1:8080';
const ROOT = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

const CLINIC = {
  email: process.env.QP_CLINIC_EMAIL || 'admin@serenity.lk',
  password: process.env.QP_CLINIC_PASSWORD || 'Serenity#2026',
};
const PLATFORM = {
  email: process.env.QP_PLATFORM_EMAIL || 'owner@queuepilot.app',
  password: process.env.QP_PLATFORM_PASSWORD || 'QueuePilot#2026',
};

function routes() {
  const src = fs.readFileSync(path.join(ROOT, 'routes/web.php'), 'utf8');
  const gets = new Set();
  const posts = new Set();
  for (const m of src.matchAll(/Router::(get|post)\(\s*'([^']+)'/g)) {
    if (m[2].includes('{')) continue;           // needs a token or an id
    (m[1] === 'get' ? gets : posts).add(m[2]);
  }
  return { gets: [...gets].sort(), posts: [...posts].sort() };
}

class Client {
  constructor(label) {
    this.label = label;
    this.jar = new Map();
  }

  header() {
    return { cookie: [...this.jar].map(([k, v]) => `${k}=${v}`).join('; ') };
  }

  absorb(res) {
    for (const c of res.headers.getSetCookie?.() ?? []) {
      const [pair] = c.split(';');
      const i = pair.indexOf('=');
      if (i > 0) this.jar.set(pair.slice(0, i), pair.slice(i + 1));
    }
  }

  async get(p) {
    const res = await fetch(BASE + p, { headers: this.header(), redirect: 'manual' });
    this.absorb(res);
    return res;
  }

  async post(p, fields) {
    const res = await fetch(BASE + p, {
      method: 'POST',
      redirect: 'manual',
      headers: { ...this.header(), 'content-type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(fields),
    });
    this.absorb(res);
    return res;
  }

  async login(email, password) {
    const page = await this.get('/login');
    const html = await page.text();
    const token = (html.match(/name="_token"\s+value="([^"]+)"/) || [])[1];
    const res = await this.post('/login', { _token: token ?? '', email, password });
    return res.status < 400 || res.status === 302;
  }

  /** The CSRF token rotates at login, so it must be read from a page after it. */
  async token(p = '/settings') {
    const html = await (await this.get(p)).text();
    return (html.match(/name="_token"\s+value="([^"]+)"/) || [])[1] ?? '';
  }
}

async function main() {
  const { gets, posts } = routes();
  const failures = [];

  const clinic = new Client('clinic owner');
  if (!(await clinic.login(CLINIC.email, CLINIC.password))) {
    console.error(`Could not sign in as ${CLINIC.email}. Is the server running, and the seed loaded?`);
    process.exit(2);
  }
  const platform = new Client('platform admin');
  if (!(await platform.login(PLATFORM.email, PLATFORM.password))) {
    console.error(`Could not sign in as ${PLATFORM.email}.`);
    process.exit(2);
  }

  // ---- GET
  const expected5xx = new Set(['/api/health']);   // 503 is its documented answer
  const skipGet = new Set(['/logout']);
  let getChecked = 0;
  for (const [client, label] of [[clinic, 'clinic owner'], [platform, 'platform admin']]) {
    for (const p of gets) {
      if (skipGet.has(p) || expected5xx.has(p)) continue;
      const res = await client.get(p);
      getChecked++;
      if (res.status >= 500) failures.push(`${label}: GET ${p} → HTTP ${res.status}`);
    }
  }

  // ---- POST, empty body
  const skipPost = new Set(['/logout', '/login', '/register']);
  const token = await clinic.token('/settings');
  let postChecked = 0;
  for (const p of posts) {
    if (skipPost.has(p)) continue;
    const res = await clinic.post(p, { _token: token });
    postChecked++;
    if (res.status >= 500) failures.push(`clinic owner: POST ${p} (empty body) → HTTP ${res.status}`);
  }

  console.log(`Route sweep — ${getChecked} GET requests, ${postChecked} POST requests, ${gets.length} GET and ${posts.length} POST routes in the table`);
  console.log('-'.repeat(60));
  if (failures.length) {
    console.log(`\x1b[31m${failures.length} crash(es)\x1b[0m:`);
    for (const f of failures) console.log(`  · ${f}`);
    process.exit(1);
  }
  console.log('\x1b[32mOK\x1b[0m — no route returns a 5xx to a signed-in user.');
}


main().catch((e) => { console.error(e); process.exit(1); });
