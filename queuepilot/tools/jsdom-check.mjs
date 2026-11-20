/**
 * Browser-level smoke test.
 *
 * Every other check reads the app over HTTP, which cannot tell whether the
 * JavaScript runs. Three features were dead for exactly that reason: the CSP
 * refused them and nothing logged an error, so the pages fetched fine and
 * looked finished. This loads real page HTML in a DOM, executes the real
 * app.js against it, and asserts the features actually bind.
 *
 *   node tools/jsdom-check.mjs
 */
import { JSDOM, VirtualConsole } from 'jsdom';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = process.env.QP_BASE ?? 'http://127.0.0.1:8080';
const appJs = readFileSync(new URL('../public/assets/js/app.js', import.meta.url), 'utf8');

/* The desk only renders results for a signed-in clinic, so the run logs in
 * rather than depending on a cookie somebody remembered to export. These are
 * the seeded demo credentials, which tools/seed.php already prints; override
 * with QP_EMAIL / QP_PASSWORD against a real database. */
const ACCOUNT = {
    email: process.env.QP_EMAIL ?? 'admin@serenity.lk',
    password: process.env.QP_PASSWORD ?? 'Serenity#2026',
};
let cookie = '';

async function login() {
    await fetch(BASE + '/login', { redirect: 'manual' });
    // POST /login declares csrf => false: the form is the first thing an
    // unauthenticated visitor sees, so there is no session to protect yet.
    const res = await fetch(BASE + '/login', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(ACCOUNT),
        redirect: 'manual',
    });
    return (res.headers.getSetCookie?.() ?? []).map((c) => c.split(';')[0]).join('; ');
}

/* Fixtures. The key is minted by a PHP helper because the checker needs a
 * real board URL, and it is removed again at the end of the run. */
const probeOut = execFileSync(
    process.execPath,
    ['tools/php.mjs', 'tools/jsdom-probe.php'],
    { encoding: 'utf8' }
);
const probe = JSON.parse(probeOut.trim().split('\n').pop());
const releaseProbe = () => {
    try {
        execFileSync(process.execPath, ['tools/php.mjs', 'tools/jsdom-probe.php', '--cleanup'], { stdio: 'ignore' });
    } catch { /* the next run will find it; nothing here depends on it */ }
};

let pass = 0;
const fails = [];
function ok(cond, label) {
    if (cond) { pass++; console.log(`  \x1b[32m✓\x1b[0m ${label}`); }
    else { fails.push(label); console.log(`  \x1b[31m✗\x1b[0m ${label}`); }
}

async function fetchPage(path) {
    const r = await fetch(BASE + path, { headers: cookie ? { cookie } : {} });
    return { status: r.status, html: await r.text() };
}

/**
 * Load a page and run the real app.js inside it.
 * Returns the window plus anything the script threw.
 */
function boot(html, { intervals = [] } = {}) {
    const errors = [];
    const vc = new VirtualConsole();
    vc.on('jsdomError', (e) => errors.push(String(e.message).split('\n')[0]));
    vc.on('error', (...a) => errors.push(a.map(String).join(' ').split('\n')[0]));

    const dom = new JSDOM(html, {
        runScripts: 'dangerously',
        pretendToBeVisual: true,
        url: BASE + '/',
        virtualConsole: vc,
        beforeParse(win) {
            // The DOM has no network. Every fetch is recorded, never sent, so
            // this asserts the code *runs* rather than that it got a 200.
            win.fetch = () => Promise.resolve({
                ok: true, status: 200,
                json: () => Promise.resolve({ ok: true, serving: [], upcoming: [], solo: null, rooms: [] }),
            });
            const realSetInterval = win.setInterval.bind(win);
            win.setInterval = (fn, ms, ...rest) => {
                intervals.push({ ms, src: String(fn) });
                return realSetInterval(() => {}, 1e9); // never actually fire
            };
            win.matchMedia = win.matchMedia || (() => ({ matches: false, addEventListener() {}, addListener() {} }));
        },
    });
    return { dom, errors };
}

function runAppJs(dom) {
    const s = dom.window.document.createElement('script');
    s.textContent = appJs;
    dom.window.document.body.appendChild(s);
}

/* ------------------------------------------------------------------ */

console.log('\n\x1b[1mBrowser-level checks (app.js executing against real page DOM)\x1b[0m\n');

cookie = await login();
if (!cookie) {
    console.log('\x1b[31mCould not sign in as ' + ACCOUNT.email + ' — is the server running?\x1b[0m\n');
    process.exit(1);
}

// 1. app.js must run on every page without throwing.
for (const path of ['/app', '/settings', '/queue/new', '/patients', '/reports']) {
    const { status, html } = await fetchPage(path);
    if (status !== 200) { ok(false, `${path} fetched 200 (got ${status})`); continue; }
    const { dom, errors } = boot(html);
    runAppJs(dom);
    ok(errors.length === 0, `${path} runs app.js with no errors${errors.length ? ': ' + errors[0] : ''}`);
    dom.window.close();
}

// 2. The phone board: its only update path is setInterval(fetchBoard, 6000),
//    which sat inside a blocked inline script. It must now be scheduled.
{
    const { html } = await fetchPage(`/live/${probe.key}`);
    const intervals = [];
    const { dom, errors } = boot(html, { intervals });
    runAppJs(dom);
    ok(errors.length === 0, '/live runs app.js with no errors' + (errors.length ? ': ' + errors[0] : ''));
    const poller = intervals.find((i) => i.src.includes('fetchBoard'));
    ok(!!poller, 'the phone board schedules its poll');
    ok(poller?.ms === 6000, `and polls every 6s (got ${poller?.ms})`);
    dom.window.close();
}

// 3. The token desk: pick() was never bound, so the desk found a patient and
//    then created a duplicate. Clicking a result must select it.
{
    const { html } = await fetchPage(`/queue/new?q=${encodeURIComponent(probe.phone)}`);
    const { dom, errors } = boot(html);
    runAppJs(dom);
    ok(errors.length === 0, '/queue/new runs app.js with no errors' + (errors.length ? ': ' + errors[0] : ''));

    const doc = dom.window.document;
    const btns = [...doc.querySelectorAll('[data-pick-patient]')];
    ok(btns.length > 0, `search found the patient and rendered ${btns.length} pick button(s)`);

    if (btns.length) {
        const hidden = doc.getElementById('new-patient-fields');
        const picked = doc.getElementById('picked-patient');
        const wasHidden = hidden?.classList.contains('hidden');
        btns[0].dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        ok(doc.getElementById('patient_id')?.value === btns[0].dataset.pickPatient,
            'clicking a result fills the hidden patient id');
        ok(picked && !picked.classList.contains('hidden'), 'and reveals the picked-patient panel');
        ok(wasHidden === false || hidden.classList.contains('hidden'),
            'and collapses the new-patient fields so a duplicate cannot be typed over it');
    }
    dom.window.close();
}

// 4. The TV speech toggle was visible and inert.
{
    const { html } = await fetchPage(`/board/${probe.key}`);
    const { dom, errors } = boot(html);
    runAppJs(dom);
    ok(errors.length === 0, '/board runs app.js with no errors' + (errors.length ? ': ' + errors[0] : ''));

    const btn = dom.window.document.getElementById('tv-speech');
    if (!btn) { ok(false, 'the TV speech button exists'); }
    else {
        const before = btn.textContent;
        btn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        ok(btn.textContent !== before, `the speech toggle responds ("${before}" → "${btn.textContent}")`);
    }
    dom.window.close();
}

/* ------------------------------------------------------------------ */
console.log('');
releaseProbe();
if (fails.length) {
    console.log(`\x1b[31m${fails.length} of ${pass + fails.length} browser checks failed:\x1b[0m`);
    for (const f of fails) console.log('   • ' + f);
    process.exit(1);
}
console.log(`\x1b[32mAll ${pass} browser checks passed.\x1b[0m\n`);
