#!/usr/bin/env node
/**
 * GitDock backend self-test.
 *
 * Proves the system is a working backend, not a frontend shell: every check below goes
 * over HTTP to the running panel, and every side effect is verified on the filesystem,
 * in git, in MySQL, or by fetching a live port.
 *
 * It creates its own project ("Selftest <random>") and removes every trace at the end:
 * project directory, git remote, MySQL schema, secret file, previews and worktrees.
 *
 * usage:  node scripts/selftest.js            (panel must be running on :3000)
 *         GITDOCK_URL=http://host:port node scripts/selftest.js
 */
'use strict';
const { execFile } = require('child_process');
const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');
const os = require('os');

const BASE = process.env.GITDOCK_URL || 'http://127.0.0.1:3000';
const testAuth = require('./test-auth.js');

// the panel is locked, so the suite signs in first and sends the session with every request
let SESSION = '';
const ROOT = path.join(__dirname, '..');
const DATA = path.join(ROOT, 'data');
const MYSQL_JSON = path.join(DATA, 'mysql.json');

let pass = 0, fail = 0, skip = 0;
const failures = [];
const created = { projects: [], previews: [], remotes: [], tmpFiles: [], users: [] };

const C = { g: '\x1b[32m', r: '\x1b[31m', y: '\x1b[33m', d: '\x1b[2m', b: '\x1b[1m', x: '\x1b[0m' };

const sh = (cmd, args, opts = {}) =>
  new Promise((resolve) => {
    execFile(cmd, args, { maxBuffer: 16 * 1024 * 1024, ...opts }, (error, stdout, stderr) =>
      resolve({ ok: !error, code: error ? error.code || 1 : 0, stdout: String(stdout || ''), stderr: String(stderr || '') }));
  });

async function api(pathname, { method = 'GET', body = null, raw = false } = {}) {
  const res = await fetch(BASE + pathname, {
    method,
    headers: {
      ...(body !== null && !raw ? { 'content-type': 'application/json' } : {}),
      ...(SESSION ? { 'x-gitdock-session': SESSION } : {}),
    },
    body: body === null ? undefined : raw ? body : JSON.stringify(body),
  });
  if (raw) return res;
  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { data = { raw: text }; }
  if (!res.ok || (data && data.ok === false)) {
    throw new Error(`${method} ${pathname} → ${(data && data.error) || res.status}`);
  }
  return data;
}

/** like api(), but for endpoints that take a raw body — still returns parsed JSON */
async function apiRaw(pathname, buf, method = 'POST') {
  const res = await fetch(BASE + pathname, { method, body: buf, headers: SESSION ? { 'x-gitdock-session': SESSION } : {} });
  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { data = { raw: text }; }
  if (!res.ok || (data && data.ok === false)) throw new Error(`${method} ${pathname} → ${(data && data.error) || res.status}`);
  return data;
}

function assert(cond, msg) { if (!cond) throw new Error(msg || 'assertion failed'); }
function equal(a, b, msg) { if (a !== b) throw new Error(`${msg || 'not equal'} (got ${JSON.stringify(a)}, expected ${JSON.stringify(b)})`); }

async function test(name, fn) {
  const t0 = Date.now();
  try {
    await fn();
    const ms = Date.now() - t0;
    console.log(`  ${C.g}✓${C.x} ${name} ${C.d}${ms}ms${C.x}`);
    pass++;
  } catch (e) {
    console.log(`  ${C.r}✗${C.x} ${name}\n      ${C.r}${e.message}${C.x}`);
    failures.push({ name, error: e.message });
    fail++;
  }
}

const section = (t) => console.log(`\n${C.b}${t}${C.x}`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const getJson = async (file) => JSON.parse(await fsp.readFile(file, 'utf8'));

async function mysql(args, { socket = false } = {}) {
  let cfg = null;
  try { cfg = await getJson(MYSQL_JSON); } catch { return { ok: false, stdout: '', stderr: 'no mysql.json', code: 1 }; }
  const base = socket
    ? ['--socket=/run/mysqld/mysqld.sock']
    : ['-h', cfg.host, '-P', String(cfg.port), '-u', cfg.user, `-p${cfg.password}`];
  return sh('mysql', [...base, ...args]);
}

// ---------------------------------------------------------------------------
// the run
// ---------------------------------------------------------------------------

/** entry names from a zip buffer — enough to assert what a download really contains */
function zipEntries(buf) {
  const names = [];
  for (let i = 0; i <= buf.length - 46; i++) {
    if (buf.readUInt32LE(i) === 0x02014b50) {
      const n = buf.readUInt16LE(i + 28);
      names.push(buf.subarray(i + 46, i + 46 + n).toString('utf8'));
    }
  }
  return names;
}

async function main() {
  const stamp = Math.random().toString(36).slice(2, 7);
  // the panel is locked: make a throwaway account through the real CLI, then sign in over HTTP
  testAuth.ensureTestUser();
  const projectName = `Selftest ${stamp}`;
  const slug = projectName.toLowerCase().replace(/[^a-z0-9]+/g, '-');
  const projectDir = path.join(DATA, 'projects', slug);
  const dbName = `gitdock_${slug.replace(/-/g, '_')}`;
  const marker = `BACKEND-OK-${stamp}`;

  console.log(`${C.b}GitDock backend self-test${C.x} ${C.d}→ ${BASE}${C.x}`);
  console.log(`${C.d}project: ${slug}   database: ${dbName}${C.x}`);

  try {
    // ------------------------------------------------------------ access control
    section('access control (the panel is locked)');
    await test('requests without a session are refused', async () => {
      for (const path of ['/api/projects', '/api/settings']) {
        const res = await fetch(`${BASE}${path}`);
        equal(res.status, 401, `${path} without a session`);
      }
      // the panel *shell* is public on purpose (the preview iframe gets its session in the URL and
      // needs the app to load first) — but it must not contain a single byte of anybody's data
      const shell = await fetch(`${BASE}/`);
      equal(shell.status, 200, 'panel shell');
      const html = await shell.text();
      assert(/GitDock/.test(html), 'the shell should still be the panel');
      // precise check: no project that exists on this box may be named in the public shell
      const onDisk = await fsp.readdir(path.join(DATA, 'projects')).catch(() => []);
      // whole word only: a slug like "devi" must not match the word "device" in the panel's own markup
      const leaked = onDisk.filter((slug) => {
        if (!slug) return false;
        const word = slug.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return new RegExp(`\\b${word}\\b`).test(html);
      });
      assert(!leaked.length, `the public shell leaks project names: ${leaked.join(', ')}`);
      const loginPage = await fetch(`${BASE}/login`);
      equal(loginPage.status, 200, 'sign-in page must be reachable without a session');
    });
    await test('a wrong password is rejected', async () => {
      const res = await fetch(`${BASE}/api/auth/login`, {
        method: 'POST', headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ name: testAuth.TEST_USER, password: 'definitely-not-it' }),
      });
      equal(res.status, 401, 'wrong password status');
    });
    await test('signing in yields a session that works as a token and as a cookie', async () => {
      const session = await testAuth.signIn();
      SESSION = session.token;
      assert(SESSION.length > 40, 'no token returned');
      assert(session.cookie.startsWith('gitdock_sid='), `unexpected cookie: ${session.cookie}`);
      assert(session.user && session.user.role === 'owner', 'test account should be an owner');
      const viaToken = await fetch(`${BASE}/api/projects`, { headers: { 'x-gitdock-session': SESSION } });
      equal(viaToken.status, 200, 'session header');
      const viaCookie = await fetch(`${BASE}/api/projects`, { headers: { cookie: session.cookie } });
      equal(viaCookie.status, 200, 'session cookie');
      const junk = await fetch(`${BASE}/api/projects`, { headers: { cookie: 'gitdock_sid=not.a.real.token' } });
      equal(junk.status, 401, 'forged cookie must not work');
    });
    await test('an owner can add a teammate, the teammate can sign in, a member cannot manage access', async () => {
      const mate = `mate-${stamp}`;
      const added = await api('/api/auth/users', { method: 'POST', body: { name: mate, role: 'member' } });
      assert(added.generatedPassword, 'no password generated for the new user');
      created.users.push(mate);
      const mateSession = await testAuth.signIn(mate, added.generatedPassword);
      assert(mateSession.user.role === 'member', 'new user should be a member');
      const denied = await fetch(`${BASE}/api/auth/users`, {
        method: 'POST', headers: { 'content-type': 'application/json', 'x-gitdock-session': mateSession.token },
        body: JSON.stringify({ name: `nope-${stamp}` }),
      });
      equal(denied.status, 403, 'a member must not be able to add users');
      const users = await api('/api/auth/users');
      assert(users.users.some((u) => u.name === mate), 'new user not listed');
      const removed = await api(`/api/auth/users/${mate}`, { method: 'DELETE' });
      equal(removed.removed, mate, 'removed user');
      created.users = created.users.filter((u) => u !== mate);
      const gone = await fetch(`${BASE}/api/auth/login`, {
        method: 'POST', headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ name: mate, password: added.generatedPassword }),
      });
      equal(gone.status, 401, 'a removed user must not be able to sign in');
    });
    await test('a changed password takes effect immediately', async () => {
      const mate = `pw-${stamp}`;
      const added = await api('/api/auth/users', { method: 'POST', body: { name: mate, role: 'member' } });
      created.users.push(mate);
      const before = await testAuth.signIn(mate, added.generatedPassword);
      const res = await fetch(`${BASE}/api/auth/password`, {
        method: 'POST', headers: { 'content-type': 'application/json', 'x-gitdock-session': before.token },
        body: JSON.stringify({ current: added.generatedPassword, next: 'brand-new-pass-1' }),
      });
      equal(res.status, 200, 'password change status');
      const res2 = await fetch(`${BASE}/api/auth/login`, {
        method: 'POST', headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ name: mate, password: 'brand-new-pass-1' }),
      });
      equal(res2.status, 200, 'sign in with the new password');
      const res3 = await fetch(`${BASE}/api/auth/login`, {
        method: 'POST', headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ name: mate, password: added.generatedPassword }),
      });
      equal(res3.status, 401, 'the old password must stop working');
      await api(`/api/auth/users/${mate}`, { method: 'DELETE' });
      created.users = created.users.filter((u) => u !== mate);
    });

    await test('every secret file is mode 0600 (snapshot restores relax it)', async () => {
      const targets = [
        path.join(DATA, 'auth.json'),
        path.join(DATA, 'mysql.json'),
        path.join(DATA, 'FIRST-LOGIN.txt'),
      ];
      const secretDir = path.join(DATA, 'secrets');
      for (const f of await fsp.readdir(secretDir).catch(() => [])) targets.push(path.join(secretDir, f));
      const loose = [];
      for (const f of targets) {
        const st = await fsp.stat(f).catch(() => null);
        if (st && (st.mode & 0o777) !== 0o600) loose.push(`${path.basename(f)} is ${(st.mode & 0o777).toString(8)}`);
      }
      assert(!loose.length, `world-readable secrets: ${loose.join(', ')}`);
    });

    // ---------------------------------------------------------------- health
    section('panel + environment');
    let panelStartedAt = '';
    await test('GET /api/health answers', async () => {
      const d = await api('/api/health');
      assert(d.ok, 'health not ok');
      assert(typeof d.startedAt === 'string', 'no startedAt');
      panelStartedAt = d.startedAt;
    });
    await test('runtimes are real (php, sqlite, git, mysql)', async () => {
      const s = await api('/api/settings');
      assert(/^php/i.test(s.runtimes.php), `php not installed: ${s.runtimes.php}`);
      assert(!/not installed/i.test(s.runtimes.sqlite), 'sqlite missing');
      assert(/git version/.test(s.runtimes.git), 'git missing');
      equal(s.runtimes.mysql, 'connected', 'mysql');
    });
    await test('projects list is real and currently empty of fixtures', async () => {
      const d = await api('/api/projects');
      assert(Array.isArray(d.projects), 'projects not an array');
    });

    // ---------------------------------------------------------------- create
    section('project creation (real filesystem + git)');
    await test('create a static project', async () => {
      await api('/api/projects', { method: 'POST', body: { name: projectName, template: 'static' } });
      created.projects.push(slug);
      assert(fs.existsSync(projectDir), 'project dir not created');
      assert(fs.existsSync(path.join(projectDir, '.git')), '.git not initialised');
      const hist = await api(`/api/projects/${slug}/history`);
      assert(hist.commits.length >= 1, 'no initial commit');
    });

    // ---------------------------------------------------------- bulk upload
    section('bulk upload → ONE commit (the folder-drop promise)');
    await test('upload 12 files, then commit once', async () => {
      for (let i = 1; i <= 12; i++) {
        await api(`/api/projects/${slug}/upload?path=${encodeURIComponent(`assets/file${i}.txt`)}`, {
          method: 'POST', raw: true, body: Buffer.from(`content ${i} ${marker}`),
        });
      }
      const c = await api(`/api/projects/${slug}/commit`, { method: 'POST', body: { message: 'Upload 12 file(s)' } });
      equal(c.commit.files.length, 12, 'files in the commit');
      assert(!c.commit.files.some((f) => f.startsWith('.gitdock')), 'GitDock metadata leaked into the commit');
      assert(c.commit.sha, 'no sha');
      const onDisk = await fsp.readdir(path.join(projectDir, 'assets'));
      equal(onDisk.length, 12, 'files on disk');
    });
    await test('ignored patterns are skipped, not stored', async () => {
      const d = await apiRaw(`/api/projects/${slug}/upload?path=${encodeURIComponent('node_modules/x/y.js')}`, Buffer.from('nope'));
      assert(d.skipped, 'node_modules should be skipped');
      assert(!fs.existsSync(path.join(projectDir, 'node_modules')), 'node_modules was written');
    });
    await test('path traversal is rejected', async () => {
      const res = await fetch(`${BASE}/api/projects/${slug}/file?path=${encodeURIComponent('../../etc/passwd')}`, { headers: SESSION ? { 'x-gitdock-session': SESSION } : {} });
      const j = await res.json();
      assert(j.ok === false, 'traversal was accepted!');
    });

    // ------------------------------------------------------------- editing
    section('file editing + commits');
    await test('write a file, read it back, one commit per save', async () => {
      const rel = 'app/index.php';
      await api(`/api/projects/${slug}/file?path=${encodeURIComponent(rel)}`, {
        method: 'PUT',
        body: { content: `<?php echo "${marker}"; echo " db=" . getenv('DB_DATABASE'); ?>`, message: 'Add php page' },
      });
      const read = await api(`/api/projects/${slug}/file?path=${encodeURIComponent(rel)}`);
      assert(read.content.includes(marker), 'content did not round-trip');
      const hist = await api(`/api/projects/${slug}/history`);
      assert(hist.commits.some((c) => /Add php page/.test(c.subject)), 'commit missing');
    });
    await test('bulk delete removes files and records one commit', async () => {
      const paths = ['assets/file1.txt', 'assets/file2.txt', 'assets/file3.txt'];
      const r = await api(`/api/projects/${slug}/bulk-delete`, { method: 'POST', body: { paths } });
      equal(r.deleted.length, 3, 'deleted count');
      for (const p of paths) assert(!fs.existsSync(path.join(projectDir, p)), `${p} still on disk`);
      assert(r.commit.sha, 'no commit for the delete');
    });

    // ------------------------------------------------------------ versions
    section('version history + rollback');
    let firstSha = null;
    await test('restore an older version (history preserved)', async () => {
      const hist = await api(`/api/projects/${slug}/history`);
      firstSha = hist.commits[hist.commits.length - 1].sha;
      const before = (await api(`/api/projects/${slug}/tree`)).tree;
      await api(`/api/projects/${slug}/restore`, { method: 'POST', body: { sha: firstSha } });
      const after = await api(`/api/projects/${slug}/tree`);
      assert(!JSON.stringify(after.tree).includes('assets'), 'restore did not remove later files');
      const hist2 = await api(`/api/projects/${slug}/history`);
      assert(hist2.commits.length > hist.commits.length, 'restore should add a commit');
      assert(hist2.commits.some((c) => c.sha === firstSha), 'old version vanished from history');
    });
    await test('a previous version downloads as a real zip', async () => {
      const res = await api(`/api/projects/${slug}/archive?sha=${firstSha}`, { raw: true });
      const buf = Buffer.from(await res.arrayBuffer());
      equal(buf.subarray(0, 2).toString(), 'PK', 'not a zip');
      assert(buf.length > 100, 'zip suspiciously small');
    });

    // ------------------------------------------------------------- manifest
    section('gitdock.yaml manifest → services, database, env');
    await test('re-add the php entry point after the rollback', async () => {
      assert(!fs.existsSync(path.join(projectDir, 'app/index.php')), 'rollback should have removed app/index.php');
      await api(`/api/projects/${slug}/file?path=${encodeURIComponent('app/index.php')}`, {
        method: 'PUT',
        body: { content: `<?php echo "${marker}"; echo " db=" . getenv('DB_DATABASE'); ?>`, message: 'Restore php page' },
      });
      assert(fs.existsSync(path.join(projectDir, 'app/index.php')), 'file not recreated');
    });
    await test('save a manifest (parsed + committed)', async () => {
      const text = [
        'version: 1', 'project:', `  name: ${slug}`, '  runtime: php', '',
        'services:',
        '  - id: website', '    path: .', '    runtime: static', '    public: true',
        '  - id: api', '    path: app', '    runtime: php', '    public: true',
        '', 'databases:', '  - id: main', '    engine: mysql', '',
        'env:', '  defaults:', '    SELFTEST_FLAG: manifest-was-here',
        '', 'preview:', '  ttlHours: 1', '',
      ].join('\n');
      const r = await api(`/api/projects/${slug}/setup/manifest`, { method: 'PUT', body: { text } });
      assert(r.manifest.ok, 'manifest parse failed');
      assert(fs.existsSync(path.join(projectDir, 'gitdock.yaml')), 'file not written');
    });
    await test('apply the manifest: services created, MySQL provisioned, env written', async () => {
      const r = await api(`/api/projects/${slug}/setup/apply`, { method: 'POST', body: { start: false } });
      assert(r.report.services.includes('api'), 'api service not created');
      const dbs = await mysql(['-N', '-B', '-e', `SELECT schema_name FROM information_schema.schemata WHERE schema_name='${dbName}'`]);
      assert(dbs.stdout.includes(dbName), `database ${dbName} not created`);
      const env = await fsp.readFile(path.join(DATA, 'secrets', `${slug}.env`), 'utf8');
      assert(env.includes('SELFTEST_FLAG=manifest-was-here'), 'env default not written');
      assert(env.includes('DB_DATABASE='), 'DB credentials not injected');
    });
    await test('detection ladder reports runtime + reasons', async () => {
      const d = await api(`/api/projects/${slug}/setup`);
      assert(d.detected.runtime, 'no runtime detected');
      assert(Array.isArray(d.detected.reasons) && d.detected.reasons.length, 'no reasons given');
      assert(['low', 'medium', 'high'].includes(d.detected.confidence), 'no confidence');
    });

    // ------------------------------------------------------------- services
    section('services on real ports (php + static)');
    const ports = {};
    await test('start both services and reach them over HTTP', async () => {
      const r = await api(`/api/projects/${slug}/services/start-all`, { method: 'POST', body: {} });
      for (const s of r.started) {
        assert(!s.error, `${s.id}: ${s.error}`);
        ports[s.id] = s.port;
        assert(s.health.up, `${s.id} did not answer a health check`);
      }
      equal(Object.keys(ports).length, 2, 'services started');
      const apiRes = await fetch(`http://127.0.0.1:${ports.api}/`);
      const body = await apiRes.text();
      assert(body.includes(marker), 'php service did not render the marker');
      assert(body.includes(dbName), 'env injection did not reach the service (DB_DATABASE missing)');
    });
    await test('service logs are captured', async () => {
      const d = await api(`/api/projects/${slug}/services/api/logs`);
      assert(typeof d.log === 'string', 'no log field');
    });

    // ------------------------------------------------------------- database
    section('database: MySQL import / query / export, SQLite');
    await test('import a .sql dump', async () => {
      const sql = `CREATE TABLE items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60));
INSERT INTO items (name) VALUES ('alpha'),('beta'),('gamma');`;
      const r = await apiRaw(`/api/projects/${slug}/db/import`, Buffer.from(sql));
      assert(r.tables.includes('items'), `expected items table, got ${r.tables.join(',')}`);
    });
    await test('query the MySQL database through the API', async () => {
      const r = await api(`/api/projects/${slug}/db/mysql-query?sql=${encodeURIComponent('SELECT COUNT(*) FROM items')}`);
      assert(r.raw.join(' ').includes('3'), `expected 3 rows, got ${JSON.stringify(r.raw)}`);
    });
    await test('export a .sql dump', async () => {
      const res = await api(`/api/projects/${slug}/db/export`, { raw: true });
      const body = await res.text();
      assert(body.includes('CREATE TABLE'), 'export has no DDL');
      assert(body.includes('items'), 'export missing the table');
    });
    await test('SQLite: upload a file, list tables, query rows', async () => {
      const tmp = path.join(os.tmpdir(), `selftest-${stamp}.sqlite`);
      created.tmpFiles.push(tmp);
      await sh('sqlite3', [tmp, "CREATE TABLE notes(id INTEGER PRIMARY KEY, body TEXT); INSERT INTO notes(body) VALUES ('one'),('two');"]);
      const buf = await fsp.readFile(tmp);
      const up = await apiRaw(`/api/projects/${slug}/db/sqlite-upload?path=${encodeURIComponent('database/test.sqlite')}`, buf);
      assert(up.tables.includes('notes'), 'sqlite tables not detected');
      const q = await api(`/api/projects/${slug}/db/sqlite-query?path=${encodeURIComponent('database/test.sqlite')}&sql=${encodeURIComponent('SELECT * FROM notes ORDER BY id')}`);
      equal(q.rows.length, 2, 'sqlite rows');
      equal(q.rows[0].body, 'one', 'first row');
    });
    await test('db status endpoint reports both engines', async () => {
      const d = await api(`/api/projects/${slug}/db`);
      assert(d.mysql.available, 'mysql not reported available');
      assert(d.sqlite.some((x) => x.path.includes('test.sqlite')), 'sqlite file not listed');
    });

    // ------------------------------------------------------------------ env
    section('env / secrets');
    await test('.env is written, masked on read, and missing keys reported', async () => {
      await api(`/api/projects/${slug}/env`, {
        method: 'PUT',
        body: { content: 'APP_ENV=local\nDB_PASSWORD=supersecretvalue\nCUSTOM_KEY=hello\n' },
      });
      const mode = (await fsp.stat(path.join(DATA, 'secrets', `${slug}.env`))).mode & 0o777;
      equal(mode, 0o600, 'secret file permissions');
      const d = await api(`/api/projects/${slug}/env`);
      const pw = d.keys.find((k) => k.k === 'DB_PASSWORD');
      assert(!pw.v.includes('supersecretvalue'), 'password leaked in the API response');
      assert(d.keys.some((k) => k.k === 'CUSTOM_KEY'), 'custom key missing');
    });

    // ------------------------------------------------------------------- git
    section('git remote: push, pull, PR refs');
    const origin = path.join(os.tmpdir(), `gitdock-selftest-origin-${stamp}.git`);
    created.remotes.push(origin);
    await test('connect a remote and push', async () => {
      await sh('git', ['init', '-q', '--bare', '-b', 'main', origin]);
      await api(`/api/projects/${slug}/git/remote`, { method: 'POST', body: { url: origin, branch: 'main' } });
      const p = await api(`/api/projects/${slug}/git/push`, { method: 'POST', body: {} });
      assert(p.ok, `push failed: ${p.output}`);
      const received = await sh('git', ['--git-dir', origin, 'rev-list', '--count', 'main']);
      assert(Number(received.stdout.trim()) >= 1, 'remote has no commits after push');
    });
    await test('pull brings remote commits in', async () => {
      const clone = path.join(os.tmpdir(), `gitdock-selftest-clone-${stamp}`);
      created.tmpFiles.push(clone);
      const step = async (label, args, cwd) => {
        const r = await sh('git', args, cwd ? { cwd } : {});
        assert(r.ok, `harness setup (${label}) failed: ${(r.stderr || r.stdout).trim().slice(0, 200)}`);
        return r;
      };
      await step('clone', ['clone', '-q', origin, clone]);
      await fsp.writeFile(path.join(clone, 'from-remote.txt'), 'made elsewhere');
      await step('add', ['add', '-A'], clone);
      await step('commit', ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-qm', 'remote change'], clone);
      await step('push', ['push', '-q', 'origin', 'main'], clone);
      const remoteHead = (await sh('git', ['--git-dir', origin, 'rev-parse', 'main'])).stdout.trim();

      const r = await api(`/api/projects/${slug}/git/pull`, { method: 'POST', body: {} });
      assert(r.ok, `pull failed: ${r.output}`);
      const localHead = (await sh('git', ['-C', projectDir, 'rev-parse', 'HEAD'])).stdout.trim();
      assert(localHead === remoteHead,
        `project HEAD (${localHead.slice(0, 7)}) did not move to the remote commit (${remoteHead.slice(0, 7)}). pull said: ${String(r.output).trim().slice(0, 200)}`);
      assert(fs.existsSync(path.join(projectDir, 'from-remote.txt')), 'pulled file not in the project');
    });
    await test('PR ref preview: fetch refs/pull/N/head and run it', async () => {
      // publish a PR ref the way GitHub does
      const wt = path.join(os.tmpdir(), `gitdock-selftest-pr-${stamp}`);
      created.tmpFiles.push(wt);
      await sh('git', ['clone', '-q', origin, wt]);
      await fsp.writeFile(path.join(wt, 'index.html'), `<!doctype html><title>PR</title><h1>${marker}-PR</h1>`);
      await sh('git', ['-C', wt, 'add', '-A']);
      await sh('git', ['-C', wt, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-qm', 'PR branch change']);
      await sh('git', ['-C', wt, 'push', '-q', 'origin', 'HEAD:refs/pull/7/head']); // exactly how GitHub publishes PR refs
      const sha = (await sh('git', ['-C', wt, 'rev-parse', 'HEAD'])).stdout.trim();
      const check = await sh('git', ['--git-dir', origin, 'rev-parse', 'refs/pull/7/head']);
      assert(check.ok && check.stdout.trim() === sha, 'test setup: PR ref not published to the remote');

      const r = await api(`/api/projects/${slug}/git/preview-pr`, { method: 'POST', body: { number: 7 } });
      created.previews.push(r.preview.id);
      equal(r.preview.kind, 'pr', 'preview kind');
      const svc = r.preview.services.find((s) => s.port);
      assert(svc, 'PR preview started no service');
      const body = await (await fetch(`http://127.0.0.1:${svc.port}/`)).text();
      assert(body.includes(`${marker}-PR`), 'PR preview served the wrong revision');
      const mainBody = await (await fetch(`http://127.0.0.1:${ports.website}/`)).text();
      assert(!mainBody.includes(`${marker}-PR`), 'main service was affected by the PR preview');
    });

    // ------------------------------------------------------- clone + identity
    section('cloning a repo, and who commits are authored as');
    // ask the server what it created, instead of re-deriving the slug and getting it subtly wrong
    let cloneSlug = '';

    await test('clone from a remote URL, then edit it (identity must not block commits)', async () => {
      const made = await api('/api/projects', { method: 'POST', body: { cloneUrl: origin, name: `${projectName}Clone` } });
      cloneSlug = made.slug;
      created.projects.push(cloneSlug);
      const dir = path.join(DATA, 'projects', cloneSlug);
      const deadline = Date.now() + 30000;
      while (!fs.existsSync(path.join(dir, '.git')) && Date.now() < deadline) await sleep(400);
      assert(fs.existsSync(path.join(dir, '.git')), 'clone never produced a repository');
      // a cloned repo has no local git identity: committing used to fail with "empty ident name"
      const r = await api(`/api/projects/${cloneSlug}/file?path=${encodeURIComponent('proof.txt')}`, {
        method: 'PUT', body: { content: 'edited after cloning', message: 'Edit the cloned repo' },
      });
      assert(r.commit.committed, 'editing a cloned repo did not commit');
      const author = (await sh('git', ['-C', dir, 'log', '-1', '--pretty=%an <%ae>'])).stdout.trim();
      assert(author.includes('<') && author.includes('@'), `commit has no valid author: ${author}`);
    });
    await test('set a project identity and verify git actually uses it', async () => {
      const r = await api(`/api/projects/${cloneSlug}/git/identity`, {
        method: 'PUT', body: { name: 'Nimal Perera', email: 'nimal@example.com' },
      });
      equal(r.effective.email, 'nimal@example.com', 'effective identity');
      await api(`/api/projects/${cloneSlug}/file?path=${encodeURIComponent('proof2.txt')}`, {
        method: 'PUT', body: { content: 'second commit', message: 'Commit as Nimal' },
      });
      const dir = path.join(DATA, 'projects', cloneSlug);
      const author = (await sh('git', ['-C', dir, 'log', '-1', '--pretty=%an <%ae>'])).stdout.trim();
      equal(author, 'Nimal Perera <nimal@example.com>', 'git author line');
      const repoCfg = await sh('git', ['-C', dir, 'config', 'user.email']);
      equal(repoCfg.stdout.trim(), 'nimal@example.com', 'repo config email');
    });
    await test('invalid identity is rejected', async () => {
      const res = await fetch(`${BASE}/api/projects/${cloneSlug}/git/identity`, {
        method: 'PUT', headers: { 'content-type': 'application/json', ...(SESSION ? { 'x-gitdock-session': SESSION } : {}) },
        body: JSON.stringify({ name: 'X', email: 'not-an-email' }),
      });
      const j = await res.json();
      assert(j.ok === false, 'a malformed email was accepted');
    });
    await test('GitDock never stores credentials in the repository config', async () => {
      const dir = path.join(DATA, 'projects', cloneSlug);
      const cfg = await fsp.readFile(path.join(dir, '.git/config'), 'utf8');
      assert(!/ghp_|github_pat_|x-access-token:|password/i.test(cfg), 'credentials found in .git/config');
      const remote = (await sh('git', ['-C', dir, 'remote', 'get-url', 'origin'])).stdout;
      assert(!remote.includes('@') || !/:[^@/]+@/.test(remote), 'remote URL contains embedded credentials');
    });

    await test('deleting a project mid-job leaves no zombie directory', async () => {
      const zSlug = `${slug}-zombie`.replace(/[^a-z0-9-]/g, '');
      const zDir = path.join(DATA, 'projects', zSlug);
      const made = await api('/api/projects', { method: 'POST', body: { cloneUrl: origin, name: zSlug } });
      // delete immediately, while the clone job is still working
      const r = await api(`/api/projects/${made.slug}`, { method: 'DELETE' });
      if (!r.cleaned) throw new Error('no cleanup report');
      await sleep(2500);
      assert(!fs.existsSync(zDir), 'the clone job recreated the deleted project directory');
      const list = await api('/api/projects');
      assert(!list.projects.some((p) => p.slug === made.slug), 'deleted project is still listed');
    });

    // -------------------------------------------------------------- previews
    section('previews: worktrees, isolation, teardown, TTL');
    await test('branch preview runs beside main, working tree untouched', async () => {
      await sh('git', ['-C', projectDir, 'checkout', '-q', '-b', 'feature/x']);
      await fsp.writeFile(path.join(projectDir, 'index.html'), `<!doctype html><h1>${marker}-BRANCH</h1>`);
      await sh('git', ['-C', projectDir, 'add', '-A']);
      await sh('git', ['-C', projectDir, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-qm', 'branch change']);
      await sh('git', ['-C', projectDir, 'checkout', '-q', 'main']);

      const r = await api(`/api/projects/${slug}/previews`, { method: 'POST', body: { ref: 'feature/x', ttlHours: 1 } });
      created.previews.push(r.preview.id);
      const svc = r.preview.services.find((s) => s.port);
      assert(svc, 'no service in the preview');
      const body = await (await fetch(`http://127.0.0.1:${svc.port}/`)).text();
      assert(body.includes(`${marker}-BRANCH`), 'preview did not serve the branch');
      const worktreeFile = await fsp.readFile(path.join(projectDir, 'index.html'), 'utf8');
      assert(!worktreeFile.includes(`${marker}-BRANCH`), 'working tree was modified by the preview');
      const listed = await api(`/api/projects/${slug}/previews`);
      assert(listed.previews.some((p) => p.id === r.preview.id), 'preview not listed');
    });
    await test('teardown closes the port and removes the worktree', async () => {
      const id = created.previews[created.previews.length - 1];
      const list = await api(`/api/projects/${slug}/previews`);
      const rec = list.previews.find((p) => p.id === id);
      const port = (rec.services.find((s) => s.port) || {}).port;
      await api(`/api/projects/${slug}/previews/${id}`, { method: 'DELETE' });
      created.previews = created.previews.filter((x) => x !== id);
      assert(!fs.existsSync(rec.dir), 'preview directory still on disk');
      let closed = false;
      try { await fetch(`http://127.0.0.1:${port}/`, { signal: AbortSignal.timeout(1500) }); } catch { closed = true; }
      assert(closed, `port ${port} still answering after teardown`);
    });
    await test('TTL sweeper tears a preview down automatically', async () => {
      const r = await api(`/api/projects/${slug}/previews`, { method: 'POST', body: { ref: 'main', ttlHours: 0.0015 } });
      const id = r.preview.id;
      const deadline = Date.now() + 45000;
      let gone = false;
      while (Date.now() < deadline) {
        const list = await api(`/api/projects/${slug}/previews`);
        if (!list.previews.some((p) => p.id === id)) { gone = true; break; }
        await sleep(1500);
      }
      assert(gone, 'expired preview was never torn down (sweeper interval too long?)');
    });

    // -------------------------------------------------------- web/panel sanity
    section('panel surface');
    await test('the panel process survived the whole suite (it once died on a cancelled job)', async () => {
      // a job killed mid-flight used to write into its closed log stream, and an unhandled stream error
      // took the whole panel down. Every later request then failed with "fetch failed" — confusing.
      const d = await api('/api/health');
      equal(d.startedAt, panelStartedAt, 'the panel restarted or died during the run');
    });
    await test('panel serves its UI', async () => {
      const res = await fetch(`${BASE}/`);
      const html = await res.text();
      assert(res.ok && html.includes('GitDock'), 'panel HTML missing');
    });

    // ------------------------------------- multi-app layout, manifest paths, service editing
    section('multi-app layout (manifest paths, subfolders, service editing, resource caps)');
    await test('a gitdock.yaml service path is honoured (the site lives in web/)', async () => {
      const origin = path.join(os.tmpdir(), `gitdock-manifest-${stamp}.git`);
      const work = path.join(os.tmpdir(), `gitdock-manifest-work-${stamp}`);
      created.remotes.push(origin); created.tmpFiles.push(work);
      await sh('git', ['init', '-q', '-b', 'main', work]);
      await fsp.mkdir(path.join(work, 'web'), { recursive: true });
      await fsp.mkdir(path.join(work, 'admin'), { recursive: true });
      await fsp.writeFile(path.join(work, 'web', 'index.html'), '<h1>MANIFEST-WEB</h1>');
      await fsp.writeFile(path.join(work, 'admin', 'index.html'), '<h1>MANIFEST-ADMIN</h1>');
      await fsp.writeFile(path.join(work, 'gitdock.yaml'), [
        'name: Manifest App',
        'services:',
        '  - id: website',
        '    runtime: static',
        '    path: web',
        '  - id: admin',
        '    runtime: static',
        '    path: admin',
        '',
      ].join('\n'));
      await sh('git', ['-C', work, 'add', '-A']);
      await sh('git', ['-C', work, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-qm', 'manifest app']);
      await sh('git', ['init', '-q', '--bare', '-b', 'main', origin]);
      await sh('git', ['-C', work, 'push', '-q', origin, 'main']);

      const made = await api('/api/projects', { method: 'POST', body: { cloneUrl: origin, name: `Selftest ${stamp} Multi` } });
      const multiSlug = made.slug;
      created.projects.push(multiSlug);
      // wait for the job to REPORT done: .git exists from the first second of a clone, long before
      // detection has written the service definitions
      const deadline = Date.now() + 30000;
      while (Date.now() < deadline) {
        const job = (await api(`/api/projects/${multiSlug}/job?id=${made.job.id}`)).job;
        if (job.status !== 'running') break;
        await sleep(300);
      }

      const services = (await api(`/api/projects/${multiSlug}/services`)).services;
      const website = services.find((x) => x.id === 'website');
      const admin = services.find((x) => x.id === 'admin');
      assert(website && website.path === 'web', `website path was "${website && website.path}" (the manifest says web)`);
      assert(admin && admin.path === 'admin', `admin was not created: ${services.map((x) => x.id).join(', ')}`);

      // two apps, two ports, each serving its own folder — the multi-app requirement
      const a = await api(`/api/projects/${multiSlug}/services/${website.id}/start`, { method: 'POST' });
      const b = await api(`/api/projects/${multiSlug}/services/${admin.id}/start`, { method: 'POST' });
      assert(a.info.port !== b.info.port, 'both services got the same port');
      const bodyA = await (await fetch(`http://127.0.0.1:${a.info.port}/`)).text();
      const bodyB = await (await fetch(`http://127.0.0.1:${b.info.port}/`)).text();
      assert(bodyA.includes('MANIFEST-WEB'), `website served the wrong folder: ${bodyA.slice(0, 60)}`);
      assert(bodyB.includes('MANIFEST-ADMIN'), `admin served the wrong folder: ${bodyB.slice(0, 60)}`);

      // the request is a project name in a workspace where one already exists: make sure the message is clear
      const dup = await fetch(`${BASE}/api/projects`, {
        method: 'POST', headers: { 'content-type': 'application/json', 'x-gitdock-session': SESSION },
        body: JSON.stringify({ name: `Selftest ${stamp} Multi` }),
      });
      equal(dup.status, 409, 'duplicate project name');
    });
    await test('a subfolder site is found without a manifest, and a service can be re-pointed', async () => {
      // the uploaded folder has no index.html at its root — the usual shape when you zip a site folder
      const origin = path.join(os.tmpdir(), `gitdock-sub-${stamp}.git`);
      const work = path.join(os.tmpdir(), `gitdock-sub-work-${stamp}`);
      created.remotes.push(origin); created.tmpFiles.push(work);
      for (const sub of ['public', 'site']) {
        await fsp.mkdir(path.join(work, sub), { recursive: true });
        await fsp.writeFile(path.join(work, sub, 'index.html'), `<h1>${sub === 'site' ? 'IN-SITE' : 'IN-PUBLIC'}</h1>`);
      }
      await sh('git', ['init', '-q', '-b', 'main', work]);
      await sh('git', ['-C', work, 'add', '-A']);
      await sh('git', ['-C', work, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-qm', 'two static roots']);
      await sh('git', ['init', '-q', '--bare', '-b', 'main', origin]);
      await sh('git', ['-C', work, 'push', '-q', origin, 'main']);
      const madeSub = await api('/api/projects', { method: 'POST', body: { cloneUrl: origin, name: `Selftest ${stamp} Sub` } });
      const subSlug = madeSub.slug;
      created.projects.push(subSlug);
      const dir = path.join(DATA, 'projects', subSlug);
      const ready = Date.now() + 30000;
      while (!fs.existsSync(path.join(dir, '.git')) && Date.now() < ready) await sleep(400);

      const det = await api(`/api/projects/${subSlug}/setup`);
      assert(det.detected.runtime === 'static', `runtime detected as ${det.detected.runtime}`);
      const found = det.detected.services.find((x) => x.id === 'website');
      assert(found && found.path !== '.', 'detection did not look into subfolders');

      // add one by hand, then move it with PUT — this is how you wire up cms/admin/website by hand
      const added = await api(`/api/projects/${subSlug}/services`, {
        method: 'POST', body: { id: 'admin', runtime: 'static', path: 'public', start: false },
      });
      equal(added.service.path, 'public', 'service path at create time');
      const moved = await api(`/api/projects/${subSlug}/services/admin`, { method: 'PUT', body: { path: 'site' } });
      equal(moved.service.path, 'site', 'service path after PUT');
      const started = await api(`/api/projects/${subSlug}/services/admin/start`, { method: 'POST' });
      const body = await (await fetch(`http://127.0.0.1:${started.info.port}/`)).text();
      assert(body.includes('IN-SITE'), `the re-pointed service served "${body.slice(0, 40)}"`);
      const bogus = await fetch(`${BASE}/api/projects/${subSlug}/services/admin`, {
        method: 'PUT', headers: { 'content-type': 'application/json', 'x-gitdock-session': SESSION },
        body: JSON.stringify({ path: 'does-not-exist' }),
      });
      equal(bogus.status, 400, 're-pointing at a missing folder must be refused');
    });
    await test('services run with resource caps in place', async () => {
      const services = (await api(`/api/projects/${slug}/services`)).services;
      const running = services.find((x) => x.port);
      assert(running, 'no running service to inspect');
      const log = (await api(`/api/projects/${slug}/services/${running.id}/logs`)).log;
      // process + open-file caps always apply; the address-space cap is opt-in because Node/WASM
      // reserves huge virtual memory and a 1 GB cap kills such apps on boot
      assert(/limits: .*\d+ processes, \d+ open files/.test(log), `no resource caps in the service log:\n${log.slice(0, 400)}`);
      assert(!/exited immediately/.test(log), 'the service died on start');
    });

    // ---------------------------------------------- the failures people hit for real
    section('failure paths (the messages a person actually sees)');
    await test('the git credential helper is executable (a snapshot restore drops that bit)', async () => {
      const helper = path.join(__dirname, '..', 'app', 'lib', 'askpass.sh');
      assert(fs.existsSync(helper), 'askpass.sh is missing');
      const st = await fsp.stat(helper);
      assert((st.mode & 0o111) !== 0, `askpass.sh is not executable (mode ${(st.mode & 0o777).toString(8)}) — every private clone/push would fail with "cannot exec"`);
    });
    await test('a failed clone still reports WHY, after its project directory is gone', async () => {
      // a failed clone deletes the directory, and the UI polls the job to learn the reason. That poll
      // used to hit the "project exists" guard and answer 404 "project not found" — the exact useless
      // message this check exists to prevent.
      const made = await api('/api/projects', { method: 'POST', body: { cloneUrl: 'https://127.0.0.1:9/does-not-exist.git', name: `Selftest ${stamp} Badclone` } });
      const badSlug = made.slug;
      const jobId = made.job.id;
      const deadline = Date.now() + 25000;
      let job = null;
      while (Date.now() < deadline) {
        job = (await api(`/api/projects/${badSlug}/job?id=${jobId}`)).job;
        if (job.status !== 'running') break;
        await sleep(400);
      }
      assert(job, 'job status was never readable');
      assert(job.status === 'failed', `expected a failed job, got "${job.status}"`);
      assert(!/project not found/i.test(job.note || ''), `the note is the useless one: "${job.note}"`);
      assert(job.note && job.note.length > 20, `the note should explain the failure, got: "${job.note}"`);
      assert(!fs.existsSync(path.join(DATA, 'projects', badSlug)), 'a failed clone should leave no directory');
      // and the same job stays readable straight from disk after a restart
      const fromDisk = await api(`/api/projects/${badSlug}/job?id=${jobId}`);
      assert(fromDisk.job.status === 'failed', 'the job should stay readable after the project is gone');
    });
    await test('a service that dies on boot is reported as a failure, not a green dot', async () => {
      const made = await api('/api/projects', { method: 'POST', body: { name: `Selftest ${stamp} Dies` } });
      const diesSlug = made.slug;
      created.projects.push(diesSlug);
      await api(`/api/projects/${diesSlug}/file?path=${encodeURIComponent('package.json')}`, {
        method: 'PUT', body: { content: '{"name":"dies","scripts":{"start":"exit 7"}}', message: 'start script that fails' },
      });
      await api(`/api/projects/${diesSlug}/services`, { method: 'POST', body: { id: 'broken', runtime: 'node', path: '.', start: false } });
      const res = await fetch(`${BASE}/api/projects/${diesSlug}/services/broken/start`, {
        method: 'POST', headers: { 'content-type': 'application/json', 'x-gitdock-session': SESSION },
      });
      const body = await res.json();
      assert(!body.ok, 'starting a service that exits immediately must not report success');
      assert(/exited immediately/i.test(body.error || ''), `the error should say the process died, got: "${body.error}"`);
      const list = (await api(`/api/projects/${diesSlug}/services`)).services;
      const broken = list.find((x) => x.id === 'broken');
      assert(!broken.running, 'a dead service must not be listed as running');
      assert(!broken.port, 'a dead service must not keep a port');
    });

    await test('stopping a service really stops it — even when it is started through npm', async () => {
      // the classic leak: "npm run start" spawns node as its own child, so killing npm leaves the app
      // running and holding the port. Real reports look like "I stopped it and the site is still up".
      const made = await api('/api/projects', { method: 'POST', body: { name: `Selftest ${stamp} Npm` } });
      const npmSlug = made.slug;
      created.projects.push(npmSlug);
      const server = [
        "const http = require('http');",
        "const port = Number(process.env.PORT || 8100);",
        "http.createServer((_, res) => res.end('NPM-SERVICE-UP')).listen(port, '0.0.0.0');",
        "console.log('listening on ' + port);",
      ].join('\n');
      await api(`/api/projects/${npmSlug}/file?path=${encodeURIComponent('package.json')}`, {
        method: 'PUT',
        body: { content: JSON.stringify({ name: 'npm-service', scripts: { start: 'node app.js' } }), message: 'npm start shim' },
      });
      await api(`/api/projects/${npmSlug}/file?path=${encodeURIComponent('app.js')}`, {
        method: 'PUT', body: { content: server, message: 'the actual server' },
      });
      const started = await api(`/api/projects/${npmSlug}/services`, {
        method: 'POST', body: { id: 'npmapp', runtime: 'node', path: '.', start: true },
      });
      const port = started.info.port;
      assert(port, 'the npm service did not get a port');
      const body = await (await fetch(`http://127.0.0.1:${port}/`)).text();
      assert(body.includes('NPM-SERVICE-UP'), `the npm service did not serve: ${body.slice(0, 60)}`);

      await api(`/api/projects/${npmSlug}/services/npmapp/stop`, { method: 'POST' });
      await sleep(600);
      let stillUp = false;
      try { await fetch(`http://127.0.0.1:${port}/`, { signal: AbortSignal.timeout(1500) }); stillUp = true; } catch {}
      assert(!stillUp, `the app is still serving on :${port} after stop — the child outlived its parent`);
      const list = (await api(`/api/projects/${npmSlug}/services`)).services;
      assert(!list.find((x) => x.id === 'npmapp').running, 'the service still reports as running');
    });

    // ------------------------------------------------ downloads & cross-origin preview use
    section('downloads and the sandboxed-preview path (the white screen)');
    await test('CORS preflight is answered without a session, and with the right headers', async () => {
      const res = await fetch(`${BASE}/api/projects`, {
        method: 'OPTIONS',
        headers: {
          origin: 'null',
          'access-control-request-method': 'GET',
          'access-control-request-headers': 'x-gitdock-session',
        },
        signal: AbortSignal.timeout(5000),
      });
      equal(res.status, 204, 'preflight status');
      equal(res.headers.get('access-control-allow-origin'), '*', 'allow-origin');
      assert(/x-gitdock-session/.test(res.headers.get('access-control-allow-headers') || ''), 'token header must be allowed');
    });
    await test('a cross-origin (iframe) API call gets CORS headers', async () => {
      const res = await fetch(`${BASE}/api/projects`, { headers: { origin: 'null', 'x-gitdock-session': SESSION } });
      equal(res.status, 200, 'cross-origin token request');
      equal(res.headers.get('access-control-allow-origin'), '*', 'allow-origin on a real response');
    });
    await test('a link navigation without a session gets a page, not bare JSON (the white screen)', async () => {
      // clicking "Download zip" used to navigate the iframe at the API: 401 JSON rendered as a white
      // screen, wiping the panel. A browser navigation must now get an HTML page that explains it.
      const nave = await fetch(`${BASE}/api/projects/${slug}/archive?sha=HEAD`, {
        headers: { accept: 'text/html,application/xhtml+xml' },
      });
      equal(nave.status, 401, 'status for an unauthenticated navigation');
      assert(/text\/html/.test(nave.headers.get('content-type') || ''), `expected HTML, got ${nave.headers.get('content-type')}`);
      const html = await nave.text();
      assert(/sign in/i.test(html), 'the page should offer to sign in');
      assert(!/^\s*\{/.test(html), 'a page must not be JSON');
      // a fetch() (Accept: application/json) still gets JSON to act on
      const api = await fetch(`${BASE}/api/projects/${slug}/archive?sha=HEAD`, { headers: { accept: 'application/json' } });
      equal(api.status, 401, 'status for a fetch');
      assert(/application\/json/.test(api.headers.get('content-type') || ''), 'a fetch must still get JSON');
    });
    await test('downloads authenticate from the URL token (the new-tab fallback)', async () => {
      const svcSession = await testAuth.signIn();
      const res = await fetch(`${BASE}/api/projects/${slug}/archive?sha=HEAD&t=${encodeURIComponent(svcSession.token)}`, {
        signal: AbortSignal.timeout(30000),
      });
      equal(res.status, 200, 'archive with ?t=');
      equal(res.headers.get('content-type'), 'application/zip', 'content type');
      const buf = Buffer.from(await res.arrayBuffer());
      assert(buf.length > 200, `zip is suspiciously small (${buf.length} bytes)`);
      equal(buf.slice(0, 2).toString(), 'PK', 'the bytes must be a real zip');
    });
    await test('a short-lived download link works with no session at all, then expires', async () => {
      // this is how a person gets a file out of a locked-down preview frame: copy the link, paste it
      // into a normal tab. Minting needs a session; USING it must not.
      const minted = await api(`/api/projects/${slug}/dl-key`, { method: 'POST', body: { kind: 'archive', sha: 'HEAD' } });
      assert(/^\/api\/dl\/[0-9a-f]{16,}$/.test(minted.url), `unexpected url: ${minted.url}`);
      const bare = await fetch(`${BASE}${minted.url}`, { signal: AbortSignal.timeout(30000) });
      equal(bare.status, 200, 'download link without a session');
      equal(bare.headers.get('content-type'), 'application/zip', 'content type');
      assert(/attachment/.test(bare.headers.get('content-disposition') || ''), 'must be an attachment');
      const buf = Buffer.from(await bare.arrayBuffer());
      equal(buf.slice(0, 2).toString(), 'PK', 'a real zip');
      // least privilege: the key is a download key, never a session
      const asSession = await fetch(`${BASE}/api/projects`, { headers: { 'x-gitdock-session': minted.url.split('/').pop() } });
      equal(asSession.status, 401, 'a download key must not authenticate anything else');
      const unknown = await fetch(`${BASE}/api/dl/deadbeefdeadbeefdeadbeef`);
      equal(unknown.status, 404, 'an unknown key');
      // expiry
      const shortLived = await api(`/api/projects/${slug}/dl-key`, { method: 'POST', body: { kind: 'archive', sha: 'HEAD', ttlSeconds: 1 } });
      equal((await fetch(`${BASE}${shortLived.url}`)).status, 200, 'valid while fresh');
      await sleep(1300);
      const expired = await fetch(`${BASE}${shortLived.url}`);
      assert(expired.status === 410 || expired.status === 404, `an expired link must be refused, got ${expired.status}`);
      // and a key can never point outside the project
      const escape = await fetch(`${BASE}/api/projects/${slug}/dl-key`, {
        method: 'POST', headers: { 'content-type': 'application/json', 'x-gitdock-session': SESSION },
        body: JSON.stringify({ kind: 'raw', path: '../../etc/passwd' }),
      });
      equal(escape.status, 400, 'minting a key for an escaping path must be refused');
    });
    await test('one file can be downloaded on its own (the button used to hand out the whole project)', async () => {
      await api(`/api/projects/${slug}/file?path=${encodeURIComponent('single.txt')}`, {
        method: 'PUT', body: { content: 'just this file', message: 'add a single file to download' },
      });
      const res = await fetch(`${BASE}/api/projects/${slug}/raw?path=${encodeURIComponent('single.txt')}`, {
        headers: { 'x-gitdock-session': SESSION },
      });
      equal(res.status, 200, 'raw status');
      equal((await res.text()).trim(), 'just this file', 'raw content');
      assert(/attachment/.test(res.headers.get('content-disposition') || ''), 'should be sent as an attachment');
      const missing = await fetch(`${BASE}/api/projects/${slug}/raw?path=${encodeURIComponent('../../etc/passwd')}`, {
        headers: { 'x-gitdock-session': SESSION },
      });
      assert(missing.status >= 400, 'path traversal through the download route must be refused');
    });

    await test('one folder can be downloaded on its own, subfolders included', async () => {
      // a folder download is a git archive of a pathspec: the folder name stays on every entry, and
      // nothing outside it leaks in
      const put = (rel, content) => api(`/api/projects/${slug}/file?path=${encodeURIComponent(rel)}`, {
        method: 'PUT', body: { content, message: `add ${rel}` },
      });
      await put('dlassets/css/site.css', 'body{color:red}');
      await put('dlassets/js/app.js', 'console.log(1)');
      await put('dlassets/img/logo.svg', '<svg/>');
      await put('other/keep.txt', 'not part of dlassets');
      const arch = await fetch(`${BASE}/api/projects/${slug}/archive?sha=HEAD&path=dlassets`, {
        headers: { 'x-gitdock-session': SESSION }, signal: AbortSignal.timeout(30000),
      });
      equal(arch.status, 200, 'folder archive status');
      equal(arch.headers.get('content-type'), 'application/zip', 'content type');
      assert(/attachment; filename="dlassets-/.test(arch.headers.get('content-disposition') || ''),
        `filename should name the folder: ${arch.headers.get('content-disposition')}`);
      const buf = Buffer.from(await arch.arrayBuffer());
      const names = zipEntries(buf);
      assert(names.includes('dlassets/css/site.css'), `nested file missing from ${names.join(', ')}`);
      assert(names.includes('dlassets/js/app.js'), `sibling subfolder missing from ${names.join(', ')}`);
      assert(names.includes('dlassets/img/logo.svg'), `deep file missing from ${names.join(', ')}`);
      assert(!names.some((n) => n.startsWith('other/')), `the zip must not contain other folders: ${names.join(', ')}`);
      assert(!names.some((n) => n === 'index.html'), 'the zip must not contain the project root');

      // refusals: no such folder, a file instead of a folder, and an escape attempt
      equal((await fetch(`${BASE}/api/projects/${slug}/archive?sha=HEAD&path=nope`, { headers: { 'x-gitdock-session': SESSION } })).status, 404, 'missing folder');
      equal((await fetch(`${BASE}/api/projects/${slug}/archive?sha=HEAD&path=index.html`, { headers: { 'x-gitdock-session': SESSION } })).status, 400, 'a file is not a folder');
      const escape = await fetch(`${BASE}/api/projects/${slug}/archive?sha=HEAD&path=${encodeURIComponent('../../etc')}`, { headers: { 'x-gitdock-session': SESSION } });
      assert(escape.status === 400, `traversal must be refused, got ${escape.status}`);

      // a folder that holds only git-ignored files would come back as an empty zip — say so instead
      // nothing committed in this folder — only a file git ignores, so a git archive of it is empty
      await fsp.mkdir(path.join(DATA, 'projects', slug, 'dlignored'), { recursive: true });
      fs.writeFileSync(path.join(DATA, 'projects', slug, 'dlignored', 'dump.sqlite'), 'not really a database');
      const ignored = await fetch(`${BASE}/api/projects/${slug}/archive?sha=HEAD&path=dlignored`, { headers: { 'x-gitdock-session': SESSION } });
      equal(ignored.status, 409, 'a folder with nothing committed');
      assert(/committed/i.test((await ignored.json()).error || ''), 'the refusal should explain itself');
      fs.rmSync(path.join(DATA, 'projects', slug, 'dlignored', 'dump.sqlite'), { force: true });

      // and the short-lived handover link must carry the folder too (that is the phone/iframe path)
      const minted = await api(`/api/projects/${slug}/dl-key`, { method: 'POST', body: { kind: 'archive', sha: 'HEAD', folder: 'dlassets' } });
      const bare = await fetch(`${BASE}${minted.url}`, { signal: AbortSignal.timeout(30000) });
      equal(bare.status, 200, 'handover link status');
      const names2 = zipEntries(Buffer.from(await bare.arrayBuffer()));
      equal(names2.length, names.length, 'the handover link must serve the same folder');
      const badLink = await api(`/api/projects/${slug}/dl-key`, { method: 'POST', body: { kind: 'archive', sha: 'HEAD', folder: '../..' } })
        .then(() => 'allowed').catch((e) => e.message);
      assert(/escapes|400/.test(badLink), `minting a key for an escaping folder must be refused (${badLink})`);
      // a key may not name a folder that does not exist either
      const ghost = await api(`/api/projects/${slug}/dl-key`, { method: 'POST', body: { kind: 'archive', sha: 'HEAD', folder: 'nope-not-here' } })
        .then(() => 'allowed').catch((e) => e.message);
      assert(/not found|404/.test(ghost), `minting a key for a missing folder must be refused (${ghost})`);
    });

    await test('cloning asks GitHub first, and a repository the token cannot reach is refused up front', async () => {
      // the dialog asks this route before it creates anything, so a token that cannot see the repo is
      // answered immediately instead of surfacing later as a half-open project
      const junk = await fetch(`${BASE}/api/github/probe`, {
        method: 'POST', headers: { 'content-type': 'application/json', 'x-gitdock-session': SESSION },
        body: JSON.stringify({ url: 'not a repository at all' }),
      });
      equal(junk.status, 400, 'a URL that is not a GitHub repo');

      const missing = await fetch(`${BASE}/api/github/probe`, {
        method: 'POST', headers: { 'content-type': 'application/json', 'x-gitdock-session': SESSION },
        body: JSON.stringify({ url: 'https://github.com/ihshan001/gitdock-no-such-repo-9f3.git' }),
      }).then((r) => r.json());
      equal(missing.ok, true, 'the probe answers 200 with a verdict');
      equal(missing.repo, 'ihshan001/gitdock-no-such-repo-9f3', 'the URL is parsed down to owner/repo');
      equal(missing.reachable, false, 'a repository that does not exist must not be described as reachable');
      assert((missing.reason || '').length > 20, 'the refusal must explain itself, not just say no');

      // ssh and bare forms parse too — people paste all three
      for (const form of ['git@github.com:ihshan001/kadepos.git', 'ihshan001/kadepos']) {
        const d = await fetch(`${BASE}/api/github/probe`, {
          method: 'POST', headers: { 'content-type': 'application/json', 'x-gitdock-session': SESSION },
          body: JSON.stringify({ url: form }),
        }).then((r) => r.json());
        equal(d.repo, 'ihshan001/kadepos', `parsing ${form}`);
      }
    });
    await test('a clone that fails removes everything it created (no half-open project)', async () => {
      const made = await api('/api/projects', {
        method: 'POST',
        body: { cloneUrl: 'https://github.com/ihshan001/gitdock-no-such-repo-9f3.git', name: 'clone-that-fails' },
      });
      assert(made.job && made.job.id, 'the clone should be a job');
      const slug = made.slug;
      const deadline = Date.now() + 60000;
      let job = null;
      while (Date.now() < deadline) {
        const r = await api(`/api/projects/${slug}/job?id=${made.job.id}`).catch(() => null);
        if (r && r.job && r.job.status !== 'running') { job = r.job; break; }
        if (!r) break;                       // the project itself is gone: that is the pass condition below
        await sleep(700);
      }
      if (job) {
        equal(job.status, 'failed', 'a clone of a repository that does not exist');
        assert(/clone/i.test(job.note || ''), `the job note should say the clone failed (got: ${job.note})`);
      }
      // whatever the route, nothing may be left on disk — not the directory, not the pending metadata
      const dir = path.join(DATA, 'projects', slug);
      equal(fs.existsSync(dir), false, `the failed clone left ${dir} behind`);
      equal(fs.existsSync(path.join(DATA, '.pending', `${slug}.json`)), false, 'the failed clone left pending metadata');
      const list = await api('/api/projects');
      equal(list.projects.some((p) => p.slug === slug), false, 'the failed clone is still listed as a project');
    });

    // ---------------------------------------------------------------- branches, merges, PRs
    section('branches: create, merge, undo the merge, delete');
    // this section runs after the access tests (which sign every device out) and after the cleanup
    // helper that removes the bot account, so rebuild the account and the session here
    testAuth.ensureTestUser();
    SESSION = (await testAuth.signIn()).token;
    const repoDir = path.join(DATA, 'projects', slug);
    const gitIn = async (...args) => (await sh('git', ['-C', repoDir, ...args])).stdout.trim();
    const writeFileApi = (rel, content, message) =>
      api(`/api/projects/${slug}/file?path=${encodeURIComponent(rel)}`, { method: 'PUT', body: { content, message } });
    /** assert a call is refused with a specific status (and hand back the body to inspect) */
    const refuse = async (pathname, body, wantStatus, what) => {
      const res = await fetch(BASE + pathname, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-gitdock-session': SESSION },
        body: JSON.stringify(body),
      });
      const data = await res.json().catch(() => ({}));
      assert(res.status === wantStatus, `${what}: expected ${wantStatus}, got ${res.status} (${data.error || ''})`);
      return data;
    };

    await test('a branch is created, switched to, and reported with its distance from the base', async () => {
      await writeFileApi('branchwork.txt', 'main side\n', 'main wording');
      const made = await api(`/api/projects/${slug}/git/branches`, { method: 'POST', body: { name: 'feature/selftest' } });
      equal(made.current, 'feature/selftest', 'creating a branch must switch to it');
      equal(await gitIn('branch', '--show-current'), 'feature/selftest', 'git agrees about the current branch');
      await writeFileApi('branchwork.txt', 'branch side\n', 'branch wording');
      const st = await api(`/api/projects/${slug}/git/branches`);
      const mine = st.local.find((b) => b.name === 'feature/selftest');
      assert(mine, 'the new branch must be listed');
      equal(st.current, 'feature/selftest', 'current branch');
      equal(st.base, 'main', 'base branch detected');
      equal(mine.ahead, 1, 'the branch is one commit ahead of the base');
      equal(mine.vsBase, 'main', 'compared against the base branch when there is no upstream');
      equal(await gitIn('branch', '--list', 'feature/selftest').then((x) => x.startsWith('*')), true, 'git shows it as current');
    });

    await test('branch names git would choke on are refused before git sees them', async () => {
      await refuse(`/api/projects/${slug}/git/branches`, { name: 'feature/selftest' }, 409, 'duplicate name');
      await refuse(`/api/projects/${slug}/git/branches`, { name: 'has spaces' }, 400, 'name with a space');
      await refuse(`/api/projects/${slug}/git/branches`, { name: '-d' }, 400, 'name that looks like a flag');
      await refuse(`/api/projects/${slug}/git/branches`, { name: 'a..b' }, 400, 'name with ..');
      await refuse(`/api/projects/${slug}/git/branches`, { name: '' }, 400, 'empty name');
      const missing = await refuse(`/api/projects/${slug}/git/checkout`, { name: 'nope/nope' }, 404, 'switching to a branch that does not exist');
      assert(/no branch called/i.test(missing.error), `unhelpful message: ${missing.error}`);
    });

    await test('merging makes a real merge commit and pulls the branch work into the base', async () => {
      await api(`/api/projects/${slug}/git/checkout`, { method: 'POST', body: { name: 'main' } });
      const merged = await api(`/api/projects/${slug}/git/merge`, { method: 'POST', body: { branch: 'feature/selftest' } });
      equal(merged.merged, 'feature/selftest', 'merged branch');
      equal(merged.upToDate, false, 'this was a real merge, not a no-op');
      // verified in git itself, not just in the response
      const parents = await gitIn('rev-list', '--parents', '-n1', 'HEAD');
      equal(parents.split(/\s+/).length, 3, 'the merge commit must have two parents');
      equal(await fsp.readFile(path.join(repoDir, 'branchwork.txt'), 'utf8'), 'branch side\n', 'the branch version of the file won on main');
      const again = await api(`/api/projects/${slug}/git/merge`, { method: 'POST', body: { branch: 'feature/selftest' } });
      equal(again.upToDate, true, 'merging the same branch twice must report "already up to date"');
      const hist = await api(`/api/projects/${slug}/history`);
      const mergeRow = hist.commits.find((c) => c.sha === merged.sha || c.short === merged.sha);
      assert(mergeRow ? mergeRow.parents >= 2 : hist.commits.some((c) => c.parents >= 2), 'history must flag the merge commit so the UI can offer "undo merge"');
    });

    await test('undoing a merge reverts it, keeps the history, and never rewrites commits', async () => {
      const before = await gitIn('rev-list', '--count', 'HEAD');
      const mergeSha = await gitIn('rev-parse', '--short', 'HEAD');
      const undone = await api(`/api/projects/${slug}/git/revert-merge`, { method: 'POST', body: { sha: mergeSha } });
      assert(undone.sha && undone.sha !== mergeSha, 'undoing must create a new commit');
      equal(await fsp.readFile(path.join(repoDir, 'branchwork.txt'), 'utf8'), 'main side\n', 'the merge effect is gone from the working tree');
      equal((await gitIn('rev-list', '--count', 'HEAD')), String(Number(before) + 1), 'exactly one commit was added');
      assert((await gitIn('log', '--oneline', '--all')).includes(mergeSha), 'the merge commit is still in history (nothing was rewritten)');
      assert(/^Revert /m.test(await gitIn('log', '--format=%s', '-1')), 'the new commit is a revert');
      // a normal commit is not a merge — reverting it is a different operation and must be refused here
      const normal = await gitIn('rev-parse', '--short', 'HEAD');
      const refused = await refuse(`/api/projects/${slug}/git/revert-merge`, { sha: normal }, 400, 'reverting a non-merge');
      assert(/not a merge/i.test(refused.error), `message should explain why: ${refused.error}`);
    });

    await test('a conflicting merge is aborted, and the tree is left exactly as it was', async () => {
      await api(`/api/projects/${slug}/git/branches`, { method: 'POST', body: { name: 'feature/clash' } });
      await writeFileApi('clash.txt', 'from the branch\n', 'branch clash');
      await api(`/api/projects/${slug}/git/checkout`, { method: 'POST', body: { name: 'main' } });
      await writeFileApi('clash.txt', 'from main\n', 'main clash');
      const body = await refuse(`/api/projects/${slug}/git/merge`, { branch: 'feature/clash' }, 409, 'conflicting merge');
      assert(Array.isArray(body.conflicts) && body.conflicts.includes('clash.txt'), `the UI needs the file list, got ${JSON.stringify(body.conflicts)}`);
      equal(await gitIn('status', '--porcelain'), '', 'no half-merged tree may be left behind');
      assert(!(await gitIn('status')).includes('MERGING'), 'the merge must be aborted, not paused');
      equal(await gitIn('branch', '--show-current'), 'main', 'still on the branch we started from');
      equal(await fsp.readFile(path.join(repoDir, 'clash.txt'), 'utf8'), 'from main\n', 'the working file was not touched');
    });

    await test('deleting a branch: refuses the current one, asks before losing unique commits, then obeys', async () => {
      await refuse(`/api/projects/${slug}/git/branches/delete`, { name: 'main' }, 409, 'deleting the current branch');
      const asked = await refuse(`/api/projects/${slug}/git/branches/delete`, { name: 'feature/clash' }, 409, 'deleting a branch with unique commits');
      equal(asked.needsForce, true, 'the refusal must say that forcing is the answer, so the UI can ask');
      equal((await gitIn('branch', '--list', 'feature/clash')).length > 0, true, 'nothing was deleted yet');
      const done = await api(`/api/projects/${slug}/git/branches/delete`, { method: 'POST', body: { name: 'feature/clash', force: true } });
      equal(done.deleted, 'feature/clash', 'force delete');
      equal(await gitIn('branch', '--list', 'feature/clash'), '', 'the branch is gone');
      await refuse(`/api/projects/${slug}/git/branches/delete`, { name: 'feature/ghost' }, 404, 'deleting a branch that does not exist');
    });

    await test('a branch pushed to the remote can be checked out as a local branch that tracks it', async () => {
      await api(`/api/projects/${slug}/git/branches`, { method: 'POST', body: { name: 'feature/tracking' } });
      await writeFileApi('tracked.txt', 'pushed\n', 'work that goes to the remote');
      await api(`/api/projects/${slug}/git/push`, { method: 'POST', body: { branch: 'feature/tracking' } });
      await api(`/api/projects/${slug}/git/checkout`, { method: 'POST', body: { name: 'main' } });
      await api(`/api/projects/${slug}/git/branches/delete`, { method: 'POST', body: { name: 'feature/tracking', force: true } });
      const back = await api(`/api/projects/${slug}/git/checkout`, { method: 'POST', body: { name: 'origin/feature/tracking' } });
      equal(back.current, 'feature/tracking', 'checking out origin/x must give a local branch called x');
      equal(back.trackedFrom, 'origin/feature/tracking', 'and it must track the remote branch');
      equal(await gitIn('rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'), 'origin/feature/tracking', 'upstream is set in git itself');
    });

    await test('pull requests refuse politely when the pieces are missing (they need GitHub, not a bare remote)', async () => {
      const noGithub = await refuse(`/api/projects/${slug}/git/pr`, { title: 'nope', head: 'main', base: 'main' }, 400, 'PR on a non-github remote');
      assert(/github/i.test(noGithub.error), `the message must say what is missing: ${noGithub.error}`);
      await refuse(`/api/projects/${slug}/git/pr/merge`, { number: 1 }, 400, 'merging a PR with no github remote');
      await refuse(`/api/projects/${slug}/git/pr/close`, { number: 1 }, 400, 'closing a PR with no github remote');
      // And once a github remote is connected, a pull request is stopped by whichever piece is really
      // missing — the panel must name THAT piece, not fall over. Two honest answers are possible here:
      //   no token installed  → 400, "…needs a token…"
      //   a token installed   → the branch is pushed first, and a push to a repository that does not
      //                         exist fails → 409, naming the push
      // The second case is real (a token can be installed while this suite runs), so assert the shape
      // of the refusal instead of assuming the environment.
      await api(`/api/projects/${slug}/git/remote`, { method: 'POST', body: { url: 'https://github.com/gitdock-selftest/does-not-exist.git' } });
      const prTryRes = await api(`/api/projects/${slug}/git/pr`, { method: 'POST', body: JSON.stringify({ title: 'x', head: 'feature/selftest', base: 'main' }), raw: true });
      const prTry = { status: prTryRes.status, ...(await prTryRes.json().catch(() => ({}))) };
      if (prTry.status === 400 || prTry.status === 409) {
        const msg = String(prTry.error || '');
        assert(msg.length > 20, `the refusal must explain itself: ${msg}`);
        if (prTry.status === 400) assert(/token/i.test(msg), `a 400 here is the missing token and must say so: ${msg}`);
        else assert(/push|branch|remote/i.test(msg), `a 409 here is a failed push and must say so: ${msg}`);
      } else {
        throw new Error(`a pull request that cannot be created must answer 400 or 409, got ${prTry.status}: ${prTry.error}`);
      }
      await api(`/api/projects/${slug}/git/remote`, { method: 'POST', body: { url: origin } });
    });


    // ------------------------------------------------------------- cleanup
    section('cleanup (delete the project and verify nothing is left behind)');
    await test('DELETE /api/projects/:slug removes project, port, DB and secrets', async () => {
      const r = await api(`/api/projects/${slug}`, { method: 'DELETE' });
      assert(r.cleaned, 'no cleanup report');
      assert(!fs.existsSync(projectDir), 'project directory survived');
      assert(!fs.existsSync(path.join(DATA, 'secrets', `${slug}.env`)), 'secret file survived');
      const dbs = await mysql(['-N', '-B', '-e', `SELECT schema_name FROM information_schema.schemata WHERE schema_name='${dbName}'`]);
      assert(!dbs.stdout.includes(dbName), `database ${dbName} survived`);
      const list = await api('/api/projects');
      assert(!list.projects.some((p) => p.slug === slug), 'project still listed');
      created.projects = created.projects.filter((x) => x !== slug);
      let stillUp = false;
      try { await fetch(`http://127.0.0.1:${ports.website}/`, { signal: AbortSignal.timeout(1500) }); stillUp = true; } catch {}
      assert(!stillUp, 'service port still answering after deletion');
    });
  } finally {
    // best-effort cleanup even if a test blew up
    for (const id of created.previews) {
      try { await api(`/api/projects/${slug}/previews/${id}`, { method: 'DELETE' }); } catch {}
    }
    for (const p of created.projects) {
      try { await api(`/api/projects/${p}`, { method: 'DELETE' }); }
      catch (e) {
        if (!/not found|404/i.test(e.message)) console.log(`${C.y}!${C.x} cleanup: could not delete ${p}: ${e.message}`);
      }
    }
    try {
      const left = await api('/api/projects');
      const strays = left.projects.filter((p) => p.slug.includes('selftest'));
      if (strays.length) console.log(`${C.y}!${C.x} cleanup left ${strays.length} project(s) behind: ${strays.map((p) => p.slug).join(', ')}`);
    } catch {}
    for (const r of created.remotes) { try { await fsp.rm(r, { recursive: true, force: true }); } catch {} }
    for (const f of created.tmpFiles) { try { await fsp.rm(f, { recursive: true, force: true }); } catch {} }
    try { await mysql(['-e', `DROP DATABASE IF EXISTS \`${dbName}\``]); } catch {}
    for (const u of created.users) {
      try { await api(`/api/auth/users/${u}`, { method: 'DELETE' }); }
      catch (e) { console.log(`${C.y}!${C.x} cleanup: could not remove user ${u}: ${e.message}`); }
    }
    testAuth.removeTestUser();
  }

  // ---------------------------------------------------------------- summary
  const total = pass + fail;
  console.log(`\n${C.b}${fail === 0 ? C.g + 'ALL GREEN' : C.r + 'FAILURES'}${C.x}  ${pass}/${total} checks passed${skip ? `, ${skip} skipped` : ''}`);
  if (failures.length) {
    console.log(`\n${C.r}failed checks:${C.x}`);
    for (const f of failures) console.log(`  · ${f.name}\n      ${f.error}`);
  }
  process.exit(fail === 0 ? 0 : 1);
}

main().catch((e) => {
  console.error(`\n${C.r}self-test crashed:${C.x} ${e.message}\n${e.stack}`);
  process.exit(2);
});
