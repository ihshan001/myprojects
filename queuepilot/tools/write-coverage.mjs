#!/usr/bin/env node
/**
 * Submit every write path with *valid* data and prove the row landed.
 *
 * tools/sql-coverage.mjs answers "did any statement throw?" while posting empty
 * forms. That catches a broken query but not a path that quietly writes
 * nothing: an empty form is usually refused by validation long before the
 * INSERT, and a 302 to the form page looks identical whether the record was
 * created or rejected. This script fills the forms in properly and then reads
 * the row back.
 *
 * Where the row is read is deliberately the database rather than the page.
 * A success banner is what the application says; a row is what happened.
 *
 * SQLite is read directly (the dev server keeps it on disk). Against MySQL,
 * read the same rows with:
 *
 *     mysql -u root queuepilot -e "select id, full_name from patients ..."
 *
 *   node tools/serve.mjs             # in one terminal
 *   node tools/write-coverage.mjs    # in another
 *
 * Exits 0 when every write path persisted its row, 1 otherwise.
 */
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.QP_BASE || 'http://127.0.0.1:8080';
const ROOT = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const DB = path.join(ROOT, 'storage/queuepilot.sqlite');
const LOG = path.join(ROOT, 'storage/logs/app.log');
const STAMP = String(Date.now()).slice(-6);

/** Run one query against the database and return the rows. */
function rows(sqlText) {
  const out = execFileSync('python3', ['-c', [
    'import sqlite3, json, sys',
    `c = sqlite3.connect(${JSON.stringify(DB)})`,
    `print(json.dumps(c.execute(${JSON.stringify(sqlText)}).fetchall()))`,
  ].join('\n')]).toString().trim();
  return JSON.parse(out);
}

class Client {
  constructor() { this.jar = new Map(); this.tokenPage = '/app'; }
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

  /**
   * Sign in, and prove it worked.
   *
   * A refused login also redirects, so the status code alone cannot tell a
   * successful sign-in from a throttled one — an earlier version of this
   * script "verified" a restored password while login was actually blocked.
   */
  async login(email, password) {
    const t = await this.csrf('/login');
    await this.req('/login', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _token: t, email, password }).toString() });
    return (await this.get('/app')).status === 200;
  }

  async post(p, fields = {}) {
    const t = await this.csrf(this.tokenPage);
    const r = await this.req(p, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _token: t, ...fields }).toString() });
    return { status: r.status, location: r.headers.get('location') };
  }
}

const results = [];
const chk = (name, pass, detail = '') => results.push({ name, pass, detail });

const logSize = () => { try { return fs.statSync(LOG).size; } catch { return 0; } };
const mark = logSize();

const admin = new Client();
chk('clinic admin can sign in', await admin.login('admin@serenity.lk', 'Serenity#2026'));

// ---------------------------------------------------------------- records
let r = await admin.post('/patients', { full_name: `Write Sweep ${STAMP}`, phone: `077${STAMP}00`, gender: 'female', birth_year: '1990' });
let got = rows(`SELECT id FROM patients WHERE full_name = 'Write Sweep ${STAMP}'`);
chk('POST /patients creates the patient row', got.length === 1, `${r.status}, ${got.length} row(s)`);

r = await admin.post('/departments', { name: `Write Dept ${STAMP}`, code: `WD${STAMP}`, floor: '2' });
got = rows(`SELECT id FROM departments WHERE name = 'Write Dept ${STAMP}'`);
chk('POST /departments creates the department row', got.length === 1, `${r.status}, ${got.length} row(s)`);

r = await admin.post('/rooms', { name: `Write Room ${STAMP}`, floor: '2' });
got = rows(`SELECT id FROM rooms WHERE name = 'Write Room ${STAMP}'`);
chk('POST /rooms creates the room row', got.length === 1, `${r.status}, ${got.length} row(s)`);

r = await admin.post('/incidents', { title: `Write incident ${STAMP}`, severity: 'minor', summary: 'automated write sweep' });
got = rows(`SELECT id FROM incidents WHERE title = 'Write incident ${STAMP}'`);
chk('POST /incidents creates the incident row', got.length === 1, `${r.status}, ${got.length} row(s)`);

// Support tickets are capped at 10 per clinic per hour (SupportController).
// A sweep run repeatedly would hit that ceiling and look like a broken write
// path, so the cap is cleared for this clinic first — the point here is to
// prove the INSERT works, not to test the limiter.
execFileSync('python3', ['-c', [
  'import sqlite3',
  `c = sqlite3.connect(${JSON.stringify(DB)})`,
  `c.execute("DELETE FROM rate_limits WHERE bucket = 'support:1'")`,
  'c.commit()',
].join('\n')]);
r = await admin.post('/support', { subject: `Write ticket ${STAMP}`, body: 'Automated sweep checking the write path.', category: 'general', priority: 'normal' });
got = rows(`SELECT id FROM support_tickets WHERE subject = 'Write ticket ${STAMP}'`);
chk('POST /support creates the ticket row', got.length === 1, `${r.status}, ${got.length} row(s)`);

r = await admin.post('/display/keys', { name: `Write Screen ${STAMP}`, kind: 'waiting_room' });
got = rows(`SELECT display_key FROM public_display_keys WHERE name = 'Write Screen ${STAMP}'`);
chk('POST /display/keys issues a screen key', got.length === 1, `${r.status}, ${got.length} row(s)`);

// ---------------------------------------------------------------- settings
r = await admin.post('/settings/hours', { open_1: '09:00', close_1: '17:30', horizon_days: '30' });
got = rows("SELECT value FROM settings WHERE scope = 'clinic' AND key_name = 'hours.1'");
chk('POST /settings/hours writes the opening hours', got.length >= 1, `hours.1 = ${got.length ? got[got.length - 1][0] : 'missing'}`);

const originalName = rows('SELECT name FROM clinics WHERE id = 1')[0][0];
const sweptName = `Write Sweep Clinic ${STAMP}`;
r = await admin.post('/settings', { name: sweptName, phone: '0112345678' });
chk('POST /settings renames the clinic', rows('SELECT name FROM clinics WHERE id = 1')[0][0] === sweptName, `${originalName} -> ${sweptName}`);
await admin.post('/settings', { name: originalName, phone: '0112345678' });
chk('clinic name restored afterwards', rows('SELECT name FROM clinics WHERE id = 1')[0][0] === originalName, originalName);

// ---------------------------------------------------------------- passwords
const beforeHash = rows("SELECT password_hash FROM users WHERE email = 'reception@serenity.lk'")[0][0];
const desk = new Client();
chk('receptionist can sign in', await desk.login('reception@serenity.lk', 'Reception#2026'));
desk.tokenPage = '/settings/password';
r = await desk.post('/settings/password', {
  current_password: 'Reception#2026',
  password: 'Reception#2027',
  password_confirmation: 'Reception#2027',
});
const afterHash = rows("SELECT password_hash FROM users WHERE email = 'reception@serenity.lk'")[0][0];
chk('POST /settings/password rotates the stored hash', r.status === 302 && afterHash !== beforeHash,
  beforeHash === afterHash ? 'unchanged' : `rotated (${afterHash.slice(0, 12)}…)`);

if (afterHash !== beforeHash) {
  // Put the demo password back through the app's own hasher, then prove the
  // login still works — restoring the hash is only half of it.
  //
  // The hash is passed to sqlite3 as a bound parameter, not interpolated: a
  // bcrypt hash contains `$` and `'`, and a mishandled one is a corrupted
  // password rather than an error, which is exactly how an earlier version of
  // this script "restored" an account nobody could sign in to.
  const hash = execFileSync('node', ['tools/php.mjs', 'tools/hash-password.php', '--password', 'Reception#2026'], { cwd: ROOT }).toString().trim();
  if (!/^\$2[aby]\$\d{2}\$.{53}$/.test(hash)) {
    throw new Error(`hash-password.php did not return a bcrypt hash: ${JSON.stringify(hash)}`);
  }
  if (hash.length < 50) throw new Error(`hash-password.php returned something that is not a hash: ${JSON.stringify(hash)}`);
  execFileSync('python3', ['-c', [
    'import sqlite3, sys',
    `c = sqlite3.connect(${JSON.stringify(DB)})`,
    `c.execute("update users set password_hash = ? where email = 'reception@serenity.lk'", (sys.argv[1],))`,
    'c.commit()',
  ].join('\n'), hash]);
}
const restored = await new Client().login('reception@serenity.lk', 'Reception#2026');
chk('receptionist can still sign in with the demo password', restored, restored ? 'sign-in reached the dashboard' : 'SIGN-IN BLOCKED');

// ---------------------------------------------------------------- clean-up
// Remove what the sweep created, and fail if it cannot: a test that leaves
// rows behind turns the next run's assertions into guesses. `users` is not
// touched — the password is restored above, not the row deleted.
let leftover = 0;
for (const [table, column, value] of [
  ['patients', 'full_name', `Write Sweep ${STAMP}`],
  ['departments', 'name', `Write Dept ${STAMP}`],
  ['rooms', 'name', `Write Room ${STAMP}`],
  ['incidents', 'title', `Write incident ${STAMP}`],
  ['support_tickets', 'subject', `Write ticket ${STAMP}`],
  ['public_display_keys', 'name', `Write Screen ${STAMP}`],
]) {
  const out = execFileSync('python3', ['-c', [
    'import sqlite3, sys',
    `c = sqlite3.connect(${JSON.stringify(DB)})`,
    `c.execute(${JSON.stringify(`DELETE FROM ${table} WHERE ${column} = ?`)}, (sys.argv[1],))`,
    'c.commit()',
    `print(c.execute(${JSON.stringify(`SELECT COUNT(*) FROM ${table} WHERE ${column} = ?`)}, (sys.argv[1],)).fetchone()[0])`,
  ].join('\n'), value]).toString().trim();
  if (out !== '0') {
    leftover++;
    console.log(`  left behind: ${out} row(s) in ${table} where ${column} = ${value}`);
  }
}
chk('the sweep removed every row it created', leftover === 0, `${leftover} table(s) still holding sweep rows`);

const newLog = fs.existsSync(LOG) ? fs.readFileSync(LOG).slice(mark).toString() : '';
const failures = newLog.split('\n').filter((l) => l.includes('db.statement_failed'));
chk('no statement failed during the writes', failures.length === 0, `${failures.length} failure(s)`);

const passed = results.filter((x) => x.pass).length;
for (const x of results) console.log(`${x.pass ? 'PASS' : 'FAIL'}  ${x.name}${x.detail ? ' — ' + x.detail : ''}`);
console.log(`\n${passed}/${results.length} write paths persisted a row`);
process.exit(passed === results.length ? 0 : 1);
