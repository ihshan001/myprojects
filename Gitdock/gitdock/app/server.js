#!/usr/bin/env node
/**
 * GitDock — proof-of-concept control plane
 * ----------------------------------------
 * Zero npm dependencies on purpose: the app directory survives workspace snapshots,
 * so only the system runtimes (php, mariadb, sqlite3) ever need reinstalling.
 *
 * What this process does:
 *   1. serves the control-panel UI
 *   2. owns "projects" = git working trees on disk (one repo each)
 *   3. performs bulk file operations and turns them into single atomic commits
 *   4. runs project services on their own ports (static / php / laravel / node)
 *   5. provisions databases (SQLite files, MySQL schemas) and imports dumps
 *   6. talks to GitHub (pull / push / PR list) with a token held only in memory
 *
 * What it deliberately does NOT do (yet):
 *   - hard-isolate services (no containers here; each service gets rlimit caps instead)
 * Access is controlled by data/auth.json (see lib/auth.js): the panel is locked by default.
 */

'use strict';

const http = require('http');
const https = require('https');
const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');
const os = require('os');
const net = require('net');
const { spawn, execFile } = require('child_process');
const crypto = require('crypto');
const { detectProject, readManifest, planFromManifest } = require('./lib/manifest.js');
const auth = require('./lib/auth.js');

const ROOT = __dirname;
const DATA = path.join(ROOT, '..', 'data');
const PROJECTS = path.join(DATA, 'projects');
const LOGS = path.join(DATA, 'logs');
const SECRETS = path.join(DATA, 'secrets');
const JOBS_DIR = path.join(DATA, 'jobs');
const PUBLIC = path.join(ROOT, 'public');
const PORT = Number(process.env.GITDOCK_PORT || 3000);
const BIND = process.env.GITDOCK_BIND || '0.0.0.0';

// ---------------------------------------------------------------------------
// state
// ---------------------------------------------------------------------------

/**
 * GitHub token: memory only, never written to disk (deliberate: a POC should not own your credentials).
 * Non-secret preferences (commit identity) persist in data/settings.json.
 */
const state = {
  githubToken: null,
  githubLogin: null,
  githubName: null,
  identity: { name: null, email: null },   // global default for commits
  startedAt: new Date().toISOString(),
};

const SETTINGS_FILE = () => path.join(DATA, 'settings.json');

async function loadSettings() {
  try {
    const j = JSON.parse(await fsp.readFile(SETTINGS_FILE(), 'utf8'));
    if (j.identity) state.identity = { name: j.identity.name || null, email: j.identity.email || null };
  } catch {}
}

async function saveSettings() {
  await fsp.mkdir(DATA, { recursive: true });
  await fsp.writeFile(SETTINGS_FILE(), JSON.stringify({ identity: state.identity }, null, 2));
}

/**
 * Who commits are authored as. Resolution order:
 *   1. the project's own identity (meta.gitName / meta.gitEmail)
 *   2. the panel-wide default (Settings)
 *   3. the connected GitHub account, using GitHub's no-reply address (keeps your real email private)
 *   4. a harmless fallback, so a commit never fails
 * This matters beyond tidiness: GitHub attributes a pushed commit to whatever the author line says.
 */
async function resolveIdentity(slug) {
  if (slug) {
    const meta = (await readMeta(slug)) || {};
    if (meta.gitName && meta.gitEmail) return { name: meta.gitName, email: meta.gitEmail, source: 'project' };
  }
  if (state.identity.name && state.identity.email) return { ...state.identity, source: 'panel' };
  if (state.githubLogin) {
    return { name: state.githubName || state.githubLogin, email: `${state.githubLogin}@users.noreply.github.com`, source: 'github' };
  }
  return { name: 'GitDock', email: 'gitdock@localhost', source: 'fallback' };
}

/** write the resolved identity into the repository's own config (idempotent, cheap) */
async function ensureGitIdentity(dir, slug) {
  const id = await resolveIdentity(slug);
  const cur = await git(dir, ['config', 'user.email']);
  const curName = await git(dir, ['config', 'user.name']);
  if (cur.stdout.trim() !== id.email) await git(dir, ['config', 'user.email', id.email]);
  if (curName.stdout.trim() !== id.name) await git(dir, ['config', 'user.name', id.name]);
  return id;
}

/** strip anything token-shaped out of text that is about to be shown to a human */
function redact(text) {
  let out = String(text || '');
  const secrets = [state.githubToken].filter(Boolean);
  for (const sec of secrets) out = out.split(sec).join('***token***');
  return out
    .replace(/github_pat_[A-Za-z0-9_]{10,}/g, '***token***')
    .replace(/gh[pousr]_[A-Za-z0-9]{10,}/g, '***token***')
    .replace(/(x-access-token|oauth2|git):[^@\s/]+@/gi, '$1:***@')
    .replace(/\/\/[^:@\s/]+:[^@\s/]+@/g, '//***:***@');
}

/** running service children: key -> { child, port, startedAt, logPath } */
const running = new Map();

/** per-project serialisation of git-mutating work (the "deploy lock") */
const locks = new Map();

/** async jobs (project creation, composer, imports) */
const jobs = new Map();

const IGNORED_DIRS = new Set(['.git', 'node_modules', 'vendor', '__MACOSX', '.idea', '.vscode']);
const IGNORED_FILES = new Set(['.DS_Store', 'Thumbs.db', 'desktop.ini']);
const SECRET_FILE_PATTERNS = [/^\.env($|\.)/i, /\.pem$/i, /\.key$/i, /id_rsa/i, /credentials\.json$/i];
const MAX_UPLOAD_BYTES = 128 * 1024 * 1024; // per file
const MAX_EDIT_BYTES = 512 * 1024;

// ---------------------------------------------------------------------------
// tiny helpers
// ---------------------------------------------------------------------------

const nowIso = () => new Date().toISOString();

function log(...a) {
  console.log(`[${new Date().toISOString().slice(11, 19)}]`, ...a);
}

/**
 * A previous panel run can leave project servers behind (its own SIGTERM never arrived,
 * or it was killed hard). At boot, find processes we clearly spawned and stop them,
 * so a restart never inherits mystery listeners.
 */
/** PIDs listening on a port, from `ss` (the socket → pid mapping the kernel gives nobody else) */
async function pidsOnPort(port) {
  const r = await exec('ss', ['-lptnH', `sport = :${port}`], { timeout: 4000 }).catch(() => ({ ok: false, stdout: '' }));
  const pids = [];
  for (const m of String(r.stdout || '').matchAll(/pid=(\d+)/g)) pids.push(Number(m[1]));
  return [...new Set(pids)];
}

/** is this process one of ours? cwd inside our data dir is the reliable signal (npm wrappers hide the path) */
async function isGitdockProcess(pid, cmdline = '') {
  if (cmdline.includes('gitdock/app/lib/static-server.js')) return true;
  if (/(^|\s)php .*-S 0\.0\.0\.0:8[123]\d\d/.test(cmdline)) return true;
  for (const d of [PROJECTS, PREVIEWS_DIR, LOGS]) {
    try {
      const cwd = await fsp.readlink(`/proc/${pid}/cwd`);
      if (cwd.startsWith(d)) return true;
    } catch {}
  }
  return false;
}

/**
 * After a crash or a hard restart, services can be left running with nothing tracking them — and a
 * Node app started through npm keeps running after the panel is gone. Reclaim them from BOTH
 * directions: anything listening in the service port range whose working directory (or command line)
 * says it belongs to GitDock.
 */
async function reclaimOrphans() {
  const killed = [];
  const seen = new Set();
  const candidates = [];

  for (let port = 8100; port <= 8399; port++) candidates.push({ port, pids: await pidsOnPort(port) });

  let entries = [];
  try { entries = await fsp.readdir('/proc'); } catch {}
  for (const d of entries) {
    if (!/^\d+$/.test(d)) continue;
    const pid = Number(d);
    if (pid === process.pid || seen.has(pid)) continue;
    let cmdline = '';
    try { cmdline = (await fsp.readFile(`/proc/${d}/cmdline`, 'utf8')).replace(/\0/g, ' ').trim(); } catch { continue; }
    if (!cmdline) continue;
    if (await isGitdockProcess(pid, cmdline)) candidates.push({ port: null, pids: [pid] });
  }

  for (const { port, pids } of candidates) {
    for (const pid of pids) {
      if (seen.has(pid) || pid === process.pid) continue;
      let cmdline = '';
      try { cmdline = (await fsp.readFile(`/proc/${pid}/cmdline`, 'utf8')).replace(/\0/g, ' ').trim(); } catch {}
      if (!(await isGitdockProcess(pid, cmdline)) && port === null) continue;
      seen.add(pid);
      try {
        process.kill(pid, 'SIGTERM');
        killed.push({ pid, port, cmdline: cmdline.slice(0, 70) });
      } catch {}
    }
  }
  return killed;
}

async function ensureDirs() {
  for (const d of [DATA, PROJECTS, LOGS, SECRETS, JOBS_DIR]) {
    await fsp.mkdir(d, { recursive: true });
  }
}

function json(res, code, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(code, {
    'content-type': 'application/json; charset=utf-8',
    'cache-control': 'no-store',
  });
  res.end(body);
}

/**
 * A link navigation that hits the session check used to render bare JSON — which looks like a white
 * screen and takes the panel down with it. If the browser asked for HTML, answer with a page that
 * explains the situation and offers the way back.
 */
function failHtml(res, code, message, { signIn = false, next = '/' } = {}) {
  const body = `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>GitDock — ${code}</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔒</text></svg>">
<style>
 body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0e1116;color:#e6edf3;
      font:15px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:1.2rem}
 .card{max-width:440px;width:100%;background:#161b22;border:1px solid #26303d;border-radius:14px;padding:1.3rem}
 h1{margin:.1rem 0 .5rem;font-size:1.15rem}
 p{color:#8b96a5;margin:.4rem 0}
 a.btn{display:inline-block;margin-top:1rem;padding:.55rem .9rem;border-radius:9px;background:#4c8dff;color:#06121f;
       font-weight:650;text-decoration:none}
 code{background:#0d1117;border:1px solid #26303d;border-radius:5px;padding:.1rem .3rem;font-size:.8rem}
</style></head><body><div class="card">
 <h1>${signIn ? 'Please sign in again' : `Something went wrong (${code})`}</h1>
 <p>${message}</p>
 ${signIn
    ? `<p>Your panel session ended or the link needed one. Nothing is lost — sign in and you will land back on the panel.</p>
       <a class="btn" href="/login?next=${encodeURIComponent(next)}">Sign in</a>`
    : `<a class="btn" href="/">Back to the panel</a>`}
 <p style="margin-top:1rem;font-size:.82rem">Tip: downloads and file links work best when the panel is opened in its own browser tab
 (<code>${'https://<panel-host>'}</code>), because the preview frame blocks downloads.</p>
</div></body></html>`;
  res.writeHead(code, { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store' });
  return res.end(body);
}

function fail(res, code, message, extra = {}) {
  json(res, code, { ok: false, error: message, ...extra });
}

function readBody(req, limit = MAX_UPLOAD_BYTES) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > limit) {
        reject(new Error(`payload too large (limit ${Math.round(limit / 1024 / 1024)} MB)`));
        req.destroy();
        return;
      }
      chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

async function readJsonBody(req) {
  const buf = await readBody(req, 4 * 1024 * 1024);
  if (!buf.length) return {};
  try {
    return JSON.parse(buf.toString('utf8'));
  } catch {
    throw new Error('invalid JSON body');
  }
}

/** resolve a client-supplied relative path safely inside a project root */
function safeJoin(root, rel, { allowEmpty = true } = {}) {
  let clean = String(rel || '').replace(/\\/g, '/').replace(/^\/+/, '').trim();
  if (!clean && !allowEmpty) throw new Error('path required');
  const target = path.resolve(root, clean);
  const rootResolved = path.resolve(root);
  if (target !== rootResolved && !target.startsWith(rootResolved + path.sep)) {
    throw new Error(`path escapes project root: ${rel}`);
  }
  return target;
}

/** is this relative path something we refuse to store / display */
function isSecretPath(rel) {
  const base = path.basename(rel);
  return SECRET_FILE_PATTERNS.some((re) => re.test(base));
}

function isIgnoredPath(rel) {
  const parts = rel.split('/');
  if (parts.some((p) => IGNORED_DIRS.has(p))) return true;
  if (IGNORED_FILES.has(path.basename(rel))) return true;
  if (rel.endsWith('.zip') && parts.length === 1 && parts[0].length > 0) return true; // stray archives
  return false;
}

function humanBytes(n) {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  if (n < 1024 * 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`;
  return `${(n / 1024 / 1024 / 1024).toFixed(2)} GB`;
}

/** the per-project lock: git work is serialised, like a deploy mutex */
function withLock(key, fn) {
  const prev = locks.get(key) || Promise.resolve();
  const next = prev.then(fn, fn);
  locks.set(key, next.catch(() => {}));
  return next;
}

function exec(cmd, args, opts = {}) {
  return new Promise((resolve) => {
    execFile(cmd, args, { maxBuffer: 32 * 1024 * 1024, ...opts }, (error, stdout, stderr) => {
      resolve({
        ok: !error,
        code: error ? error.code || 1 : 0,
        stdout: String(stdout || ''),
        stderr: String(stderr || ''),
      });
    });
  });
}

// ---------------------------------------------------------------------------
// git plumbing
// ---------------------------------------------------------------------------

const gitEnv = () => ({
  ...process.env,
  GIT_TERMINAL_PROMPT: '0',
  GIT_CONFIG_NOSYSTEM: '1',
  HOME: process.env.HOME || '/home/user',
});

async function git(cwd, args) {
  const r = await exec('git', args, { cwd, env: gitEnv() });
  return r;
}

async function gitMust(cwd, args) {
  const r = await git(cwd, args);
  if (!r.ok) throw new Error(`git ${args[0]} failed: ${(r.stderr || r.stdout).trim().slice(0, 400)}`);
  return r.stdout;
}

async function gitInit(dir, slug = null) {
  await gitMust(dir, ['init', '-q', '-b', 'main']);
  await ensureGitIdentity(dir, slug);
  await fsEnsureGitignore(dir);
  await git(dir, ['add', '-A', '--', '.', ':(exclude).gitdock']);
  const r = await git(dir, ['commit', '-q', '-m', 'Initial commit (GitDock)', '--allow-empty']);
  return r.ok;
}

async function fsEnsureGitignore(dir) {
  const p = path.join(dir, '.gitignore');
  if (fs.existsSync(p)) return;
  await fsp.writeFile(
    p,
    [
      '# GitDock defaults — things that should never be in git',
      '.gitdock/',
      'node_modules/',
      'vendor/',
      '.env',
      '.env.*',
      '!.env.example',
      '*.sqlite',
      '*.sqlite3',
      '*.db',
      '*.sql',
      '*.zip',
      'storage/logs/*',
      'storage/framework/cache/**',
      'storage/framework/sessions/**',
      'storage/framework/views/**',
      'bootstrap/cache/*.php',
      '.DS_Store',
      'Thumbs.db',
      '',
    ].join('\n'),
    'utf8'
  );
}

/** commit whatever is staged; returns {committed, sha|null, files} */
async function commitAll(dir, message, slug = null) {
  // a commit must always carry an author, even in a freshly cloned repo with no git identity
  await ensureGitIdentity(dir, slug);
  // never stage GitDock's own bookkeeping (it is machine-local state, not project content)
  await git(dir, ['add', '-A', '--', '.', ':(exclude).gitdock']);
  const staged = await git(dir, ['diff', '--cached', '--name-only']);
  const files = staged.stdout.split('\n').filter(Boolean);
  if (!files.length) return { committed: false, sha: null, files: [] };
  const r = await git(dir, ['commit', '-q', '-m', message]);
  if (!r.ok) throw new Error(`commit failed: ${(r.stderr || r.stdout).trim()}`);
  const sha = (await git(dir, ['rev-parse', 'HEAD'])).stdout.trim();
  return { committed: true, sha, files };
}

async function history(dir, limit = 100) {
  const r = await git(dir, [
    'log',
    `-n${limit}`,
    '--pretty=format:%H%x1f%h%x1f%an%x1f%ad%x1f%s%x1f%p',
    '--date=format:%Y-%m-%d %H:%M',
  ]);
  if (!r.ok) return [];
  return r.stdout
    .split('\n')
    .filter(Boolean)
    .map((line) => {
      const [sha, short, author, date, subject, parents] = line.split('\x1f');
      // parents is a space-separated list: two or more means this commit is a merge
      return { sha, short, author, date, subject, parents: parents ? parents.split(' ').filter(Boolean).length : 0 };
    });
}

// ---------------------------------------------------------------------------
// projects
// ---------------------------------------------------------------------------

function slugify(name) {
  return (
    String(name)
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 40) || 'project'
  );
}

const projectDir = (slug) => path.join(PROJECTS, slug);
const metaFile = (slug) => path.join(projectDir(slug), '.gitdock', 'meta.json');
/** metadata for projects whose directory must stay empty (composer create-project) */
const pendingMetaFile = (slug) => path.join(DATA, '.pending', `${slug}.json`);
const servicesFile = (slug) => path.join(projectDir(slug), '.gitdock', 'services.json');

async function readMeta(slug) {
  for (const f of [metaFile(slug), pendingMetaFile(slug)]) {
    try {
      return JSON.parse(await fsp.readFile(f, 'utf8'));
    } catch {}
  }
  return null;
}

async function writePendingMeta(slug, meta) {
  await fsp.mkdir(path.dirname(pendingMetaFile(slug)), { recursive: true });
  await fsp.writeFile(pendingMetaFile(slug), JSON.stringify(meta, null, 2));
}

async function writeMeta(slug, meta) {
  await fsp.mkdir(path.dirname(metaFile(slug)), { recursive: true });
  await fsp.writeFile(metaFile(slug), JSON.stringify(meta, null, 2));
}

async function listProjects() {
  let entries = [];
  try {
    entries = await fsp.readdir(PROJECTS, { withFileTypes: true });
  } catch {
    return [];
  }
  const out = [];
  for (const e of entries) {
    if (!e.isDirectory()) continue;
    const slug = e.name;
    const meta = (await readMeta(slug)) || { name: slug, runtime: 'static', createdAt: null };
    const services = await readServices(slug);
    out.push({
      slug,
      name: meta.name,
      runtime: meta.runtime,
      remote: meta.remote || null,
      branch: meta.branch || 'main',
      serviceCount: services.length,
      runningCount: services.filter((s) => running.has(`${slug}:${s.id}`)).length,
      lastCommit: (await history(projectDir(slug), 1))[0] || null,
      sizeBytes: await dirSize(projectDir(slug)),
    });
  }
  out.sort((a, b) => (a.slug < b.slug ? 1 : -1));
  return out;
}

async function dirSize(dir, budget = 200000) {
  let total = 0;
  let seen = 0;
  const stack = [dir];
  while (stack.length && seen < budget) {
    const cur = stack.pop();
    let entries;
    try {
      entries = await fsp.readdir(cur, { withFileTypes: true });
    } catch {
      continue;
    }
    for (const e of entries) {
      if (e.name === '.git') continue;
      const full = path.join(cur, e.name);
      if (e.isDirectory()) stack.push(full);
      else if (e.isFile()) {
        try {
          total += (await fsp.stat(full)).size;
        } catch {}
        seen++;
      }
      if (seen >= budget) break;
    }
  }
  return total;
}

async function readServices(slug) {
  try {
    return JSON.parse(await fsp.readFile(servicesFile(slug), 'utf8'));
  } catch {
    return [];
  }
}

async function writeServices(slug, list) {
  await fsp.mkdir(path.dirname(servicesFile(slug)), { recursive: true });
  await fsp.writeFile(servicesFile(slug), JSON.stringify(list, null, 2));
}

// ---------------------------------------------------------------------------
// runtime templates
// ---------------------------------------------------------------------------

const TEMPLATES = {
  static: {
    label: 'Static site (HTML/CSS/JS)',
    files: {
      'index.html': `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My static site</title>
<style>
  body { margin:0; font-family:-apple-system,Segoe UI,Roboto,sans-serif; background:#0b1020; color:#e6edf7;
         display:grid; place-items:center; min-height:100vh; text-align:center; }
  h1 { font-size:2.2rem; margin:0 0 .4rem; }
  p { color:#93a4c3; }
  code { background:#161c33; padding:.15rem .4rem; border-radius:6px; }
</style>
</head>
<body>
  <main>
    <h1>It runs 🎉</h1>
    <p>This file came from a folder you dropped in the browser.</p>
    <p>Edit <code>index.html</code> in GitDock, save, reload.</p>
  </main>
</body>
</html>
`,
    },
    service: { id: 'website', runtime: 'static', path: '.', docroot: '.' },
  },

  php: {
    label: 'Plain PHP',
    files: {
      'index.php': `<?php
$title = 'PHP is running';
$now = date('Y-m-d H:i:s');
$ext = ['PDO' => class_exists('PDO'), 'pdo_mysql' => extension_loaded('pdo_mysql'), 'mbstring' => extension_loaded('mbstring')];
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<style>
 body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b1020;color:#e6edf7;padding:2rem}
 h1{font-size:1.8rem;margin:0 0 .3rem} .muted{color:#93a4c3}
 table{border-collapse:collapse;margin-top:1rem} td{border:1px solid #253055;padding:.4rem .7rem}
 .ok{color:#4ade80}.no{color:#f87171} code{background:#161c33;padding:.15rem .4rem;border-radius:6px}
</style></head><body>
<h1><?= $title ?></h1>
<p class="muted">Served by php -S on port <?= $_SERVER['SERVER_PORT'] ?? '?' ?> at <?= $now ?></p>
<table>
  <tr><td>PHP version</td><td><?= PHP_VERSION ?></td></tr>
  <?php foreach ($ext as $k => $v): ?>
  <tr><td><?= $k ?></td><td class="<?= $v ? 'ok' : 'no' ?>"><?= $v ? 'yes' : 'no' ?></td></tr>
  <?php endforeach; ?>
</table>
<p class="muted">Next: upload a <code>.sql</code> dump or <code>.sqlite</code> file in the Database tab.</p>
</body></html>
`,
    },
    service: { id: 'website', runtime: 'php', path: '.', docroot: '.' },
  },

  laravel: {
    label: 'Laravel (composer create-project — takes ~1–2 min)',
    composer: 'laravel/laravel',
    service: { id: 'website', runtime: 'php', path: '.', docroot: 'public' },
  },
};

// ---------------------------------------------------------------------------
// service supervision (ports!)
// ---------------------------------------------------------------------------

async function portFree(port) {
  return new Promise((resolve) => {
    const srv = net.createServer();
    srv.once('error', () => resolve(false));
    srv.once('listening', () => srv.close(() => resolve(true)));
    srv.listen(port, '0.0.0.0');
  });
}

async function allocatePort(from = 8100, to = 8399) {
  for (let p = from; p <= to; p++) {
    const inUse = [...running.values()].some((s) => s.port === p);
    if (!inUse && (await portFree(p))) return p;
  }
  throw new Error('no free port in range');
}

async function parseEnvFile(slug) {
  const p = path.join(SECRETS, `${slug}.env`);
  const env = {};
  try {
    const txt = await fsp.readFile(p, 'utf8');
    for (const line of txt.split('\n')) {
      const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
      if (!m) continue;
      let v = m[2].trim();
      if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1);
      env[m[1]] = v;
    }
  } catch {}
  return env;
}

async function startService(slug, service, opts = {}) {
  return startServiceIn(projectDir(slug), `${slug}:${service.id}`, service, { slug, ...opts });
}

/**
 * Run one project service. `root` is the directory the code lives in — the project itself,
 * or a preview worktree of a branch/PR. `key` identifies it in the running map.
 */
async function startServiceIn(root, key, service, { slug, previewHost } = {}) {
  if (running.has(key)) return { already: true, port: running.get(key).port };

  const appPath = safeJoin(root, service.path || '.');
  if (!fs.existsSync(appPath)) throw new Error(`service path not found: ${service.path}`);

  const port = service.port && (await portFree(service.port)) ? service.port : await allocatePort();
  // build the public URL this service will be reachable at, when the host is known
  if (!previewHost && state.publicHostSuffix) previewHost = `${port}-${state.publicHostSuffix}`;
  const logPath = path.join(LOGS, `${key.replace(/[:/]/g, '-')}.log`);
  const out = fs.createWriteStream(logPath, { flags: 'a' });
  out.write(`\n=== ${nowIso()} starting ${service.id} (${service.runtime}) from ${root} on :${port} ===\n`);

  // env comes from the owning project's secret file (previews inherit it, overridable)
  const envFromFile = slug ? await parseEnvFile(slug) : {};
  const env = {
    ...process.env,
    ...envFromFile,
    PORT: String(port),
    APP_ENV: envFromFile.APP_ENV || 'local',
    ...(previewHost ? { APP_URL: `https://${previewHost}`, ASSET_URL: `https://${previewHost}` } : {}),
  };

  let cmd;
  let args;
  if (service.runtime === 'static') {
    cmd = process.execPath;
    args = [path.join(ROOT, 'lib', 'static-server.js'), appPath, String(port)];
  } else if (service.runtime === 'php' || service.runtime === 'laravel') {
    // Laravel: make sure the app is usable without a terminal
    if (service.runtime === 'laravel' && slug) {
      const envFile = path.join(SECRETS, `${slug}.env`);
      if (!fs.existsSync(envFile) && fs.existsSync(path.join(appPath, '.env.example'))) {
        await fsp.copyFile(path.join(appPath, '.env.example'), envFile);
      }
      const appKey = envFromFile.APP_KEY;
      if (!appKey) {
        const k = 'base64:' + crypto.randomBytes(32).toString('base64');
        await appendEnv(slug, { APP_KEY: k });
        env.APP_KEY = k;
        out.write(`[gitdock] generated APP_KEY\n`);
      }
    }
    if (service.runtime === 'laravel' && slug) {
      try { await restoreLaravelEnv(slug, appPath, (m) => out.write(`[gitdock] ${m}\n`)); } catch {}
    }
    cmd = 'php';
    args = ['-d', 'variables_order=EGPCS', '-S', `0.0.0.0:${port}`, '-t', safeJoin(appPath, service.docroot || '.')];
  } else if (service.runtime === 'node') {
    cmd = 'npm';
    args = ['run', 'start'];
  } else {
    throw new Error(`unknown runtime ${service.runtime}`);
  }

  // First slice of isolation: cap what one service can do to the sandbox it shares with everything
  // else. prlimit (util-linux) applies the limits to the process it execs, so no wrapper script
  // and no root are needed. Override per service with limits:{memMB,procs,nofile}.
  const caps = service.limits || {};
  // NB: RLIMIT_AS (address space) is NOT set by default on purpose. Node's WebAssembly runtime
  // reserves gigabytes of *virtual* memory, so a 1 GB address-space cap kills sql.js/wa-sqlite apps
  // on boot with "failed to asynchronously prepare wasm: RangeError". Set limits:{memMB: n} to cap it
  // anyway, or wait for the cgroup memory limit (which counts resident memory, not reservations).
  const memMB = Number(caps.memMB || process.env.GITDOCK_SVC_MEM_MB || 0);
  const procs = Number(caps.procs || process.env.GITDOCK_SVC_PROCS || 256);
  const nofile = Number(caps.nofile || process.env.GITDOCK_SVC_NOFILE || 4096);
  const limits = [];
  if (memMB) limits.push(`--as=${memMB * 1024 * 1024}`);
  limits.push(`--nproc=${procs}`, `--nofile=${nofile}`);
  let launch = cmd;
  let launchArgs = args;
  if (fs.existsSync('/usr/bin/prlimit')) {
    launch = '/usr/bin/prlimit';
    launchArgs = [...limits, '--', cmd, ...args];
  }
  out.write(`[gitdock] limits: ${memMB ? `${memMB}MB address space, ` : ''}${procs} processes, ${nofile} open files`
    + `${memMB ? '' : ' (no memory cap — node/wasm reserve large virtual address space)'}\n`);
  // detached:true puts each service in its own process group. That matters because a Node app is
  // started through npm (`npm run start` → `node server.js`): killing npm leaves the real server
  // running and holding its port. Killing the whole group gets the app too.
  const child = spawn(launch, launchArgs, { cwd: appPath, env, detached: true });
  // A runtime that is not installed (php, node, npm…) makes spawn() emit 'error'. With no listener
  // that is an unhandled event and it takes the whole panel down — the Run bar then simply vanishes.
  let spawnFailure = null;
  child.on('error', (e) => {
    spawnFailure = e;
    svcLogLater(`[gitdock] could not start \`${launch}\`: ${e.message} — is ${launch} installed?\n`);
  });
  // same lesson as job logs: a stopped service can flush one last line into a closed file, and an
  // unhandled stream error would take the panel with it
  out.on('error', (e) => log(`service log ${key} error (ignored): ${e.message}`));
  const svcLogLater = (text) => { try { out.write(text); } catch {} };
  let logOpen = true;
  const svcLog = (chunk) => {
    if (!logOpen || out.writableEnded || out.destroyed) return;
    try { out.write(chunk); } catch {}
  };
  child.stdout.on('data', (d) => svcLog(d));
  child.stderr.on('data', (d) => svcLog(d));
  child.on('exit', (code, sig) => {
    svcLog(`=== exited code=${code} signal=${sig} ${nowIso()} ===\n`);
    running.delete(key);
  });

  running.set(key, { child, port, startedAt: nowIso(), logPath, runtime: service.runtime, slug, id: service.id, key });

  const health = await waitForHttp(port, 8000);

  if (spawnFailure) {
    running.delete(key);
    const hint = /ENOENT/.test(spawnFailure.message) ? ` — the runtime for this service is not installed on this host` : '';
    throw new Error(`service "${service.id}" could not start (${spawnFailure.message})${hint}`);
  }

  // If the process is already gone, "started" is a lie — and the green dot hides the reason from the
  // person looking at the Run bar. Report the failure with the line that explains it.
  if (child.exitCode !== null || child.signalCode) {
    running.delete(key);
    const why = lastErrorLine(logPath) || `exit code ${child.exitCode}`;
    throw new Error(`service "${service.id}" exited immediately (${child.signalCode || 'code ' + child.exitCode}): ${why}`);
  }
  if (!health.up) {
    out.write(`[gitdock] not answering on :${port} yet after 8s — it may still be booting; check the logs\n`);
  }
  return { port, pid: child.pid, health };
}

/**
 * Stop a service and *wait* until the process has exited and its port is free.
 * Callers (delete project, teardown preview) must be able to promise "it is gone".
 */
/** kill a service and everything it started (npm wrappers, workers, cli children) */
function killServiceTree(child, signal) {
  let done = false;
  try { process.kill(-child.pid, signal); done = true; } catch {}
  try { child.kill(signal); done = true; } catch {}
  return done;
}

async function stopServiceByKey(key, { exitTimeoutMs = 2500, portTimeoutMs = 2500 } = {}) {
  const s = running.get(key);
  if (!s) return false;
  const { child, port } = s;
  const waitExit = () =>
    new Promise((resolve) => {
      if (child.exitCode !== null || child.signalCode) return resolve(true);
      const t = setTimeout(() => resolve(false), exitTimeoutMs);
      child.once('exit', () => { clearTimeout(t); resolve(true); });
    });
  killServiceTree(child, 'SIGTERM');
  let exited = await waitExit();
  if (!exited) {
    killServiceTree(child, 'SIGKILL');
    exited = await waitExit();
  }
  // last resort: whatever still answers on that port belongs to this service — take it down too
  if (!(await portFree(port))) {
    for (const pid of await pidsOnPort(port)) {
      try { process.kill(pid, 'SIGKILL'); log(`  freed port ${port} from pid ${pid}`); } catch {}
    }
  }
  running.delete(key);
  const deadline = Date.now() + portTimeoutMs;
  while (Date.now() < deadline) {
    if (await portFree(port)) break;
    await new Promise((r) => setTimeout(r, 100));
  }
  return true;
}

function stopService(slug, id) {
  return stopServiceByKey(`${slug}:${id}`);
}

async function waitForHttp(port, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(`http://127.0.0.1:${port}/`, { signal: AbortSignal.timeout(1500) });
      return { up: true, status: res.status };
    } catch {
      await new Promise((r) => setTimeout(r, 400));
    }
  }
  return { up: false, status: null };
}

// ---------------------------------------------------------------------------
// downloads: one implementation, two ways in (session, or a short-lived link)
// ---------------------------------------------------------------------------

/**
 * Download links are minted on demand, scoped to one project + one ref, and die after a few minutes.
 * They exist because a browser inside a sandboxed iframe cannot save a file at all in the strictest
 * case: fetching the bytes and clicking a blob link is silently blocked. A URL the person can copy
 * into a new tab always works — and because it is a throwaway key, no session token ever lands in a
 * URL bar, a bookmark or a history entry.
 */
const downloadKeys = new Map();
const DL_TTL_SECONDS = 300;

function mintDownloadKey(slug, { kind, ref, folder = null, ttlSeconds = null } = {}) {
  const ttl = Math.min(Math.max(Number(ttlSeconds) || DL_TTL_SECONDS, 1), 3600);
  const key = crypto.randomBytes(16).toString('hex');
  const entry = { slug, kind, ref, folder, exp: Date.now() + ttl * 1000, createdAt: nowIso() };
  downloadKeys.set(key, entry);
  for (const [k, v] of downloadKeys) if (v.exp < Date.now()) downloadKeys.delete(k);   // opportunistic sweep
  return { key, url: `/api/dl/${key}`, expiresAt: new Date(entry.exp).toISOString(), expiresInSeconds: ttl, kind, ref };
}

/** a zip of one folder (its subfolders and files included), or of the whole tree when sub is null */
async function sendArchive(res, slug, sha = 'HEAD', filename = null, sub = null) {
  const root = projectDir(slug);
  if (!fs.existsSync(root)) return fail(res, 404, 'project not found');
  let into = null;
  if (sub) {
    // path comes from the UI, so it is treated as untrusted: inside the project, and a real folder
    try { into = safeJoin(root, String(sub), { allowEmpty: false }); } catch (e) { return fail(res, 400, e.message); }
    const rel = path.relative(root, into).split(path.sep).join('/');
    const st = await fsp.stat(into).catch(() => null);
    if (!st) return fail(res, 404, `no folder "${rel}" in this project`);
    if (!st.isDirectory()) return fail(res, 400, `"${rel}" is a file — download it instead`);
    sub = rel;
  }
  // A folder whose files are all ignored by git (node_modules, a dump, a database file…) has nothing
  // to archive: git would answer "pathspec did not match any files", which tells a person nothing.
  // Check first, and say what is actually wrong.
  if (sub) {
    const tracked = await git(root, ['ls-tree', '-r', '--name-only', sha, '--', sub]);
    if (tracked.ok && !tracked.stdout.trim()) {
      return fail(res, 409, `nothing in "${sub}" is committed to git — this folder holds only files git ignores (node_modules, vendor, .env, dumps, database files…)`);
    }
  }
  const out = path.join(os.tmpdir(), `gitdock-${slug}-${Date.now()}.zip`);
  const args = ['archive', '--format=zip', '-o', out, sha];
  if (sub) args.push('--', sub);       // archive's pathspec keeps the folder name inside the zip
  const r = await git(root, args);
  if (!r.ok) return fail(res, 400, r.stderr.trim().slice(0, 300));
  const buf = await fsp.readFile(out);
  await fsp.unlink(out).catch(() => {});
  const fallback = sub ? `${sub.split('/').filter(Boolean).pop()}-${String(sha).slice(0, 7)}.zip` : `${slug}-${String(sha).slice(0, 7)}.zip`;
  log(`archive download: ${slug}@${String(sha).slice(0, 7)}${sub ? ` ${sub}/` : ''} (${(buf.length / 1048576).toFixed(1)} MB)`);
  res.writeHead(200, {
    'content-type': 'application/zip',
    'content-disposition': `attachment; filename="${filename || fallback}"`,
    'content-length': buf.length,
  });
  return res.end(buf);
}

async function sendRaw(res, slug, rel) {
  const root = projectDir(slug);
  if (!fs.existsSync(root)) return fail(res, 404, 'project not found');
  let full;
  try { full = safeJoin(root, rel, { allowEmpty: false }); } catch (e) { return fail(res, 400, e.message); }
  const st = await fsp.stat(full).catch(() => null);
  if (!st || !st.isFile()) return fail(res, 404, 'file not found');
  const name = path.basename(full).replace(/"/g, '');
  res.writeHead(200, {
    'content-type': MIME[path.extname(full).toLowerCase()] || 'application/octet-stream',
    'content-disposition': `attachment; filename="${name}"`,
    'content-length': st.size,
  });
  return fs.createReadStream(full).pipe(res);
}

/** the line of a log that actually explains a failure, for job notes and toasts */
function lastErrorLine(logPath, max = 240) {
  const text = tailLog(logPath, 8000);
  const lines = text.split('\n').map((l) => l.trim()).filter(Boolean);
  const meaningful = lines.filter((l) =>
    /^(fatal|error|remote:|warning: failed)|Permission denied|not found|could not read|could not resolve|Authentication failed|Repository not found|support for password/i.test(l));
  const pick = meaningful.slice(-2).join(' | ') || lines.slice(-2).join(' | ');
  return pick.replace(/\s+/g, ' ').slice(0, max);
}

function tailLog(logPath, bytes = 20000) {
  try {
    const st = fs.statSync(logPath);
    const start = Math.max(0, st.size - bytes);
    const fd = fs.openSync(logPath, 'r');
    const buf = Buffer.alloc(Math.min(bytes, st.size));
    fs.readSync(fd, buf, 0, buf.length, start);
    fs.closeSync(fd);
    return buf.toString('utf8');
  } catch {
    return '';
  }
}

// ---------------------------------------------------------------------------
// env / secrets
// ---------------------------------------------------------------------------

const envPath = (slug) => path.join(SECRETS, `${slug}.env`);

async function readEnv(slug) {
  try {
    return await fsp.readFile(envPath(slug), 'utf8');
  } catch {
    return '';
  }
}

async function writeEnv(slug, content) {
  await fsp.mkdir(SECRETS, { recursive: true });
  await fsp.writeFile(envPath(slug), content, { mode: 0o600 });
}

async function appendEnv(slug, pairs) {
  const cur = await readEnv(slug);
  const lines = cur.split('\n').filter((l) => l.trim().length);
  for (const [k, v] of Object.entries(pairs)) {
    const idx = lines.findIndex((l) => l.trim().startsWith(`${k}=`));
    if (idx >= 0) lines[idx] = `${k}=${v}`;
    else lines.push(`${k}=${v}`);
  }
  await writeEnv(slug, lines.join('\n') + '\n');
}

/**
 * Secrets must be 0600. Snapshot restores and umasks quietly relax that, so re-assert it at boot and
 * report what was fixed (silent fixes are how you end up with a world-readable password file).
 */
async function enforceSecretModes() {
  const targets = [path.join(DATA, 'auth.json'), path.join(DATA, 'mysql.json'), path.join(DATA, 'FIRST-LOGIN.txt')];
  try {
    for (const e of await fsp.readdir(SECRETS, { withFileTypes: true })) {
      if (e.isFile()) targets.push(path.join(SECRETS, e.name));
    }
  } catch {}
  const fixed = [];
  for (const f of targets) {
    try {
      const st = await fsp.stat(f);
      if ((st.mode & 0o777) !== 0o600) {
        await fsp.chmod(f, 0o600);
        fixed.push(path.basename(f));
      }
    } catch {}
  }
  return fixed;
}

/**
 * Does a repo's .gitignore keep .env out of git? Only then is it safe to write a .env file into the
 * working tree — we never want the panel to be the reason a secret gets committed.
 */
async function envIsGitIgnored(root) {
  for (const name of ['.gitignore', '.git/info/exclude']) {
    try {
      const txt = await fsp.readFile(path.join(root, name), 'utf8');
      if (/^\s*(\/)?\.env(\.\*)?\s*$/m.test(txt) || /^\s*\*\.env\s*$/m.test(txt)) return true;
    } catch {}
  }
  return false;
}

/** copy the stored secrets into <appPath>/.env so framework tooling (artisan, cron, tinker) sees them */
async function restoreLaravelEnv(slug, appPath, note = () => {}) {
  if (!slug) return { written: false, reason: 'no project' };
  const stored = await readEnv(slug);
  if (!stored.trim()) return { written: false, reason: 'no stored env' };
  const target = path.join(appPath, '.env');
  const existing = await fsp.readFile(target, 'utf8').catch(() => null);
  if (existing === stored) return { written: false, reason: 'already in place' };
  if (!(await envIsGitIgnored(appPath))) return { written: false, reason: '.env is not gitignored here — not writing it' };
  await fsp.writeFile(target, stored, { mode: 0o600 });
  note(`restored .env in ${appPath}`);
  return { written: true };
}

/**
 * Secrets restore sweep (runs at boot, and on demand from the panel).
 * data/secrets/<slug>.env is the source of truth. Sandboxes restart, project directories get
 * re-cloned — this makes sure the secrets a project needs are still there afterwards, and says out
 * loud which projects have none. It only ever CREATES missing files or fixes permissions; it never
 * overwrites .env content a service wrote for itself.
 */
async function restoreSecretsSweep() {
  const report = { checked: 0, createdFromExample: 0, permissionsFixed: 0, laravelEnv: 0, withoutSecrets: [] };
  let entries = [];
  try { entries = await fsp.readdir(PROJECTS, { withFileTypes: true }); } catch { return report; }
  await fsp.mkdir(SECRETS, { recursive: true });
  for (const e of entries) {
    if (!e.isDirectory()) continue;
    report.checked += 1;
    const slug = e.name;
    const file = envPath(slug);
    let has = fs.existsSync(file);
    if (!has) {
      for (const cand of ['.env.example', '.env.sample', '.env.dist']) {
        const example = path.join(projectDir(slug), cand);
        if (fs.existsSync(example)) {
          await fsp.copyFile(example, file);
          await fsp.chmod(file, 0o600);
          report.createdFromExample += 1;
          log(`secret restored for ${slug}: seeded from ${cand}`);
          has = true;
          break;
        }
      }
    }
    if (has) {
      try {
        const st = await fsp.stat(file);
        if ((st.mode & 0o777) !== 0o600) { await fsp.chmod(file, 0o600); report.permissionsFixed += 1; }
      } catch {}
      const meta = (await readMeta(slug)) || {};
      const services = await readServices(slug);
      const isLaravel = meta.runtime === 'laravel' || services.some((sv) => sv.runtime === 'laravel');
      if (isLaravel) {
        for (const svc of services) {
          const appPath = safeJoin(projectDir(slug), svc.path || '.');
          if (!fs.existsSync(appPath)) continue;
          try {
            const r = await restoreLaravelEnv(slug, appPath);
            if (r.written) report.laravelEnv += 1;
          } catch (err) { log(`could not restore .env for ${slug}: ${err.message}`); }
        }
      }
    } else {
      report.withoutSecrets.push(slug);
    }
  }
  if (report.createdFromExample || report.permissionsFixed || report.laravelEnv) {
    log(`secrets sweep: ${report.checked} project(s) — ${report.createdFromExample} seeded from .env.example, ${report.permissionsFixed} permission fix(es), ${report.laravelEnv} .env restored`);
  }
  if (report.withoutSecrets.length) log(`secrets sweep: no .env stored for ${report.withoutSecrets.join(', ')}`);
  state.lastSecretsSweep = { ...report, at: nowIso() };
  return report;
}

function parseEnvText(text) {
  const map = {};
  for (const line of String(text || '').split('\n')) {
    const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/);
    if (m) map[m[1]] = m[2];
  }
  return map;
}

// ---------------------------------------------------------------------------
// databases
// ---------------------------------------------------------------------------

async function mysqlConfig() {
  try {
    return JSON.parse(await fsp.readFile(path.join(DATA, 'mysql.json'), 'utf8'));
  } catch {
    return null;
  }
}

async function mysqlAvailable() {
  const cfg = await mysqlConfig();
  if (!cfg) return false;
  const r = await exec('mysql', [
    '-h', cfg.host, '-P', String(cfg.port), '-u', cfg.user, `-p${cfg.password}`,
    '-N', '-B', '-e', 'SELECT 1',
  ]);
  return r.ok;
}

async function mysqlRun(cfg, sql, db = null) {
  const args = ['-h', cfg.host, '-P', String(cfg.port), '-u', cfg.user, `-p${cfg.password}`];
  if (db) args.push(db);
  args.push('-N', '-B', '-e', sql);
  const r = await exec('mysql', args);
  // execFile reports a missing binary as ok:false with an ENOENT code — name it, so the person
  // reads "the mysql client is not installed" instead of a blank "not reachable"
  if (!r.ok && /ENOENT/.test(String(r.stderr) + String(r.code))) {
    return { ...r, stderr: 'the mysql client is not installed on this host' };
  }
  return r;
}

async function provisionDb(slug) {
  const cfg = await mysqlConfig();
  if (!cfg) throw new Error('MySQL is not configured on this host');
  const db = `gitdock_${slug.replace(/-/g, '_')}`;
  const user = cfg.user;
  // exec() reports ok:false rather than throwing — without this check a missing client would look
  // like a provisioned database and the credentials would be written for a schema that does not exist
  const mine = await mysqlRun(cfg, `CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  if (!mine.ok) throw new Error(`could not create the database: ${(mine.stderr || mine.stdout || 'mysql refused').trim().slice(0, 300)}`);
  // The panel's own account is granted on the whole `gitdock\_%` pattern, so every project schema is
  // already usable and this GRANT is only needed where the account has narrower rights. MariaDB
  // refuses a GRANT from a wildcard holder (ERROR 1044) — that is not a failure, so verify access
  // instead of refusing to provision.
  const grant = await mysqlRun(cfg, `GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${user}'@'%'`);
  if (!grant.ok) {
    const canUse = await mysqlRun(cfg, 'SELECT 1', db);
    if (!canUse.ok) {
      throw new Error(`could not use the new database: ${(canUse.stderr || grant.stderr || '').trim().slice(0, 300)}`);
    }
  } else {
    await mysqlRun(cfg, 'FLUSH PRIVILEGES');
  }
  const injected = {
    DB_CONNECTION: 'mysql',
    DB_HOST: cfg.host,
    DB_PORT: String(cfg.port),
    DB_DATABASE: db,
    DB_USERNAME: cfg.user,
    DB_PASSWORD: cfg.password,
  };
  await appendEnv(slug, injected);
  return { database: db, ...injected };
}

async function importSql(slug, buf) {
  const cfg = await mysqlConfig();
  if (!cfg) throw new Error('MySQL is not configured on this host');
  const db = `gitdock_${slug.replace(/-/g, '_')}`;
  await mysqlRun(cfg, `CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  const tmp = path.join(os.tmpdir(), `gitdock-${slug}-${Date.now()}.sql`);
  await fsp.writeFile(tmp, buf);
  const r = await new Promise((resolve) => {
    const child = spawn('mysql', [
      '--default-character-set=utf8mb4', '-h', cfg.host, '-P', String(cfg.port),
      '-u', cfg.user, `-p${cfg.password}`, db,
    ]);
    const err = [];
    child.stderr.on('data', (d) => err.push(d));
    child.stdin.on('error', () => {});
    child.on('error', (e) => resolve({ code: -1, stderr: `could not run the mysql client: ${e.message}` }));
    fs.createReadStream(tmp).pipe(child.stdin);
    child.on('close', (code) => resolve({ code, stderr: Buffer.concat(err).toString().slice(0, 2000) }));
  });
  await fsp.unlink(tmp).catch(() => {});
  // code -1 means the client itself never ran (missing binary, no permission). Reporting that as a
  // successful import with a warning is worse than failing: the tables are not there.
  if (r.code === -1 || /could not run the mysql client/i.test(r.stderr)) throw new Error(r.stderr.trim().slice(0, 500));
  if (r.code !== 0 && /ERROR/i.test(r.stderr)) throw new Error(r.stderr.trim().slice(0, 500));
  const tables = await mysqlRun(cfg, 'SHOW TABLES', db);
  return { database: db, tables: tables.stdout.split('\n').filter(Boolean), warnings: r.stderr.trim().slice(0, 400) };
}

async function dbStatus(slug) {
  const cfg = await mysqlConfig();
  const s = { mysql: { configured: !!cfg, available: false }, sqlite: [], injected: null };
  if (cfg) {
    const db = `gitdock_${slug.replace(/-/g, '_')}`;
    const ok = await mysqlAvailable();
    s.mysql.available = ok;
    s.mysql.database = db;
    if (ok) {
      const t = await mysqlRun(cfg, 'SHOW TABLES', db);
      s.mysql.tables = t.stdout.split('\n').filter(Boolean);
    }
    s.injected = {
      DB_CONNECTION: 'mysql', DB_HOST: cfg.host, DB_PORT: String(cfg.port),
      DB_DATABASE: db, DB_USERNAME: cfg.user, DB_PASSWORD: '•••••• (in .env)',
    };
  }
  // SQLite files anywhere in the project
  const root = projectDir(slug);
  const stack = [root];
  while (stack.length) {
    const cur = stack.pop();
    let entries = [];
    try {
      entries = await fsp.readdir(cur, { withFileTypes: true });
    } catch {
      continue;
    }
    for (const e of entries) {
      if (e.name === '.git') continue;
      const full = path.join(cur, e.name);
      if (e.isDirectory()) stack.push(full);
      else if (/\.(sqlite|sqlite3|db)$/i.test(e.name)) {
        const rel = path.relative(root, full);
        const tables = await sqliteTables(full);
        s.sqlite.push({ path: rel, size: humanBytes((await fsp.stat(full)).size), tables });
      }
    }
  }
  return s;
}

async function sqliteTables(file) {
  const r = await exec('sqlite3', ['-json', file, "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"]);
  if (!r.ok) return [];
  try {
    return JSON.parse(r.stdout || '[]').map((o) => o.name);
  } catch {
    return [];
  }
}

async function sqliteQuery(file, sql, limit = 100) {
  const r = await exec('sqlite3', ['-json', file, sql]);
  if (!r.ok) throw new Error(r.stderr.trim().slice(0, 400));
  const rows = JSON.parse(r.stdout || '[]');
  return rows.slice(0, limit);
}

// ---------------------------------------------------------------------------
// github
// ---------------------------------------------------------------------------

/**
 * Pull owner/repo out of anything a person might paste: a github.com URL (with or without .git),
 * an ssh remote, or the bare "owner/repo" form. Anything else is not a GitHub repo — return null
 * rather than guessing, so the caller can say so.
 */
function parseRepoRef(input) {
  const raw = String(input || '').trim();
  if (!raw) return null;
  const patterns = [
    /^(?:https?:\/\/)?(?:www\.)?github\.com[/:]([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+?)(?:\.git)?(?:[/#?].*)?$/i,
    /^(?:git@)?github\.com:([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+?)(?:\.git)?$/i,
    /^([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+?)(?:\.git)?$/,
  ];
  for (const re of patterns) {
    const m = raw.match(re);
    if (m && m[1] && m[2]) return { owner: m[1], repo: m[2], full: `${m[1]}/${m[2]}` };
  }
  return null;
}

/**
 * Every repository the current token can reach — the honest answer to "why can't it see my repo?".
 * Cached briefly: the New-project dialog may ask more than once, and GitHub rate-limits per token.
 */
let tokenReposCache = { at: 0, list: [] };
async function listTokenRepos({ maxAgeMs = 60000 } = {}) {
  if (Date.now() - tokenReposCache.at < maxAgeMs && tokenReposCache.list.length) return tokenReposCache.list;
  const raw = await ghApi('/user/repos?per_page=100&sort=updated&affiliation=owner,collaborator,organization_member');
  const list = (raw || []).map((r) => ({
    full: r.full_name, owner: r.owner.login, name: r.name, private: !!r.private,
    defaultBranch: r.default_branch, pushedAt: r.pushed_at,
    canPush: !!(r.permissions && r.permissions.push),
  }));
  tokenReposCache = { at: Date.now(), list };
  return list;
}

/** near matches, so a typo does not read like a permissions problem */
function similarRepos(want, list) {
  const needle = String(want || '').toLowerCase();
  if (!needle) return [];
  const distance = (n) => {
    const m = new Array(needle.length + 1).fill(0).map((_, i) => i);
    for (let i = 1; i <= n.length; i++) {
      let prev = m[0]; m[0] = i;
      for (let j = 1; j <= needle.length; j++) {
        const tmp = m[j];
        m[j] = Math.min(m[j] + 1, m[j - 1] + 1, prev + (n[i - 1] === needle[j - 1] ? 0 : 1));
        prev = tmp;
      }
    }
    return m[needle.length];
  };
  return list
    .map((r) => {
      const nm = r.name.toLowerCase();
      const d = nm === needle ? 0 : (nm.includes(needle) || needle.includes(nm)) ? 1 : distance(nm);
      return { r, d };
    })
    .filter((x) => x.d <= Math.max(2, Math.floor(needle.length / 3)))
    .sort((a, b) => a.d - b.d)
    .slice(0, 5)
    .map((x) => x.r.full);
}

async function ghApi(pathname, { method = 'GET', body = null } = {}) {
  if (!state.githubToken) throw new Error('no GitHub token set');
  const res = await fetch(`https://api.github.com${pathname}`, {
    method,
    headers: {
      accept: 'application/vnd.github+json',
      'user-agent': 'gitdock-poc',
      authorization: `Bearer ${state.githubToken}`,
      'x-github-api-version': '2022-11-28',
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {}
  if (res.status === 401) {
    // GitHub is not refusing this repository, it is refusing the token: revoked, regenerated or
    // expired. Clear it, so the panel stops claiming to be connected and every later call does not
    // fail the same silent way.
    const was = state.githubLogin ? ` (was signed in as ${state.githubLogin})` : '';
    state.githubToken = null;
    state.githubLogin = null;
    state.githubName = null;
    log(`GitHub rejected the token${was}: 401 Bad credentials — cleared it; paste a new token in Settings -> GitHub`);
    const err = new Error('the GitHub token this panel holds is no longer valid (401 Bad credentials) — it was revoked, regenerated or expired, and has now been cleared. Create a new token and paste it in Settings -> GitHub');
    err.status = 401;
    throw err;
  }
  if (!res.ok) throw new Error(`GitHub ${res.status}: ${(data && data.message) || text.slice(0, 200)}`);
  return data;
}

/**
 * git execs this script, and a workspace/snapshot restore drops the executable bit — which makes every
 * authenticated git call fail with:
 *     fatal: cannot exec '…/askpass.sh': Permission denied
 *     fatal: could not read Username for 'https://github.com': terminal prompts disabled
 * That looks like a token problem in the UI but is not. Re-assert the bit before any git call.
 */
function ensureAskpass() {
  const file = path.join(ROOT, 'lib', 'askpass.sh');
  try {
    fs.accessSync(file, fs.constants.X_OK);
    return { file, fixed: false };
  } catch {}
  try {
    fs.chmodSync(file, 0o755);
    log(`credential helper was not executable — fixed: ${file}`);
  } catch (e) {
    log(`could not make ${file} executable: ${e.message} — private clones and pushes will fail`);
  }
  return { file, fixed: true };
}

/** token never touches disk: passed through GIT_ASKPASS for this call only */
async function gitRemote(slug, args) {
  const askpass = ensureAskpass().file;
  const r = await exec('git', ['-c', `core.askpass=${askpass}`, ...args], {
    cwd: projectDir(slug),
    env: { ...gitEnv(), GITDOCK_TOKEN: state.githubToken || '', GITDOCK_LOGIN: state.githubLogin || 'x-access-token' },
  });
  return r;
}

function parseRepo(url) {
  const m = String(url).match(/github\.com[:/]([^/]+)\/([^/.]+?)(\.git)?$/i);
  return m ? { owner: m[1], repo: m[2] } : null;
}

// ---------------------------------------------------------------------------
// branches, merges and pull requests
// ---------------------------------------------------------------------------

/** reject branch names that git would choke on, before git gets a chance to be cryptic */
function branchNameProblem(name) {
  const n = String(name == null ? '' : name).trim();
  if (!n) return 'a branch name is required';
  if (n.length > 120) return 'that branch name is too long';
  if (/[\s~^:?*[\\]/.test(n)) return 'branch names cannot contain spaces or any of ~ ^ : ? * [ \\';
  if (n.startsWith('-') || n.startsWith('/') || n.endsWith('/') || n.endsWith('.')) return 'that is not a valid branch name';
  if (n.includes('..') || n.includes('//') || n.includes('@{')) return 'that is not a valid branch name';
  return null;
}

async function refExists(root, ref) {
  const r = await git(root, ['rev-parse', '--verify', '--quiet', `${ref}^{commit}`]);
  return r.ok && r.stdout.trim().length > 0;
}

async function currentBranchOf(root) {
  const r = await git(root, ['symbolic-ref', '--quiet', '--short', 'HEAD']);
  return r.ok ? r.stdout.trim() : '';
}

function trackNumbers(track) {
  const a = /ahead (\d+)/.exec(track || '');
  const b = /behind (\d+)/.exec(track || '');
  return { ahead: a ? Number(a[1]) : 0, behind: b ? Number(b[1]) : 0, gone: /gone/.test(track || '') };
}

// NOTE: git 2.47 does NOT expand `%x1f` inside `for-each-ref --format` (it does in `log --pretty`),
// it prints the four characters literally — so the separator has to be a real 0x1f byte in the string.
const UNIT_SEP = '\x1f';
const REF_FORMAT = ['%(refname:short)', '%(objectname:short)', '%(committerdate:relative)', '%(subject)', '%(upstream:short)', '%(upstream:track)'].join(UNIT_SEP);

function parseRefs(out) {
  return out.split('\n').filter(Boolean).map((line) => {
    const [name, sha, date, subject, upstream, track] = line.split(UNIT_SEP);
    return { name, sha, date, subject, upstream: upstream || null, ...trackNumbers(track) };
  });
}

/** everything the branch UI needs in one round trip */
async function branchState(slug) {
  const root = projectDir(slug);
  const current = await currentBranchOf(root);
  const local = parseRefs((await git(root, ['for-each-ref', '--sort=-committerdate', `--format=${REF_FORMAT}`, 'refs/heads'])).stdout);
  const remote = parseRefs((await git(root, ['for-each-ref', '--sort=-committerdate', `--format=${REF_FORMAT}`, 'refs/remotes'])).stdout)
    .filter((b) => !/\/HEAD$/.test(b.name))
    .map((b) => {
      const i = b.name.indexOf('/');
      return { ...b, remote: b.name.slice(0, i), name: b.name.slice(i + 1) };
    });
  const status = await git(root, ['status', '--porcelain']);
  const meta = (await readMeta(slug)) || {};
  // without an upstream (a project with no remote, or a brand-new branch) there is still a useful
  // comparison: how far this branch is from the branch it would be merged into
  const baseName = meta.branch
    || (local.some((b) => b.name === 'main') ? 'main' : (local.some((b) => b.name === 'master') ? 'master' : null));
  for (const b of local) {
    if (b.upstream || !baseName || b.name === baseName) continue;
    const c = await git(root, ['rev-list', '--left-right', '--count', `${baseName}...${b.name}`]);
    if (!c.ok) continue;
    const [behind, ahead] = c.stdout.trim().split(/\s+/).map(Number);
    b.behind = behind || 0;
    b.ahead = ahead || 0;
    b.vsBase = baseName;
  }
  const branch = local.find((b) => b.name === current) || null;
  return {
    current: current || null,
    detached: !current,
    dirty: status.stdout.split('\n').filter(Boolean).length,
    default: meta.branch || null,
    base: baseName,
    remoteUrl: meta.remote || null,
    ahead: branch ? branch.ahead : 0,
    behind: branch ? branch.behind : 0,
    vsBase: branch ? branch.vsBase || null : null,
    local,
    remote,
  };
}

/** is this commit a merge (2+ parents)? reverting a normal commit is a different operation */
async function mergeParents(root, sha) {
  const r = await git(root, ['rev-list', '--parents', '-n1', sha]);
  if (!r.ok) return null;
  return r.stdout.trim().split(/\s+/).slice(1);
}

// ---------------------------------------------------------------------------
// jobs (long running creation / composer)
// ---------------------------------------------------------------------------

/**
 * Keep GitDock's own metadata out of `git status`. Commits already exclude it (`:(exclude).gitdock`),
 * but raw git still shows it as untracked — confusing for anyone who opens a terminal, and a trap for
 * "git add -A" by hand. .git/info/exclude is local-only, so this never travels with the repo.
 */
async function excludeGitdockMeta(dir) {
  try {
    const file = path.join(dir, '.git', 'info', 'exclude');
    await fsp.mkdir(path.dirname(file), { recursive: true });
    const cur = await fsp.readFile(file, 'utf8').catch(() => '');
    if (/^\s*\.gitdock\/?\s*$/m.test(cur)) return false;
    await fsp.writeFile(file, `${cur.replace(/\s*$/, '')}\n# GitDock metadata (local only)\n.gitdock/\n`);
    return true;
  } catch {
    return false;
  }
}

function makeJob(kind, label, slug = null) {
  const id = crypto.randomBytes(6).toString('hex');
  const logPath = path.join(JOBS_DIR, `${id}.log`);
  const stream = fs.createWriteStream(logPath);
  // A cancelled job's child can still emit output after we closed the log. Writing then raises
  // ERR_STREAM_WRITE_AFTER_END, and an 'error' event with no listener takes the whole panel down —
  // that is exactly how one deleted-mid-clone project killed the process. Never again: swallow it.
  stream.on('error', (e) => log(`job ${id} log stream error (ignored): ${e.message}`));
  const job = {
    id, kind, label, slug, status: 'running', startedAt: nowIso(), logPath, child: null,
    cancelled: false, stream, closed: false,
    write(chunk) {
      if (job.closed || stream.writableEnded || stream.destroyed) return false;
      try { return stream.write(chunk); } catch { return false; }
    },
  };
  jobs.set(id, job);
  return job;
}

/** cancel everything still running for a project (used when it is deleted mid-job) */
function cancelJobsFor(slug) {
  const cancelled = [];
  for (const job of jobs.values()) {
    if (job.slug !== slug || job.status !== 'running') continue;
    job.cancelled = true;
    try { if (job.child) job.child.kill('SIGTERM'); } catch {}
    finishJob(job, 'cancelled', `project ${slug} was deleted`);
    cancelled.push(job.id);
  }
  return cancelled;
}

/**
 * Jobs must not resurrect a deleted project. Call between steps and before writing anything.
 * Returns true when the job should stop.
 */
function jobAborted(job, dir) {
  if (job.cancelled) return true;
  if (dir && !fs.existsSync(dir)) {
    finishJob(job, 'cancelled', 'project directory disappeared (project deleted)');
    return true;
  }
  return false;
}

function finishJob(job, status, note) {
  if (job.closed) return job;
  job.status = status;
  job.note = note || '';
  job.endedAt = nowIso();
  job.write(`\n=== ${status} ${note || ''} ===\n`);
  job.closed = true;
  try { job.stream.end(); } catch {}
  return job;
}

function jobView(job) {
  return {
    id: job.id,
    kind: job.kind,
    label: job.label,
    status: job.status,
    note: job.note || '',
    startedAt: job.startedAt,
    endedAt: job.endedAt || null,
    log: redact(tailLog(job.logPath, 8000)),
  };
}

// ---------------------------------------------------------------------------
// manifest / setup
// ---------------------------------------------------------------------------

const configFile = (slug) => path.join(projectDir(slug), '.gitdock', 'config.json');

async function readConfig(slug) {
  try { return JSON.parse(await fsp.readFile(configFile(slug), 'utf8')); } catch { return null; }
}

async function writeConfig(slug, cfg) {
  await fsp.mkdir(path.dirname(configFile(slug)), { recursive: true });
  await fsp.writeFile(configFile(slug), JSON.stringify(cfg, null, 2));
}

/** what GitDock would do with this project right now: manifest if present, else detection */
async function setupView(slug) {
  const root = projectDir(slug);
  const manifest = await readManifest(root);
  let plan = null;
  let planError = null;
  if (manifest.parsed) {
    try { plan = planFromManifest(manifest.parsed); } catch (e) { planError = e.message; }
  }
  const detected = await detectProject(root);
  const meta = (await readMeta(slug)) || {};
  return {
    slug,
    manifest: { file: manifest.file, exists: !!manifest.file, ok: manifest.ok, errors: manifest.errors, text: manifest.text },
    plan, planError,
    detected,
    applied: await readConfig(slug),
    meta,
  };
}

/** apply a plan: services, databases, env defaults — then optionally install and start */
async function applyPlan(slug, { runInstall = false, start = false, source = 'manifest' } = {}) {
  const root = projectDir(slug);
  const view = await setupView(slug);
  const plan = view.plan || {
    services: view.detected.services,
    install: view.detected.install,
    databases: view.detected.databases,
    env: view.detected.env,
    preview: view.detected.preview,
    resources: view.detected.resources,
    runtime: view.detected.runtime,
    docroot: view.detected.docroot,
  };
  if (!plan || !plan.services || !plan.services.length) throw new Error('nothing to apply — no manifest and no detection result');

  const report = { source, services: [], databases: [], env: [], install: null, started: [] };

  // 1. env defaults + required keys
  for (const [k, v] of Object.entries((plan.env && plan.env.defaults) || {})) {
    const cur = await parseEnvFile(slug);
    if (!(k in cur)) { await appendEnv(slug, { [k]: String(v) }); report.env.push(`${k}=${v}`); }
  }

  // 2. services
  const list = await readServices(slug);
  for (const svc of plan.services) {
    if (list.some((s) => s.id === svc.id)) continue;
    const def = {
      id: svc.id,
      runtime: svc.runtime === 'laravel' || svc.runtime === 'php' ? 'php' : svc.runtime,
      path: svc.path || '.',
      docroot: svc.docroot || '.',
      port: svc.port || null,
      public: svc.public !== false,
      command: svc.command || null,
      createdAt: nowIso(),
    };
    if (def.runtime === 'node' || def.runtime === 'static' || def.runtime === 'php') {
      list.push(def);
      report.services.push(def.id);
    } else {
      report.services.push(`${def.id} (skipped: ${def.runtime} runtime not in this POC)`);
    }
  }
  await writeServices(slug, list);

  // 3. databases
  for (const db of plan.databases || []) {
    try {
      if (/mysql|mariadb/i.test(db.engine)) {
        const out = await provisionDb(slug);
        report.databases.push(`${db.id}: mysql ${out.database}`);
        if (db.import) {
          const imp = safeJoin(root, db.import);
          if (fs.existsSync(imp)) {
            const res = await importSql(slug, await fsp.readFile(imp));
            report.databases.push(`${db.id}: imported ${res.tables.length} table(s) from ${db.import}`);
          }
        }
      } else if (/sqlite/i.test(db.engine)) {
        const target = safeJoin(root, 'database/database.sqlite');
        await fsp.mkdir(path.dirname(target), { recursive: true });
        if (!fs.existsSync(target)) await fsp.writeFile(target, '');
        report.databases.push(`${db.id}: sqlite database/database.sqlite`);
      } else {
        report.databases.push(`${db.id}: ${db.engine} not supported in this POC`);
      }
    } catch (e) {
      report.databases.push(`${db.id}: failed — ${e.message}`);
    }
  }

  // 3b. now that databases injected their keys, report anything still missing
  if (plan.env && plan.env.requiredFrom) {
    const reqFile = safeJoin(root, plan.env.requiredFrom);
    if (fs.existsSync(reqFile)) {
      const required = Object.keys(parseEnvText(await fsp.readFile(reqFile, 'utf8')));
      const have = await parseEnvFile(slug);
      report.envMissing = required.filter((k) => !(k in have));
    }
  }

  // 4. remember what we applied
  await writeConfig(slug, { appliedAt: nowIso(), source, plan, report });

  // 5. optional install + start (install runs as a job so the UI can stream it)
  if (runInstall && plan.install && plan.install.length) {
    const job = makeJob('install', `Install dependencies for ${slug}`, slug);
    runInstallJob(slug, plan.install, job).catch(() => {});
    report.install = { job: jobView(job) };
  }
  if (start) {
    for (const def of await readServices(slug)) {
      if (running.has(`${slug}:${def.id}`)) continue;
      try { report.started.push({ id: def.id, ...(await startService(slug, def, {})) }); }
      catch (e) { report.started.push({ id: def.id, error: e.message }); }
    }
  }
  return report;
}

async function runInstallJob(slug, commands, job) {
  const root = projectDir(slug);
  const env = { ...process.env, COMPOSER_MEMORY_LIMIT: '-1', COMPOSER_ALLOW_SUPERUSER: '1', npm_config_yes: 'true' };
  for (const cmd of commands) {
    if (jobAborted(job, root)) return;
    job.write(`\n$ ${cmd}\n`);
    const code = await new Promise((resolve) => {
      const child = spawn('/bin/bash', ['-lc', cmd], { cwd: root, env });
      job.child = child;
      child.stdout.on('data', (d) => job.write(d));
      child.stderr.on('data', (d) => job.write(d));
      child.on('error', (e) => { job.write(`[gitdock] could not run \`${cmd}\`: ${e.message}\n`); resolve(-1); });
      child.on('close', resolve);
    });
    job.child = null;
    if (jobAborted(job, root)) return;
    if (code !== 0) return finishJob(job, 'failed', `\`${cmd}\` exited ${code}: ${lastErrorLine(job.logPath)}`);
  }
  finishJob(job, 'done', 'dependencies installed');
}

// ---------------------------------------------------------------------------
// previews (branch / PR sandboxes with TTL)
// ---------------------------------------------------------------------------

const PREVIEWS_FILE = path.join(DATA, 'previews.json');
const PREVIEWS_DIR = path.join(DATA, 'previews');
const previewKey = (id, svcId) => `preview:${id}:${svcId}`;

async function readPreviews() {
  try { return JSON.parse(await fsp.readFile(PREVIEWS_FILE, 'utf8')); } catch { return []; }
}
async function writePreviews(list) {
  await fsp.mkdir(PREVIEWS_DIR, { recursive: true });
  await fsp.writeFile(PREVIEWS_FILE, JSON.stringify(list, null, 2));
}

async function createPreview(slug, { ref, kind = 'branch', number = null, ttlHours = null, install = false, start = true } = {}) {
  const root = projectDir(slug);
  if (!ref) throw new Error('ref required');
  const view = await setupView(slug);
  const plan = view.plan || {
    services: view.detected.services, install: view.detected.install,
    databases: [], env: view.detected.env, preview: view.detected.preview,
  };
  const id = crypto.randomBytes(4).toString('hex');
  const dir = path.join(PREVIEWS_DIR, `${slug}-${id}`);
  await fsp.mkdir(PREVIEWS_DIR, { recursive: true });

  // a worktree shares the project's git objects: instant, no copying
  const wt = await git(root, ['worktree', 'add', '--detach', dir, ref]);
  if (!wt.ok) throw new Error(`could not create preview worktree: ${(wt.stderr || '').trim().slice(0, 300)}`);

  const ttl = Number(ttlHours || (plan.preview && plan.preview.ttlHours) || 72);
  const record = {
    id, slug, ref, kind, number, dir,
    createdAt: nowIso(),
    expiresAt: new Date(Date.now() + ttl * 3600 * 1000).toISOString(),
    ttlHours: ttl,
    services: [],
    installJob: null,
    status: 'starting',
  };

  if (install && plan.install && plan.install.length) {
    const job = makeJob('install', `Install for preview ${ref}`, slug);
    record.installJob = job.id;
    runInstallJobIn(dir, plan.install, job).catch(() => {});
  }

  // start each service from the plan inside the preview dir
  for (const svc of plan.services) {
    if (svc.runtime === 'worker' || svc.runtime === 'cron') continue;
    const def = {
      id: svc.id,
      runtime: svc.runtime === 'laravel' ? 'php' : svc.runtime,
      path: svc.path || '.',
      docroot: svc.docroot || '.',
      port: null,
    };
    try {
      const info = await startServiceIn(dir, previewKey(id, def.id), def, { slug });
      record.services.push({ id: def.id, port: info.port, pid: info.pid, up: !!(info.health && info.health.up) });
    } catch (e) {
      record.services.push({ id: def.id, error: e.message });
    }
  }
  record.status = record.services.some((s) => s.port) ? 'running' : 'failed';

  const all = await readPreviews();
  all.push(record);
  await writePreviews(all);
  return record;
}

async function runInstallJobIn(dir, commands, job) {
  const env = { ...process.env, COMPOSER_MEMORY_LIMIT: '-1', COMPOSER_ALLOW_SUPERUSER: '1', npm_config_yes: 'true' };
  for (const cmd of commands) {
    if (jobAborted(job, dir)) return;
    job.write(`\n$ ${cmd}\n`);
    const code = await new Promise((resolve) => {
      const child = spawn('/bin/bash', ['-lc', cmd], { cwd: dir, env });
      job.child = child;
      child.stdout.on('data', (d) => job.write(d));
      child.stderr.on('data', (d) => job.write(d));
      child.on('error', (e) => { job.write(`[gitdock] could not run \`${cmd}\`: ${e.message}\n`); resolve(-1); });
      child.on('close', resolve);
    });
    job.child = null;
    if (jobAborted(job, dir)) return;
    if (code !== 0) return finishJob(job, 'failed', `\`${cmd}\` exited ${code}`);
  }
  finishJob(job, 'done', 'dependencies installed');
}

async function teardownPreview(id, why = 'manual') {
  const all = await readPreviews();
  const rec = all.find((p) => p.id === id);
  if (!rec) return null;
  for (const s of rec.services || []) await stopServiceByKey(previewKey(id, s.id));
  const root = projectDir(rec.slug);
  await git(root, ['worktree', 'remove', '--force', rec.dir]);
  await git(root, ['worktree', 'prune']);
  await fsp.rm(rec.dir, { recursive: true, force: true });
  const left = all.filter((p) => p.id !== id);
  await writePreviews(left);
  log(`preview ${id} (${rec.slug}@${rec.ref}) torn down: ${why}`);
  return { ...rec, teardownReason: why };
}

/** every minute: tear down expired previews */
function startPreviewSweeper() {
  setInterval(async () => {
    try {
      for (const p of await readPreviews()) {
        if (new Date(p.expiresAt).getTime() < Date.now()) await teardownPreview(p.id, 'expired (ttl)');
      }
    } catch (e) { log('sweeper error', e.message); }
  }, Number(process.env.GITDOCK_SWEEP_MS || 60 * 1000));
}

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------

async function api(req, res, url) {
  const p = url.pathname.replace(/\/+$/, '') || '/';
  const q = url.searchParams;
  const method = req.method.toUpperCase();

  // Remember the public host this panel is being reached on, e.g. "3000-abc123.e2b.app"
  // -> the sandbox suffix "abc123.e2b.app" lets us build correct preview URLs server-side.
  const hostHeader = String(req.headers['x-forwarded-host'] || req.headers.host || '').split(',')[0].trim();
  const hostMatch = hostHeader.match(/^\d+-(.+)$/);
  if (hostMatch) state.publicHostSuffix = hostMatch[1];
  const parts = p.split('/').filter(Boolean); // e.g. ['api','projects','slug','tree']

  // ---- access control -----------------------------------------------------
  // public: these must work before anyone is signed in
  // a download link: no session, the key carries the permission, scope and expiry
  if (parts[0] === 'api' && parts[1] === 'dl' && parts[2] && method === 'GET') {
    const entry = downloadKeys.get(parts[2]);
    if (!entry) return fail(res, 404, 'this download link is not valid (already used, expired, or the panel restarted) — open the panel and click download again');
    if (entry.exp < Date.now()) {
      downloadKeys.delete(parts[2]);
      return fail(res, 410, 'this download link expired — open the panel and click download again');
    }
    if (entry.kind === 'raw') return sendRaw(res, entry.slug, entry.ref);
    return sendArchive(res, entry.slug, entry.ref, null, entry.folder || null);
  }

  if (p === '/api/auth/me' && method === 'GET') {
    const who = auth.whoami(req);
    const store = auth.store();
    return json(res, 200, {
      ok: true,
      mode: who.mode,                     // open | locked | ok
      user: who.user,
      locked: who.mode === 'locked',
      users: who.mode === 'ok' && auth.isOwner(who.user) ? auth.listUsers().length : undefined,
      firstAccount: store ? undefined : true,
    });
  }
  if (p === '/api/auth/login' && method === 'POST') {
    const body = await readJsonBody(req);
    const name = String(body.name || '').trim();
    const password = String(body.password || '');
    if (!name || !password) return fail(res, 400, 'user name and password are required');
    const ip = clientIp(req);
    const wait = auth.throttled(ip, name);
    if (wait) return fail(res, 429, `too many failed attempts — try again in ${Math.ceil(wait / 60)} minute(s)`);
    const session = auth.login(name, password);
    if (!session) {
      auth.noteFailure(ip, name);
      log(`failed sign-in for "${name}" from ${ip}`);
      return fail(res, 401, 'wrong user name or password');
    }
    auth.clearFailures(ip, name);
    log(`signed in: ${session.user.name} (${session.user.role}) from ${ip}`);
    const secure = String(req.headers['x-forwarded-proto'] || '').includes('https');
    res.setHeader('set-cookie', auth.cookieHeader(session.token, { secure, ttlHours: Number((auth.store() || {}).ttlHours || 720) }));
    return json(res, 200, { ok: true, user: session.user, token: session.token });
  }
  if (p === '/api/auth/logout' && method === 'POST') {
    // This device only: the token that made this call stops working immediately, the cookie goes
    // away, and any other device or teammate keeps the session it already has.
    const tokens = auth.rawTokens(req);
    const killed = tokens.reduce((n, t) => n + (auth.revoke(t) ? 1 : 0), 0);
    res.setHeader('set-cookie', auth.clearCookie());
    if (killed) log(`signed out on this device — ${killed} session token(s) revoked`);
    return json(res, 200, { ok: true, revoked: killed });
  }
  // owner-only: manage who can get in
  if (p === '/api/auth/users') {
    const who = auth.whoami(req);
    if (who.mode === 'locked') return fail(res, 401, 'sign in required');
    if (who.mode === 'ok' && !auth.isOwner(who.user)) return fail(res, 403, 'only an owner can manage access');
    if (method === 'GET') return json(res, 200, { ok: true, users: auth.listUsers(), you: who.user });
    if (method === 'POST') {
      const body = await readJsonBody(req);
      try {
        const made = auth.addUser({ name: String(body.name || '').trim(), password: body.password || null, role: body.role === 'owner' ? 'owner' : 'member' });
        return json(res, 201, { ok: true, user: made, generatedPassword: made.password });
      } catch (e) {
        return fail(res, 400, e.message);
      }
    }
  }
  if (parts[0] === 'api' && parts[1] === 'auth' && parts[2] === 'users' && parts[3] && method === 'DELETE') {
    const who = auth.whoami(req);
    if (who.mode === 'locked') return fail(res, 401, 'sign in required');
    if (who.mode === 'ok' && !auth.isOwner(who.user)) return fail(res, 403, 'only an owner can manage access');
    try {
      return json(res, 200, { ok: true, removed: auth.removeUser(decodeURIComponent(parts[3])).name });
    } catch (e) {
      return fail(res, 400, e.message);
    }
  }
  if (p === '/api/auth/password' && method === 'POST') {
    const who = auth.whoami(req);
    if (who.mode !== 'ok') return fail(res, 401, 'sign in required');
    const body = await readJsonBody(req);
    const me = auth.findUser(who.user.name);
    if (!auth.login(who.user.name, String(body.current || ''))) return fail(res, 403, 'current password is wrong');
    try {
      auth.setPassword(who.user.name, String(body.next || ''));
    } catch (e) {
      return fail(res, 400, e.message);
    }
    const session = auth.login(who.user.name, String(body.next));
    const secure = String(req.headers['x-forwarded-proto'] || '').includes('https');
    res.setHeader('set-cookie', auth.cookieHeader(session.token, { secure }));
    log(`${who.user.name} changed their password`);
    return json(res, 200, { ok: true, token: session.token, user: me ? { name: me.name, role: me.role } : who.user });
  }
  if (p === '/api/auth/rotate' && method === 'POST') {
    const who = auth.whoami(req);
    if (who.mode === 'locked') return fail(res, 401, 'sign in required');
    if (who.mode === 'ok' && !auth.isOwner(who.user)) return fail(res, 403, 'only an owner can do this');
    auth.rotateSecret();
    const user = auth.findUser(who.user ? who.user.name : 'admin');
    const token = user ? auth.issue(user) : null;
    if (token) {
      const secure = String(req.headers['x-forwarded-proto'] || '').includes('https');
      res.setHeader('set-cookie', auth.cookieHeader(token, { secure }));
    }
    log('session secret rotated — every other device is signed out');
    return json(res, 200, { ok: true, token, note: 'every other session is signed out' });
  }

  if (p === '/api/previews' && method === 'GET') {
    const all = await readPreviews();
    return json(res, 200, {
      ok: true,
      previews: all.map((x) => ({ ...x, services: (x.services || []).map((s) => ({ ...s, running: !!running.get(previewKey(x.id, s.id)) })) })),
    });
  }
  if (p === '/api/health') return json(res, 200, { ok: true, startedAt: state.startedAt, projects: (await listProjects()).length });
  if (p === '/api/settings' && method === 'GET') {
    return json(res, 200, {
      ok: true,
      githubTokenSet: !!state.githubToken,
      githubLogin: state.githubLogin,
      identity: state.identity,
      effectiveIdentity: await resolveIdentity(null),
      runtimes: await runtimeReport(),
      projectsRoot: PROJECTS,
      lastSecretsSweep: state.lastSecretsSweep || null,
      user: auth.whoami(req).user,
      access: {
        mode: auth.whoami(req).mode,
        users: auth.listUsers().map((u) => u.name),
        locked: auth.whoami(req).mode !== 'open',
      },
    });
  }
  if (p === '/api/settings/token' && method === 'POST') {
    const body = await readJsonBody(req);
    const token = String(body.token || '').trim();
    if (!token) return fail(res, 400, 'token required');
    state.githubToken = token;
    try {
      const me = await ghApi('/user');
      state.githubLogin = me.login;
      state.githubName = me.name || null;
      return json(res, 200, {
        ok: true, login: me.login, name: me.name, avatar: me.avatar_url,
        identitySuggestion: { name: me.name || me.login, email: `${me.login}@users.noreply.github.com` },
      });
    } catch (e) {
      state.githubToken = null;
      state.githubLogin = null;
      // this is the paste-time path: the token was never stored, so word it as a bad paste
      if (e.status === 401) {
        return fail(res, 400, 'GitHub rejected this token (401 Bad credentials) — check it was copied in full, that it has not expired or been revoked, and that it belongs to the account you expect');
      }
      return fail(res, 400, `token rejected: ${e.message}`);
    }
  }
  if (p === '/api/settings/identity' && method === 'PUT') {
    const body = await readJsonBody(req);
    const name = body.name ? String(body.name).trim() : null;
    const email = body.email ? String(body.email).trim() : null;
    if ((name && !email) || (!name && email)) return fail(res, 400, 'give both a name and an email (or neither, to clear)');
    if (email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return fail(res, 400, `"${email}" does not look like an email address`);
    state.identity = { name, email };
    await saveSettings();
    return json(res, 200, { ok: true, identity: state.identity, effectiveIdentity: await resolveIdentity(null) });
  }

  if (p === '/api/settings/token' && method === 'DELETE') {
    state.githubToken = null;
    state.githubLogin = null;
    return json(res, 200, { ok: true });
  }

  if (p === '/api/maintenance/secrets' && method === 'GET') {
    return json(res, 200, { ok: true, lastSweep: state.lastSecretsSweep || null });
  }
  if (p === '/api/maintenance/secrets' && method === 'POST') {
    const report = await restoreSecretsSweep();
    return json(res, 200, { ok: true, ...report });
  }

  if (p === '/api/templates') {
    return json(res, 200, { ok: true, templates: Object.entries(TEMPLATES).map(([id, t]) => ({ id, label: t.label })) });
  }

  // everything this token can reach — used by the project dialog, and by anyone debugging access
  if (p === '/api/github/repos' && method === 'GET') {
    if (!state.githubToken) return json(res, 200, { ok: true, login: null, repos: [] });
    try {
      const repos = await listTokenRepos();
      return json(res, 200, { ok: true, login: state.githubLogin || null, count: repos.length, repos });
    } catch (e) {
      return fail(res, 502, `GitHub would not list repositories for this token: ${e.message}`);
    }
  }

  // "can my token actually see this repository?" — asked by the New-project dialog BEFORE it creates
  // anything, so a token that cannot reach the repo is answered here instead of by a failed clone
  // that leaves a half-open project in the panel.
  if (p === '/api/github/probe' && method === 'POST') {
    const body = await readJsonBody(req).catch(() => ({}));
    const ref = parseRepoRef(String(body.url || body.repo || ''));
    if (!ref) return fail(res, 400, 'that does not look like a GitHub repository (owner/repo, or a github.com URL)');
    if (!state.githubToken) {
      return json(res, 200, {
        ok: true, reachable: false, repo: ref.full,
        reason: 'no GitHub token is set in this panel, so only public repositories can be cloned — add one in Settings → GitHub',
      });
    }
    try {
      const r = await ghApi(`/repos/${ref.owner}/${ref.repo}`);
      return json(res, 200, {
        ok: true, reachable: true, login: state.githubLogin || null,
        repo: r.full_name, private: !!r.private, defaultBranch: r.default_branch,
        canPush: !!(r.permissions && r.permissions.push), isEmpty: r.size === 0,
      });
    } catch (e) {
      const msg = String(e.message || e);
      const status = Number(e.status || (msg.match(/GitHub (\d+)/) || [])[1] || 0);
      // 404 for a private repo is GitHub saying "you may not know this exists" — the token is the
      // thing to fix, and saying so here is the difference between a five-minute fix and a mystery
      let suggestions = [];
      let visible = null;
      if (status === 404) {
        // make the refusal concrete: what CAN this token see, and is the name a near miss?
        visible = await listTokenRepos().catch(() => null);
        if (visible) suggestions = similarRepos(ref.repo, visible);   // any owner: 'wrong account' reads the same to a person
      }
      const reason = status === 404
        ? `the token cannot see ${ref.full} — either it does not exist, or this token is not allowed to read it`
          + ` (fine-grained tokens need the repository added under "Selected repositories" with Contents: read; a classic token needs the "repo" scope)` + (visible ? ` — it can currently reach ${visible.length} repositor${visible.length === 1 ? 'y' : 'ies'}` : '')
        : status === 403
          ? `GitHub refused the request (403) — ${msg.replace(/^GitHub 403: /, '')}. Rate limits or token permissions will do this.`
          : status === 401
            ? msg                 // already worded for a person: the token is dead, here is what to do
            : msg;
      return json(res, 200, { ok: true, reachable: false, repo: ref.full, status, reason, suggestions });
    }
  }

  // ---- projects -----------------------------------------------------------
  if (p === '/api/projects' && method === 'GET') return json(res, 200, { ok: true, projects: await listProjects() });

  if (p === '/api/projects' && method === 'POST') {
    const body = await readJsonBody(req);
    const cloneUrl = String(body.cloneUrl || '').trim();
    const name = String(body.name || '').trim() ||
      (cloneUrl ? cloneUrl.replace(/\.git$/, '').split('/').filter(Boolean).pop() : '');
    if (!name) return fail(res, 400, 'name (or clone URL) required');
    const template = String(body.template || 'static');
    const slug = slugify(name);
    if (fs.existsSync(projectDir(slug))) return fail(res, 409, `project "${slug}" already exists`);

    // clone an existing repository: the normal way to start with real code
    if (cloneUrl) {
      const job = makeJob('clone', `Clone ${cloneUrl}`, slug);
      await fsp.mkdir(projectDir(slug), { recursive: true });
      await writePendingMeta(slug, {
        name: String(body.name || name), runtime: 'unknown', createdAt: nowIso(),
        template: 'clone', branch: body.branch || 'main', remote: cloneUrl,
      });
      runCloneJob(slug, cloneUrl, body.branch || null, job).catch((e) => finishJob(job, 'failed', e.message));
      return json(res, 202, { ok: true, slug, job: jobView(job) });
    }
    if (template === 'laravel') {
      const job = makeJob('create-laravel', `Create Laravel project ${slug}`, slug);
      await fsp.mkdir(projectDir(slug), { recursive: true });
      // composer create-project refuses a non-empty directory, so metadata lives elsewhere until it finishes
      await writePendingMeta(slug, { name, runtime: 'laravel', createdAt: nowIso(), template, branch: 'main' });
      runLaravelCreate(slug, job).catch(() => {});
      return json(res, 202, { ok: true, slug, job: jobView(job) });
    }
    const t = TEMPLATES[template] || TEMPLATES.static;
    await fsp.mkdir(projectDir(slug), { recursive: true });
    for (const [rel, content] of Object.entries(t.files || {})) {
      const target = safeJoin(projectDir(slug), rel);
      await fsp.mkdir(path.dirname(target), { recursive: true });
      await fsp.writeFile(target, content, 'utf8');
    }
    await gitInit(projectDir(slug), slug);
    const meta = { name, runtime: t.service?.runtime || 'static', createdAt: nowIso(), template, branch: 'main', remote: null };
    await writeMeta(slug, meta);
    if (t.service) await writeServices(slug, [{ ...t.service, createdAt: nowIso(), port: null }]);
    return json(res, 201, { ok: true, slug, project: meta });
  }

  // Job status must be readable even when the project is gone: a FAILED clone deletes the directory,
  // and the UI polls this route to learn how it failed. Guarding it behind "project exists" answered
  // every poll with 404 "project not found" — hiding the real git error from the person who needs it.
  if (parts[0] === 'api' && parts[1] === 'projects' && parts[2] && ['job', 'jobs'].includes(parts[3]) && method === 'GET') {
    const slug = parts[2];
    if (parts[3] === 'jobs') return json(res, 200, { ok: true, jobs: [...jobs.values()].filter((j) => !j.slug || j.slug === slug).map(jobView).reverse() });
    const id = q.get('id');
    const live = id ? jobs.get(id) : null;
    if (live) return json(res, 200, { ok: true, job: jobView(live) });
    // not in memory (panel restarted?): read the job log that is still on disk
    const logPath = id ? path.join(JOBS_DIR, `${id}.log`) : null;
    if (logPath && fs.existsSync(logPath)) {
      const text = tailLog(logPath, 20000);
      const end = [...text.matchAll(/=== (done|failed|cancelled) ([^=]*) ===/g)].pop();
      return json(res, 200, {
        ok: true,
        job: {
          id, slug, status: end ? end[1] : 'unknown', note: end ? end[2].trim() : 'no longer running',
          log: text, fromDisk: true,
        },
      });
    }
    return fail(res, 404, 'job not found');
  }

  if (parts[0] === 'api' && parts[1] === 'projects' && parts[2]) {
    const slug = parts[2];
    const root = projectDir(slug);
    if (!fs.existsSync(root)) return fail(res, 404, 'project not found (it was never created, or it has been deleted — a failed clone leaves nothing behind)');
    const sub = parts[3] || '';
    const rest = parts.slice(4).join('/');

    if (!sub && method === 'GET') {
      const identity = await resolveIdentity(slug);
      const meta = (await readMeta(slug)) || {};
      const services = await withLivePorts(slug);
      // `project` is the canonical shape the panel reads; the flat keys stay for older callers
      return json(res, 200, { ok: true, slug, meta, identity, services, project: { slug, meta, identity } });
    }
    if (!sub && method === 'DELETE') {
      const cleaned = { services: [], previews: [], database: null, secrets: false, cancelledJobs: [] };
      // stop anything still running for this project, so a clone/composer can't recreate it after deletion
      cleaned.cancelledJobs = cancelJobsFor(slug);
      await new Promise((r) => setTimeout(r, 150));
      for (const pv of (await readPreviews()).filter((x) => x.slug === slug)) {
        await teardownPreview(pv.id, 'project deleted');
        cleaned.previews.push(pv.id);
      }
      for (const svc of await readServices(slug)) {
        if (await stopService(slug, svc.id)) cleaned.services.push(svc.id);
      }
      try {
        const cfg = await mysqlConfig();
        if (cfg) {
          const dbName = `gitdock_${slug.replace(/-/g, '_')}`;
          const exists = await mysqlRun(cfg, `SELECT schema_name FROM information_schema.schemata WHERE schema_name='${dbName}'`);
          if (exists.ok && exists.stdout.trim()) {
            await mysqlRun(cfg, `DROP DATABASE IF EXISTS \`${dbName}\``);
            cleaned.database = dbName;
          }
        }
      } catch {}
      const envFile = path.join(SECRETS, `${slug}.env`);
      if (fs.existsSync(envFile)) {
        try { await fsp.rm(envFile, { force: true }); cleaned.secrets = true; } catch {}
      }
      await fsp.rm(root, { recursive: true, force: true });
      await fsp.rm(pendingMetaFile(slug), { force: true });
      // Deletion must be the end of it. If anything recreates the directory (a job that was mid-flight,
      // an editor autosave, a stray service) we want to hear about it instead of wondering later.
      log(`deleted ${slug}${cleaned.cancelledJobs.length ? ` (cancelled jobs: ${cleaned.cancelledJobs.join(', ')})` : ''}` + (fs.existsSync(root) ? ' — WARNING: directory still present right after rm' : ''));
      setTimeout(() => { if (fs.existsSync(root)) log(`WARNING: ${slug} reappeared at ${root} after deletion (something is recreating it)`); }, 3000);
      return json(res, 200, { ok: true, deleted: slug, cleaned });
    }

    // download ONE file: the editor's "zip" button used to hand out the whole project
    if (sub === 'raw' && method === 'GET') return sendRaw(res, slug, q.get('path'));

    if (sub === 'tree' && method === 'GET') return json(res, 200, { ok: true, tree: await buildTree(root) });

    if (sub === 'file' && method === 'GET') {
      const rel = q.get('path');
      const full = safeJoin(root, rel, { allowEmpty: false });
      const st = await fsp.stat(full).catch(() => null);
      if (!st || !st.isFile()) return fail(res, 404, 'file not found');
      const secret = isSecretPath(rel);
      if (st.size > MAX_EDIT_BYTES) {
        return json(res, 200, { ok: true, path: rel, tooLarge: true, size: st.size, humanSize: humanBytes(st.size) });
      }
      const buf = await fsp.readFile(full);
      const binary = buf.includes(0) || !/^[\x09\x0a\x0d\x20-\x7e\x80-\xff]*$/.test(buf.slice(0, 4096).toString('binary'));
      if (binary) return json(res, 200, { ok: true, path: rel, binary: true, size: st.size, humanSize: humanBytes(st.size) });
      return json(res, 200, {
        ok: true, path: rel, content: buf.toString('utf8'), secret,
        size: st.size, modified: st.mtime.toISOString(),
      });
    }

    if (sub === 'file' && method === 'PUT') {
      const rel = q.get('path');
      const body = await readJsonBody(req);
      const full = safeJoin(root, rel, { allowEmpty: false });
      await fsp.mkdir(path.dirname(full), { recursive: true });
      await fsp.writeFile(full, String(body.content ?? ''), 'utf8');
      const commit = await withLock(slug, () => commitAll(root, body.message || `Edit ${rel}`, slug));
      return json(res, 200, { ok: true, commit });
    }

    if (sub === 'file' && method === 'DELETE') {
      const rel = q.get('path');
      await deletePaths(slug, [rel]);
      return json(res, 200, { ok: true });
    }

    if (sub === 'upload-batch' && method === 'POST') {
      const buf = await readBody(req, 8 * 1024 * 1024);
      if (buf.length < 4) return fail(res, 400, 'missing batch manifest');
      const len = buf.readUInt32BE(0);
      if (len > 256 * 1024 || len + 4 > buf.length) return fail(res, 400, 'invalid batch manifest length');
      let manifest;
      try { manifest = JSON.parse(buf.subarray(4, 4 + len).toString('utf8')); }
      catch { return fail(res, 400, 'invalid batch manifest'); }
      if (!Array.isArray(manifest) || !manifest.length || manifest.length > 100) return fail(res, 400, 'batch must contain 1–100 files');
      let offset = 4 + len;
      const files = [], seen = new Set();
      for (const item of manifest) {
        if (!item || typeof item.path !== 'string' || !Number.isSafeInteger(item.size) || item.size < 0 || offset + item.size > buf.length) return fail(res, 400, 'invalid batch entry');
        const full = safeJoin(root, item.path, { allowEmpty: false });
        if (seen.has(full) || isIgnoredPath(item.path)) return fail(res, 400, 'duplicate or ignored batch path');
        seen.add(full);
        files.push({ full, start: offset, end: offset + item.size }); offset += item.size;
      }
      if (offset !== buf.length) return fail(res, 400, 'batch size mismatch');
      for (const f of files) {
        await fsp.mkdir(path.dirname(f.full), { recursive: true });
        await fsp.writeFile(f.full, buf.subarray(f.start, f.end));
      }
      return json(res, 200, { ok: true, files: files.length });
    }

    if (sub === 'upload' && method === 'POST') {
      const rel = q.get('path') || '';
      if (!rel) return fail(res, 400, 'path query required');
      if (isIgnoredPath(rel)) return json(res, 200, { ok: true, skipped: true, reason: 'ignored pattern', path: rel });
      const buf = await readBody(req);
      const full = safeJoin(root, rel, { allowEmpty: false });
      await fsp.mkdir(path.dirname(full), { recursive: true });
      await fsp.writeFile(full, buf);
      return json(res, 200, { ok: true, path: rel, bytes: buf.length });
    }

    if (sub === 'commit' && method === 'POST') {
      const body = await readJsonBody(req);
      const commit = await withLock(slug, () => commitAll(root, body.message || 'Update from GitDock', slug));
      return json(res, 200, { ok: true, commit });
    }

    if (sub === 'bulk-delete' && method === 'POST') {
      const body = await readJsonBody(req);
      const paths = Array.isArray(body.paths) ? body.paths : [];
      if (!paths.length) return fail(res, 400, 'paths required');
      const out = await withLock(slug, async () => {
        const result = await deletePaths(slug, paths);
        const commit = await commitAll(root, body.message || `Delete ${paths.length} item(s)`, slug);
        return { ...result, commit };
      });
      return json(res, 200, { ok: true, ...out });
    }

    if (sub === 'history' && method === 'GET') {
      return json(res, 200, { ok: true, commits: await history(root, 100) });
    }

    if (sub === 'version' && method === 'GET') {
      const sha = q.get('sha');
      const files = (await git(root, ['ls-tree', '-r', '--name-only', sha])).stdout.split('\n').filter(Boolean);
      const stat = await git(root, ['show', '--stat', '--oneline', '--no-color', sha]);
      return json(res, 200, { ok: true, sha, files, stat: stat.stdout.slice(0, 8000) });
    }

    if (sub === 'restore' && method === 'POST') {
      const body = await readJsonBody(req);
      const sha = String(body.sha || '');
      if (!sha) return fail(res, 400, 'sha required');
      return withLock(slug, async () => {
        const r = await git(root, ['read-tree', '-u', '--reset', sha]);
        if (!r.ok) return fail(res, 400, `restore failed: ${r.stderr.trim().slice(0, 300)}`);
        await git(root, ['reset', '-q']); // keep index consistent with worktree
        const commit = await commitAll(root, body.message || `Restore version ${sha.slice(0, 7)}`, slug);
        return json(res, 200, { ok: true, commit, note: commit.committed ? 'restored as a new commit — history preserved' : 'working tree already matched' });
      }).catch((e) => fail(res, 500, e.message));
    }

    if (sub === 'archive' && method === 'GET') {
      return sendArchive(res, slug, q.get('sha') || 'HEAD', null, q.get('path') || null);
    }
    // a short-lived link that needs no session: how a browser inside a sandboxed iframe still gets a
    // file (copy the link, open it in a tab — nothing can block that)
    if (sub === 'dl-key' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      const kind = body.kind === 'raw' ? 'raw' : 'archive';
      const ref = kind === 'raw' ? String(body.path || '') : String(body.sha || 'HEAD');
      const folder = kind === 'archive' && body.folder ? String(body.folder) : null;
      if (kind === 'raw') {
        try { safeJoin(root, ref, { allowEmpty: false }); } catch (e) { return fail(res, 400, e.message); }
        const st = await fsp.stat(safeJoin(root, ref, { allowEmpty: false })).catch(() => null);
        if (!st || !st.isFile()) return fail(res, 404, 'file not found');
      }
      if (folder) {
        let full;
        try { full = safeJoin(root, folder, { allowEmpty: false }); } catch (e) { return fail(res, 400, e.message); }
        const st = await fsp.stat(full).catch(() => null);
        if (!st || !st.isDirectory()) return fail(res, 404, 'folder not found');
      }
      const minted = mintDownloadKey(slug, { kind, ref, folder, ttlSeconds: body.ttlSeconds });
      return json(res, 200, { ok: true, ...minted });
    }

    // ---- services ---------------------------------------------------------
    if (sub === 'services' && !rest && method === 'GET') {
      return json(res, 200, { ok: true, services: await withLivePorts(slug) });
    }
    if (sub === 'services' && !rest && method === 'POST') {
      const body = await readJsonBody(req);
      const list = await readServices(slug);
      const def = {
        id: slugify(body.id || `svc-${list.length + 1}`),
        runtime: body.runtime || 'static',
        path: body.path || '.',
        docroot: body.docroot || '.',
        port: body.port ? Number(body.port) : null,
        createdAt: nowIso(),
      };
      if (list.some((s) => s.id === def.id)) return fail(res, 409, `service "${def.id}" exists`);
      list.push(def);
      await writeServices(slug, list);
      if (body.start !== false) {
        const info = await startService(slug, def, { previewHost: body.previewHost || null });
        return json(res, 201, { ok: true, service: (await withLivePorts(slug)).find((s) => s.id === def.id), info });
      }
      return json(res, 201, { ok: true, service: def });
    }
    if (sub === 'services' && rest && !rest.includes('/') && method === 'PUT') {
      const id = rest;
      const list = await readServices(slug);
      const def = list.find((s) => s.id === id);
      if (!def) return fail(res, 404, 'service not found');
      const body = await readJsonBody(req);
      const wasRunning = running.has(`${slug}:${id}`);
      if (wasRunning) await stopService(slug, id);          // a changed path needs a fresh process
      if (body.runtime) def.runtime = String(body.runtime);
      if (body.path !== undefined) def.path = String(body.path || '.');
      if (body.docroot !== undefined) def.docroot = String(body.docroot || '.');
      if (body.port !== undefined) def.port = body.port ? Number(body.port) : null;
      if (body.limits && typeof body.limits === 'object') def.limits = body.limits;
      const target = safeJoin(root, def.path || '.');
      if (!fs.existsSync(target)) return fail(res, 400, `service path not found: ${def.path} (nothing at ${target})`);
      await writeServices(slug, list);
      let restarted = null;
      if (wasRunning) restarted = await startService(slug, def, {}).catch((e) => ({ error: e.message }));
      return json(res, 200, {
        ok: true,
        service: (await withLivePorts(slug)).find((s) => s.id === id),
        restarted,
      });
    }
    if (sub === 'services' && rest && method === 'DELETE') {
      const id = rest;
      await stopService(slug, id);
      const list = (await readServices(slug)).filter((s) => s.id !== id);
      await writeServices(slug, list);
      return json(res, 200, { ok: true, removed: id });
    }
    if (sub === 'services' && rest === 'start-all' && method === 'POST') {
      const list = await readServices(slug);
      const started = [];
      for (const def of list) {
        if (running.has(`${slug}:${def.id}`)) continue;
        try { started.push({ id: def.id, ...(await startService(slug, def, {})) }); }
        catch (e) { started.push({ id: def.id, error: e.message }); }
      }
      return json(res, 200, { ok: true, started });
    }
    if (sub === 'services' && rest && method === 'POST' && rest.endsWith('/stop')) {
      const id = rest.replace(/\/stop$/, '');
      const stopped = await stopService(slug, id);
      return json(res, 200, { ok: true, stopped });
    }
    if (sub === 'services' && rest && method === 'POST' && rest.endsWith('/start')) {
      const id = rest.replace(/\/start$/, '');
      const list = await readServices(slug);
      const def = list.find((s) => s.id === id);
      if (!def) return fail(res, 404, 'service not found');
      const body = await readJsonBody(req).catch(() => ({}));
      try {
        const info = await startService(slug, def, { previewHost: body.previewHost || null });
        return json(res, 200, { ok: true, info });
      } catch (e) {
        return fail(res, 500, e.message);
      }
    }
    if (sub === 'services' && rest && rest.endsWith('/logs') && method === 'GET') {
      const id = rest.replace(/\/logs$/, '');
      const r = running.get(`${slug}:${id}`);
      const logPath = (r && r.logPath) || path.join(LOGS, `${slug}-${id}.log`);
      return json(res, 200, { ok: true, log: tailLog(logPath, 30000) });
    }

    // ---- env --------------------------------------------------------------
    if (sub === 'env' && method === 'GET') {
      const content = await readEnv(slug);
      const parsed = parseEnvText(content);
      let exampleKeys = [];
      for (const cand of ['.env.example', '.env.sample', '.env.dist']) {
        const f = safeJoin(root, cand);
        if (fs.existsSync(f)) {
          exampleKeys = Object.keys(parseEnvText(await fsp.readFile(f, 'utf8')));
          break;
        }
      }
      const missing = exampleKeys.filter((k) => !(k in parsed));
      const mask = (v) => (v.length > 6 ? v.slice(0, 3) + '•'.repeat(Math.min(12, v.length - 3)) : '••••');
      return json(res, 200, {
        ok: true,
        content,
        keys: Object.entries(parsed).map(([k, v]) => ({ k, v: /PASSWORD|SECRET|KEY|TOKEN/i.test(k) ? mask(v) : v })),
        missing,
        hasExample: exampleKeys.length > 0,
      });
    }
    if (sub === 'env' && method === 'PUT') {
      const body = await readJsonBody(req);
      await writeEnv(slug, String(body.content ?? ''));
      return json(res, 200, { ok: true });
    }

    // ---- databases --------------------------------------------------------
    if (sub === 'db' && !rest && method === 'GET') return json(res, 200, { ok: true, ...(await dbStatus(slug)) });
    if (sub === 'db' && rest === 'provision' && method === 'POST') {
      try {
        const out = await provisionDb(slug);
        return json(res, 200, { ok: true, ...out, note: 'credentials written to the project .env' });
      } catch (e) {
        return fail(res, 500, e.message);
      }
    }
    if (sub === 'db' && rest === 'import' && method === 'POST') {
      const buf = await readBody(req, 256 * 1024 * 1024);
      try {
        const out = await importSql(slug, buf);
        return json(res, 200, { ok: true, ...out, bytes: buf.length });
      } catch (e) {
        return fail(res, 500, e.message);
      }
    }
    if (sub === 'db' && rest === 'sqlite-upload' && method === 'POST') {
      const rel = q.get('path') || 'database/db.sqlite';
      const buf = await readBody(req, 256 * 1024 * 1024);
      const full = safeJoin(root, rel, { allowEmpty: false });
      await fsp.mkdir(path.dirname(full), { recursive: true });
      await fsp.writeFile(full, buf);
      const tables = await sqliteTables(full);
      return json(res, 200, { ok: true, path: rel, bytes: buf.length, tables });
    }
    if (sub === 'db' && rest === 'sqlite-query' && method === 'GET') {
      const rel = q.get('path');
      const sql = q.get('sql');
      const full = safeJoin(root, rel, { allowEmpty: false });
      if (!fs.existsSync(full)) return fail(res, 404, 'sqlite file not found');
      try {
        const rows = await sqliteQuery(full, sql);
        return json(res, 200, { ok: true, rows });
      } catch (e) {
        return fail(res, 400, e.message);
      }
    }
    if (sub === 'db' && rest === 'mysql-query' && method === 'GET') {
      const cfg = await mysqlConfig();
      if (!cfg) return fail(res, 400, 'no MySQL configured');
      const db = `gitdock_${slug.replace(/-/g, '_')}`;
      const sql = q.get('sql') || '';
      if (!/^\s*(select|show|describe|explain)/i.test(sql)) return fail(res, 400, 'read-only queries only in the POC');
      const r = await mysqlRun(cfg, sql.replace(/\s+/g, ' '), db);
      if (!r.ok) return fail(res, 400, r.stderr.trim().slice(0, 500));
      const lines = r.stdout.split('\n').filter(Boolean);
      return json(res, 200, { ok: true, raw: lines.slice(0, 200) });
    }
    if (sub === 'db' && rest === 'export' && method === 'GET') {
      const cfg = await mysqlConfig();
      if (!cfg) return fail(res, 400, 'no MySQL configured');
      const db = `gitdock_${slug.replace(/-/g, '_')}`;
      const r = await new Promise((resolve) => {
        const child = spawn('mysqldump', ['-h', cfg.host, '-P', String(cfg.port), '-u', cfg.user, `-p${cfg.password}`, '--single-transaction', '--routines', db]);
        const chunks = [];
        const err = [];
        child.stdout.on('data', (d) => chunks.push(d));
        child.stderr.on('data', (d) => err.push(d));
        child.on('error', (e) => resolve({ code: -1, out: Buffer.alloc(0), err: `could not run mysqldump: ${e.message}` }));
        child.on('close', (code) => resolve({ code, out: Buffer.concat(chunks), err: Buffer.concat(err).toString() }));
      });
      if (r.code !== 0) return fail(res, 500, r.err.slice(0, 300));
      res.writeHead(200, {
        'content-type': 'application/sql',
        'content-disposition': `attachment; filename="${db}-${new Date().toISOString().slice(0, 10)}.sql"`,
        'content-length': r.out.length,
      });
      return res.end(r.out);
    }

    // ---- git remote -------------------------------------------------------
    if (sub === 'git' && rest === 'remote' && method === 'POST') {
      const body = await readJsonBody(req);
      const remote = String(body.url || '').trim();
      if (!remote) return fail(res, 400, 'url required');
      // push/pull work with any git URL (GitHub, GitLab, a local path); only PR features need GitHub
      const parsed = parseRepo(remote);
      await git(root, ['remote', 'remove', 'origin']).catch(() => {});
      await gitMust(root, ['remote', 'add', 'origin', remote]);
      const meta = (await readMeta(slug)) || {};
      meta.remote = remote;
      meta.remoteProvider = parsed ? 'github' : 'generic';
      meta.branch = body.branch || meta.branch || 'main';
      await writeMeta(slug, meta);
      return json(res, 200, {
        ok: true, remote, branch: meta.branch, provider: meta.remoteProvider,
        note: parsed ? null : 'this is not a github.com remote — push/pull work, PR features will be unavailable',
      });
    }
    if (sub === 'git' && rest === 'branches' && method === 'GET') {
      return json(res, 200, { ok: true, ...(await branchState(slug)) });
    }

    // create a branch and switch to it — the first half of "commit → open pull request"
    if (sub === 'git' && rest === 'branches' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      const name = String(body.name || '').trim();
      const problem = branchNameProblem(name);
      if (problem) return fail(res, 400, problem);
      return withLock(slug, async () => {
        const root = projectDir(slug);
        const from = String(body.from || '').trim();
        if (from && !(await refExists(root, from))) return fail(res, 404, `'${from}' is not a branch or commit in this project`);
        if (await refExists(root, name)) return fail(res, 409, `the branch '${name}' already exists — pick another name or switch to it`);
        const r = await git(root, from ? ['checkout', '-b', name, from] : ['checkout', '-b', name]);
        if (!r.ok) return fail(res, 409, redact((r.stderr || r.stdout).trim().slice(0, 500)));
        log(`${slug}: branch ${name} created${from ? ` from ${from}` : ''}`);
        return json(res, 200, { ok: true, branch: name, current: name, output: (r.stdout + r.stderr).slice(0, 800) });
      });
    }

    if (sub === 'git' && rest === 'checkout' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      const name = String(body.name || '').trim();
      if (!name) return fail(res, 400, 'which branch?');
      return withLock(slug, async () => {
        const root = projectDir(slug);
        if (!(await refExists(root, name))) return fail(res, 404, `there is no branch called '${name}'`);
        // checking out a remote branch should give you a local branch that tracks it, never a detached HEAD
        const onRemote = /^[^/]+\/.+/.test(name) && (await refExists(root, `refs/remotes/${name}`));
        const local = onRemote ? name.replace(/^[^/]+\//, '') : name;
        const r = onRemote
          ? (await refExists(root, local)
            ? await git(root, ['checkout', local])
            : await git(root, ['checkout', '-b', local, '--track', name]))
          : await git(root, ['checkout', name]);
        if (!r.ok) {
          const err = redact((r.stderr || r.stdout).trim());
          const blocked = /overwritten|local changes/i.test(err);
          return fail(res, blocked ? 409 : 400, blocked
            ? 'uncommitted changes would be overwritten by the switch — commit or discard them first'
            : err.slice(0, 500));
        }
        return json(res, 200, { ok: true, current: local, trackedFrom: onRemote ? name : null });
      });
    }

    if (sub === 'git' && rest === 'branches/delete' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      const name = String(body.name || '').trim();
      const problem = branchNameProblem(name);
      if (problem) return fail(res, 400, problem);
      return withLock(slug, async () => {
        const root = projectDir(slug);
        const cur = await currentBranchOf(root);
        if (cur === name) return fail(res, 409, `you are on '${name}' — switch to another branch first`);
        if (!(await refExists(root, name))) return fail(res, 404, `there is no branch called '${name}'`);
        if (body.remote) {
          const r = await gitRemote(slug, ['push', 'origin', '--delete', name]);
          if (!r.ok) {
            const err = redact((r.stderr || r.stdout).trim());
            const needsAuth = /could not read username|authentication failed|permission denied|terminal prompts disabled/i.test(err);
            return fail(res, needsAuth ? 401 : 409, needsAuth
              ? 'deleting a branch on GitHub needs a token — add one in Settings'
              : err.slice(0, 400));
          }
          return json(res, 200, { ok: true, deletedRemote: name });
        }
        const r = await git(root, ['branch', body.force ? '-D' : '-d', name]);
        if (!r.ok) {
          const err = redact((r.stderr || r.stdout).trim());
          if (/not fully merged/i.test(err)) {
            // not a silent failure: a decision the person has to make, so the reason travels with the refusal
            return fail(res, 409, `'${name}' has commits that exist nowhere else — deleting it would throw them away`, { needsForce: true });
          }
          return fail(res, 400, err.slice(0, 400));
        }
        log(`${slug}: branch ${name} deleted`);
        return json(res, 200, { ok: true, deleted: name });
      });
    }

    // merge a branch into the one you are on; a conflict aborts cleanly so the tree is never left broken
    if (sub === 'git' && rest === 'merge' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      const src = String(body.branch || '').trim();
      if (!src) return fail(res, 400, 'which branch should be merged in?');
      return withLock(slug, async () => {
        const root = projectDir(slug);
        const cur = await currentBranchOf(root);
        if (!cur) return fail(res, 409, 'this project is not on a branch (detached HEAD) — switch to a branch first');
        if (src === cur) return fail(res, 400, `you are already on '${src}'`);
        if (!(await refExists(root, src))) return fail(res, 404, `there is no branch called '${src}' (pull first if it only exists on GitHub)`);
        const message = String(body.message || '').trim() || `Merge branch '${src}' into ${cur}`;
        const r = await git(root, ['merge', body.squash ? '--squash' : '--no-ff', src, '-m', message]);
        if (r.ok) {
          const sha = (await git(root, ['rev-parse', '--short', 'HEAD'])).stdout.trim();
          const output = (r.stdout + r.stderr).slice(0, 2000);
          const upToDate = /already up to date/i.test(output);
          if (!upToDate) log(`${slug}: merged ${src} into ${cur} (${sha})`);
          return json(res, 200, { ok: true, current: cur, merged: src, sha, upToDate, output });
        }
        const status = await git(root, ['status', '--porcelain']);
        const conflicts = status.stdout.split('\n').filter((l) => /^(UU|AA|DD|AU|UA|DU|UD)\s/.test(l)).map((l) => l.slice(3).trim());
        await git(root, ['merge', '--abort']);
        return fail(res, 409, conflicts.length
          ? `'${src}' conflicts with '${cur}' in ${conflicts.length} file(s) — the merge was undone, your branch is exactly as it was`
          : `could not merge '${src}'`, { conflicts, output: redact((r.stderr || r.stdout).trim().slice(0, 1500)) });
      });
    }

    // undo a merge by reverting it: history stays intact, a new commit takes the merge's effect away
    if (sub === 'git' && rest === 'revert-merge' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      const sha = String(body.sha || '').trim();
      if (!/^[0-9a-f]{7,40}$/i.test(sha)) return fail(res, 400, 'a commit sha is required');
      return withLock(slug, async () => {
        const root = projectDir(slug);
        if (!(await refExists(root, sha))) return fail(res, 404, `there is no commit '${sha}' in this project`);
        const parents = await mergeParents(root, sha);
        if (!parents || parents.length < 2) return fail(res, 400, 'that commit is not a merge — use the Versions tab to revert a normal commit');
        const which = body.parent === 2 ? 2 : 1;
        const r = await git(root, ['revert', '-m', String(which), '--no-edit', sha]);
        if (r.ok) {
          const newSha = (await git(root, ['rev-parse', '--short', 'HEAD'])).stdout.trim();
          log(`${slug}: reverted merge ${sha.slice(0, 7)} → ${newSha}`);
          return json(res, 200, { ok: true, sha: newSha, reverted: sha, output: (r.stdout + r.stderr).slice(0, 1500) });
        }
        const status = await git(root, ['status', '--porcelain']);
        const conflicts = status.stdout.split('\n').filter((l) => /^(UU|AA|DD|AU|UA|DU|UD)\s/.test(l)).map((l) => l.slice(3).trim());
        await git(root, ['revert', '--abort']);
        return fail(res, 409, conflicts.length
          ? `undoing that merge conflicts in ${conflicts.length} file(s) — nothing was changed`
          : 'could not revert that merge', { conflicts, output: redact((r.stderr || r.stdout).trim().slice(0, 1500)) });
      });
    }

    // commit on a branch and open the PR in one shot: push the branch, then ask GitHub for the PR
    if (sub === 'git' && rest === 'pr' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      const meta = (await readMeta(slug)) || {};
      const parsed = meta.remote ? parseRepo(meta.remote) : null;
      if (!parsed) return fail(res, 400, 'opening a pull request needs a github.com remote (Settings → GitHub token, GitHub tab → connect)');
      if (!state.githubToken) return fail(res, 400, 'a pull request is created on GitHub, so it needs a token — paste one in Settings first');
      const title = String(body.title || '').trim();
      if (!title) return fail(res, 400, 'a pull request needs a title');
      return withLock(slug, async () => {
        const root = projectDir(slug);
        const head = String(body.head || '').trim() || (await currentBranchOf(root));
        const base = String(body.base || '').trim() || meta.branch || 'main';
        if (!head) return fail(res, 409, 'this project is not on a branch (detached HEAD)');
        if (head === base) return fail(res, 400, `'${head}' is the base branch itself — create a branch and commit there first`);
        if (!(await refExists(root, head))) return fail(res, 404, `there is no branch called '${head}'`);
        const p = await gitRemote(slug, ['push', '-u', 'origin', `${head}:${head}`]);
        if (!p.ok) {
          const err = redact((p.stderr || p.stdout).trim());
          const needsAuth = /could not read username|authentication failed|permission denied|terminal prompts disabled|invalid username or password/i.test(err);
          return fail(res, needsAuth ? 401 : 409, needsAuth
            ? 'GitHub asked for credentials — add a token in Settings (it needs repo scope)'
            : 'the branch could not be pushed, so no pull request was opened', { output: err.slice(0, 1500) });
        }
        try {
          const pr = await ghApi(`/repos/${parsed.owner}/${parsed.repo}/pulls`, {
            method: 'POST',
            body: { title, head, base, body: String(body.body || '') , draft: !!body.draft },
          });
          log(`${slug}: opened PR #${pr.number} (${head} → ${base})`);
          return json(res, 200, { ok: true, number: pr.number, url: pr.html_url, title: pr.title, head, base });
        } catch (e) {
          return fail(res, 409, e.message, { output: `pushed ${head} to origin, but GitHub refused the pull request` });
        }
      });
    }

    if (sub === 'git' && rest === 'pr/merge' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      const meta = (await readMeta(slug)) || {};
      const parsed = meta.remote ? parseRepo(meta.remote) : null;
      if (!parsed) return fail(res, 400, 'merging a pull request needs a github.com remote');
      if (!state.githubToken) return fail(res, 400, 'merging happens on GitHub, so it needs a token — paste one in Settings');
      const number = Number(body.number);
      if (!Number.isInteger(number) || number <= 0) return fail(res, 400, 'which pull request? (number required)');
      const method = ['merge', 'squash', 'rebase'].includes(body.method) ? body.method : 'merge';
      return withLock(slug, async () => {
        let merged;
        try {
          merged = await ghApi(`/repos/${parsed.owner}/${parsed.repo}/pulls/${number}/merge`, { method: 'PUT', body: { merge_method: method } });
        } catch (e) {
          return fail(res, 409, e.message);
        }
        if (!merged || !merged.merged) return fail(res, 409, (merged && merged.message) || 'GitHub refused the merge');
        // bring the local copy up to date, so the repo and GitHub do not drift
        const base = String(body.base || '').trim();
        let pulled = false;
        await gitRemote(slug, ['fetch', 'origin', '--prune']);
        const root = projectDir(slug);
        const cur = await currentBranchOf(root);
        if (base && cur === base) {
          const ff = await git(root, ['merge', '--ff-only', `origin/${base}`]);
          pulled = ff.ok;
        }
        log(`${slug}: PR #${number} merged (${method}) as ${String(merged.sha).slice(0, 7)}`);
        return json(res, 200, { ok: true, sha: merged.sha, message: merged.message, pulled, current: cur || null });
      });
    }

    if (sub === 'git' && rest === 'pr/close' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      const meta = (await readMeta(slug)) || {};
      const parsed = meta.remote ? parseRepo(meta.remote) : null;
      if (!parsed) return fail(res, 400, 'closing a pull request needs a github.com remote');
      if (!state.githubToken) return fail(res, 400, 'closing happens on GitHub, so it needs a token — paste one in Settings');
      const number = Number(body.number);
      if (!Number.isInteger(number) || number <= 0) return fail(res, 400, 'which pull request? (number required)');
      try {
        const pr = await ghApi(`/repos/${parsed.owner}/${parsed.repo}/pulls/${number}`, { method: 'PATCH', body: { state: 'closed' } });
        return json(res, 200, { ok: true, number: pr.number, state: pr.state });
      } catch (e) {
        return fail(res, 409, e.message);
      }
    }

    if (sub === 'git' && rest === 'status' && method === 'GET') {
      const st = await branchState(slug);
      return json(res, 200, { ok: true, ...st });
    }
    // ---- git identity -----------------------------------------------------
    if (sub === 'git' && rest === 'identity' && method === 'GET') {
      const meta = (await readMeta(slug)) || {};
      const effective = await resolveIdentity(slug);
      return json(res, 200, {
        ok: true,
        repo: { name: (await git(root, ['config', 'user.name'])).stdout.trim(), email: (await git(root, ['config', 'user.email'])).stdout.trim() },
        project: { name: meta.gitName || null, email: meta.gitEmail || null },
        effective,
        suggestion: state.githubLogin
          ? { name: state.githubName || state.githubLogin, email: `${state.githubLogin}@users.noreply.github.com` }
          : null,
      });
    }
    if (sub === 'git' && rest === 'identity' && method === 'PUT') {
      const body = await readJsonBody(req);
      const meta = (await readMeta(slug)) || {};
      const name = body.name === null ? null : String(body.name || '').trim();
      const email = body.email === null ? null : String(body.email || '').trim();
      if ((name && !email) || (!name && email)) return fail(res, 400, 'give both a name and an email (or neither, to clear)');
      if (email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return fail(res, 400, `"${email}" does not look like an email address`);
      meta.gitName = name || null;
      meta.gitEmail = email || null;
      await writeMeta(slug, meta);
      const effective = await ensureGitIdentity(root, slug);
      return json(res, 200, { ok: true, effective });
    }

    if (sub === 'git' && rest === 'pull' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      return withLock(slug, async () => {
        const meta = (await readMeta(slug)) || {};
        const branch = body.branch || meta.branch || 'main';
        const f = await gitRemote(slug, ['fetch', 'origin', '--prune']);
        if (!f.ok) {
          const err = redact((f.stderr || f.stdout).trim());
          const needsAuth = /could not read username|authentication failed|permission denied|repository not found|terminal prompts disabled/i.test(err);
          return fail(res, needsAuth ? 401 : 400, needsAuth
            ? 'cannot read that remote — check the URL, and add a GitHub token in Settings for private repos'
            : err.slice(0, 400), { output: err.slice(0, 4000) });
        }
        const ahead = (await git(root, ['rev-list', '--count', `origin/${branch}..HEAD`])).stdout.trim();
        const m = await gitRemote(slug, ['merge', '--no-edit', `origin/${branch}`]);
        if (!m.ok) {
          const err = (m.stdout + m.stderr).trim();
          const unrelated = /unrelated histories/.test(err);
          await git(root, ['merge', '--abort']).catch(() => {});
          return fail(res, 409,
            unrelated
              ? "this branch and the remote branch have no shared history - the local history was made by GitDock. Push first, or start from a clone of the repo instead of an empty project"
              : "merge conflict - GitDock aborted the merge and left your working tree untouched; resolve it in the Files tab, or push your work first",
            { output: err.slice(0, 4000), localCommitsAhead: Number(ahead) || 0 });
        }
        return json(res, 200, {
          ok: true,
          output: redact((m.stdout + m.stderr).slice(0, 4000)),
          commits: await history(root, 5),
        });
      });
    }
    if (sub === 'git' && rest === 'push' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      return withLock(slug, async () => {
        const meta = (await readMeta(slug)) || {};
        const branch = body.branch || meta.branch || 'main';
        const p = await gitRemote(slug, ['push', '-u', 'origin', `${branch}:${branch}`]);
        if (p.ok) return json(res, 200, { ok: true, output: redact((p.stdout + p.stderr).slice(0, 4000)) });
        const err = redact((p.stderr || p.stdout).trim());
        // a remote that needs credentials is the only case where a token is the answer
        const needsAuth = /could not read username|authentication failed|permission denied|terminal prompts disabled|invalid username or password|403/i.test(err);
        return fail(res, needsAuth ? 401 : 409, needsAuth
          ? 'the remote asked for credentials — add a GitHub token in Settings (or use a remote URL that already contains a token)'
          : 'push rejected — if the remote moved, pull first; GitDock never force-pushes',
          { output: err.slice(0, 4000) });
      });
    }
    if (sub === 'git' && rest === 'prs' && method === 'GET') {
      const meta = (await readMeta(slug)) || {};
      const parsed = meta.remote ? parseRepo(meta.remote) : null;
      if (!parsed) return fail(res, 400, 'the pull-request features need a github.com remote (push/pull work with any git URL)');
      if (!state.githubToken) return fail(res, 400, 'set a GitHub token in Settings first');
      try {
        const prs = await ghApi(`/repos/${parsed.owner}/${parsed.repo}/pulls?state=all&per_page=30&sort=updated`);
        return json(res, 200, {
          ok: true,
          repo: `${parsed.owner}/${parsed.repo}`,
          prs: prs.map((x) => ({
            number: x.number, title: x.title, state: x.state, draft: x.draft,
            user: x.user && x.user.login, head: x.head.ref, base: x.base.ref,
            updated: x.updated_at, url: x.html_url, mergeable: x.mergeable ?? null,
            mergeCommit: x.merge_commit_sha || null, mergedAt: x.merged_at || null, mergedBy: (x.merged_by && x.merged_by.login) || null,
            additions: x.additions ?? null, deletions: x.deletions ?? null,
            changedFiles: x.changed_files ?? null,
          })),
        });
      } catch (e) {
        return fail(res, 400, e.message);
      }
    }

    // ---- setup: manifest + detection --------------------------------------
    if (sub === 'setup' && !rest && method === 'GET') {
      return json(res, 200, { ok: true, ...(await setupView(slug)) });
    }
    if (sub === 'setup' && rest === 'detect' && method === 'GET') {
      return json(res, 200, { ok: true, detected: await detectProject(root) });
    }
    if (sub === 'setup' && rest === 'manifest' && method === 'PUT') {
      const body = await readJsonBody(req);
      const file = path.join(root, 'gitdock.yaml');
      await fsp.writeFile(file, String(body.text ?? ''), 'utf8');
      const commit = await withLock(slug, () => commitAll(root, body.message || 'Update gitdock.yaml', slug));
      const manifest = await readManifest(root);
      return json(res, 200, { ok: true, commit, manifest: { file: manifest.file, ok: manifest.ok, errors: manifest.errors } });
    }
    if (sub === 'setup' && rest === 'apply' && method === 'POST') {
      const body = await readJsonBody(req).catch(() => ({}));
      try {
        const report = await applyPlan(slug, { runInstall: !!body.runInstall, start: !!body.start, source: body.source || 'manifest' });
        return json(res, 200, { ok: true, report });
      } catch (e) {
        return fail(res, 400, e.message);
      }
    }

    // ---- previews: branch / PR sandboxes ----------------------------------
    if (sub === 'previews' && !rest && method === 'GET') {
      const all = await readPreviews();
      const mine = all.filter((p) => p.slug === slug).map((p) => ({
        ...p,
        services: (p.services || []).map((s) => ({ ...s, running: !!running.get(previewKey(p.id, s.id)) })),
      }));
      return json(res, 200, { ok: true, previews: mine });
    }
    if (sub === 'previews' && !rest && method === 'POST') {
      const body = await readJsonBody(req);
      try {
        const rec = await createPreview(slug, {
          ref: body.ref, kind: body.kind || 'branch', number: body.number || null,
          ttlHours: body.ttlHours || null, install: !!body.install, start: true,
        });
        return json(res, 201, { ok: true, preview: rec });
      } catch (e) {
        return fail(res, 400, e.message);
      }
    }
    if (sub === 'previews' && rest && method === 'DELETE') {
      const rec = await teardownPreview(rest, 'manual');
      if (!rec) return fail(res, 404, 'preview not found');
      return json(res, 200, { ok: true, tornDown: rest });
    }
    if (sub === 'branches' && method === 'GET') {
      const r = await git(root, ['branch', '-a', '--format=%(refname:short)']);
      const local = r.stdout.split('\n').filter(Boolean);
      return json(res, 200, { ok: true, branches: local });
    }
    if (sub === 'git' && rest === 'preview-pr' && method === 'POST') {
      // trust git's own config, not our metadata: whatever "origin" is, we can fetch a PR ref from it
      const remoteUrl = (await git(root, ['remote', 'get-url', 'origin'])).stdout.trim();
      if (!remoteUrl) return fail(res, 400, 'connect a Git remote first');
      const body = await readJsonBody(req);
      const n = Number(body.number);
      if (!n) return fail(res, 400, 'PR number required');
      const refName = `refs/gitdock/pr-${n}`;
      const f = await gitRemote(slug, ['fetch', 'origin', `refs/pull/${n}/head:${refName}`, '--force']);
      if (!f.ok) {
        const err = (f.stderr || '').trim().slice(0, 400);
        return fail(res, 400, /auth|could not read|denied|terminal prompts/i.test(err)
          ? `could not fetch the PR ref (${err}) — if the repo is private, set a GitHub token in Settings`
          : err);
      }
      try {
        const rec = await createPreview(slug, { ref: refName, kind: 'pr', number: n, install: !!body.install, start: true });
        return json(res, 201, { ok: true, preview: rec });
      } catch (e) {
        return fail(res, 400, e.message);
      }
    }

    if (sub === 'jobs' && method === 'GET') {
      return json(res, 200, { ok: true, jobs: [...jobs.values()].map(jobView).reverse() });
    }
    if (sub === 'job' && method === 'GET') {
      const job = jobs.get(q.get('id'));
      if (!job) return fail(res, 404, 'job not found');
      return json(res, 200, { ok: true, job: jobView(job) });
    }

    return fail(res, 404, `no route ${method} ${p}`);
  }

  return fail(res, 404, `no route ${method} ${p}`);
}

async function deletePaths(slug, paths) {
  const root = projectDir(slug);
  const deleted = [];
  for (const rel of paths) {
    const full = safeJoin(root, rel, { allowEmpty: false });
    if (full === path.resolve(root)) throw new Error('refusing to delete project root');
    if (!fs.existsSync(full)) continue;
    await fsp.rm(full, { recursive: true, force: true });
    deleted.push(rel);
  }
  return { deleted };
}

async function buildTree(root) {
  async function walk(dir, relBase, depth) {
    if (depth > 12) return [];
    let entries = [];
    try {
      entries = await fsp.readdir(dir, { withFileTypes: true });
    } catch {
      return [];
    }
    const out = [];
    for (const e of entries) {
      if (IGNORED_DIRS.has(e.name)) continue;
      if (e.name === '.gitdock') continue;
      const rel = relBase ? `${relBase}/${e.name}` : e.name;
      const full = path.join(dir, e.name);
      if (e.isDirectory()) {
        out.push({ name: e.name, path: rel, type: 'dir', children: await walk(full, rel, depth + 1) });
      } else if (e.isFile()) {
        const st = await fsp.stat(full).catch(() => null);
        out.push({
          name: e.name, path: rel, type: 'file',
          size: st ? st.size : 0, humanSize: st ? humanBytes(st.size) : '?',
          secret: isSecretPath(rel),
        });
      }
    }
    out.sort((a, b) => (a.type === b.type ? a.name.localeCompare(b.name) : a.type === 'dir' ? -1 : 1));
    return out;
  }
  return walk(root, '', 0);
}

async function withLivePorts(slug) {
  const list = await readServices(slug);
  return list.map((s) => {
    const r = running.get(`${slug}:${s.id}`);
    return {
      ...s,
      running: !!r,
      port: r ? r.port : s.port,
      startedAt: r ? r.startedAt : null,
      url: r ? `/api/projects/${slug}/services/${s.id}/proxy/` : null,
    };
  });
}

async function runtimeReport() {
  const [php, sqlite, mysqlOk, gitv, nodev] = await Promise.all([
    exec('php', ['-v']),
    exec('sqlite3', ['--version']),
    mysqlAvailable(),
    exec('git', ['--version']),
    exec('node', ['-v']),
  ]);
  const line = (r) => (r.stdout || r.stderr || '').split('\n')[0].trim();
  return {
    php: php.ok ? line(php) : 'not installed',
    sqlite: sqlite.ok ? line(sqlite) : 'not installed',
    git: line(gitv),
    node: line(nodev),
    mysql: mysqlOk ? 'connected' : 'unavailable',
  };
}

// ---------------------------------------------------------------------------
// background: laravel create-project
// ---------------------------------------------------------------------------

async function runCloneJob(slug, url, branch, job) {
  const dir = projectDir(slug);
  const askpass = ensureAskpass().file;

  /** clone once; returns git's exit code */
  const attempt = async (branchName) => {
    const args = ['-c', `core.askpass=${askpass}`, 'clone', '--progress'];
    if (branchName) args.push('--branch', branchName);
    args.push(url, '.');
    job.write(`$ git ${args.slice(2).join(' ')}\n`);
    return new Promise((resolve) => {
      const child = spawn('git', args, {
        cwd: dir,
        env: { ...gitEnv(), GITDOCK_TOKEN: state.githubToken || '', GITDOCK_LOGIN: state.githubLogin || 'x-access-token' },
      });
      job.child = child;
      child.on('error', (e) => job.write(`[gitdock] spawn error: ${e.message}\n`));
      child.stdout.on('data', (d) => job.write(d));
      child.stderr.on('data', (d) => job.write(d));
      child.on('close', resolve);
    });
  };

  let code = await attempt(branch);
  let branchNote = '';

  // The branch field is optional for a reason: if it was typed by hand it is easy to get wrong
  // (main vs master vs dev). Retry on the repo's default branch rather than failing the whole thing.
  if (code !== 0 && branch) {
    const why = lastErrorLine(job.logPath);
    if (/Remote branch .* not found|couldn't find remote ref|not our ref|unknown revision/i.test(why)) {
      job.write(`[gitdock] branch "${branch}" does not exist in this repo — retrying on its default branch\n`);
      await fsp.rm(path.join(dir, '.git'), { recursive: true, force: true });
      code = await attempt(null);
      if (code === 0) branchNote = ` (branch "${branch}" does not exist — used the repo's default branch)`;
    }
  }
  job.child = null;
  if (jobAborted(job, dir)) {
    await fsp.rm(dir, { recursive: true, force: true });
    await fsp.rm(pendingMetaFile(slug), { force: true });
    return;
  }
  if (code !== 0) {
    // say what git actually said — a wrong guess ("add a token") sends people down the wrong path
    const why = lastErrorLine(job.logPath);
    const hint = /terminal prompts disabled|Permission denied.*askpass/i.test(why)
      ? ' — the credential helper could not run or no token is set'
      : /Write access to repository not granted|403/i.test(why)
        ? ` — GitHub accepted the token (${state.githubLogin || 'unknown account'}) but not for this repository.`
          + ' A fine-grained token only reaches the repositories you ticked: open the token on GitHub, add this'
          + ' repository under "Selected repositories" with Contents: read (and write, if you push), or use a'
          + ' classic token with the "repo" scope. Then paste the new token in Settings → GitHub.'
        : /Repository not found|not found/i.test(why)
          ? ' — check the URL, and that your token lists this repository (fine-grained tokens need it selected)'
          : '';
    await fsp.rm(dir, { recursive: true, force: true });
    await fsp.rm(pendingMetaFile(slug), { force: true });
    return finishJob(job, 'failed', `git clone failed: ${why}${hint}`);
  }

  // a clone arrives with the repo's own history but no local identity
  await excludeGitdockMeta(dir);
  const identity = await ensureGitIdentity(dir, slug);
  const det = await detectProject(dir);
  if (jobAborted(job, dir)) return;
  const meta = { ...(await readMeta(slug)) || {}, runtime: det.runtime, docroot: det.docroot, clonedAt: nowIso(), gitName: identity.name, gitEmail: identity.email };
  await writeMeta(slug, meta);
  await fsp.rm(pendingMetaFile(slug), { force: true });
  if (jobAborted(job, dir)) return;
  const services = await writeDetectedServices(slug);
  if (jobAborted(job)) return;
  finishJob(job, 'done',
    `cloned → detected ${det.runtime}${det.docroot !== '.' ? ' (docroot ' + det.docroot + ')' : ''}, ` +
    `${services.length} service(s) ready in the Run bar. Open Setup to apply databases and env.${branchNote}`);
}

/** write service definitions inferred from detection (no DB provisioning, no auto-start) */
async function writeDetectedServices(slug) {
  const det = await detectProject(projectDir(slug));
  const list = await readServices(slug);
  for (const svc of det.services) {
    if (svc.runtime === 'worker' || svc.runtime === 'cron') continue;
    if (list.some((x) => x.id === svc.id)) continue;
    list.push({
      id: svc.id,
      runtime: svc.runtime === 'laravel' ? 'php' : svc.runtime,
      path: svc.path || '.',
      docroot: svc.docroot || '.',
      port: null,
      public: svc.public !== false,
      createdAt: nowIso(),
    });
  }
  await writeServices(slug, list);
  return list;
}

async function runLaravelCreate(slug, job) {
  const dir = projectDir(slug);
  job.write(`composer create-project laravel/laravel . — in ${dir}\n`);
  const child = spawn('composer', ['create-project', '--no-interaction', '--prefer-dist', '--no-progress', 'laravel/laravel', '.'], {
    cwd: dir,
    env: { ...process.env, COMPOSER_MEMORY_LIMIT: '-1', COMPOSER_ALLOW_SUPERUSER: '1' },
  });
  job.child = child;
  child.stdout.pipe(job.stream, { end: false });
  child.stderr.pipe(job.stream, { end: false });
  const code = await new Promise((r) => {
    child.on('error', (e) => { job.write(`[gitdock] could not run composer: ${e.message} — is composer installed?\n`); r(-1); });
    child.on('close', r);
  });
  job.child = null;
  if (jobAborted(job, dir)) {
    await fsp.rm(dir, { recursive: true, force: true });
    await fsp.rm(pendingMetaFile(slug), { force: true });
    return;
  }
  if (code !== 0) return finishJob(job, 'failed', `composer exited ${code}: ${lastErrorLine(job.logPath)}`);

  const meta = { name: slug, runtime: 'laravel', createdAt: nowIso(), template: 'laravel', branch: 'main', remote: null };
  await writeMeta(slug, Object.assign(meta, await readMeta(slug) || {}));
  await fsp.rm(pendingMetaFile(slug), { force: true });
  await gitInit(dir, slug);
  await writeServices(slug, [{ id: 'website', runtime: 'php', path: '.', docroot: 'public', port: null, createdAt: nowIso() }]);
  finishJob(job, 'done', 'Laravel installed — start the "website" service from the Run bar');
}

// ---------------------------------------------------------------------------
// server
// ---------------------------------------------------------------------------

const MIME = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.gif': 'image/gif', '.webp': 'image/webp', '.ico': 'image/x-icon', '.woff2': 'font/woff2',
  '.map': 'application/json', '.txt': 'text/plain; charset=utf-8', '.md': 'text/plain; charset=utf-8',
};

async function serveStatic(req, res, url) {
  let rel = decodeURIComponent(url.pathname);
  if (rel === '/' || rel === '') rel = '/index.html';
  if (rel === '/login' || rel === '/login/') rel = '/login.html';
  const full = path.join(PUBLIC, rel);
  if (!full.startsWith(PUBLIC)) return fail(res, 403, 'forbidden');
  try {
    const st = await fsp.stat(full);
    if (!st.isFile()) throw new Error('not a file');
    const type = MIME[path.extname(full).toLowerCase()] || 'application/octet-stream';
    // ETag from size+mtime: the panel is one file today, and this kills the reload-on-every-poll cost
    const etag = `W/"${st.size.toString(16)}-${Math.floor(st.mtimeMs).toString(16)}"`;
    const inm = String(req.headers['if-none-match'] || '');
    if (inm && inm.split(',').map((x) => x.trim()).includes(etag)) {
      res.writeHead(304, { etag, 'cache-control': 'no-cache' });
      return res.end();
    }
    res.writeHead(200, { 'content-type': type, 'cache-control': 'no-cache', etag });
    fs.createReadStream(full).pipe(res);
  } catch {
    return fail(res, 404, 'not found');
  }
}

/**
 * The panel is often embedded in a sandboxed iframe on another origin (the Arena preview). Such a
 * frame has an opaque origin, which turns every call to this API into a cross-origin request — and a
 * cross-origin request without CORS headers fails before it ever reaches the session check. Two rules:
 *   - preflight (OPTIONS) answers without a session: a preflight cannot carry the token header
 *   - credentials are the *token header*, never a cookie, so a wildcard origin here does not let a
 *     random page act as a signed-in user; without the token the answer is still 401
 */
function applyCors(req, res) {
  const origin = req.headers.origin;
  const wantsHeader = !!req.headers['x-gitdock-session'];
  if (!origin) return false;
  if (origin === 'null' || wantsHeader || req.method === 'OPTIONS') {
    res.setHeader('access-control-allow-origin', '*');
    res.setHeader('access-control-allow-methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    res.setHeader('access-control-allow-headers', 'content-type, x-gitdock-session');
    res.setHeader('access-control-max-age', '600');
    res.setHeader('vary', 'origin');
    return true;
  }
  return false;
}

function clientIp(req) {
  return String(req.headers['x-forwarded-for'] || req.socket.remoteAddress || '?').split(',')[0].trim();
}

/** paths anyone may reach: the login page, the sign-in endpoint, a health ping */
const PUBLIC_PATHS = new Set([
  '/login', '/login/', '/login.html',
  '/api/auth/login', '/api/auth/me', '/api/health', '/api/auth/logout',
  // The panel *shell* (html/css/js) is public on purpose: a request that carries the session in the
  // URL query — the preview-iframe case, where cookies are blocked and a fragment never reaches the
  // server — must be able to load the app, which then sends the token as a header. Every byte of
  // data behind it (projects, files, env, git) still needs a session: the API gate below is strict.
  '/', '/index.html',
]);

/**
 * The gate. Everything except PUBLIC_PATHS needs a session — both the API and the panel itself,
 * so an unauthenticated visitor cannot even read the UI source.
 */
function gate(req, res, url) {
  let who = auth.whoami(req);
  // a session handed over in the URL (?t=…) — used once, right after signing in inside an iframe
  const urlToken = url.searchParams.get('t');
  if (who.mode === 'locked' && urlToken) {
    const opened = auth.open(urlToken);
    if (opened) {
      who = { mode: 'ok', user: opened };
      const secure = String(req.headers['x-forwarded-proto'] || '').includes('https');
      res.setHeader('set-cookie', auth.cookieHeader(urlToken, { secure }));
    }
  }
  // short-lived download links carry their own permission — no session needed (the key is 128 bits,
  // scoped to one project + ref, and expires in minutes)
  if (url.pathname.startsWith('/api/dl/')) return { mode: 'open', user: null };
  if (who.mode === 'open' || PUBLIC_PATHS.has(url.pathname.replace(/\/+$/, '') || '/')) return who;
  if (who.mode === 'ok') return who;
  if (url.pathname.startsWith('/api/')) {
    // a person clicking a link gets a page; a fetch() gets JSON to act on
    if (String(req.headers.accept || '').includes('text/html')) {
      failHtml(res, 401, 'This link needs a session. The panel asks for one before anything runs.', {
        signIn: true, next: url.pathname + (url.search || ''),
      });
      return null;
    }
    fail(res, 401, 'sign in required', { code: 'unauth' });
    return null;
  }
  // remember where they wanted to go, so signing in lands there
  const next = url.pathname + (url.search || '');
  res.writeHead(302, { location: `/login?next=${encodeURIComponent(next)}`, 'cache-control': 'no-store' });
  res.end();
  return null;
}

// Anything that slips through must not take the panel down silently — with no process, every page in
// the browser just stops answering and there is nothing to read. Log it loudly and keep serving.
process.on('uncaughtException', (e) => {
  log(`UNCAUGHT EXCEPTION — the panel is still running, fix this: ${e && e.stack ? e.stack.split('\n').slice(0, 4).join(' | ') : e}`);
});
process.on('unhandledRejection', (e) => {
  log(`unhandled rejection (ignored): ${e && e.message ? e.message : e}`);
});

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  try {
    if (url.pathname.startsWith('/api/')) {
      const isApi = true;
      applyCors(req, res);
      // preflight: never require a session (the browser sends it without the token header)
      if (req.method === 'OPTIONS') {
        res.writeHead(204, { 'content-length': 0 });
        return res.end();          // writeHead alone leaves the preflight hanging until the browser gives up
      }
      if (!gate(req, res, url)) return;
      if (isApi) return await api(req, res, url);
    }
    if (!gate(req, res, url)) return;
    return await serveStatic(req, res, url);
  } catch (e) {
    console.error('request error', url.pathname, e);
    if (!res.headersSent) return fail(res, 500, e.message || 'internal error');
    res.end();
  }
});

(async () => {
  await ensureDirs();
  await loadSettings();
  // access control: lock by default. First boot mints an owner and prints the password once.
  const authState = auth.ensureStore();
  if (authState.created) {
    log('no accounts existed — created the first owner ("admin"), password printed above');
    log('change it in the panel: Settings → Access');
  } else if (process.env.GITDOCK_AUTH === 'off') {
    log('WARNING: GITDOCK_AUTH=off — the panel is open to anyone who can reach this URL');
  }
  // Sandbox restores do not preserve file modes: re-tighten secrets AND restore the executable bit on
  // the git credential helper (that one silently breaks every private clone/push).
  ensureAskpass();
  const tightened = await enforceSecretModes();
  if (tightened.length) log(`tightened permissions on ${tightened.length} secret file(s): ${tightened.join(', ')}`);
  const swept = await restoreSecretsSweep();
  const orphans = await reclaimOrphans();
  if (orphans.length) log(`reclaimed ${orphans.length} orphaned service process(es) from a previous run`);
  // mark previously-running services as stopped, list them on boot
  let restored = 0;
  for (const e of await fsp.readdir(PROJECTS, { withFileTypes: true }).catch(() => [])) {
    if (!e.isDirectory()) continue;
    restored += (await readServices(e.name)).length;
  }
  server.listen(PORT, BIND, () => {
    log(`GitDock POC listening on http://${BIND}:${PORT}`);
    log(`data dir: ${DATA}`);
    log(`known service definitions: ${restored} (none auto-started — use the Run bar)`);
  });

  startPreviewSweeper();

  // don't leave orphaned project servers behind when the panel goes down
  const shutdown = async (sig) => {
    const keys = [...running.keys()];
    log(`${sig} — stopping ${keys.length} service(s)`);
    for (const key of keys) {
      try {
        await stopServiceByKey(key);
        log(`  stopped ${key}`);
      } catch (e) { log(`  could not stop ${key}: ${e.message}`); }
    }
    process.exit(0);
  };
  process.on('SIGTERM', () => { shutdown('SIGTERM'); });
  process.on('SIGINT', () => { shutdown('SIGINT'); });
})();
