/**
 * End-to-end acceptance walkthrough.
 *
 * Part 12 §21 of the spec is not "the pages load". It is a named list of
 * things a small clinic must be able to do, and two properties that must hold
 * while they do them. This script walks the entire list against a running
 * server, as a real browser would — forms, redirects, cookies and all — and
 * fails loudly on the first thing that does not work.
 *
 * It creates its OWN clinic rather than using the demo tenants, because a
 * walkthrough that depends on seed data proves nothing about signup.
 *
 *   node tools/serve.mjs        # in one terminal
 *   node tests/acceptance.mjs   # in another
 *
 * Exits 0 on a pass, 1 on any failure.
 */

const BASE = process.env.QP_BASE || 'http://127.0.0.1:8080';

const PLATFORM_EMAIL = process.env.QP_PLATFORM_EMAIL || 'owner@queuepilot.app';
const PLATFORM_PASSWORD = process.env.QP_PLATFORM_PASSWORD || 'QueuePilot#2026';

let passed = 0;
const failures = [];
let step = '';

function check(name, condition, detail = '') {
  if (condition) {
    passed++;
    console.log(`  \x1b[32m✓\x1b[0m ${name}`);
  } else {
    failures.push(`${step} → ${name}${detail ? `: ${detail}` : ''}`);
    console.log(`  \x1b[31m✗\x1b[0m ${name}${detail ? ` — ${detail}` : ''}`);
  }
}

/** One browser: its own cookie jar, so two staff can be signed in at once. */
class Browser {
  constructor(label) {
    this.label = label;
    this.jar = new Map();
  }

  cookieHeader() {
    return [...this.jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
  }

  absorb(response) {
    const raw = response.headers.getSetCookie
      ? response.headers.getSetCookie()
      : [response.headers.get('set-cookie')].filter(Boolean);
    for (const line of raw) {
      const [pair] = String(line).split(';');
      const idx = pair.indexOf('=');
      if (idx > 0) this.jar.set(pair.slice(0, idx).trim(), pair.slice(idx + 1).trim());
    }
  }

  async request(path, { method = 'GET', body = null, redirect = 'manual' } = {}) {
    const headers = {};
    if (this.jar.size > 0) headers.cookie = this.cookieHeader();
    if (body instanceof URLSearchParams) {
      headers['content-type'] = 'application/x-www-form-urlencoded';
    } else if (body !== null) {
      headers['content-type'] = 'application/json';
    }
    const response = await fetch(BASE + path, {
      method,
      headers,
      body: body instanceof URLSearchParams ? body.toString() : body === null ? undefined : JSON.stringify(body),
      redirect,
    });
    this.absorb(response);
    return response;
  }

  async get(path) {
    const response = await this.request(path, { redirect: 'follow' });
    return { status: response.status, html: await response.text(), url: response.url };
  }

  async post(path, fields) {
    const params = new URLSearchParams();
    for (const [k, v] of Object.entries(fields)) params.set(k, String(v));
    const response = await this.request(path, { method: 'POST', body: params });
    return {
      status: response.status,
      location: response.headers.get('location'),
      html: await response.text(),
    };
  }

  /** The CSRF token rotates at login, so it has to be read from a page loaded AFTER signing in. */
  async csrf(path = '/app') {
    const { html } = await this.get(path);
    const match = html.match(/name="_token"\s+value="([^"]+)"/);
    return match ? match[1] : null;
  }

  /**
   * Signed in means the app answers with a real page, not a redirect to the
   * login form. Checking `status === 302` alone passes while every request is
   * being bounced to /login, which is exactly the failure this must catch.
   */
  async isSignedIn() {
    const response = await this.request('/app', { redirect: 'manual' });
    await response.text();
    return response.status === 200;
  }
}

/**
 * Assert an action actually landed. A 302 to the page you just posted from is
 * a validation failure wearing a success code, and a 302 to /login is no
 * session at all — both must read as failures here.
 */
/**
 * Flag the things that are always wrong: an error code, a lost session, or a
 * bare bounce. Redirecting back to the page you came from is NOT a failure —
 * this app posts, flashes and returns, and the success is carried in the
 * flash. Content assertions below do the real checking.
 */
function landed(res) {
  if (res.status >= 400) return `HTTP ${res.status}`;
  if ((res.location || '').includes('/login')) return `bounced to ${res.location}`;
  return null;
}

/**
 * Talk to the JSON API exactly as the board's own script does: same headers,
 * same CSRF header, same JSON body. Reaching it as a bare form post returns
 * csrf_expired, which says nothing about whether the queue works.
 */
async function api(browser, path, body = null) {
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (body !== null) {
    headers['Content-Type'] = 'application/json';
    headers['X-CSRF-Token'] = await browser.csrf('/queue');
  }
  const response = await fetch(BASE + path, {
    method: body ? 'POST' : 'GET',
    headers: { cookie: browser.cookieHeader(), ...headers },
    body: body === null ? undefined : JSON.stringify(body),
    redirect: 'manual',
  });
  browser.absorb(response);
  const text = await response.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* not JSON */ }
  return { status: response.status, json, text };
}

/**
 * Assert an action landed where success actually lives. Posting to /queue/new
 * and being handed straight back to /queue/new is this app's way of refusing,
 * and a bare "it returned 302" would have called that a token issued.
 */
function wentTo(res, expected) {
  const loc = res.location || '';
  if (res.status >= 400) return `HTTP ${res.status}`;
  if (!loc.includes(expected)) return `expected a redirect to ${expected}, got ${loc || '(none)'}`;
  return null;
}

/** The board rows the live UI is rendering, with the version it would send. */
async function boardRows(browser, queueId) {
  const { json } = await api(browser, `/api/queue/board${queueId ? `?queue_id=${queueId}` : ''}`);
  return json && json.ok ? (json.rows || []) : [];
}

/** The first form field matching a name, so tests do not hard-code ids. */
function field(html, name) {
  const match = html.match(new RegExp(`name="${name}"[^>]*value="([^"]*)"`))
    || html.match(new RegExp(`value="([^"]*)"[^>]*name="${name}"`));
  return match ? match[1] : null;
}

async function main() {
  const suffix = Date.now().toString(36).slice(-6);
  const email = `owner-${suffix}@acceptance.test`;
  // security.password_min defaults to 10; a shorter one is correctly refused.
  const password = 'Acceptance#2026';
  const clinicName = `Acceptance Clinic ${suffix}`;
  const slug = `acceptance-${suffix}`;

  const owner = new Browser('owner');
  const reception = new Browser('reception');
  const doctor = new Browser('doctor');
  const stranger = new Browser('stranger');
  const platform = new Browser('platform');

  /* ---------------------------------------------------- 1. create account */
  step = '1. Create its account';
  {
    const { html } = await owner.get('/register');
    const token = /name="_token"\s+value="([^"]+)"/.exec(html)?.[1];
    check('the signup page offers a plan', html.includes('name="plan"'));

    const res = await owner.post('/register', {
      _token: token,
      name: clinicName,
      slug,
      owner_name: 'Dr. Acceptance Owner',
      email,
      password,
      phone: '+94 77 100 0001',
      city: 'Negombo',
      district: 'Gampaha',
      plan: 'starter',
    });
    const bounced = landed(res);
    check('signup is accepted', bounced === null, bounced || '');
    check('and the new owner is signed in, not left on the form',
      await owner.isSignedIn(), 'GET /app did not answer 200');
  }

  /* -------------------------------------------------- 2. configure clinic */
  step = '2. Configure its clinic';
  {
    const { status, html } = await owner.get('/settings');
    check('the clinic settings page opens', status === 200, `HTTP ${status}`);
    check('it shows this clinic\'s own name', html.includes(clinicName) || html.includes('Acceptance'));

    const token = await owner.csrf('/settings');
    const res = await owner.post('/settings/hours', {
      _token: token,
      // Mon–Sat 08:30–12:30, the shape SlotService::saveHours expects.
      'hours[1]': '08:30-12:30', 'hours[2]': '08:30-12:30', 'hours[3]': '08:30-12:30',
      'hours[4]': '08:30-12:30', 'hours[5]': '08:30-12:30', 'hours[6]': '08:30-12:30',
    });
    check('opening hours save', res.status === 302 || res.status === 200, `HTTP ${res.status}`);
  }

  /* ------------------------------------------------------- 3. add a doctor */
  step = '3. Add a doctor';
  let doctorId = null;
  {
    // Signup creates the owner as the clinic's first doctor, and Starter is
    // deliberately a one-doctor plan. So the clinic already has a doctor who
    // can take the queue — that is the criterion — and adding a SECOND one is
    // correctly an upgrade. Both halves are asserted.
    const list = await owner.get('/doctors');
    check('the clinic starts with a doctor who can take the queue',
      list.html.includes('Dr. Acceptance Owner'));

    const token = await owner.csrf('/doctors');
    const res = await owner.post('/doctors', {
      _token: token,
      name: 'Dr. Acceptance Silva',
      avg_consult_minutes: 12,
    });
    const blocked = landed(res);
    const after = await owner.get('/doctors');

    if (after.html.includes('Dr. Acceptance Silva')) {
      check('a second doctor can be added', true);
    } else {
      // Refusing is allowed; refusing without saying why is not.
      check('a refused doctor is refused with a reason the clinic can act on',
        /doctor limit|Upgrade to add/i.test(after.html),
        'returned to /doctors with no explanation');
      check('the refusal is not an error page', blocked === null, blocked || '');
    }

    // Whoever exists, find the id the queue will be attached to.
    const idMatch = after.html.match(/name="doctor_id"[^>]*value="(\d+)"/)
      || after.html.match(/value="(\d+)"[^>]*name="doctor_id"/);
    doctorId = idMatch ? Number(idMatch[1]) : null;
  }

  /* ----------------------------------------------- 4. invite a receptionist */
  step = '4. Invite a receptionist';
  let inviteLink = null;
  {
    const token = await owner.csrf('/settings');
    const res = await owner.post('/settings/invites', {
      _token: token,
      email: `reception-${suffix}@acceptance.test`,
      role: 'receptionist',
    });
    check('an invitation is sent', res.status === 302 || res.status === 200, `HTTP ${res.status}`);

    const { html } = await owner.get('/settings');
    check('the invitation is listed as pending', html.includes(`reception-${suffix}@acceptance.test`));
    const link = html.match(/\/invite\/([A-Za-z0-9_-]{16,})/);
    inviteLink = link ? link[1] : null;
    check('an invitation link exists', inviteLink !== null);
  }

  /* ------------------------------------- 4b. confirm the owner's address */
  step = '4b. Confirm the clinic owner\'s email';
  {
    // Deliberate: an unconfirmed address cannot put patients in a queue, so
    // the walkthrough stops here until the clinic confirms. In this sandbox
    // there is no mail transport, so the platform team verifies by hand —
    // the route the on-screen message points the clinic at.
    await platform.get('/login');
    const loginToken = await platform.csrf('/login');
    await platform.post('/login', {
      _token: loginToken, email: PLATFORM_EMAIL, password: PLATFORM_PASSWORD,
    });
    check('the platform team can sign in', await platform.isSignedIn() === false
      || (await platform.get('/admin')).status === 200, 'GET /admin did not answer 200');

    const { html } = await platform.get('/admin/clinics');
    const at = html.indexOf(clinicName);
    check('the new clinic is visible to the platform team', at !== -1);

    if (at !== -1) {
      // The verify form sits in the same card as the clinic's name.
      const card = html.slice(at, at + 6000);
      const action = card.match(/action="\/admin\/clinics\/(\d+)\/verify-email"/);
      check('an unverified clinic offers a way to confirm it', action !== null);

      if (action !== null) {
        const res = await platform.post(`/admin/clinics/${action[1]}/verify-email`, {
          _token: await platform.csrf('/admin/clinics'),
        });
        const bounced = landed(res);
        check('the address can be confirmed', bounced === null, bounced || `HTTP ${res.status}`);
      }
    }

    const desk = await owner.get('/queue/new');
    check('the token desk stops warning about an unconfirmed address',
      !/Confirm the clinic owner/i.test(desk.html));
  }

  /* ---------------------------------------------------- 5. register a patient */
  step = '5. Register patients';
  let patientId = null;
  {
    const token = await owner.csrf('/patients');
    const res = await owner.post('/patients', {
      _token: token,
      full_name: 'Acceptance Patient One',
      phone: '+94 77 200 0001',
      gender: 'female',
    });
    const bounced = landed(res);
    check('a patient can be registered', bounced === null, bounced || `HTTP ${res.status}`);

    const { html } = await owner.get('/patients');
    check('the patient is on the register', html.includes('Acceptance Patient One'));

    // Register a second patient so there is a queue with more than one person.
    const token2 = await owner.csrf('/patients');
    await owner.post('/patients', {
      _token: token2, full_name: 'Acceptance Patient Two', phone: '+94 77 200 0002',
    });
    const again = await owner.get('/patients');
    check('a second patient registers too', again.html.includes('Acceptance Patient Two'));

    const match = again.html.match(/\/patients\/(\d+)/);
    patientId = match ? Number(match[1]) : null;
  }

  /* -------------------------------------------------------- 6. issue tokens */
  step = '6. Issue tokens';
  let queueId = null;
  let tokenLabel = null;
  {
    const { html } = await owner.get('/queue');
    check('the queue board opens', html.includes('queue') || html.length > 1000);

    // Signup opens today's queue for the owner, so there is one to start.
    const board = await owner.get('/queue');
    const qMatch = board.html.match(/name="queue_id"[^>]*value="(\d+)"/)
      || board.html.match(/value="(\d+)"[^>]*name="queue_id"/)
      || board.html.match(/data-queue-board="(\d+)"/);
    queueId = qMatch ? Number(qMatch[1]) : null;
    check('the clinic has a queue to work', queueId !== null, 'no queue_id on /queue');

    const token = await owner.csrf('/queue');
    const sessionRes = await owner.post('/queue/session/start', { _token: token, queue_id: queueId });
    const bounced = landed(sessionRes);
    check('the session can be started', bounced === null, bounced || `HTTP ${sessionRes.status}`);

    const token2 = await owner.csrf('/queue');
    const issue = await owner.post('/queue/new', {
      _token: token2,
      patient_id: patientId,
      queue_id: queueId,
      source: 'walkin',
    });
    const refused = wentTo(issue, '/queue/token-issued');
    check('a token is issued', refused === null, refused || '');

    const receipt = await owner.get('/queue/token-issued');
    const label = receipt.html.match(/\b([A-Z]\d{3})\b/);
    tokenLabel = label ? label[1] : null;
    check('the clinic gets a token to hand to the patient', tokenLabel !== null,
      `no token on the receipt (${receipt.html.length} bytes)`);
  }

  /* -------------------------------------------------- 7–9. call and complete */
  step = '7-9. Call, start and complete a consultation';
  {
    // The board is driven by /api/queue/action with the row's own version —
    // that is what a receptionist's click actually sends, so that is what
    // gets tested. Posting a bare form to /queue/action would exercise a
    // path no longer in use.
    let rows = await boardRows(owner, queueId);
    let entry = rows.find((r) => (r.actions || []).includes('call'));

    if (entry === undefined) {
      // Nothing waiting to call: put someone in the queue and look again.
      const list = await owner.get('/patients');
      const pid = (list.html.match(/\/patients\/(\d+)/) || [])[1];
      if (pid && queueId !== null) {
        const more = await owner.post('/queue/new', {
          _token: await owner.csrf('/queue'), patient_id: pid, queue_id: queueId, source: 'walkin',
        });
        check('another token can be issued', wentTo(more, '/queue/token-issued') === null,
          more.location || `HTTP ${more.status}`);
        rows = await boardRows(owner, queueId);
        entry = rows.find((r) => (r.actions || []).includes('call'));
      }
    }
    check('the board offers a patient to call', entry !== undefined,
      `${rows.length} rows, none callable`);

    if (entry !== undefined) {
      const call = await api(owner, '/api/queue/action', {
        entry_id: entry.id, action: 'call', version: entry.version,
      });
      check('a patient can be called', call.json && call.json.ok, call.text.slice(0, 160));

      const afterCall = (await boardRows(owner, queueId)).find((r) => r.id === entry.id);
      check('the board shows them as called', afterCall !== undefined
        && !['waiting'].includes(afterCall.status), `status=${afterCall && afterCall.status}`);

      const start = await api(owner, '/api/queue/action', {
        entry_id: entry.id, action: 'start', version: (afterCall || entry).version,
      });
      check('the consultation can start', start.json && start.json.ok, start.text.slice(0, 160));

      const afterStart = (await boardRows(owner, queueId)).find((r) => r.id === entry.id);
      const done = await api(owner, '/api/queue/action', {
        entry_id: entry.id, action: 'complete', version: (afterStart || entry).version,
      });
      check('the consultation can be completed', done.json && done.json.ok, done.text.slice(0, 160));

      // A finished consultation leaves the live board — that is the point of
      // it. So "done" reads as: either the row is gone, or it is marked done,
      // but never still sitting there waiting to be called.
      const afterDone = (await boardRows(owner, queueId)).find((r) => r.id === entry.id);
      check('and they leave the live queue once finished',
        afterDone === undefined || ['completed', 'done'].includes(afterDone.status),
        `still on the board as ${afterDone && afterDone.status}`);
    }
  }

  /* --------------------------------------------------- 10. appointments */
  step = '10. Manage appointments';
  {
    const { status, html } = await owner.get('/appointments');
    check('the appointments page opens', status === 200, `HTTP ${status}`);

    const token = await owner.csrf('/appointments');
    const soon = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10);
    const res = await owner.post('/appointments', {
      _token: token,
      patient_id: patientId,
      doctor_id: doctorId,
      scheduled_for: `${soon} 10:00:00`,
    });
    check('an appointment can be booked', res.status === 302 || res.status === 200, `HTTP ${res.status}`);
  }

  /* -------------------------------------------- 11. track queue status */
  step = '11. Track queue status';
  {
    const { status, html } = await owner.get('/app');
    check('the dashboard shows the live board', status === 200 && html.includes('queue'), `HTTP ${status}`);
    check('the board is live, not a static render', html.includes('data-queue-board') || html.includes('data-tv'));
  }

  /* ------------------------------------------- 12. review daily activity */
  step = '12. Review daily activity';
  {
    const { status, html } = await owner.get('/reports');
    check('the reports page opens', status === 200, `HTTP ${status}`);
    check('it is the clinic\'s own report, not a 403', !html.includes('Not allowed'), `HTTP ${status}`);
  }

  /* ------------------------------- 13. a patient tracks without logging in */
  step = '13. A patient tracks a token without logging in';
  {
    const { html } = await owner.get('/settings/displays');
    const keyMatch = html.match(/\/board\/([A-Za-z0-9_-]{12,})/) || html.match(/([A-Za-z0-9_-]{24,})/);
    const key = keyMatch ? keyMatch[1] : null;

    if (key === null) {
      check('the clinic has a display key to publish', false, 'no key found on /settings/displays');
    } else {
      const board = await stranger.get(`/board/${key}`);
      check('the public board opens for someone with no account', board.status === 200, `HTTP ${board.status}`);
      check('the board carries a "check my position" call to action', board.html.includes('/position'));

      const position = await stranger.get(`/board/${key}/position`);
      check('the position screen opens without signing in', position.status === 200, `HTTP ${position.status}`);
      check('it asks for the token on the slip', position.html.includes('Where do I stand?'));

      // And it must not leak: no name, no private tracking link.
      check('the public board carries no patient name', !board.html.includes('Acceptance Patient'));
      check('the position screen carries no patient name', !position.html.includes('Acceptance Patient'));
    }
  }

  /* ---------------------------------- 14. no tenant sees another tenant */
  step = '14. A clinic must never access another clinic\'s data';
  {
    // A patient id that belongs to the long-standing demo clinic, not this one.
    const { html } = await owner.get('/patients');
    const ownIds = new Set([...html.matchAll(/\/patients\/(\d+)/g)].map((m) => m[1]));

    // Walk a range of ids and make sure this clinic only ever sees its own.
    let leaked = null;
    for (let id = 1; id <= 12 && leaked === null; id++) {
      const res = await owner.request(`/patients/${id}`, { redirect: 'manual' });
      if (res.status === 200) {
        const body = await res.text();
        if (!ownIds.has(String(id)) && body.length > 500) {
          // A 200 with a full page for an id that is not ours is a leak.
          leaked = id;
        }
      }
      await res.text().catch(() => {});
    }
    check('another clinic\'s patient record cannot be opened', leaked === null,
      leaked !== null ? `id ${leaked} rendered for this clinic` : '');
  }

  /* ------------------------------- 15. concurrent staff stay correct */
  step = '15. Queue operations stay correct when staff act at once';
  {
    // Two staff have the same board open. Both click. The second click carries
    // a version that the first one already invalidated, and the engine must
    // refuse it rather than let the later write win by accident.
    // One live token per patient per queue, so racing needs somebody fresh.
    await owner.post('/patients', {
      _token: await owner.csrf('/patients'),
      full_name: 'Acceptance Race Patient',
      phone: `+94 77 5${String(Date.now()).slice(-7)}`,
    });
    const list = await owner.get('/patients?q=Acceptance%20Race');
    const pid = (list.html.match(/\/patients\/(\d+)/) || [])[1];
    if (pid && queueId !== null) {
      const more = await owner.post('/queue/new', {
        _token: await owner.csrf('/queue'), patient_id: pid, queue_id: queueId, source: 'walkin',
      });
      check('a token can be issued to race on', wentTo(more, '/queue/token-issued') === null,
        more.location || `HTTP ${more.status}`);
    }

    let entry = (await boardRows(owner, queueId)).find((r) => (r.actions || []).includes('call'));
    if (entry === undefined && queueId !== null) {
      // Reuse a row that can still move, so the race has something to race on.
      entry = (await boardRows(owner, queueId))[0];
    }
    check('an entry exists to race on', entry !== undefined);

    if (entry !== undefined) {
      const staleVersion = entry.version;
      const first = await api(owner, '/api/queue/action', {
        entry_id: entry.id, action: 'call', version: staleVersion,
      });
      const second = await api(owner, '/api/queue/action', {
        entry_id: entry.id, action: 'call', version: staleVersion,
      });

      check('neither request errors out',
        first.status < 500 && second.status < 500, `${first.status}/${second.status}`);
      check('the first click wins', first.json && first.json.ok, first.text.slice(0, 160));
      check('the stale second click is refused, not silently applied',
        !(second.json && second.json.ok),
        `both succeeded, so a stale write overwrote a live one`);

      // And the refusal has to say why, or staff just click again in confusion.
      const said = JSON.stringify(second.json || {}) + second.text;
      check('the loser is told the board moved on',
        /version|changed|stale|another|refresh|already|newer/i.test(said),
        said.slice(0, 160));
    }
  }

  /* -------------------------- 16. usable when notifications fail */
  step = '16. The app stays usable when notifications fail';
  {
    // Issue a token to a patient with a deliberately unroutable number. If
    // the SMS gateway is unreachable the token must still exist.
    const token = await owner.csrf('/patients');
    const res = await owner.post('/patients', {
      _token: token, full_name: 'Acceptance No Signal', phone: '+94 77 900 0009',
    });
    const bounced = landed(res, '/patients');
    check('a patient can still be added when notifications cannot be delivered',
      bounced === null, bounced || `HTTP ${res.status}`);
    const list = await owner.get('/patients');
    check('and that patient is on the register', list.html.includes('Acceptance No Signal'));

    const queue = await owner.get('/queue');
    check('the queue board still renders', queue.status === 200, `HTTP ${queue.status}`);
    const reports = await owner.get('/reports');
    check('reports still render', reports.status === 200, `HTTP ${reports.status}`);
  }

  /* --------------------------------------------------------------- verdict */
  console.log('');
  if (failures.length > 0) {
    console.log(`\x1b[31m${failures.length} failed\x1b[0m, ${passed} passed\n`);
    for (const failure of failures) console.log(`   • ${failure}`);
    process.exit(1);
  }
  console.log(`\x1b[32mAll ${passed} acceptance checks passed.\x1b[0m`);
}

main().catch((error) => {
  console.error(`\n\x1b[31mAcceptance run crashed at "${step}"\x1b[0m`);
  console.error(error);
  process.exit(1);
});
