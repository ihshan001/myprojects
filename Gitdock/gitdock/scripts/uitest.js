#!/usr/bin/env node
/**
 * GitDock UI test — drives the real panel in a headless browser.
 *
 * Why this exists: the API self-test passed while the panel was completely broken at boot
 * (the client read `project.meta` from a response that had no `project` key). Nothing but a
 * browser would have caught that. This script loads the actual UI, clicks the actual buttons,
 * and fails on any console error, page error or failed request.
 *
 * usage:  node scripts/uitest.js
 *   requires:  npm install puppeteer      (in the gitdock directory)
 *   needs:     the panel running on :3000
 *
 * It creates its own project, exercises the tabs, starts a service, verifies the port from
 * Node (outside the browser), screenshots the result and deletes the project again.
 */
'use strict';
const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');
const os = require('os');
const { execFileSync } = require('child_process');

const BASE = process.env.GITDOCK_URL || 'http://127.0.0.1:3000';
const ROOT = path.join(__dirname, '..');
const SHOTS = path.join(ROOT, 'data', 'ui-screenshots');

let puppeteer;
try {
  puppeteer = require('puppeteer');
} catch {
  console.error('puppeteer is not installed. Run:  cd gitdock && npm install puppeteer');
  process.exit(2);
}

const C = { g: '\x1b[32m', r: '\x1b[31m', y: '\x1b[33m', d: '\x1b[2m', b: '\x1b[1m', x: '\x1b[0m' };
let pass = 0, fail = 0;
const failures = [];
const consoleErrors = [];
const pageErrors = [];
const badRequests = [];
/** URLs a check deliberately provokes (an honest error state in the UI); matched console noise is ignored */
const expectedFailures = [];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const testAuth = require('./test-auth.js');

/**
 * Talk to the panel's API from Node — outside the browser. Used to check what a UI click really did,
 * so a green check cannot be a green toast with nothing behind it.
 */
const NODE_SESSION = { token: '' };
async function sessionApi(pathname, { method = 'GET', body = null } = {}) {
  if (!NODE_SESSION.token) NODE_SESSION.token = (await testAuth.signIn()).token;
  const res = await fetch(BASE + pathname, {
    method,
    headers: { ...(body ? { 'content-type': 'application/json' } : {}), 'x-gitdock-session': NODE_SESSION.token },
    // accept an object (usual) or an already-encoded string — double-encoding silently sends "{\"...\"}"
    body: body == null ? undefined : (typeof body === 'string' ? body : JSON.stringify(body)),
  });
  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { data = { raw: text }; }
  if (!res.ok || (data && data.ok === false)) throw new Error(`${method} ${pathname} → ${(data && data.error) || res.status}`);
  return data;
}

async function check(name, fn) {
  const t0 = Date.now();
  try {
    await fn();
    console.log(`  ${C.g}✓${C.x} ${name} ${C.d}${Date.now() - t0}ms${C.x}`);
    pass++;
  } catch (e) {
    console.log(`  ${C.r}✗${C.x} ${name}\n      ${C.r}${e.message}${C.x}`);
    failures.push({ name, error: e.message });
    fail++;
  }
}

const section = (t) => console.log(`\n${C.b}${t}${C.x}`);

/** entry names out of a zip buffer — used to prove a download really contains what it claims */
function zipNames(buf) {
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
  const stamp = Math.random().toString(36).slice(2, 6);
  const projectName = `UI Test ${stamp}`;
  await fsp.mkdir(SHOTS, { recursive: true });

  console.log(`${C.b}GitDock UI test${C.x} ${C.d}→ ${BASE}${C.x}`);

  testAuth.ensureTestUser();   // the panel is locked; sign in like a person would

  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--window-size=1440,1000'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 1000 });

  // Errors are recorded with the page they happened on: the wrong-password check deliberately causes
  // one 401 on /login, and that must not look like a regression in the panel.
  const here = () => { try { return new URL(page.url()).pathname; } catch { return '?'; } };
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const t = m.text();
    if (here().startsWith('/login') && /401/.test(t)) return;      // expected: bad credentials
    const at = (m.location() && m.location().url) || '';
    if (expectedFailures.some((x) => at.includes(x))) return;      // expected: a refusal we assert on
    consoleErrors.push(`[${here()}] ${t}`);
  });
  page.on('pageerror', (e) => pageErrors.push(`[${here()}] ${e.message}`));
  page.on('requestfailed', (r) => {
    const url = r.url();
    if (url.startsWith(BASE)) badRequests.push(`${r.method()} ${url} — ${r.failure() && r.failure().errorText}`);
  });
  page.on('response', (r) => {
    if (r.url().startsWith(BASE) && r.status() >= 500) badRequests.push(`${r.request().method()} ${r.url()} — HTTP ${r.status()}`);
  });

  // downloads: let Chrome write them somewhere we can inspect, so "Download zip" is verified by the
  // file that actually lands on disk — not by a hopeful toast
  const DL_DIR = path.join(SHOTS, '..', 'tmp-downloads');
  await fsp.rm(DL_DIR, { recursive: true, force: true });
  await fsp.mkdir(DL_DIR, { recursive: true });
  const cdp = await page.target().createCDPSession();
  await cdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: DL_DIR, eventsEnabled: true }).catch(() => {});

  const text = (sel) => page.$eval(sel, (el) => el.textContent.trim()).catch(() => null);
  const visible = (sel) => page.$eval(sel, (el) => getComputedStyle(el).display !== 'none').catch(() => false);
  const count = (sel) => page.$$eval(sel, (els) => els.length).catch(() => 0);
  /** click a file row in the tree, re-querying inside the page so a re-render cannot bite */
  const openTreeFile = async (rel) => {
    await page.waitForFunction((f) => !!document.querySelector(`#tree .n[data-file="${f}"]`), { timeout: 15000 }, rel);
    await page.evaluate((f) => { const el = document.querySelector(`#tree .n[data-file="${f}"]`); if (el) el.click(); }, rel);
  };
  /** the project list now lives in the ▦ popover: open it before touching rows or "+ New project" */
  const openProjects = async () => {
    await page.evaluate(() => { const b = document.querySelector('#btnProjects'); if (b) b.click(); });
    await page.waitForFunction(() => {
      const p = document.querySelector('#projPop');
      return p && getComputedStyle(p).display !== 'none';
    }, { timeout: 5000 }).catch(() => {});
  };

  try {
    // ---------------------------------------------------------------- boot
    section('boot');
    await check('panel loads with no page error', async () => {
      await page.goto(BASE, { waitUntil: 'networkidle2', timeout: 30000 });
      // the panel is locked: a signed-out visitor lands on the sign-in page
      if (!/\/login/.test(page.url())) throw new Error(`expected the sign-in page, got ${page.url()}`);
      await page.waitForSelector('#f', { timeout: 10000 });
    });
    await check('signing in with a wrong password shows an error, not a crash', async () => {
      await page.type('#u', testAuth.TEST_USER);
      await page.type('#p', 'definitely-not-it');
      await page.click('#go');
      await page.waitForFunction(() => /wrong user name or password|failed/i.test(document.getElementById('m').textContent), { timeout: 10000 });
      await page.$eval('#p', (el) => { el.value = ''; });
    });
    await check('signing in with the real password opens the panel', async () => {
      await page.$eval('#p', (el) => { el.value = ''; });
      await page.type('#p', testAuth.TEST_PASS);
      await page.click('#go');
      await page.waitForFunction(() => !/\/login/.test(location.pathname), { timeout: 15000 });
      await page.waitForSelector('#projects', { timeout: 10000 });
      // Everything above happened while signed out: opening / without a session is SUPPOSED to 401 and
      // bounce to the sign-in page. Start the "no console errors" record from here, authenticated.
      consoleErrors.length = 0; pageErrors.length = 0; badRequests.length = 0;
      await sleep(600);
      if (pageErrors.length) throw new Error(`page error at boot: ${pageErrors[0]}`);
    });
    await check('the header shows who is signed in', async () => {
      const who = await text('#whoTag');
      if (!who || !who.includes(testAuth.TEST_USER)) throw new Error(`header says "${who}"`);
    });
    await check('the sign-in page is reachable without a session and has no dead ends', async () => {
      const probe = await browser.newPage();
      await probe.goto(`${BASE}/login`, { waitUntil: 'networkidle2', timeout: 20000 });
      const hasForm = await probe.$('#u');
      const hasSubmit = await probe.$('#go');
      const hint = await probe.$eval('body', (b) => b.innerText);
      await probe.close();
      if (!hasForm || !hasSubmit) throw new Error('sign-in form is missing');
      if (!/auth\.js/.test(hint)) throw new Error('no recovery hint on the sign-in page');
    });
    await check('no "Boot error" toast on a clean start', async () => {
      const body = await page.$eval('body', (b) => b.innerText);
      if (/Boot error/i.test(body)) throw new Error(body.split('\n').find((l) => /Boot error/i.test(l)));
      if (/UI error in/i.test(body)) throw new Error(body.split('\n').find((l) => /UI error in/i.test(l)));
    });
    await check('runtimes shown in the header are real', async () => {
      const php = await text('#rtPhp');
      const my = await text('#rtMysql');
      if (!/php/i.test(php || '')) throw new Error(`php chip shows "${php}"`);
      if (!/mysql/i.test(my || '')) throw new Error(`mysql chip shows "${my}"`);
    });

    // ------------------------------------------------------- create project
    section('create a project through the dialog');
    await check('New project dialog opens', async () => {
      await openProjects();
      await page.click('#btnNew');
      await page.waitForSelector('#inName', { visible: true, timeout: 5000 });
      const opts = await count('input[name=tpl]');
      if (opts < 3) throw new Error(`only ${opts} template options`);
    });
    await check('creating a static project opens it without error', async () => {
      await page.type('#inName', projectName);
      await page.evaluate(() => document.querySelector('input[name=tpl][value="static"]').click());
      await page.click('#btnCreate');
      // the panel may already hold other projects, so select ours explicitly by name
      await page.evaluate((name) => {
        const item = [...document.querySelectorAll('#projects .pitem')].find((i) => i.textContent.includes(name));
        if (item) item.click();
      }, projectName);
      await page.waitForFunction((name) => {
        const el = document.querySelector('#pname');
        return el && el.textContent.trim() === name;
      }, { timeout: 20000 }, projectName);
      const sub = await text('#psub');
      if (!/static/i.test(sub || '')) throw new Error(`project subtitle shows "${sub}"`);
    });

    await check('the clone dialog asks GitHub first and refuses a repo the token cannot reach', async () => {
      const before = await sessionApi('/api/projects').then((d) => d.projects.length);
      await openProjects();
      await page.click('#btnNew');
      await page.waitForSelector('input[name=tpl][value="clone"]', { visible: true, timeout: 5000 });
      // the clone fields only exist once the clone template is chosen
      await page.evaluate(() => document.querySelector('input[name=tpl][value="clone"]').click());
      await page.waitForFunction(() => getComputedStyle(document.querySelector('#cloneRow')).display !== 'none', { timeout: 5000 });
      try {
        await page.type('#inName', 'must not be created');
        await page.type('#inClone', 'https://github.com/ihshan001/gitdock-no-such-repo-9f3.git');
        await page.click('#btnCreate');
        // the dialog must stay open, say why, and create nothing at all
        await page.waitForFunction(() => {
          const n = document.querySelector('#cloneNote');
          return n && /⚠/.test(n.textContent);
        }, { timeout: 25000 });
        const said = await page.$eval('#cloneNote', (e) => e.innerText);
        if (said.length < 30) throw new Error(`the note is too thin to act on: "${said}"`);
        if (!(await page.$('#inClone'))) throw new Error('the dialog closed even though the repo was refused');
        await sleep(800);                                // any accidental create would land by now
        const after = await sessionApi('/api/projects').then((d) => d.projects.length);
        if (after !== before) throw new Error(`a project was created anyway (${before} → ${after})`);
      } finally {
        // never leave a modal open: one failed check would take every later check with it
        await page.evaluate(() => { const b = document.querySelector('#dlgNo'); if (b) b.click(); });
        await page.waitForFunction(() => !document.querySelector('#inClone'), { timeout: 5000 }).catch(() => {});
      }
    });

    // ---------------------------------------------------------------- files
    section('files tab');
    await check('file tree renders from the API', async () => {
      await page.waitForSelector('.n[data-file]', { timeout: 10000 });
      const files = await page.$$eval('.n[data-file]', (els) => els.map((e) => e.dataset.file));
      if (!files.includes('index.html')) throw new Error(`tree has: ${files.join(', ')}`);
    });
    await check('opening a file loads its content into the editor', async () => {
      await page.click('.n[data-file="index.html"] .file-name');
      await page.waitForSelector('#taFile', { timeout: 10000 });
      const val = await page.$eval('#taFile', (t) => t.value);
      if (!/<!doctype html>/i.test(val)) throw new Error('editor did not receive the file content');
    });
    await check('saving commits and confirms with a sha', async () => {
      await page.$eval('#taFile', (t) => { t.value = '<!doctype html><h1>Edited by the UI test</h1>'; });
      await page.click('#btnSaveFile');
      await page.waitForFunction(() => {
        const t = document.querySelector('.toast');
        return t && /commit/i.test(t.textContent);
      }, { timeout: 10000 });
      const toast = await text('.toast');
      if (!/[0-9a-f]{7}/.test(toast || '')) throw new Error(`toast did not show a commit sha: ${toast}`);
    });

    await check('the tree nests folders and folds them on click', async () => {
      const slug = await page.evaluate(() => (document.querySelector('#projects .pitem.active') || { dataset: {} }).dataset.slug || '');
      // put something two folders deep, so there is a real nest to open and close
      await sessionApi(`/api/projects/${slug}/file?path=${encodeURIComponent('assets/css/site.css')}`, {
        method: 'PUT', body: { content: 'body{font-family:system-ui,sans-serif}', message: 'add a nested file' },
      });
      const nested = await sessionApi(`/api/projects/${slug}/file?path=${encodeURIComponent('assets/css/site.css')}`);
      if (!nested.content) throw new Error('the nested file was created empty');
      await page.evaluate(() => document.querySelector('.tab[data-tab="files"]').click());
      await page.waitForSelector('#tree .n[data-dir]', { timeout: 15000 });
      const dir = await page.$eval('#tree .n[data-dir]', (d) => d.dataset.dir);
      if (dir !== 'assets') throw new Error(`the first folder is "${dir}"`);
      const badge = await page.$eval('#tree .n[data-dir] .sz', (e) => e.textContent.trim());
      if (!/^[1-9]\d*$/.test(badge)) throw new Error(`the folder badge says "${badge}"`);
      const open = await count('.n[data-file]');
      await page.evaluate((d) => document.querySelector(`#tree .n[data-dir="${d}"]`).click(), dir);
      await page.waitForFunction((n) => document.querySelectorAll('#tree .n[data-file]').length < n, { timeout: 5000 }, open);
      const folded = await count('#tree .n[data-file]');
      await page.evaluate((d) => document.querySelector(`#tree .n[data-dir="${d}"]`).click(), dir);
      await page.waitForFunction((n) => document.querySelectorAll('#tree .n[data-file]').length > n, { timeout: 5000 }, folded);
    });
    await check('a file opens in Code with Preview one click away, and renders in a sandboxed frame', async () => {
      await openTreeFile('index.html');
      await page.waitForSelector('#taFile', { timeout: 10000 });                      // Code first
      await page.evaluate(() => document.querySelector('.seg .sg[data-mode="preview"]').click());
      await page.waitForFunction(() => !!document.querySelector('#fileBody iframe[sandbox]'), { timeout: 15000 });
      const src = await page.$eval('#fileBody iframe', (f) => f.getAttribute('srcdoc') || '');
      if (!/Edited by the UI test/.test(src)) throw new Error('the preview frame did not get the file content');
      await page.evaluate(() => document.querySelector('.seg .sg[data-mode="code"]').click());
      await page.waitForSelector('#taFile', { timeout: 10000 });                      // and back to the editor
    });
    await check('a markdown file previews as rendered markdown, tables included', async () => {
      const slug = await page.evaluate(() => (document.querySelector('#projects .pitem.active') || { dataset: {} }).dataset.slug || '');
      await sessionApi(`/api/projects/${slug}/file?path=NOTES.md`, {
        method: 'PUT',
        body: { content: '# Notes\n\n- first thing\n- second thing\n\n| key | value |\n| --- | --- |\n| a | 1 |\n', message: 'add notes' },
      });
      // the file must really be on disk before the UI is asked to render it
      const back = await sessionApi(`/api/projects/${slug}/file?path=NOTES.md`);
      if (!back.content || back.content.length < 10) throw new Error(`the markdown never landed (content=${JSON.stringify(back.content).slice(0, 40)})`);
      await page.evaluate(() => document.querySelector('.tab[data-tab="files"]').click());
      await openTreeFile('NOTES.md');
      // wait for the EDITOR to be showing NOTES.md — another file's slower load must not be clicked on
      await page.waitForFunction(() => {
        const head = document.querySelector('#editor b.mono');
        return !!head && head.textContent === 'NOTES.md' && !!document.querySelector('#editor .seg .sg[data-mode="preview"]');
      }, { timeout: 15000 });
      await page.evaluate(() => document.querySelector('.seg .sg[data-mode="preview"]').click());
      try {
        await page.waitForSelector('#fileBody .md h1', { timeout: 10000 });
      } catch (e) {
        // say what the panel is actually showing, so a failure like this is diagnosable next time
        const dump = await page.evaluate(() => ({
          openFile: (document.querySelector('#editor b.mono') || {}).textContent || null,
          segs: [...document.querySelectorAll('#editor .seg .sg')].map((b) => b.dataset.mode + (b.classList.contains('on') ? '*' : '')).join(','),
          previewClass: (document.querySelector('#fileBody') || {}).className || null,
          previewHead: ((document.querySelector('#fileBody') || {}).innerHTML || '').slice(0, 120),
        }));
        throw new Error(`no markdown rendered — panel shows ${JSON.stringify(dump)}`);
      }
      const body = await page.$eval('#fileBody .md', (el) => el.innerText);
      if (!/Notes/.test(body) || !/first thing/.test(body)) throw new Error('markdown preview shows: ' + body.slice(0, 60));
      if (!(await page.$('#fileBody .md table'))) throw new Error('the markdown table did not render');
    });

    await check('a folder row downloads that folder as a zip (and does not fold it)', async () => {
      const slug = await page.evaluate(() => (document.querySelector('#projects .pitem.active') || { dataset: {} }).dataset.slug || '');
      await page.evaluate(() => document.querySelector('.tab[data-tab="files"]').click());
      await page.waitForSelector('#tree .n[data-dir]', { timeout: 15000 });
      const before = await count('#tree .n[data-file]');

      const btn = await page.$eval('#tree .n[data-dir="assets"] [data-dl]', (el) => ({ dl: el.dataset.dl, name: el.dataset.dlname }));
      if (!/\/archive\?sha=HEAD&path=assets$/.test(btn.dl)) throw new Error(`the folder button points at ${btn.dl}`);
      if (btn.name !== 'assets.zip') throw new Error(`the download would be named ${btn.name}`);

      // watch the network: a toast is not proof, a 200 with zip bytes is
      const calls = [];
      const watch = (r) => { if (r.url().includes('/archive')) calls.push(r.status()); };
      page.on('response', watch);
      await page.evaluate(() => document.querySelector('#tree .n[data-dir="assets"] [data-dl]').click());
      await page.waitForFunction(() => {
        const t = document.querySelector('.toast');
        return t && /download|prepared|blocked/i.test(t.textContent);
      }, { timeout: 20000 });
      page.off('response', watch);
      if (!calls.length || !calls.every((c) => c === 200)) throw new Error(`archive calls: ${calls.join(', ') || 'none'}`);

      // and the bytes behind that toast really are the folder
      const res = await fetch(`${BASE}${btn.dl}`, { headers: { 'x-gitdock-session': (await testAuth.signIn()).token } });
      if (res.status !== 200) throw new Error(`the folder zip answered HTTP ${res.status}`);
      const buf = Buffer.from(await res.arrayBuffer());
      if (buf.subarray(0, 2).toString() !== 'PK') throw new Error('that was not a zip');
      const names = zipNames(buf);
      if (!names.includes('assets/css/site.css')) throw new Error(`nested file missing from the zip: ${names.join(', ')}`);
      if (names.some((n) => n === 'index.html')) throw new Error('the folder zip contains the project root');

      const after = await count('#tree .n[data-file]');
      if (after !== before) throw new Error(`clicking ⬇ folded the folder (${before} → ${after} rows)`);
    });

    await check('file and folder row deletion confirms, cancels, commits and preserves siblings', async () => {
      const slug = await page.evaluate(() => S.slug);
      for (const rel of ['delete-proof/child/deep.txt', 'delete-proof/one.txt', 'keep-proof.txt']) {
        await sessionApi(`/api/projects/${slug}/file?path=${encodeURIComponent(rel)}`, { method: 'PUT', body: { content: 'delete test' } });
      }
      await page.evaluate(async () => { S.dirsAll = true; await renderTree(); });
      const clickDelete = async (rel) => {
        await page.waitForFunction(p => [...document.querySelectorAll('[data-delete-path]')].some(b => b.dataset.deletePath === p), {}, rel);
        await page.evaluate((p) => [...document.querySelectorAll('[data-delete-path]')].find(b => b.dataset.deletePath === p).click(), rel);
        await page.waitForSelector('#dlgYes', { visible: true });
      };
      try {
        await clickDelete('delete-proof');
        await page.click('#dlgNo');
        if (!(await sessionApi(`/api/projects/${slug}/file?path=delete-proof/one.txt`)).content) throw new Error('cancel deleted a file');
        await clickDelete('delete-proof/one.txt');
        await page.click('#dlgYes');
        await page.waitForFunction(() => !document.querySelector('[data-delete-path="delete-proof/one.txt"]') && document.querySelector('[data-delete-path="delete-proof"]'));
        await clickDelete('delete-proof');
        await page.click('#dlgYes');
        await page.waitForFunction(() => !document.querySelector('[data-delete-path="delete-proof"]'));
        const tree = await sessionApi(`/api/projects/${slug}/tree`);
        if (JSON.stringify(tree).includes('delete-proof')) throw new Error('folder remains');
        if (!(await sessionApi(`/api/projects/${slug}/file?path=keep-proof.txt`)).content) throw new Error('sibling deleted');
        const hist = await sessionApi(`/api/projects/${slug}/history`);
        if (!JSON.stringify(hist).includes('Delete delete-proof')) throw new Error('deletion not committed');
        await page.evaluate(() => openFile('index.html'));
      } finally {
        await page.evaluate(() => { const d = document.querySelector('#dlg'); if (d.open) d.close(); });
      }
    });

    await check('row actions support right-click and touch; Delete text stays white', async () => {
      await page.evaluate(() => document.querySelector('[data-file="index.html"]').dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true })));
      await page.waitForSelector('#rowDownload', { visible: true });
      await page.click('#rowClose');
      await page.click('[data-file="index.html"] [data-row-actions]');
      await page.waitForSelector('#rowDelete', { visible: true });
      await page.click('#rowClose');
      const color = await page.$eval('[data-file="index.html"] [data-delete-path]', b => getComputedStyle(b).color);
      if (color !== 'rgb(255, 255, 255)') throw new Error('Delete text is ' + color);
    });
    await check('uploads ask first; cancel is safe; a new branch receives the upload', async () => {
      const slug = await page.evaluate(() => S.slug);
      const original = await page.evaluate(() => S.branches.current);
      const begin = () => page.evaluate(() => { window.uploadProof = uploadFiles([{ file: new File(['hello'], 'upload-proof.txt'), rel: 'upload-proof.txt' }]); });
      await begin();
      await page.waitForSelector('#uploadBranch', { visible: true });
      await page.keyboard.press('Escape');
      await page.evaluate(() => window.uploadProof);
      const tree = await sessionApi(`/api/projects/${slug}/tree`);
      if (JSON.stringify(tree).includes('upload-proof.txt')) throw new Error('cancel uploaded data');
      await begin();
      await page.waitForSelector('#uploadBranch', { visible: true });
      await page.select('#uploadBranch', '__new__');
      await page.type('#uploadBranchName', 'upload-ui-proof');
      await page.click('#dlgYes');
      await page.waitForSelector('#uploadLocation', { visible: true });
      await page.select('#uploadLocation', 'new');
      await page.type('#uploadFolderName', 'incoming/nested');
      await page.click('#dlgYes');
      await page.evaluate(() => window.uploadProof);
      const br = await sessionApi(`/api/projects/${slug}/git/branches`);
      if (br.current !== 'upload-ui-proof') throw new Error('wrong upload branch');
      if (!(await sessionApi(`/api/projects/${slug}/file?path=incoming/nested/upload-proof.txt`)).content) throw new Error('upload missing');
      await sessionApi(`/api/projects/${slug}/git/checkout`, { method: 'POST', body: { name: original } });
      await page.evaluate(async () => { await refreshAll(); await refreshMeta(); await openFile('index.html'); });
    });
    await check('batched upload transfers 18 MB across 360 files and commits once', async () => {
      const requests = [];
      const observe = req => { if (/\/upload(?:-batch|\?)/.test(req.url())) requests.push(req.url()); };
      page.on('request', observe);
      const started = Date.now();
      try {
        await page.evaluate(() => {
          const bytes = new Uint8Array(50000); bytes.fill(65);
          window.batchProof = uploadFiles(Array.from({ length: 360 }, (_, i) => ({ file: new File([bytes], `file-${i}.txt`), rel: `batch-proof/file-${i}.txt` })));
        });
        await page.waitForSelector('#uploadBranch', { visible: true });
        await page.click('#dlgYes');
        await page.waitForSelector('#uploadLocation', { visible: true });
        await page.click('#dlgYes');
        await page.evaluate(() => window.batchProof);
        const slug = await page.evaluate(() => S.slug);
        const tree = await sessionApi(`/api/projects/${slug}/tree`);
        const folder = tree.tree.find(n => n.path === 'batch-proof');
        if (!folder || folder.children.length !== 360) throw new Error('batch files missing');
        if (requests.length > 6 || !requests.some(u => u.includes('upload-batch'))) throw new Error('uploads were not batched: ' + requests.length);
        console.log(`      18 MB / 360 files: ${requests.length} upload requests, ${Date.now()-started} ms locally (including dialogs and commit)`);
        await page.evaluate(() => openFile('index.html'));
      } finally { page.off('request', observe); }
    });
    await check('light theme uses readable semantic badge colours', async () => {
      const result = await page.evaluate(() => {
        const wasLight = document.body.classList.contains('light');
        document.body.classList.add('light');
        const color = getComputedStyle(document.querySelector('.badge.warn')).color;
        if (!wasLight) document.body.classList.remove('light');
        return color;
      });
      if (result !== 'rgb(122, 75, 0)') throw new Error('warning contrast regression: ' + result);
    });

    await check('the Actions popup closes using its visible Close button', async () => {
      await page.click('#btnPalette');
      await page.waitForSelector('#palClose', { visible: true });
      await page.click('#palClose');
      if (await page.$eval('#dlg', d => d.open)) throw new Error('Actions did not close');
    });

    await check('Push, Pull and Merge explain branch direction before any operation', async () => {
      for (const id of ['quickPush', 'quickPull']) {
        await page.click('#' + id);
        await page.waitForSelector('#dlgYes', { visible: true });
        const title = await page.$eval('#dlg h3', e => e.textContent);
        const branch = await page.evaluate(() => S.branches.current);
        if (!title.includes(branch)) throw new Error('branch not named');
        await page.click('#dlgNo');
      }
      await page.click('#quickMerge');
      await page.waitForSelector('#mergeSource', { visible: true });
      if (!await page.$('#mergeTarget')) throw new Error('no merge destination');
      await page.click('#dlgNo');
    });

    // ------------------------------------------------------------- versions
    section('versions tab');
    await check('history lists commits including the one just made', async () => {
      await page.click('.tab[data-tab="history"]');
      await page.waitForSelector('.commit', { timeout: 10000 });
      const subjects = await page.$$eval('.commit .msg', (els) => els.map((e) => e.textContent));
      if (!subjects.some((s) => /Edit index\.html/i.test(s))) throw new Error(`commits: ${subjects.join(' | ')}`);
    });

    // ---------------------------------------------------------------- setup
    section('setup tab (manifest + detection)');
    await check('Download zip really produces a zip (and does not white-screen the panel)', async () => {
      // SIZE MATTERS HERE. Chromium will not hand the body of a `Content-Disposition: attachment`
      // response to fetch() — 200 with correct headers, then reading the body dies with net::ERR_FAILED.
      // A tiny test project slides under the radar (which is how that shipped), so put an
      // incompressible ~12 MB file in the project first and demand the download comes back whole.
      const uploaded = await page.evaluate(async () => {
        // read the open project from the DOM — the panel keeps its state in a module-scope const, which
        // is not a window property
        const active = document.querySelector('#projects .pitem.active');
        const slug = active ? active.dataset.slug : '';
        if (!slug) throw new Error('no project open in the sidebar');
        const chunk = new Uint8Array(65536);
        const parts = [];
        for (let i = 0; i < 192; i++) {           // 192 × 64 KB ≈ 12 MB of random bytes
          crypto.getRandomValues(chunk);
          parts.push(chunk.slice());
        }
        const blob = new Blob(parts, { type: 'application/octet-stream' });
        const t = (window.sessionToken && window.sessionToken()) || '';
        const res = await fetch(`/api/projects/${slug}/upload?path=big.bin`, {
          method: 'POST', body: blob, headers: t ? { 'x-gitdock-session': t } : {},
        });
        const info = await res.json();
        if (info.skipped) return { skipped: true, reason: info.reason };
        const commit = await fetch(`/api/projects/${slug}/commit`, {
          method: 'POST', headers: { 'content-type': 'application/json', ...(t ? { 'x-gitdock-session': t } : {}) },
          body: JSON.stringify({ message: 'add a big file to prove large downloads work' }),
        }).then((r) => r.json());
        return {
        bytes: info.bytes,
        committed: !!(commit.commit && commit.commit.committed),
        sha: (commit.commit && commit.commit.sha) || null,
      };
      });
      if (uploaded.skipped) throw new Error(`the server skipped the big file as an ignored pattern: ${uploaded.reason}`);
      if (!(uploaded.bytes >= 12000000)) throw new Error(`the big file did not upload (got ${uploaded.bytes} bytes)`);
      if (!uploaded.committed) throw new Error('committing the big file failed');

      // switch tabs and click inside the page: puppeteer's node handles go stale the moment the tab
      // re-renders ("Node is detached from document" — the same trap the reported bug came from)
      await page.evaluate(() => document.querySelector('.tab[data-tab=history]').click());
      await page.waitForFunction(() => !!document.querySelector('#content [data-dl]'), { timeout: 15000 });
      // the history tab re-fetches on every render, so the newest row must be the commit we just made.
      // Wait for exactly that row — a stale DOM here would download an old version and look like a
      // broken download (that is how a 524-byte initial-commit zip got mistaken for the real thing).
      try {
        await page.waitForFunction(
          (sha) => {
            const el = document.querySelector('#content [data-dl]');
            return !!(el && el.getAttribute('data-dl').includes(sha));
          },
          { timeout: 20000 }, uploaded.sha,
        );
      } catch (e) {
        const seen = await page.evaluate(() => {
          const el = document.querySelector('#content [data-dl]');
          return el ? el.getAttribute('data-dl') : '(no data-dl on screen)';
        });
        throw new Error(`history does not show the commit just made (${uploaded.sha.slice(0, 7)}); newest row is ${seen}`);
      }
      // another check may already have downloaded a (small) zip — only files that appear from here on
      // are ours, otherwise the size assertion below reads somebody else's download
      const beforeSet = new Set(await fsp.readdir(DL_DIR));
      const before = beforeSet.size;
      const clicked = await page.evaluate(() => {
        const el = document.querySelector('#content [data-dl]');
        const info = {
          url: el.getAttribute('data-dl'),
          name: el.getAttribute('data-dlname'),
          text: el.innerText.trim(),
          activeSlug: (document.querySelector('#projects .pitem.active') || { dataset: {} }).dataset.slug || null,
          header: (document.querySelector('#content h2, #content h3') || {}).innerText || null,
        };
        el.click();
        return info;
      });
      console.log(`      · clicked "${clicked.text}" ${clicked.url} (active project: ${clicked.activeSlug})`);
      // wait for a file to appear AND stop growing: Chromium creates the target file and then streams
      // into it, so a stat taken the moment readdir shows it returns a partial size (524 bytes, once)
      const deadline = Date.now() + 40000;
      let files = [];
      let target = null;
      let lastSize = -1;
      while (Date.now() < deadline) {
        files = await fsp.readdir(DL_DIR);
        if (files.length > before) {
          const candidate = files.filter((f) => /\.zip$/.test(f) && !beforeSet.has(f)).sort().pop();
          if (candidate) {
            const sz = await fsp.stat(path.join(DL_DIR, candidate)).then((x) => x.size).catch(() => -1);
            if (sz > 0 && sz === lastSize) { target = candidate; break; }   // size settled → write finished
            lastSize = sz;
          }
        }
        await sleep(400);
      }
      if (files.length <= before) throw new Error(`no download landed in ${DL_DIR} (toast said it was ready)`);
      if (!target) {
        const listing = await Promise.all(files.map(async (f) => `${f} (${(await fsp.stat(path.join(DL_DIR, f)).catch(() => ({ size: -1 }))).size} B)`));
        throw new Error(`no finished .zip appeared in ${DL_DIR} — contents: ${listing.join(', ') || '(empty)'}`);
      }
      const st = await fsp.stat(path.join(DL_DIR, target));
      if (st.size < 500) throw new Error(`${target} is only ${st.size} bytes`);
      if (st.size < 11000000) {
        throw new Error(`the zip came back truncated (${st.size} bytes from ${clicked.url}, active project `
          + `${clicked.activeSlug}) — a large attachment body was not delivered in full (this is what fetch() does with `
          + 'Content-Disposition; the panel must read downloads with XHR)');
      }
      // the panel must still be alive behind the download — this is the bug that was reported
      const stillThere = await page.$('#projects');
      if (!stillThere) throw new Error('the panel disappeared from the page while downloading');
      const bodyText = await page.$eval('body', (b) => b.innerText);
      if (/sign in required/i.test(bodyText)) throw new Error('the frame navigated to raw JSON (white screen)');
    });
    await check('detection result is displayed with reasons and confidence', async () => {
      await page.click('.tab[data-tab="setup"]');
      await page.waitForSelector('#taManifest', { timeout: 10000 });
      const body = await page.$eval('#content', (c) => c.innerText);
      if (!/confidence/i.test(body)) throw new Error('no confidence chip');
      if (!/why:/i.test(body)) throw new Error('no detection reasons');
      if (!/static/i.test(body)) throw new Error('detected runtime not shown');
    });

    // ------------------------------------------------------------ database
    section('database + env + github tabs render');
    await check('database tab renders both engines', async () => {
      await page.click('.tab[data-tab="db"]');
      await page.waitForSelector('#content', { timeout: 10000 });
      await sleep(400);
      const body = await page.$eval('#content', (c) => c.innerText);
      if (!/MySQL/i.test(body) || !/SQLite/i.test(body)) throw new Error('MySQL/SQLite sections missing');
    });
    await check('env tab renders the editor', async () => {
      await page.click('.tab[data-tab="env"]');
      await page.waitForSelector('#taEnv', { timeout: 10000 });
    });
    await check('github tab shows remote, credentials and commit identity', async () => {
      await page.click('.tab[data-tab="github"]');
      await page.waitForSelector('#inRemote', { timeout: 10000 });
      const body = await page.$eval('#content', (c) => c.innerText);
      for (const needle of ['remote', 'credentials', 'commits authored as']) {
        if (!body.toLowerCase().includes(needle)) throw new Error(`github tab missing "${needle}"`);
      }
      if (!await page.$('#inIdentName')) throw new Error('commit identity inputs missing');
    });

    // ------------------------------------------------------------- service
    section('run a service');
    let port = null;
    await check('start button runs a service and reveals a port + open link', async () => {
      await page.click('.tab[data-tab="files"]');
      await page.waitForSelector('#runbar button[data-svc]', { timeout: 10000 });
      await page.click('#runbar button[data-svc]');
      await page.waitForFunction(() => {
        const a = document.querySelector('#runbar a[href*="http"]');
        return a && /:\d+/.test(document.querySelector('#runbar').innerText);
      }, { timeout: 30000 });
      const link = await page.$eval('#runbar a[href*="http"]', (a) => a.getAttribute('href'));
      const m = link.match(/:(\d+)/);
      port = m ? Number(m[1]) : null;
      if (!port) throw new Error(`could not read a port from ${link}`);
    });
    await check('the service actually serves the edited file (checked from Node, not the browser)', async () => {
      const res = await fetch(`http://127.0.0.1:${port}/`);
      const html = await res.text();
      if (!html.includes('Edited by the UI test')) throw new Error(`port ${port} served: ${html.slice(0, 80)}`);
    });

    // ------------------------------------------------------------- previews
    section('previews tab');
    await check('previews tab renders the create form and the list', async () => {
      await page.click('.tab[data-tab="previews"]');
      await page.waitForSelector('#inRef', { timeout: 10000 });
      const body = await page.$eval('#content', (c) => c.innerText);
      if (!/Preview a branch or PR/i.test(body)) throw new Error('create form missing');
      if (!/Active previews/i.test(body)) throw new Error('preview list missing');
    });

    // ----------------------------------------------------------- resilience
    section('error surfacing');

    // ------------------------------------------------------- branches, merges, the OS shell
    section('branches and merges through the actual UI');
    // the project this run created, read from the panel itself (never hard-coded)
    const slug = await page.evaluate(() => (document.querySelector('#projects .pitem.active') || { dataset: {} }).dataset.slug || '');
    if (!slug) throw new Error('no project is open in the sidebar — the branch checks need one');

    await check('the shell is real: launcher, branch chip and status bar all agree with git', async () => {
      const shell = await page.evaluate(() => ({
        launcher: document.querySelectorAll('#launcher .launch').length,
        hasBranches: !!document.querySelector('#launcher [data-go="branches"]'),
        chip: (document.querySelector('#btnBranchChip') || {}).innerText || '',
        svgIcons: document.querySelectorAll('#launcher .launch svg').length,
        status: (document.querySelector('#statusbar') || {}).innerText || '',
        tabs: [...document.querySelectorAll('#tabs .tab')].map((t) => t.dataset.tab),
      }));
      if (shell.launcher < 12) throw new Error(`launcher has only ${shell.launcher} entries`);
      if (shell.svgIcons !== shell.launcher) throw new Error('launcher icons must be inline SVG (nothing external loads in a preview frame)');
      if (!shell.hasBranches) throw new Error('no Branches entry in the launcher');
      const truth = await sessionApi(`/api/projects/${slug}/git/branches`);
      if (!shell.chip.includes(truth.current)) throw new Error(`chip says "${shell.chip.trim()}", git says ${truth.current}`);
      if (!shell.status.includes(truth.current)) throw new Error(`status bar does not name the branch: "${shell.status.replace(/\n/g, ' ')}"`);
      for (const t of ['branches', 'prs', 'files', 'history', 'db', 'env', 'github', 'setup', 'logs']) {
        if (!shell.tabs.includes(t)) throw new Error(`the ${t} workspace disappeared from the tab bar`);
      }
    });

    await check('a branch is created from the UI and the whole shell follows it', async () => {
      await page.click('.tab[data-tab="branches"]');
      await page.waitForSelector('#inBrName', { timeout: 10000 });
      await page.type('#inBrName', 'ui/feature');
      await page.click('[data-newbr]');
      await page.waitForFunction(() => {
        const chip = document.querySelector('#btnBranchChip');
        return chip && /ui\/feature/.test(chip.innerText);
      }, { timeout: 15000 });
      // the chip is updated by refreshMeta, the list re-renders off its own fetch: wait for the row
      // instead of reading mid-render (that race produced a spurious failure once)
      await page.waitForFunction(() => [...document.querySelectorAll('#content .brrow .nm')].some((n) => /ui\/feature/.test(n.innerText)), { timeout: 15000 });
      await page.waitForFunction(() => !!document.querySelector('#content .brrow.cur'), { timeout: 15000 });
      const state = await page.evaluate(() => ({
        rows: [...document.querySelectorAll('#content .brrow .nm')].map((n) => n.innerText.trim()),
        status: document.querySelector('#statusbar').innerText,
        currentMarked: !!document.querySelector('#content .brrow.cur'),
      }));
      if (!state.rows.some((r) => r.includes('ui/feature'))) throw new Error(`branch list does not show it: ${state.rows.join(' | ')}`);
      if (!state.currentMarked) throw new Error('the current branch is not highlighted in the list');
      if (!state.status.includes('ui/feature')) throw new Error('the status bar did not follow the switch');
      const truth = await sessionApi(`/api/projects/${slug}/git/branches`);
      if (truth.current !== 'ui/feature') throw new Error(`git says we are on ${truth.current}`);
    });

    await check('a real merge, made by clicking in the UI, is visible in Versions and can be undone there', async () => {
      /** read a file through the API, treating "gone" as empty text rather than an exception */
      const readMaybe = async (rel) => {
        try {
          const r = await sessionApi(`/api/projects/${slug}/file?path=${encodeURIComponent(rel)}`);
          return r.content || '';
        } catch (e) { if (/not found/i.test(e.message)) return ''; throw e; }
      };
      const waitFor = async (label, fn, ms = 8000) => {
        const deadline = Date.now() + ms;
        for (;;) {
          if (await fn()) return;
          if (Date.now() > deadline) throw new Error(`timed out waiting for ${label}`);
          await sleep(250);
        }
      };

      // work on the branch (the merge itself is what we are testing in the browser)
      await sessionApi(`/api/projects/${slug}/file?path=${encodeURIComponent('merged-by-ui.txt')}`, {
        method: 'PUT', body: { content: 'came from the branch\n', message: 'work to merge' },
      });
      await page.evaluate(() => document.querySelector('.tab[data-tab="branches"]').click());
      await page.waitForSelector('[data-switch="main"]', { timeout: 10000 });
      await page.evaluate(() => document.querySelector('[data-switch="main"]').click());
      await page.waitForFunction(() => {
        const chip = document.querySelector('#btnBranchChip');
        return chip && !/ui\/feature/.test(chip.innerText);
      }, { timeout: 15000 });

      // merge by clicking the button the UI offers, and confirm in the dialog
      await page.waitForSelector('[data-merge="ui/feature"]', { timeout: 10000 });
      await page.evaluate(() => document.querySelector('[data-merge="ui/feature"]').click());
      await page.waitForSelector('#dlgYes', { visible: true, timeout: 8000 });
      await page.click('#dlgYes');
      await page.waitForFunction(() => /undo merge/i.test(document.querySelector('#content').innerText), { timeout: 20000 });

      // the browser click must have produced a real merge commit with two parents, in git
      await waitFor('the merge commit', async () => (await sessionApi(`/api/projects/${slug}/history`)).commits.some((c) => c.parents >= 2));
      const hist = await sessionApi(`/api/projects/${slug}/history`);
      const mergeCommit = hist.commits.find((c) => c.parents >= 2);
      await waitFor('the merged file on main', async () => /came from the branch/.test(await readMaybe('merged-by-ui.txt')));

      // and undo it from the Versions tab — the button a person would reach for
      await page.evaluate(() => document.querySelector('.tab[data-tab="history"]').click());
      await page.waitForSelector('[data-revert]', { timeout: 15000 });
      await page.evaluate(() => document.querySelector('[data-revert]').click());
      await page.waitForSelector('#dlgYes', { visible: true, timeout: 8000 });
      await page.click('#dlgYes');
      await waitFor('the undo commit', async () => /^Revert/i.test((await sessionApi(`/api/projects/${slug}/history`)).commits[0].subject));
      await waitFor('the merged file to be gone again', async () => !/came from the branch/.test(await readMaybe('merged-by-ui.txt')));

      const after = await sessionApi(`/api/projects/${slug}/history`);
      if (!after.commits.some((c) => c.sha === mergeCommit.sha)) {
        throw new Error('the merge commit vanished from history — undoing a merge must never rewrite it');
      }
      await page.screenshot({ path: path.join(SHOTS, 'os-branches-after-merge.png') });
    });

    await check('the Pull requests tab explains what is missing instead of failing quietly', async () => {
      expectedFailures.push('/git/prs');   // a project with no github remote answers 400 on purpose
      await page.evaluate(() => document.querySelector('.tab[data-tab="prs"]').click());
      await page.waitForSelector('#inPrTitle', { timeout: 15000 });
      const text = await page.$eval('#content', (c) => c.innerText);
      if (!/github\.com remote|token/i.test(text)) throw new Error('the tab must say WHY it cannot list pull requests');
      if (!text.includes('Push branch')) throw new Error('the "open a pull request" form is missing');
      const stillAlive = await page.$('#projects');
      if (!stillAlive) throw new Error('the panel disappeared while loading the PR tab');
    });

    // ------------------------------------------------------------- cleanup
    section('legibility');
    await check('no control renders as invisible black-on-dark text', async () => {
      // a <button> without a colour rule keeps the browser default (black) — which disappears on this
      // dark shell. That is how the branch chip shipped invisible once; this check keeps it honest.
      const dark = await page.evaluate(() => document.body.classList.contains('light') === false);
      if (!dark) return;
      const bad = await page.evaluate(() => {
        const out = [];
        for (const el of document.querySelectorAll('button, .pitem, .tab, .sg, .role, a')) {
          const r = el.getBoundingClientRect();
          if (!r.width || !r.height) continue;                     // not on screen
          const c = getComputedStyle(el);
          if (c.visibility === 'hidden' || c.display === 'none') continue;
          if (c.color !== 'rgb(0, 0, 0)') continue;                // our palette never uses pure black
          out.push((el.id ? '#' + el.id : el.className || el.tagName).toString().slice(0, 40));
        }
        return [...new Set(out)];
      });
      if (bad.length) throw new Error(`invisible controls on the dark shell: ${bad.join(', ')}`);
    });

    section('explanations and scrolling');
    await check('long explanations hide behind ⓘ and only open when clicked', async () => {
      await page.evaluate(() => document.querySelector('.tab[data-tab="env"]').click());
      await page.waitForSelector('#taEnv', { timeout: 10000 });
      if (!(await page.$('.panel h2 .info'))) throw new Error('the Env heading has no ⓘ');
      const words = await page.$eval('.panel h2 .info', (b) => (b.dataset.info || '').length);
      if (words < 40) throw new Error('the ⓘ carries no real explanation');
      const atRest = await page.evaluate(() => {
        const p = document.querySelector('#infoPop');
        return !p || getComputedStyle(p).display === 'none';
      });
      if (!atRest) throw new Error('the explanation is on screen before anyone asked for it');
      await page.evaluate(() => document.querySelector('.panel h2 .info').click());
      await page.waitForFunction(() => {
        const p = document.querySelector('#infoPop');
        return p && getComputedStyle(p).display !== 'none' && (p.innerText || '').length > 20;
      }, { timeout: 5000 });
      await page.evaluate(() => document.querySelector('#content').click());
      await page.waitForFunction(() => {
        const p = document.querySelector('#infoPop');
        return !p || getComputedStyle(p).display === 'none';
      }, { timeout: 5000 });
    });
    await check('long content scrolls inside its own box (env, database, files panes)', async () => {
      const env = await page.$eval('#taEnv', (el) => { const c = getComputedStyle(el); return `${c.maxHeight}|${c.overflowY}`; });
      if (/^none\|/.test(env)) throw new Error('the env editor has no height limit — it stretches the tab');
      if (!/\|(auto|scroll)/.test(env)) throw new Error(`the env editor does not scroll (${env})`);
      // a real sqlite file in the project, so the database tab has a list to put in a scroll box
      let seeded = false;
      try {
        const dbFile = path.join(os.tmpdir(), 'uitest-scroll.sqlite');
        execFileSync('sqlite3', [dbFile, 'create table if not exists t(id integer primary key, note text); delete from t; insert into t(note) values ("hello");']);
        const b64 = fs.readFileSync(dbFile).toString('base64');
        seeded = await page.evaluate(async (data) => {
          const slug = (document.querySelector('#projects .pitem.active') || { dataset: {} }).dataset.slug;
          const bytes = Uint8Array.from(atob(data), (c) => c.charCodeAt(0));
          const res = await fetch(`/api/projects/${slug}/upload?path=uitest.sqlite`, { method: 'POST', body: bytes });
          return res.ok;
        }, b64);
      } catch (e) { console.log(`${C.y}!${C.x} could not seed a sqlite file for the db-tab scroll check: ${e.message}`); }
      await page.evaluate(() => document.querySelector('.tab[data-tab="db"]').click());
      await page.waitForSelector('#content .panel', { timeout: 10000 });
      if (seeded) {
        await page.waitForFunction(() => [...document.querySelectorAll('#content .mono')].some((n) => /uitest\.sqlite/.test(n.textContent)), { timeout: 10000 })
          .catch(() => { throw new Error('the database tab never listed the sqlite file that was just uploaded'); });
        const boxes = await page.$$eval('#content .scroll', (els) => els.map((e) => getComputedStyle(e).maxHeight));
        if (!boxes.length) throw new Error('the database tab has no scroll box for its lists');
        if (boxes.some((m) => m === 'none')) throw new Error('a database scroll box has no height limit');
      } else console.log(`${C.y}!${C.x} db scroll box not asserted — no sqlite seed`);
      await page.evaluate(() => document.querySelector('.tab[data-tab="files"]').click());
      await page.waitForSelector('#treeCard', { timeout: 10000 });
      const panes = await page.evaluate(() => ['#treeCard', '#editor'].map((s) => getComputedStyle(document.querySelector(s)).maxHeight));
      if (panes.some((m) => m === 'none')) throw new Error('the files panes can grow without limit: ' + panes.join(', '));
    });

    section('cleanup through the UI');
    await check('the panel works with the session in the URL (the preview-iframe case)', async () => {
      // Inside the Arena preview the panel is a third-party iframe: cookies and localStorage are
      // unavailable, so the sign-in page hands the session over in the URL and the panel sends it as
      // a header from then on. A fresh context has no cookies at all — this is that exact situation.
      const session = await testAuth.signIn();
      const ctx = await browser.createBrowserContext();
      const fresh = await ctx.newPage();
      await fresh.goto(`${BASE}/?t=${encodeURIComponent(session.token)}`, { waitUntil: 'networkidle2', timeout: 30000 });
      await fresh.waitForSelector('#projects', { timeout: 15000 });
      await fresh.waitForFunction((name) => {
        const el = document.getElementById('whoTag');
        return el && el.textContent.includes(name);
      }, { timeout: 15000 }, testAuth.TEST_USER);
      const listHtml = await fresh.$eval('#projects', (el) => el.innerText);
      if (/sign in/i.test(listHtml)) throw new Error(`panel did not authenticate: ${listHtml.slice(0, 120)}`);
      const urlAfter = await fresh.evaluate(() => location.pathname + location.search);
      if (/t=/.test(urlAfter)) throw new Error(`the token was left in the address bar: ${urlAfter}`);
      await ctx.close();
    });
    await check('in a locked-down preview frame the panel works AND hands over a working download link', async () => {
      // This is the reported situation: the panel inside sandbox="allow-scripts" — no localStorage, no
      // cookies, no popups, downloads blocked. The panel must still authenticate (token kept in memory)
      // and must offer a link the person can paste into a normal tab.
      const session = await testAuth.signIn();
      const ctx = await browser.createBrowserContext();
      const host = await ctx.newPage();
      await host.setContent(
        `<iframe sandbox="allow-scripts" style="width:1000px;height:700px;border:0" src="${BASE}/?t=${encodeURIComponent(session.token)}"></iframe>`,
        { waitUntil: 'networkidle2' }
      );
      let panel = null;
      for (let i = 0; i < 60 && !panel; i++) {
        panel = host.frames().find((f) => f !== host.mainFrame() && f.url().includes('3000'));
        if (!panel) await sleep(250);
      }
      if (!panel) throw new Error('the panel never loaded inside the sandboxed frame');
      await panel.waitForSelector('#projects', { timeout: 25000 });

      const storage = await panel.evaluate(() => { try { localStorage.setItem('x', '1'); localStorage.removeItem('x'); return true; } catch { return false; } });
      if (storage) throw new Error('this test is meaningless here: localStorage is available in the frame');

      const who = await panel.$eval('#whoTag', (el) => el.textContent.trim());
      if (!who.includes(testAuth.TEST_USER)) throw new Error(`the frame lost its session (header says "${who}") — the token did not survive the URL being cleaned`);

      // the way out of the frame is offered, and the panel knows it is framed
      const ownTabVisible = await panel.$eval('#btnOwnTab', (el) => getComputedStyle(el).display !== 'none');
      if (!ownTabVisible) throw new Error('the "own tab" escape hatch is missing inside a frame');

      // Popups are blocked inside sandbox="allow-scripts", so the escape hatch must HAND OVER an address
      // rather than do nothing. This is the click a real person makes when a download will not save:
      // they press "own tab", and if the browser refuses the popup they still need a way through.
      await panel.evaluate(() => document.getElementById('btnOwnTab').click());
      const escaped = await panel.waitForFunction(() => {
        const inp = document.querySelector('#dlg input[readonly]');
        return inp && /[?&]t=/.test(inp.value) ? { url: inp.value, copy: !!document.querySelector('#dlg [data-copy]') } : null;
      }, { timeout: 15000 }).then((h) => h.jsonValue()).catch(() => null);
      if (!escaped) throw new Error('clicking "own tab" in a frame did nothing — no copyable address was offered');
      if (!escaped.copy) throw new Error('the address dialog has no copy button');
      // close it again (the dialog's own cancel button, so the handler is exercised too)
      await panel.evaluate(() => {
        const btn = [...document.querySelectorAll('#dlg button')].find((b) => /close|cancel/i.test(b.innerText));
        if (btn) btn.click();
      });
      await panel.waitForFunction(() => !document.querySelector('#dlg'), { timeout: 8000 }).catch(() => {});

      // project → Versions → download
      await panel.evaluate((name) => {
        const item = [...document.querySelectorAll('#projects .pitem')].find((i) => i.textContent.includes(name));
        if (item) item.click();
        const tab = document.querySelector('.tab[data-tab=history]');
        if (tab) tab.click();
      }, projectName);
      await panel.waitForFunction(() => !!document.querySelector('#content [data-dl]'), { timeout: 20000 });
      await panel.evaluate(() => document.querySelector('#content [data-dl]').click());

      // the dialog must appear with a short-lived link
      await panel.waitForFunction(() => {
        const input = document.querySelector('#dlg input[readonly]');
        return !!input && /\/api\/dl\//.test(input.value);
      }, { timeout: 20000 });
      const link = await panel.$eval('#dlg input[readonly]', (el) => el.value);
      if (!/^https?:\/\//.test(link)) throw new Error(`the link is not absolute: ${link}`);
      await ctx.close();

      // and that link must really deliver the file when opened outside the frame — no session, no cookie
      const res = await fetch(link, { signal: AbortSignal.timeout(30000) });
      if (res.status !== 200) throw new Error(`the handed-over link answered HTTP ${res.status}`);
      const buf = Buffer.from(await res.arrayBuffer());
      if (buf.slice(0, 2).toString() !== 'PK') throw new Error('the handed-over link did not return a zip');
    });
    await check('delete the project via the button + dialog (gated by typing the name)', async () => {
      await page.click('.tab[data-tab="files"]');
      await page.click('#btnDeleteProject');
      await page.waitForSelector('#dlgType', { visible: true, timeout: 5000 });
      // a project delete cannot be undone from inside the panel, so the confirm button must stay
      // dead until the project's own name has been typed in
      const dead = await page.$eval('#dlgYes', (b) => b.disabled);
      if (!dead) throw new Error('the delete button was live before anything was typed');
      await page.type('#dlgType', 'wrong-name');
      await page.waitForFunction(() => document.querySelector('#dlgYes').disabled === true, { timeout: 3000 });
      const want = (await page.evaluate(() => (document.querySelector('#dlgType') || {}).placeholder || '')).trim();
      const slug = await page.evaluate(() => (document.querySelector('#projects .pitem.active') || { dataset: {} }).dataset.slug || '');
      if (!want) throw new Error('the dialog does not say which word it wants');
      if (slug && want !== slug) throw new Error(`dialog asks for "${want}" but the open project is "${slug}"`);
      await page.$eval('#dlgType', (el) => { el.value = ''; });
      await page.type('#dlgType', want);
      await page.waitForFunction(() => !document.querySelector('#dlgYes').disabled, { timeout: 5000 });
      await page.click('#dlgYes');
      // confirm OUR project is gone from the list, regardless of what else exists
      await page.waitForFunction((name) => {
        return ![...document.querySelectorAll('#projects .pitem')].some((i) => i.textContent.includes(name));
      }, { timeout: 15000 }, projectName);
    });

    await check('a project deleted from under the panel collapses the window instead of leaving a ghost', async () => {
      // this is how a failed clone used to look: the tab, the branch chip and the projbar all stayed,
      // pointing at a directory that no longer existed
      const made = await sessionApi('/api/projects', { method: 'POST', body: { name: `ghost ${Date.now().toString(36)}`, template: 'static' } });
      const slug = made.slug;
      // the panel's list is stale until it reloads — refresh first, then the row exists to click
      await page.evaluate(() => document.querySelector('#btnRefresh').click());
      await openProjects();
      await page.waitForFunction((s) => [...document.querySelectorAll('#projects .pitem')].some((i) => i.dataset.slug === s), { timeout: 15000 }, slug);
      await page.evaluate((s) => {
        const item = [...document.querySelectorAll('#projects .pitem')].find((i) => i.dataset.slug === s);
        if (item) item.click();
      }, slug);
      await page.waitForFunction(() => document.querySelector('#projbar').style.display !== 'none', { timeout: 15000 });

      // ---- nothing in the panel is wired to a function that does not exist ---------------
      await check('the panel script has no undefined calls and no dangling element ids', async () => {
        // This is the check for the bug that started it: the dock's Setup icon and the ⌘ palette's
        // Settings entry called openSettings(), which was never defined — a click threw at runtime
        // with no syntax error and no server error to catch it. lint-ui.js parses the script with a
        // real JS parser (acorn) and compares every call against every declaration.
        const { execFileSync } = require('child_process');
        try {
          const out = execFileSync('node', [path.join(ROOT, 'scripts', 'lint-ui.js')], { encoding: 'utf8' });
          if (!/nothing dangling/.test(out)) throw new Error(`the audit did not confirm a clean result: ${out.trim()}`);
        } catch (e) {
          throw new Error(`static audit failed:\n${String(e.stdout || e.message).trim()}`);
        }
      });
      await check('every dock icon, every tab and every palette entry does something real', async () => {
        // "no dead clicks": the same bug class, caught by pressing the controls instead of reading them
        const before = await page.evaluate(() => ({
          dock: [...document.querySelectorAll('#launcher .launch')].map((b) => b.dataset.go),
          tabs: [...document.querySelectorAll('#tabs .tab')].map((b) => b.dataset.tab),
        }));
        if (before.dock.length < 10 || before.tabs.length < 9) throw new Error(`the panel lost controls: ${JSON.stringify(before)}`);
        for (const go of before.dock) {
          const wasLight = await page.evaluate(() => document.body.classList.contains('light'));
          await page.evaluate((g) => { const b = [...document.querySelectorAll('#launcher .launch')].find((x) => x.dataset.go === g); if (b) b.click(); }, go);
          await sleep(450);
          const st = await page.evaluate(() => {
            const d = document.querySelector('#dlg');
            return {
              dlg: !!(d && d.open),
              isLight: document.body.classList.contains('light'),
              tab: ((document.querySelector('#tabs .tab.active') || {}).dataset || {}).tab || null,
              how: !!document.querySelector('#howList'),
              content: (document.querySelector('#content') || {}).innerText || '',
            };
          });
          if (go === 'theme') {
            if (st.isLight === wasLight) throw new Error('the Theme icon did not change the theme');
            await page.evaluate((want) => {
              const isLight = document.body.classList.contains('light');
              if (isLight !== want) document.querySelector('#btnTheme').click();
            }, wasLight);
            continue;
          }
          if (go === 'settings') {
            if (!st.dlg) throw new Error('the dock Setup icon opened no dialog (this is the openSettings bug)');
            await page.keyboard.press('Escape');
            await sleep(250);
            continue;
          }
          if (go === 'how') {
            if (!st.how) throw new Error('the dock How to icon showed no manual');
            await page.evaluate(() => { S.tab = 'files'; renderTab(); });
            await sleep(300);
            continue;
          }
          if (st.tab !== go) throw new Error(`the dock's ${go} icon left the panel on "${st.tab}"`);
          if (st.content.trim().length < 10) throw new Error(`the ${go} tab rendered nothing`);
        }
        // and the palette: every entry present, and its Settings entry really opens Settings
        await page.evaluate(() => document.querySelector('#btnPalette').click());
        await page.waitForSelector('#palList .it', { timeout: 5000 });
        const entries = await page.$$eval('#palList .it', (els) => els.map((e) => e.innerText.split('\n')[0].trim()));
        if (entries.length < 8) throw new Error(`the quick-actions list shrank to ${entries.length} entries`);
        if (!entries.some((e) => /Settings/i.test(e))) throw new Error('the palette has no Settings entry');
        await page.evaluate(() => { const it = [...document.querySelectorAll('#palList .it')].find((e) => /Settings/.test(e.innerText)); if (it) it.click(); });
        await page.waitForFunction(() => { const d = document.querySelector('#dlg'); return d && d.open; }, { timeout: 6000 });
        await page.keyboard.press('Escape');
        await sleep(250);
      });

      // ---- the manual, from inside a project --------------------------------------------
      await check('the How to tab explains every part of the panel, and filters to what you are stuck on', async () => {
        const tab = await page.$('.tab[data-tab="how"]');
        if (!tab) throw new Error('there is no How to tab in the tab strip');
        await tab.click();
        await page.waitForSelector('#howList .howsec', { timeout: 8000 });
        const got = await page.evaluate(() => ({
          sections: document.querySelectorAll('#howList .howsec').length,
          steps: document.querySelectorAll('#howList .howsteps li').length,
          empty: [...document.querySelectorAll('#howList .howsec')].filter((s) => !s.innerText.trim().length).length,
          last: (document.querySelector('#howList .howsec:last-of-type h3') || {}).innerText || '',
          text: document.querySelector('#howList').innerText,
        }));
        if (got.sections < 15) throw new Error(`only ${got.sections} sections — that is not "every section of the panel"`);
        if (got.steps < got.sections * 3) throw new Error(`${got.sections} sections but only ${got.steps} steps — some area is described without saying how`);
        if (got.empty) throw new Error(`${got.empty} section(s) rendered blank`);
        // the areas a person would search for, by name
        for (const want of ['Code', 'Database', 'GitHub', 'Pull requests', 'Previews', 'Services', 'Versions', 'Branches', 'Env', 'Setup', 'Settings', 'drag']) {
          if (!new RegExp(want, 'i').test(got.text)) throw new Error(`the manual never mentions ${want}`);
        }
        // the token question that started all this: the exact permissions, in the panel
        for (const want of ['Contents', 'Read and write', 'Pull requests', 'Metadata', 'All repositories']) {
          if (!new RegExp(want, 'i').test(got.text)) throw new Error(`the GitHub section does not say "${want}"`);
        }
        // the filter, including the honest empty state
        await page.type('#howFilter', 'token');
        await sleep(200);
        const filtered = await page.evaluate(() => ({
          visible: [...document.querySelectorAll('#howList .howsec')].filter((s) => s.style.display !== 'none').length,
          badge: document.querySelector('#howCount').textContent,
          all: document.querySelectorAll('#howList .howsec').length,
        }));
        if (!filtered.visible || filtered.visible === filtered.all) throw new Error(`filtering "token" did not narrow anything: ${JSON.stringify(filtered)}`);
        await page.evaluate(() => { const f = document.querySelector('#howFilter'); f.value = 'zzz-none'; f.dispatchEvent(new Event('input')); });
        await sleep(150);
        const nothing = await page.evaluate(() => ({
          visible: [...document.querySelectorAll('#howList .howsec')].filter((s) => s.style.display !== 'none').length,
          msg: getComputedStyle(document.querySelector('#howNone')).display !== 'none',
        }));
        if (nothing.visible || !nothing.msg) throw new Error('an unmatched search must say so rather than showing an empty page');
        await page.evaluate(() => { const f = document.querySelector('#howFilter'); f.value = ''; f.dispatchEvent(new Event('input')); });
        await sleep(150);
        await page.click('.tab[data-tab="files"]');
        await page.waitForSelector('#drop', { timeout: 8000 });
      });

      // delete it behind the panel's back, then ask the panel to refresh
      await sessionApi(`/api/projects/${slug}`, { method: 'DELETE' });
      await page.evaluate(() => document.querySelector('#btnRefresh').click());
      await page.waitForFunction(() => /is not there/i.test((document.querySelector('#content') || {}).innerText || ''), { timeout: 15000 });
      const shell = await page.evaluate(() => ({
        projbar: getComputedStyle(document.querySelector('#projbar')).display,
        tabs: getComputedStyle(document.querySelector('#tabs')).display,
      }));
      if (shell.projbar !== 'none' || shell.tabs !== 'none') throw new Error(`the window is still open on a project that is gone: ${JSON.stringify(shell)}`);
    });

    await check('with no project open, How to is one click away — and gets out of the way again', async () => {
      await page.evaluate(() => { const d = document.querySelector('#dlgNo'); if (d) d.click(); });
      const tabsHidden = await page.evaluate(() => getComputedStyle(document.querySelector('#tabs')).display);
      if (tabsHidden !== 'none') throw new Error('this check is meant to run with no project open');
      await page.click('#btnHow');
      await page.waitForSelector('#howList .howsec', { timeout: 8000 });
      const n = await page.evaluate(() => document.querySelectorAll('#howList .howsec').length);
      if (n < 15) throw new Error(`the manual came up short with no project open: ${n} sections`);
      // pressing it again hands the window back — it must not be a one-way trip
      await page.click('#btnHow');
      await page.waitForFunction(() => !!document.querySelector('#content .empty'), { timeout: 5000 });
      const how = await page.$(('#howList'));
      if (how) throw new Error('the manual stayed on screen after closing it');
    });

    await check('the panel fits real devices: nothing off-screen, and every tab scrolls to its end', async () => {
      // The lesson this check encodes: the first version of it only looked at the menu bar's
      // horizontal position, and missed that the content column was taller than the screen — every
      // tab was cut off at the bottom with no way to scroll to the rest. So this now checks BOTH
      // directions, at the sizes people actually use, and it scrolls to the end of each tab to prove
      // the end is reachable.
      const SIZES = [
        [390, 640, 'phone'],
        [844, 390, 'phone landscape'],
        [1024, 600, 'small Chromebook'],
      ];
      const TABS = ['files', 'github', 'how'];
      for (const [w, h, label] of SIZES) {
        const dev = await browser.newPage();
        try {
          await dev.setViewport({ width: w, height: h, isMobile: w < 800 });
          await dev.goto(`${BASE}/?t=${encodeURIComponent(NODE_SESSION.token)}`, { waitUntil: 'networkidle2', timeout: 30000 });
          // 1. at the top of the page, the menu bar and window bar controls must be on screen
          const head = await dev.evaluate(() => {
            const vw = window.innerWidth;
            const on = (sel) => {
              const el = document.querySelector(sel);
              if (!el) return { sel, ok: false, why: 'missing' };
              const r = el.getBoundingClientRect();
              return { sel, ok: r.x >= 0 && r.right <= vw + 1 && r.width > 0 && r.height > 0, why: `x=${Math.round(r.x)} right=${Math.round(r.right)} of ${vw}` };
            };
            return {
              overflow: document.documentElement.scrollWidth - vw,
              controls: ['#btnProjects', '#btnHow', '#btnSettings', '#btnLogout', '#btnPalette'].map(on),
            };
          });
          if (head.overflow > 2) throw new Error(`${label} ${w}x${h}: the page scrolls sideways by ${head.overflow}px`);
          const lost = head.controls.filter((c) => !c.ok);
          if (lost.length) throw new Error(`${label} ${w}x${h}: off-screen → ${lost.map((c) => `${c.sel} (${c.why})`).join(', ')}`);
          // 2. every tab must scroll to its own end — the bottom of the content is the test
          for (const tab of TABS) {
            await dev.evaluate((t) => { const el = document.querySelector(`.tab[data-tab="${t}"]`); if (el) el.click(); }, tab);
            await sleep(900);
            const r = await dev.evaluate(() => {
              const vh = window.innerHeight;
              const c = document.querySelector('#content');
              const scroller = (c && c.scrollHeight > c.clientHeight + 2) ? c
                : (document.documentElement.scrollHeight > vh + 2 ? document.documentElement : null);
              if (scroller) scroller.scrollTop = scroller.scrollHeight;
              const kids = c ? [...c.children].filter((el) => getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().height > 0) : [];
              const last = kids.length ? kids[kids.length - 1] : null;
              const lr = last ? last.getBoundingClientRect() : null;
              return {
                bottom: lr ? Math.round(lr.bottom) : null,
                vh,
                hOverflow: document.documentElement.scrollWidth - window.innerWidth,
                blank: c ? c.innerText.trim().length < 20 : true,
              };
            });
            if (r.blank) throw new Error(`${label} ${w}x${h}: the ${tab} tab rendered empty`);
            if (r.hOverflow > 2) throw new Error(`${label} ${w}x${h}: the ${tab} tab scrolls sideways by ${r.hOverflow}px`);
            if (r.bottom === null || r.bottom > r.vh + 3 || r.bottom <= 0) {
              throw new Error(`${label} ${w}x${h}: the ${tab} tab is cut off — after scrolling to the end, the content ends at ${r.bottom}px in a ${r.vh}px window`);
            }
          }
          // and the manual is reachable on the smallest of them (the tab loop may have left it open,
          // in which case the menu-bar button is a toggle and would close it)
          if (!(await dev.$('#howList'))) await dev.click('#btnHow');
          await dev.waitForSelector('#howList .howsec', { timeout: 8000 });
          const n = await dev.evaluate(() => document.querySelectorAll('#howList .howsec').length);
          if (n < 15) throw new Error(`${label}: the manual shows only ${n} sections`);
        } finally {
          await dev.close();
        }
      }
    });

    await page.screenshot({ path: path.join(SHOTS, 'panel-empty.png'), fullPage: false });
    console.log(`\n${C.d}screenshot → data/ui-screenshots/panel-empty.png${C.x}`);
    section('access control from the settings dialog');
    await check('the settings dialog adds a teammate who can really sign in, then removes them', async () => {
      const mate = 'ui-mate-' + Math.random().toString(36).slice(2, 6);
      await page.evaluate(() => document.querySelector('#btnSettings').click());
      await page.waitForSelector('#btnAddUser', { visible: true, timeout: 10000 });

      await page.type('#inNewUser', mate);
      await page.type('#inNewRole', 'member');
      await page.evaluate(() => document.querySelector('#btnAddUser').click());
      await page.waitForFunction(() => /password:/i.test((document.querySelector('#accessStat') || {}).innerText || ''), { timeout: 15000 });
      const said = await page.$eval('#accessStat', (el) => el.innerText);
      if (!said.includes(mate)) throw new Error(`the dialog did not confirm ${mate}`);
      const pw = (said.match(/password:\s*(\S+)/) || [])[1];
      if (!pw) throw new Error('the one-time password was never shown — nobody could use that account');

      // the point of an account is that it works: sign in as them, over HTTP, like a browser would
      const login = await fetch(`${BASE}/api/auth/login`, {
        method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ name: mate, password: pw }),
      }).then((r) => r.json().then((j) => ({ status: r.status, j })));
      if (login.status !== 200 || !login.j.ok || !login.j.token) throw new Error(`the new teammate cannot sign in (HTTP ${login.status})`);

      // ...that the list shows them, and that removing them really takes the access away
      await page.evaluate(() => document.querySelector('#btnSettings').click());
      await page.waitForSelector(`[data-deluser="${mate}"]`, { timeout: 10000 });
      await page.evaluate((m) => document.querySelector(`[data-deluser="${m}"]`).click(), mate);
      await page.waitForSelector('#dlgYes', { visible: true, timeout: 8000 });
      await page.click('#dlgYes');
      await page.waitForFunction((m) => !document.querySelector(`[data-deluser="${m}"]`), { timeout: 15000 }, mate);

      const after = await fetch(`${BASE}/api/projects`, { headers: { 'x-gitdock-session': login.j.token } });
      if (after.status !== 401) throw new Error(`the removed teammate's session still works (HTTP ${after.status})`);

      // leave the panel tidy for whatever runs next
      await page.evaluate(() => {
        const b = [...document.querySelectorAll('#dlg button')].find((x) => /close/i.test(x.innerText));
        if (b) b.click();
      });
      await page.waitForFunction(() => !document.querySelector('#dlg[open]'), { timeout: 8000 }).catch(() => {});
    });

    // A refused password change and a replay of a signed-out token are *supposed* to fail, and a
    // browser dutifully logs the 403/401 to its console. Doing those two on their own page keeps the
    // panel page's console a clean signal: anything in it there is a real problem, not a provocation.
    // its own context: its own cookies and storage, so signing out there cannot touch the panel page
    const authCtx = await browser.createBrowserContext();
    const authPage = await authCtx.newPage();
    await authPage.setViewport({ width: 1200, height: 800 });

    await check('a password change with the wrong current password is refused, and the session survives', async () => {
      const sess = await testAuth.signIn();
      await authPage.goto(`${BASE}/?t=${encodeURIComponent(sess.token)}`, { waitUntil: 'networkidle2' });
      await authPage.waitForSelector('#btnSettings', { timeout: 20000 });   // projects now live behind the ▦ switcher
      await authPage.evaluate(() => document.querySelector('#btnSettings').click());
      await authPage.waitForSelector('#btnChangePw', { visible: true, timeout: 10000 });
      await authPage.evaluate(() => {
        document.querySelector('#inPwCur').value = 'definitely-not-the-password';
        document.querySelector('#inPwNew').value = 'a-long-enough-new-one-91';
        document.querySelector('#btnChangePw').click();
      });
      await authPage.waitForFunction(() => /could not change|wrong/i.test((document.querySelector('#accessStat') || {}).innerText || ''), { timeout: 15000 });
      // a refused change must leave the session exactly as it was
      const probe = await fetch(`${BASE}/api/projects`, { headers: { 'x-gitdock-session': sess.token } });
      if (probe.status !== 200) throw new Error(`the session broke after a refused password change (HTTP ${probe.status})`);
    });

    await check('signing out really signs out: the old token stops working, even replayed in a URL', async () => {
      const sess = await testAuth.signIn();
      await authPage.goto(`${BASE}/?t=${encodeURIComponent(sess.token)}`, { waitUntil: 'networkidle2' });
      await authPage.waitForSelector('#btnLogout', { timeout: 20000 });
      await authPage.evaluate(() => document.querySelector('#btnLogout').click());
      await authPage.waitForFunction(() => location.pathname.startsWith('/login'), { timeout: 15000 });

      const replay = await fetch(`${BASE}/api/projects`, { headers: { 'x-gitdock-session': sess.token } });
      if (replay.status !== 401) throw new Error(`the signed-out token still has access (HTTP ${replay.status})`);

      // the same token handed over in the URL (the iframe sign-in path) must not re-open the panel
      await authPage.goto(`${BASE}/?t=${encodeURIComponent(sess.token)}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await authPage.waitForFunction(() => location.pathname.startsWith('/login'), { timeout: 15000 })
        .catch(() => { throw new Error('a revoked token in the URL still got into the panel'); });
      await authPage.close();
      await authCtx.close();
    });

    await check('no console errors, page errors or 5xx during the whole run', async () => {
      const problems = [...pageErrors, ...consoleErrors, ...badRequests];
      if (problems.length) throw new Error(`${problems.length} problem(s):\n      ${problems.slice(0, 6).join('\n      ')}`);
    });

  } finally {
    try { await page.screenshot({ path: path.join(SHOTS, 'last-state.png') }); } catch {}
    await browser.close();
    await fsp.rm(DL_DIR, { recursive: true, force: true }).catch(() => {});
    // don't leave a test account (or a test project) able to sign in to somebody's panel
    try {
      const list = await fetch(`${BASE}/api/projects`, { headers: { 'x-gitdock-session': (await testAuth.signIn()).token } })
        .then((r) => r.json()).catch(() => null);
      const strays = ((list && list.projects) || []).filter((p) => /^ui test/i.test(p.name));
      for (const p of strays) {
        await fetch(`${BASE}/api/projects/${p.slug}`, { method: 'DELETE', headers: { 'x-gitdock-session': (await testAuth.signIn()).token } });
      }
      if (strays.length) console.log(`${C.y}!${C.x} removed ${strays.length} leftover test project(s): ${strays.map((p) => p.slug).join(', ')}`);
    } catch {}
    testAuth.removeTestUser();
  }

  const total = pass + fail;
  console.log(`\n${C.b}${fail === 0 ? C.g + 'ALL GREEN' : C.r + 'FAILURES'}${C.x}  ${pass}/${total} UI checks passed`);
  if (failures.length) {
    console.log(`\n${C.r}failed:${C.x}`);
    for (const f of failures) console.log(`  · ${f.name}\n      ${f.error}`);
  }
  if (consoleErrors.length || pageErrors.length || badRequests.length) {
    console.log(`\n${C.y}captured problems:${C.x}`);
    for (const p of [...pageErrors, ...consoleErrors, ...badRequests].slice(0, 10)) console.log(`  · ${p}`);
  }
  process.exit(fail === 0 ? 0 : 1);
}

main().catch((e) => { console.error(`${C.r}ui test crashed:${C.x} ${e.message}\n${e.stack}`); process.exit(2); });
