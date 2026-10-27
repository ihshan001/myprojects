/**
 * Shared test helper: the panel is locked, so both suites sign in like a real person would.
 *
 * It creates (or resets) one throwaway account through the real CLI, then the suite logs in through
 * the real HTTP endpoint — no test-only back door, and nothing here weakens the gate.
 */
'use strict';

const path = require('path');
const { spawnSync } = require('child_process');

const ROOT = path.join(__dirname, '..');
const CLI = path.join(ROOT, 'app', 'lib', 'auth.js');
const BASE = process.env.GITDOCK_URL || 'http://127.0.0.1:3000';

const TEST_USER = process.env.GITDOCK_TEST_USER || 'gitdock-test-bot';
const TEST_PASS = process.env.GITDOCK_TEST_PASS || 'gitdock-test-pass';

function cli(args) {
  return spawnSync(process.execPath, [CLI, ...args], { encoding: 'utf8' });
}

/** make sure a signed-in-able test account exists; returns { name, password } */
function ensureTestUser(name = TEST_USER, password = TEST_PASS) {
  let r = cli(['add-user', name, '--password', password, '--role', 'owner']);
  const out = `${r.stdout || ''}${r.stderr || ''}`;
  if (/already exists/i.test(out)) {
    r = cli(['passwd', name, '--password', password]);
  }
  if (r.status !== 0) throw new Error(`could not prepare the test account: ${out.trim()}`);
  return { name, password };
}

/** remove it again (never fails the run: the last-owner rule may legitimately refuse) */
function removeTestUser(name = TEST_USER) {
  try { cli(['remove-user', name]); } catch {}
}

/** sign in over HTTP exactly like the browser does; returns { token, cookie, user } */
async function signIn(name = TEST_USER, password = TEST_PASS) {
  const res = await fetch(`${BASE}/api/auth/login`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ name, password }),
  });
  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { data = { raw: text }; }
  if (!res.ok || !data || !data.ok) throw new Error(`sign-in failed (${res.status}): ${(data && data.error) || text}`);
  return { token: data.token, cookie: (res.headers.get('set-cookie') || '').split(';')[0], user: data.user };
}

module.exports = { ensureTestUser, removeTestUser, signIn, TEST_USER, TEST_PASS, ROOT, BASE, cli };
