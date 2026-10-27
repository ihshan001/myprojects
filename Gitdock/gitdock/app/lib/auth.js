/**
 * GitDock access control — the allowlist for you and your team.
 *
 * Design notes (why it looks like this):
 *  - Accounts live in data/auth.json: name + scrypt hash + role. No signup, no email, no strangers.
 *    The panel is already reachable from the internet through the sandbox preview URL, so it is
 *    locked by default: the first boot mints an owner account and prints the password once.
 *  - Two ways to carry a session, because the panel runs in two very different places:
 *      1. HTTP cookie  — normal browsing of the panel URL directly (SameSite=Lax, HttpOnly).
 *      2. X-GitDock-Session header — for the sandbox preview iframe, where third-party cookies are
 *         blocked and localStorage may be unavailable (opaque origin). The token is handed to the
 *         page at login and kept in the URL fragment (never sent to the server as a URL) or in
 *         localStorage when the browser allows it. It is a bearer token: keep the URL private.
 *  - Passwords: scrypt with a per-user salt, compared in constant time. Unknown users still pay the
 *    hashing cost, so response timing does not reveal which names exist.
 *  - Login throttling: 6 failures per 15 minutes per (ip, name) pair → 429.
 *  - "Sign out everywhere" rotates the session secret, which invalidates every issued token.
 */

'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const APP_ROOT = path.join(__dirname, '..');
const DATA = process.env.GITDOCK_DATA || path.join(APP_ROOT, '..', 'data');
const FILE = path.join(DATA, 'auth.json');
const COOKIE = 'gitdock_sid';
const HEADER = 'x-gitdock-session';
const SCRYPT = { N: 16384, r: 8, p: 1, keylen: 64 };
const DEFAULT_TTL_HOURS = 24 * 30;

// ---------------------------------------------------------------------------
// store
// ---------------------------------------------------------------------------

let cache = null;
let cacheStamp = 0;

function readStore() {
  let st;
  try {
    st = fs.statSync(FILE);
  } catch {
    return null;
  }
  if (cache && st.mtimeMs === cacheStamp) return cache;
  try {
    cache = JSON.parse(fs.readFileSync(FILE, 'utf8'));
    cacheStamp = st.mtimeMs;
    return cache;
  } catch {
    return null;
  }
}

function writeStore(store) {
  fs.mkdirSync(DATA, { recursive: true });
  fs.writeFileSync(FILE, JSON.stringify(store, null, 2), { mode: 0o600 });
  try { fs.chmodSync(FILE, 0o600); } catch {}     // umask and snapshot restores both bite here
  cache = store;
  cacheStamp = fs.statSync(FILE).mtimeMs;
  return store;
}

/** the store must not be readable by anyone else — enforce it, don't assume it */
function enforceMode() {
  try {
    if ((fs.statSync(FILE).mode & 0o777) !== 0o600) fs.chmodSync(FILE, 0o600);
  } catch {}
}

const slugName = (name) =>
  String(name || '').trim().toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'user';

function hashPassword(password, salt = crypto.randomBytes(16).toString('hex')) {
  const hash = crypto.scryptSync(String(password), salt, SCRYPT.keylen, SCRYPT).toString('hex');
  return { salt, hash };
}

function verifyPassword(password, user) {
  if (!user || !user.salt || !user.hash) return false;
  let cand;
  try {
    cand = crypto.scryptSync(String(password), user.salt, SCRYPT.keylen, SCRYPT);
  } catch {
    return false;
  }
  const known = Buffer.from(user.hash, 'hex');
  return cand.length === known.length && crypto.timingSafeEqual(cand, known);
}

/** constant-ish cost for names that do not exist, so timing does not leak who is registered */
function burnHash(password) {
  try {
    crypto.scryptSync(String(password || ''), '0000000000000000', SCRYPT.keylen, SCRYPT);
  } catch {}
}

function generatePassword() {
  // readable, still ~62 bits of entropy: 4 groups of 5 from an unambiguous alphabet
  const alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
  const pick = () => Array.from({ length: 5 }, () => alphabet[crypto.randomInt(alphabet.length)]).join('');
  return `${pick()}-${pick()}-${pick()}-${pick()}`;
}

function ensureStore({ quiet = false } = {}) {
  const existing = readStore();
  if (existing) {
    enforceMode();
    return { created: false, store: existing };
  }
  const password = generatePassword();
  const user = { name: 'admin', role: 'owner', ...hashPassword(password), createdAt: new Date().toISOString(), lastLoginAt: null };
  const store = {
    version: 1,
    enabled: true,
    sessionSecret: crypto.randomBytes(32).toString('hex'),
    ttlHours: DEFAULT_TTL_HOURS,
    createdAt: new Date().toISOString(),
    users: [user],
  };
  writeStore(store);
  if (!quiet) {
    console.log('\n  ┌─ GitDock is locked. First account created ─────────────────────');
    console.log(`  │  user:     admin`);
    console.log(`  │  password: ${password}`);
    console.log('  │  change it in the panel: Settings → Access → your password');
    console.log('  └──────────────────────────────────────────────────────────────────\n');
  }
  return { created: true, password, store };
}

const listUsers = () =>
  (readStore() || { users: [] }).users.map((u) => ({ name: u.name, role: u.role, createdAt: u.createdAt, lastLoginAt: u.lastLoginAt || null }));

function findUser(name) {
  const store = readStore();
  if (!store) return null;
  const wanted = String(name || '').trim().toLowerCase();
  return store.users.find((u) => u.name.toLowerCase() === wanted || u.name.toLowerCase() === slugName(wanted)) || null;
}

function addUser({ name, password = null, role = 'member' } = {}) {
  const store = readStore() || ensureStore({ quiet: true }).store;
  const clean = slugName(name);
  if (!clean) throw new Error('name required');
  if (findUser(clean)) throw new Error(`user "${clean}" already exists`);
  const pass = password && String(password).length >= 8 ? String(password) : generatePassword();
  const generated = !(password && String(password).length >= 8);
  const user = { name: clean, role: role === 'owner' ? 'owner' : 'member', ...hashPassword(pass), createdAt: new Date().toISOString(), lastLoginAt: null };
  store.users.push(user);
  writeStore(store);
  return { name: user.name, role: user.role, password: generated ? pass : null };
}

function removeUser(name) {
  const store = readStore();
  if (!store) throw new Error('no auth store');
  const target = findUser(name);
  if (!target) throw new Error(`user "${name}" not found`);
  if (target.role === 'owner' && store.users.filter((u) => u.role === 'owner').length === 1) {
    throw new Error('this is the last owner — add another owner before removing it');
  }
  store.users = store.users.filter((u) => u.name !== target.name);
  writeStore(store);
  return { name: target.name };
}

function setPassword(name, password) {
  const store = readStore();
  if (!store) throw new Error('no auth store');
  const user = findUser(name);
  if (!user) throw new Error(`user "${name}" not found`);
  if (!password || String(password).length < 8) throw new Error('password must be at least 8 characters');
  Object.assign(user, hashPassword(String(password)));
  writeStore(store);
  return { name: user.name };
}

function rotateSecret() {
  const store = readStore();
  if (!store) throw new Error('no auth store');
  store.sessionSecret = crypto.randomBytes(32).toString('hex');
  writeStore(store);
  return { rotatedAt: new Date().toISOString() };
}

function setEnabled(enabled) {
  const store = readStore() || ensureStore({ quiet: true }).store;
  store.enabled = !!enabled;
  writeStore(store);
  return { enabled: store.enabled };
}

// ---------------------------------------------------------------------------
// sessions
// ---------------------------------------------------------------------------

const b64 = (buf) => Buffer.from(buf).toString('base64url');

/**
 * Tokens that were signed out on this device.
 *
 * A session here is a signed statement, not a row in a table, so there is nothing to delete when
 * somebody presses "Sign out" — the token would stay valid until it expires on its own. This set is
 * the missing half: the middleware rejects a token the moment it is revoked. It is keyed by the
 * token's signature and forgotten once the token's own expiry passes, so it cannot grow for ever.
 * (Signing out *every* device is a different, stronger move: rotateSecret() invalidates all of them.)
 */
const revoked = new Map();

function pruneRevoked() {
  const now = Date.now();
  for (const [mac, exp] of revoked) if (exp <= now) revoked.delete(mac);
}

/** read a token's own expiry without trusting anything else in it */
function tokenExpiry(token) {
  try {
    const payload = JSON.parse(Buffer.from(String(token).split('.')[0], 'base64url').toString('utf8'));
    if (payload && payload.exp) return Number(payload.exp);
  } catch {}
  return Date.now() + DEFAULT_TTL_HOURS * 3600 * 1000;
}

/** stop accepting this exact token from now on */
function revoke(token) {
  if (typeof token !== 'string' || !token.includes('.')) return false;
  pruneRevoked();
  revoked.set(token.split('.')[1], tokenExpiry(token));
  return true;
}

const revokedCount = () => { pruneRevoked(); return revoked.size; };

function sign(payload, secret) {
  const body = b64(JSON.stringify(payload));
  const mac = crypto.createHmac('sha256', secret).update(body).digest('base64url');
  return `${body}.${mac}`;
}

function open(token) {
  const store = readStore();
  if (!store || !store.sessionSecret) return null;
  if (typeof token !== 'string' || !token.includes('.')) return null;
  const [body, mac] = token.split('.');
  pruneRevoked();
  if (revoked.has(mac)) return null;                    // signed out on this device
  const expect = crypto.createHmac('sha256', store.sessionSecret).update(body).digest('base64url');
  const a = Buffer.from(String(mac));
  const b = Buffer.from(expect);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
  let payload;
  try {
    payload = JSON.parse(Buffer.from(body, 'base64url').toString('utf8'));
  } catch {
    return null;
  }
  if (!payload || !payload.u) return null;
  if (payload.exp && Date.now() > payload.exp) return null;
  const user = findUser(payload.u);
  if (!user) return null;
  return { name: user.name, role: user.role, exp: payload.exp };
}

function issue(user) {
  const store = readStore();
  const ttlHours = Number(store.ttlHours || DEFAULT_TTL_HOURS);
  return sign({ u: user.name, r: user.role, iat: Date.now(), exp: Date.now() + ttlHours * 3600 * 1000 }, store.sessionSecret);
}

function login(name, password) {
  const store = readStore();
  if (!store) return null;
  const user = findUser(name);
  if (!user) {
    burnHash(password);
    return null;
  }
  if (!verifyPassword(password, user)) return null;
  user.lastLoginAt = new Date().toISOString();
  writeStore(store);
  const token = issue(user);
  return { user: { name: user.name, role: user.role }, token };
}

function parseCookies(header) {
  const out = {};
  for (const part of String(header || '').split(';')) {
    const i = part.indexOf('=');
    if (i < 0) continue;
    out[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}

/** the session carried by this request, from the cookie or the header (iframe mode) */
function fromRequest(req) {
  const cookies = parseCookies(req.headers.cookie);
  return open(cookies[COOKIE]) || open(req.headers[HEADER]);
}

/**
 * Every raw token this request presented, unverified — only used to revoke them.
 *
 * A browser sends the cookie *and* the header when both exist, and they are not always the same
 * session (signing in again with ?t=… while an older cookie is still around gives you exactly
 * that). Signing out must end the session that was used, whichever way it arrived, so this returns
 * both and the logout route kills both.
 */
function rawTokens(req) {
  const cookies = parseCookies(req.headers.cookie);
  const list = [];
  if (cookies[COOKIE]) list.push(cookies[COOKIE]);
  const viaHeader = String(req.headers[HEADER] || '');
  if (viaHeader && !list.includes(viaHeader)) list.push(viaHeader);
  return list;
}

const cookieHeader = (token, { secure = false, ttlHours = DEFAULT_TTL_HOURS } = {}) =>
  `${COOKIE}=${encodeURIComponent(token)}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${Math.floor(ttlHours * 3600)}${secure ? '; Secure' : ''}`;

const clearCookie = () => `${COOKIE}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0`;

// ---------------------------------------------------------------------------
// login throttling
// ---------------------------------------------------------------------------

const attempts = new Map();
const WINDOW_MS = 15 * 60 * 1000;
const MAX_FAILS = 6;

const throttleKey = (ip, name) => `${ip}|${String(name || '').toLowerCase()}`;

function throttled(ip, name) {
  const rec = attempts.get(throttleKey(ip, name));
  if (!rec) return 0;
  if (Date.now() - rec.first > WINDOW_MS) {
    attempts.delete(throttleKey(ip, name));
    return 0;
  }
  return rec.fails >= MAX_FAILS ? Math.ceil((rec.first + WINDOW_MS - Date.now()) / 1000) : 0;
}

function noteFailure(ip, name) {
  const key = throttleKey(ip, name);
  const rec = attempts.get(key);
  if (!rec || Date.now() - rec.first > WINDOW_MS) attempts.set(key, { fails: 1, first: Date.now() });
  else rec.fails += 1;
}

const clearFailures = (ip, name) => attempts.delete(throttleKey(ip, name));

// ---------------------------------------------------------------------------
// the gate
// ---------------------------------------------------------------------------

/**
 * Who is this request? Returns
 *   { mode: 'open',   user: null }              — auth is switched off (explicit env override)
 *   { mode: 'locked', user: null }              — no valid session
 *   { mode: 'ok',     user: {name, role} }      — signed in
 */
function whoami(req) {
  const store = readStore();
  const off = process.env.GITDOCK_AUTH === 'off' || (store && store.enabled === false);
  if (off) return { mode: 'open', user: null };
  const user = fromRequest(req);
  return user ? { mode: 'ok', user } : { mode: 'locked', user: null };
}

const isOwner = (user) => !!user && user.role === 'owner';

module.exports = {
  FILE,
  COOKIE,
  rawTokens,
  revoke,
  revokedCount,
  HEADER,
  ensureStore,
  listUsers,
  findUser,
  addUser,
  removeUser,
  setPassword,
  setEnabled,
  rotateSecret,
  login,
  issue,
  open,
  fromRequest,
  whoami,
  isOwner,
  cookieHeader,
  clearCookie,
  throttled,
  noteFailure,
  clearFailures,
  generatePassword,
  store: readStore,
};

// ---------------------------------------------------------------------------
// CLI: node app/lib/auth.js <command>
// ---------------------------------------------------------------------------
if (require.main === module) {
  const [cmd, ...rest] = process.argv.slice(2);
  const flag = (name, def = null) => {
    const hit = rest.find((a) => a === `--${name}` || a.startsWith(`--${name}=`));
    if (!hit) return def;
    const i = hit.indexOf('=');
    return i < 0 ? rest[rest.indexOf(hit) + 1] || true : hit.slice(i + 1);
  };
  const positional = rest.filter((a) => !a.startsWith('--'));
  const say = (obj) => console.log(typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2));

  try {
    switch (cmd) {
      case 'init': {
        const r = ensureStore();
        if (!r.created) say('auth already initialised — nothing to do');
        break;
      }
      case 'add-user': {
        const name = flag('name', positional[0]);
        const out = addUser({ name, password: flag('password', null), role: flag('role', 'member') });
        say(out.password ? `created ${out.name} (${out.role}) with password: ${out.password}` : `created ${out.name} (${out.role})`);
        break;
      }
      case 'list': {
        const users = listUsers();
        say(users.length ? users.map((u) => `  ${u.name.padEnd(16)} ${u.role.padEnd(6)} last login: ${u.lastLoginAt || 'never'}`).join('\n') : '  (no users — run: node app/lib/auth.js init)');
        break;
      }
      case 'passwd': {
        const name = positional[0] || flag('name', null);
        const password = flag('password', null);
        const target = name || 'admin';
        if (password) say(`password updated for ${setPassword(target, String(password)).name}`);
        else {
          const gen = generatePassword();
          setPassword(target, gen);
          say(`new password for ${target}: ${gen}`);
        }
        break;
      }
      case 'remove-user': {
        say(`removed ${removeUser(positional[0] || flag('name', null)).name}`);
        break;
      }
      case 'disable':
        say(setEnabled(false));
        break;
      case 'enable':
        say(setEnabled(true));
        break;
      case 'rotate':
        say(rotateSecret());
        break;
      default:
        say(`GitDock access control

  node app/lib/auth.js init                       create the store + first owner (prints the password once)
  node app/lib/auth.js list                       who has access, and when they last signed in
  node app/lib/auth.js add-user <name> [--role=owner|member] [--password=...]
  node app/lib/auth.js passwd <name> [--password=...]   (no --password → generates one)
  node app/lib/auth.js remove-user <name>
  node app/lib/auth.js enable | disable            (disable = no gate; only for a private network)
  node app/lib/auth.js rotate                      sign everyone out (new session secret)

  escape hatch: GITDOCK_AUTH=off when starting the panel`);
    }
  } catch (e) {
    console.error(`error: ${e.message}`);
    process.exit(1);
  }
}
