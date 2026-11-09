/**
 * QueuePilot design preview — the screens.
 *
 * These are the designed screens from docs/screen-inventory.md, rendered with
 * real tokens and realistic data so the visual identity can be reviewed and
 * approved before Phase 1 begins. There is no backend; nothing persists.
 */

import {
  shell, page, card, statRow, button, chip, token, banner, emptyState,
  table, kv, field, input, select, live,
} from './ui.mjs';
import { icon } from './icons.mjs';

const CLINIC = 'Serenity OPD & Family Clinic';
const SUB = 'Colombo · Today';

/* ------------------------------------------------------------------ navs -- */

const NAV = {
  receptionist: [
    { items: [
      { href: '/receptionist', label: 'Overview', icon: 'home', active: true },
      { href: '/queue', label: 'Queue', icon: 'users', count: 4 },
      { href: '/receptionist', label: 'Appointments', icon: 'calendar', count: 9 },
      { href: '/receptionist', label: 'Patients', icon: 'search' },
    ]},
  ],
  doctor: [
    { items: [
      { href: '/doctor', label: "Today's queue", icon: 'users', active: true },
      { href: '/doctor', label: 'My schedule', icon: 'calendar' },
      { href: '/doctor', label: 'My patients', icon: 'search' },
      { href: '/doctor', label: 'My profile', icon: 'settings' },
    ]},
  ],
  owner: [
    { label: 'Daily', items: [
      { href: '/owner', label: 'Overview', icon: 'home', active: true },
      { href: '/owner', label: 'Appointments', icon: 'calendar' },
      { href: '/owner', label: 'Patients', icon: 'users' },
      { href: '/doctor', label: 'Doctors & rooms', icon: 'stethoscope' },
    ]},
    { label: 'Insights', items: [
      { href: '/owner', label: 'Reports', icon: 'chart' },
      { href: '/owner', label: 'Audit log', icon: 'log' },
    ]},
    { label: 'Manage', items: [
      { href: '/owner', label: 'Team', icon: 'userplus' },
      { href: '/owner', label: 'Clinic settings', icon: 'settings' },
      { href: '/owner', label: 'Subscription', icon: 'creditcard' },
    ]},
  ],
  admin: [
    { label: 'Platform', items: [
      { href: '/admin', label: 'Overview', icon: 'home', active: true },
      { href: '/admin', label: 'Clinics', icon: 'building', count: 128 },
      { href: '/admin', label: 'Organisations', icon: 'branch', count: 11 },
    ]},
    { label: 'Commercial', items: [
      { href: '/admin', label: 'Plans', icon: 'creditcard' },
      { href: '/admin', label: 'Feature gates', icon: 'sliders' },
      { href: '/admin', label: 'Payments', icon: 'filetext', count: 6 },
    ]},
    { label: 'Operations', items: [
      { href: '/admin', label: 'Support', icon: 'lifebuoy', count: 4 },
      { href: '/admin', label: 'System health', icon: 'activity' },
      { href: '/admin', label: 'Incidents', icon: 'alert' },
    ]},
  ],
};

/* -------------------------------------------------------------- homepage -- */

export function home() {
  const body = `
  <header style="background:var(--qp-surface);border-bottom:1px solid var(--qp-border)">
    <div style="max-width:var(--qp-content-max);margin:0 auto;padding:var(--qp-space-4) var(--qp-content-pad);
                display:flex;align-items:center;justify-content:space-between;gap:var(--qp-space-4)">
      <div class="qp-flex"><div class="qp-logo">QP</div><strong style="font-size:var(--qp-text-md)">QueuePilot</strong></div>
      <div class="qp-flex">${button('Sign in', { href: '/login', variant: 'ghost' })}${button('Start a clinic', { href: '/login', variant: 'primary' })}</div>
    </div>
  </header>

  <section style="max-width:var(--qp-content-max);margin:0 auto;padding:var(--qp-space-16) var(--qp-content-pad);
                  display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:var(--qp-space-12);align-items:center">
    <div>
      <div class="qp-chip tint-primary" style="margin-bottom:var(--qp-space-5)">Simple queues. Better clinic experiences.</div>
      <h1 style="font-size:38px;line-height:1.15;font-weight:700;letter-spacing:-0.02em;margin:0">
        Run your clinic's day<br>without the chaos.
      </h1>
      <p style="font-size:var(--qp-text-md);color:var(--qp-ink-600);margin-top:var(--qp-space-5);max-width:46ch">
        Register a patient in seconds. Issue a token. Let the doctor call the next
        one. Show the queue on the waiting-room screen and on every patient's own
        phone — no app, no account.
      </p>
      <div class="qp-flex qp-mt">${button('See the receptionist board', { href: '/receptionist', variant: 'primary', size: 'lg', icon: 'arrowright' })}${button('Patient tracking', { href: '/patient', size: 'lg' })}</div>
      <div class="qp-flex qp-mt" style="gap:var(--qp-space-6);font-size:var(--qp-text-sm);color:var(--qp-ink-500)">
        <span class="qp-flex" style="gap:6px">${icon('check')} No training needed</span>
        <span class="qp-flex" style="gap:6px">${icon('check')} Works offline</span>
        <span class="qp-flex" style="gap:6px">${icon('check')} Patients need no login</span>
      </div>
    </div>
    <div class="qp-card" style="box-shadow:var(--qp-shadow-md)">
      <div class="qp-card__head"><div class="qp-card__title">Live queue · right now</div>${live('live', 'Live')}</div>
      <div class="qp-card__body qp-card__body--flush">
        <div class="qp-rows">
          ${[
            ['A012', 'Anura Dissanayake', 'With doctor', 'success', 'Room 2'],
            ['A013', 'Kamala Ratnayake', 'Called — please proceed', 'primary', 'Room 2'],
            ['B004', 'Suresh Mendis', 'Waiting', 'warning', '—'],
            ['A014', 'Nimali Fernando', 'Waiting', 'warning', '—'],
          ].map(([t, n, s, tone, room]) => `
            <div class="qp-row">
              ${token(t)}
              <div class="qp-row__main"><div class="qp-row__title">${n}</div></div>
              <span class="qp-muted" style="font-size:var(--qp-text-xs)">${room}</span>
              ${chip(s, tone)}
            </div>`).join('')}
        </div>
      </div>
    </div>
  </section>

  <section style="border-top:1px solid var(--qp-border);background:var(--qp-surface)">
    <div style="max-width:var(--qp-content-max);margin:0 auto;padding:var(--qp-space-12) var(--qp-content-pad)">
      <h2 style="font-size:var(--qp-text-xl);font-weight:700;margin:0 0 var(--qp-space-8)">Five roles. Five clear jobs.</h2>
      <div class="qp-grid qp-grid--3">
        ${[
          ['search', 'Receptionist', 'Register, find, issue a token. Four screens, nothing else.', '/receptionist'],
          ['stethoscope', 'Doctor', 'Start the session, call the next patient, complete. One big button.', '/doctor'],
          ['building', 'Clinic owner', 'Doctors, rooms, schedules, reports, billing — all behind Settings.', '/owner'],
          ['phone', 'Patient', 'Book, check in, track the queue from a phone. No account.', '/patient'],
          ['shield', 'Platform admin', 'Clinics, plans, payments, health. A separate console entirely.', '/admin'],
          ['monitor', 'Waiting-room TV', 'Big tokens, big names. Readable from five metres.', '/display'],
        ].map(([ic, t, d, href]) => `
          <a class="qp-card" href="${href}" style="display:block;color:inherit">
            <div class="qp-card__body">
              <div class="qp-stat__icon tint-primary" style="margin-bottom:var(--qp-space-4)">${icon(ic)}</div>
              <div style="font-weight:600;font-size:var(--qp-text-md)">${t}</div>
              <div class="qp-muted" style="margin-top:var(--qp-space-1)">${d}</div>
            </div>
          </a>`).join('')}
      </div>
    </div>
  </section>`;

  return page('QueuePilot', `<div>${body}</div>`);
}

/* ----------------------------------------------------------------- login -- */

export function login() {
  const body = `
  <div style="min-height:100vh;display:grid;place-items:center;padding:var(--qp-space-6)">
    <div style="width:100%;max-width:400px">
      <div class="qp-flex" style="justify-content:center;margin-bottom:var(--qp-space-8)">
        <div class="qp-logo">QP</div><strong style="font-size:var(--qp-text-lg)">QueuePilot</strong>
      </div>
      <div class="qp-card" style="box-shadow:var(--qp-shadow-md)">
        <div class="qp-card__body">
          <h1 class="qp-page-title" style="font-size:var(--qp-text-lg)">Sign in</h1>
          <p class="qp-muted" style="font-size:var(--qp-text-sm);margin-top:4px">Use your clinic account.</p>
          <div style="margin-top:var(--qp-space-6)">
            ${field({ label: 'Email', id: 'email', control: input({ id: 'email', value: 'reception@serenity.lk', type: 'email' }) })}
            ${field({ label: 'Password', id: 'pw', control: input({ id: 'pw', value: '••••••••••', type: 'password' }) })}
            <label class="qp-check qp-mt-sm"><input type="checkbox" checked> Keep me signed in</label>
            <div class="qp-mt">${button('Sign in', { variant: 'primary', size: 'lg' })}</div>
          </div>
        </div>
      </div>
      <div class="qp-between qp-mt">
        <a href="#" style="font-size:var(--qp-text-sm)">Forgot password?</a>
        <a href="#" style="font-size:var(--qp-text-sm)">Create a clinic account</a>
      </div>
    </div>
  </div>`;
  return page('Sign in', body);
}

/* ---------------------------------------------------------- receptionist -- */

export function receptionist() {
  const stats = statRow([
    { label: 'Waiting now', value: '4', hint: 'patients', icon: 'users', tint: 'primary' },
    { label: 'In consultation', value: '1', hint: 'with doctor', icon: 'stethoscope', tint: 'primary' },
    { label: 'Completed today', value: '23', hint: 'patients', icon: 'checkcheck', tint: 'success' },
    { label: 'Average wait', value: '18m', hint: 'today', icon: 'clock', tint: 'warning' },
    { label: 'Longest wait', value: '1h 20m', hint: 'today', icon: 'flame', tint: 'warning' },
  ]);

  const inConsult = `
    <div class="qp-banner qp-banner--blue" style="border-radius:var(--qp-radius-lg)">
      ${icon('stethoscope')}
      <div class="qp-grow">
        <div class="qp-flex" style="gap:var(--qp-space-2)">
          ${chip('With doctor', 'primary')}
          ${token('A012', true)}
        </div>
        <div style="font-weight:600;font-size:var(--qp-text-md);margin-top:var(--qp-space-2)">Anura Dissanayake</div>
        <div class="qp-muted" style="font-size:var(--qp-text-xs);margin-top:2px">34y · Male · Room 2 · started 3:18 PM · 12m elapsed</div>
        <div class="qp-flex qp-mt-sm">
          ${button('Complete consultation', { variant: 'primary', size: 'sm', icon: 'check' })}
          ${button('Transfer', { size: 'sm' })}${button('Cancel', { size: 'sm' })}
        </div>
      </div>
    </div>`;

  const nextUp = `
    <div class="qp-banner qp-banner--amber" style="border-radius:var(--qp-radius-lg)">
      ${icon('zap')}
      <div class="qp-grow">
        <div class="qp-flex" style="gap:var(--qp-space-2)">
          ${chip('Called — please proceed', 'warning')}${token('A013', true)}
        </div>
        <div style="font-weight:600;font-size:var(--qp-text-md);margin-top:var(--qp-space-2)">Kamala Ratnayake</div>
        <div class="qp-muted" style="font-size:var(--qp-text-xs);margin-top:2px">61y · Female · joined 2:41 PM · called 2× </div>
        <div class="qp-flex qp-mt-sm">
          ${button('Start consultation', { variant: 'primary', size: 'sm', icon: 'play' })}
          ${button('Call again', { size: 'sm' })}${button('Skip', { size: 'sm' })}${button('⋯', { size: 'sm' })}
        </div>
      </div>
    </div>`;

  const waiting = [
    ['1', 'B004', 'Suresh Mendis', 'P-00104', 'joined 3:02 PM', true],
    ['2', 'A014', 'Nimali Fernando', 'P-00087', 'joined 3:09 PM', false],
    ['3', 'A015', 'Dinesh Wickrama', 'P-00131', 'joined 3:15 PM', false],
    ['4', 'C002', 'Fathima Rizwan', 'P-00122', 'joined 3:21 PM', false],
  ].map(([pos, t, name, code, joined, prio]) => `
    <div class="qp-row${prio ? ' qp-row--priority' : ''}">
      <div class="qp-pos">${pos}</div>
      ${token(t)}
      ${prio ? chip('Priority', 'danger') : ''}
      <div class="qp-row__main">
        <div class="qp-row__title">${name}</div>
        <div class="qp-row__meta">${code} · ${joined}</div>
      </div>
      <div class="qp-row__actions">
        ${button('Call', { variant: 'primary', size: 'sm' })}
        ${button('Hold', { size: 'sm' })}${button('⋯', { size: 'sm' })}
      </div>
    </div>`).join('');

  const body = `
    <div class="qp-between" style="margin-bottom:var(--qp-space-5)">
      <div>
        <div style="font-size:var(--qp-text-lg);font-weight:600">Good afternoon, Ishara 👋</div>
        <div class="qp-muted" style="font-size:var(--qp-text-sm);margin-top:2px">
          Thursday, 6 November 2026 · 3:25 PM
        </div>
      </div>
      <div class="qp-flex">
        ${live('live', 'Auto-updates every 5s')}
        ${button('Refresh now', { size: 'sm', icon: 'refresh' })}
        ${button('Waiting room screen', { size: 'sm', icon: 'monitor' })}
      </div>
    </div>

    ${stats}

    <div class="qp-grid qp-grid--2 qp-mt">
      <div>
        ${card({
          title: 'Live queue',
          hint: 'Dr Nuwan Perera · OPD · Room 2',
          actions: `<div class="qp-flex">${chip('Open', 'success')}${button('Issue a token', { variant: 'primary', size: 'sm', icon: 'plus' })}</div>`,
          body: `
            <div class="qp-flex" style="gap:var(--qp-space-2);margin-bottom:var(--qp-space-4);flex-wrap:wrap">
              <a class="qp-chip tint-primary" href="#">Dr Perera</a>
              <a class="qp-chip tint-neutral" href="#">Dr Silva</a>
              <a class="qp-chip tint-neutral" href="#">Dr Jayasinghe</a>
              ${select({ id: 'all', options: [{ value: 'a', label: 'All doctors' }] })}
              <span class="qp-live"><span class="qp-dot qp-dot--live"></span>Token auto-call is ON</span>
            </div>
            <div style="display:grid;gap:var(--qp-space-4)">
              ${inConsult}${nextUp}
            </div>
            <div class="qp-between qp-mt">
              <div style="font-weight:600;font-size:var(--qp-text-base)">Waiting in queue (4)</div>
              <a href="/queue" style="font-size:var(--qp-text-sm)">View all in queue →</a>
            </div>
            <div class="qp-rows qp-mt-sm" style="border:1px solid var(--qp-border);border-radius:var(--qp-radius-lg);overflow:hidden">
              ${waiting}
            </div>`,
        })}
      </div>

      <div>
        ${card({
          title: 'Patient tracking',
          hint: 'Scan to see your own position',
          body: `<div class="qp-flex" style="gap:var(--qp-space-4);align-items:flex-start">
              <div style="width:96px;height:96px;flex:0 0 96px;border:1px solid var(--qp-border);border-radius:var(--qp-radius-md);
                          display:grid;place-items:center;background:var(--qp-surface-alt)">${icon('qr', 48)}</div>
              <div class="qp-grow">
                <div class="qp-muted" style="font-size:var(--qp-text-xs)">serenity.queuepilot.app/t</div>
                <div class="qp-flex qp-mt-sm">${button('Copy link', { size: 'sm', icon: 'copy' })}</div>
                <div class="qp-muted" style="font-size:var(--qp-text-xs);margin-top:var(--qp-space-3)">
                  Patients scan this, enter the token on their slip, and see their own
                  position — only for this doctor, not the whole waiting room.
                </div>
              </div>
            </div>`,
        })}
        ${card({
          title: 'Today at a glance',
          body: kv([
            ['Tokens issued', '28'],
            ['Completed', '23'],
            ['No shows', '2'],
            ['Skipped', '1'],
            ['Clinical priority', '3'],
            ['Longest wait', '1h 20m'],
          ]),
        })}
        ${card({
          title: 'System status',
          body: `<div class="qp-flex">${icon('check', 18)}<div class="qp-grow">
              <div style="font-weight:600">All systems operational</div>
              <div class="qp-muted" style="font-size:var(--qp-text-xs)">Last refresh 3:25:04 PM</div>
            </div></div>`,
        })}
      </div>
    </div>`;

  return page('Receptionist overview', shell({
    clinic: CLINIC, sub: SUB, nav: NAV.receptionist,
    user: { name: 'Ishara Fernando', role: 'Receptionist' },
    title: 'Overview', subtitle: 'Thursday, 6 November 2026', current: '/receptionist',
    actions: `${button('Issue a token', { variant: 'primary', icon: 'plus' })}`,
    body,
  }));
}

/* ----------------------------------------------------------- queue board -- */

export function queueBoard() {
  const rows = [
    ['1', 'B004', 'Suresh Mendis', 'P-00104', '3:02 PM', '2×', 'Priority'],
    ['2', 'A014', 'Nimali Fernando', 'P-00087', '3:09 PM', '—', 'Waiting'],
    ['3', 'A015', 'Dinesh Wickrama', 'P-00131', '3:15 PM', '—', 'Waiting'],
    ['4', 'C002', 'Fathima Rizwan', 'P-00122', '3:21 PM', '—', 'Waiting'],
    ['5', 'A016', 'Priyanka Abey', 'P-00045', '3:24 PM', '—', 'Waiting'],
  ].map(([pos, t, name, code, joined, calls, status]) => `
    <tr${status === 'Priority' ? ' class="qp-row--priority"' : ''}>
      <td class="mono">${pos}</td>
      <td>${token(t)}</td>
      <td><div style="font-weight:600">${name}</div><div class="qp-muted" style="font-size:var(--qp-text-xs)">${code}</div></td>
      <td>${chip(status, status === 'Priority' ? 'danger' : 'warning')}</td>
      <td class="mono qp-muted">${joined}</td>
      <td class="mono qp-muted">${calls}</td>
      <td class="num qp-nowrap">
        ${button('Call', { variant: 'primary', size: 'sm' })}
        ${button('Hold', { size: 'sm' })}${button('⋯', { size: 'sm' })}
      </td>
    </tr>`).join('');

  const body = `
    ${banner({
      tone: 'green', icon: 'check',
      title: 'Session running — patients can be called.',
      text: 'Started 2:30 PM · 5 waiting.',
      action: `${button('Pause session', { size: 'sm' })}`,
    })}

    <div class="qp-grid qp-grid--3 qp-mt">
      ${card({ title: 'Now serving', body: `<div style="margin-bottom:var(--qp-space-2)">${token('A012', true)}</div>
        <div style="font-weight:600">Anura Dissanayake</div><div class="qp-muted" style="font-size:var(--qp-text-xs)">Room 2 · in consultation</div>` })}
      ${card({ title: 'Waiting', body: `<div style="font-size:var(--qp-text-2xl);font-weight:700">5</div>
        <div class="qp-muted" style="font-size:var(--qp-text-xs)">in this queue</div>` })}
      ${card({ title: 'Tokens issued', body: `<div style="font-size:var(--qp-text-2xl);font-weight:700">16</div>
        <div class="qp-muted" style="font-size:var(--qp-text-xs)">prefix A today</div>` })}
    </div>

    ${card({
      title: 'Live queue',
      hint: 'Priority first, then arrival order',
      flush: true,
      actions: button('New patient', { variant: 'primary', size: 'sm', icon: 'plus' }),
      body: `<div style="padding:var(--qp-space-4) var(--qp-space-6);border-bottom:1px solid var(--qp-border)">
            ${field({ label: 'Scan a slip or type A012', id: 'scan', control: input({ id: 'scan', placeholder: 'A012' }) })}
          </div>
          <table class="qp-table">
            <thead><tr>
              <th style="width:44px">#</th><th>Token</th><th>Patient</th>
              <th>Status</th><th>Joined</th><th>Called</th><th></th>
            </tr></thead><tbody>${rows}</tbody></table>`,
    })}`;

  return page('Queue board', shell({
    clinic: CLINIC, sub: SUB, nav: NAV.receptionist,
    user: { name: 'Ishara Fernando', role: 'Receptionist' },
    title: 'OPD — Nuwan Perera', subtitle: 'General practice · Room 2 · Thu 6 Nov 2026',
    current: '/queue',
    actions: button('New patient', { variant: 'primary', icon: 'plus' }),
    body,
  }));
}

/* ---------------------------------------------------------------- doctor -- */

export function doctor() {
  const body = `
    ${banner({
      tone: 'green', icon: 'play',
      title: 'Session running',
      text: 'Started 2:30 PM · 5 waiting · you are in Room 2',
      action: `<div class="qp-flex">${button('Pause session', { size: 'sm' })}${button('End session', { size: 'sm' })}</div>`,
    })}

    <div class="qp-grid qp-grid--3 qp-mt">
      ${card({ title: 'Now with you', body: `<div style="margin-bottom:var(--qp-space-2)">${token('A012', true)}</div>
        <div style="font-weight:600;font-size:var(--qp-text-md)">Anura Dissanayake</div>
        <div class="qp-muted" style="font-size:var(--qp-text-xs)">34y · Male · 12m elapsed</div>
        <div class="qp-mt">${button('Complete consultation', { variant: 'primary', size: 'lg', icon: 'check' })}</div>
        <div class="qp-flex qp-mt-sm">${button('Complete & call next', { size: 'sm', icon: 'checkcheck' })}${button('Transfer', { size: 'sm' })}</div>` })}
      ${card({ title: 'Next in line', body: `<div style="margin-bottom:var(--qp-space-2)">${token('A013', true)}</div>
        <div style="font-weight:600;font-size:var(--qp-text-md)">Kamala Ratnayake</div>
        <div class="qp-muted" style="font-size:var(--qp-text-xs)">61y · Female · waiting 44m</div>
        <div class="qp-mt">${button('Call next', { variant: 'primary', size: 'lg', icon: 'zap' })}</div>` })}
      ${card({ title: 'Waiting', body: `<div style="font-size:var(--qp-text-2xl);font-weight:700">5</div>
        <div class="qp-muted" style="font-size:var(--qp-text-xs);margin-bottom:var(--qp-space-4)">patients in your queue</div>
        ${kv([['Longest wait', '58m'], ['Avg today', '16m'], ['Seen today', '12']])}` })}
    </div>

    ${card({
      title: 'Your queue', flush: true,
      body: `<div class="qp-rows">${[
        ['1','A013','Kamala Ratnayake','waiting 44m','warning'],
        ['2','A014','Nimali Fernando','waiting 37m','warning'],
        ['3','A015','Dinesh Wickrama','waiting 31m','warning'],
        ['4','A016','Priyanka Abey','waiting 28m','warning'],
        ['5','A017','Rohan Silva','waiting 19m','warning'],
      ].map(([p,t,n,w,tone]) => `<div class="qp-row">
          <div class="qp-pos">${p}</div>${token(t)}
          <div class="qp-row__main"><div class="qp-row__title">${n}</div><div class="qp-row__meta">${w}</div></div>
          <div class="qp-row__actions">
            ${button('Call', { variant: 'primary', size: 'sm' })}
            ${button('Hold', { size: 'sm' })}${button('⋯', { size: 'sm' })}
          </div></div>`).join('')}</div>`,
    })}`;

  return page('Doctor workspace', shell({
    clinic: CLINIC, sub: SUB, nav: NAV.doctor,
    user: { name: 'Dr Amara Silva', role: 'Doctor' },
    title: "Today's queue", subtitle: 'Room 3 · General practice',
    current: '/doctor',
    actions: button('Call next', { variant: 'primary', size: 'lg', icon: 'zap' }),
    body,
  }));
}

/* ----------------------------------------------------------------- owner -- */

export function owner() {
  const body = `
    ${statRow([
      { label: 'Patients today', value: '28', hint: 'tokens issued', icon: 'users', tint: 'primary' },
      { label: 'Completed', value: '23', hint: 'consultations', icon: 'checkcheck', tint: 'success' },
      { label: 'Avg wait', value: '18m', hint: 'today', icon: 'clock', tint: 'warning' },
      { label: 'Revenue today', value: 'Rs 34,500', hint: 'billed', icon: 'creditcard', tint: 'success' },
      { label: 'No shows', value: '2', hint: 'today', icon: 'alert', tint: 'neutral' },
    ])}

    <div class="qp-grid qp-grid--2 qp-mt">
      ${card({
        title: 'Doctors working today', flush: true,
        actions: button('Add doctor', { variant: 'primary', size: 'sm', icon: 'plus' }),
        body: `<table class="qp-table"><thead><tr>
            <th>Doctor</th><th>Specialty</th><th>Room</th><th class="num">Seen</th><th>Status</th>
          </tr></thead><tbody>${[
            ['Dr Nuwan Perera','General practice','Room 2','16','Running'],
            ['Dr Amara Silva','General practice','Room 3','12','Running'],
            ['Dr Ruwan Jayasinghe','Paediatrics','Room 1','0','Not started'],
            ['Dr Fathima Rizwan','Dermatology','Room 4','—','On leave'],
          ].map(([n,s,r,seen,st]) => `<tr>
            <td style="font-weight:600">${n}</td><td class="qp-muted">${s}</td><td class="qp-muted">${r}</td>
            <td class="num mono">${seen}</td>
            <td>${chip(st, st === 'Running' ? 'success' : st === 'Not started' ? 'warning' : 'neutral')}</td>
          </tr>`).join('')}</tbody></table>`,
      })}
      <div>
        ${card({
          title: 'Plan & usage',
          body: `${kv([
            ['Plan', 'Professional'],
            ['Renews', '1 Dec 2026'],
            ['Doctors', '4 of 10'],
            ['Staff', '6 of 15'],
            ['SMS this month', '312 of 1,000'],
          ])}<div class="qp-mt">${button('Upgrade plan', { variant: 'primary', size: 'sm' })}</div>`,
        })}
        ${card({
          title: 'Needs attention',
          body: `<div style="display:grid;gap:var(--qp-space-4)">
            ${[
              ['alert','3 patients waited over 45 minutes today','View waiting-time report'],
              ['calendar','Dr Jayasinghe has not started today\'s session','Open queue'],
              ['creditcard','Invoice INV-0091 is due in 4 days','View invoice'],
            ].map(([ic,t,a]) => `<div class="qp-flex" style="align-items:flex-start;gap:var(--qp-space-3)">
              <span class="tint-warning" style="width:28px;height:28px;border-radius:var(--qp-radius-md);display:grid;place-items:center;flex:0 0 28px">${icon(ic,16)}</span>
              <div class="qp-grow"><div style="font-size:var(--qp-text-base)">${t}</div>
              <a href="#" style="font-size:var(--qp-text-xs)">${a} →</a></div></div>`).join('')}
          </div>`,
        })}
      </div>
    </div>`;

  return page('Clinic owner overview', shell({
    clinic: CLINIC, sub: SUB, nav: NAV.owner,
    user: { name: 'Dr Nuwan Perera', role: 'Clinic owner' },
    title: 'Overview', subtitle: 'Thursday, 6 November 2026',
    current: '/owner',
    actions: `${button('Reports', { size: 'sm', icon: 'chart' })}${button('Open the board', { variant: 'primary', size: 'sm' })}`,
    body,
  }));
}

/* --------------------------------------------------------------- patient -- */

export function patient() {
  const body = `
  <div style="min-height:100vh;display:grid;place-items:center;padding:var(--qp-space-6)">
    <div style="width:100%;max-width:420px">
      <div class="qp-card" style="box-shadow:var(--qp-shadow-md)">
        <div class="qp-card__head">
          <div>
            <div class="qp-card__title">Serenity OPD & Family Clinic</div>
            <div class="qp-card__hint">Colombo · ${live('live', 'Updated just now')}</div>
          </div>
          ${chip('Waiting', 'warning')}
        </div>
        <div class="qp-card__body" style="text-align:center;padding:var(--qp-space-8)">
          <div class="qp-muted" style="font-size:var(--qp-text-xs);text-transform:uppercase;letter-spacing:.06em;font-weight:600">
            Your token
          </div>
          <div style="font-family:var(--qp-font-mono);font-size:56px;font-weight:700;
                      color:var(--qp-primary-700);line-height:1;margin-top:var(--qp-space-2)">B004</div>
          <div style="font-size:var(--qp-text-md);font-weight:600;margin-top:var(--qp-space-4)">Dr Amara Silva</div>
          <div class="qp-muted" style="font-size:var(--qp-text-sm)">General practice · Room 3</div>

          <div style="margin-top:var(--qp-space-8);display:grid;grid-template-columns:1fr 1fr;gap:var(--qp-space-4);
                      border-top:1px solid var(--qp-border);padding-top:var(--qp-space-5)">
            <div><div style="font-size:var(--qp-text-2xl);font-weight:700">3</div>
              <div class="qp-muted" style="font-size:var(--qp-text-xs)">patients ahead</div></div>
            <div><div style="font-size:var(--qp-text-2xl);font-weight:700">15–20m</div>
              <div class="qp-muted" style="font-size:var(--qp-text-xs)">estimated wait</div></div>
          </div>

          <div class="qp-banner qp-banner--blue" style="margin-top:var(--qp-space-6);text-align:left">
            ${icon('info')}
            <div>
              <div class="qp-banner__title">Please stay nearby</div>
              <div class="qp-banner__text">We will call your number on the screen. This page updates by itself.</div>
            </div>
          </div>

          <div class="qp-muted" style="font-size:var(--qp-text-xs);margin-top:var(--qp-space-4)">
            Estimated only — not a guaranteed time.
          </div>
        </div>
      </div>

      <div class="qp-flex qp-mt" style="justify-content:center">
        ${button('Cancel my visit', { size: 'sm' })}${button('Copy link', { size: 'sm', icon: 'copy' })}
      </div>
      <p class="qp-muted" style="text-align:center;font-size:var(--qp-text-xs);margin-top:var(--qp-space-5)">
        No account needed. This link expires in 18 hours and shows only your own place in the queue.
      </p>
    </div>
  </div>`;
  return page('Patient tracking', body);
}

/* ----------------------------------------------------------------- admin -- */

export function admin() {
  const body = `
    ${statRow([
      { label: 'Clinics', value: '128', hint: '118 active', icon: 'building', tint: 'primary' },
      { label: 'Organisations', value: '11', hint: 'groups', icon: 'branch', tint: 'primary' },
      { label: 'MRR', value: 'Rs 812k', hint: 'this month', icon: 'creditcard', tint: 'success' },
      { label: 'Tokens today', value: '4,182', hint: 'platform-wide', icon: 'ticket', tint: 'success' },
      { label: 'Needs attention', value: '6', hint: 'payments + suspended', icon: 'alert', tint: 'warning' },
    ])}

    <div class="qp-grid qp-grid--2 qp-mt">
      ${card({
        title: 'Clinics', hint: 'Newest and suspended first', flush: true,
        actions: button('Add clinic', { variant: 'primary', size: 'sm', icon: 'plus' }),
        body: `<table class="qp-table"><thead><tr>
            <th>Clinic</th><th>City</th><th>Plan</th><th class="num">Staff</th><th class="num">Tokens today</th><th>Status</th>
          </tr></thead><tbody>${[
            ['Serenity OPD & Family Clinic','Colombo','Professional','6','28','Active'],
            ['Lakeview Medical Centre','Negombo','Starter','3','14','Active'],
            ['Coastal Health Collective','Galle','Clinic Plus','11','0','Suspended'],
            ['Kandy Family Practice','Kandy','Professional','5','31','Active'],
            ['Wellawatte Clinic','Colombo','Starter','2','9','Active'],
          ].map(([n,c,p,s,t,st]) => `<tr>
            <td style="font-weight:600">${n}</td><td class="qp-muted">${c}</td><td class="qp-muted">${p}</td>
            <td class="num mono">${s}</td><td class="num mono">${t}</td>
            <td>${chip(st, st === 'Active' ? 'success' : 'danger')}</td>
          </tr>`).join('')}</tbody></table>`,
      })}
      <div>
        ${card({
          title: 'System health',
          body: `<div style="display:grid;gap:var(--qp-space-3)">${[
            ['Database','ok','Connected · 4ms'],
            ['Notification worker','ok','Running · 2 pending'],
            ['Backups','ok','Last 02:00 · 6h ago'],
            ['Schema parity','ok','34 tables, no drift'],
            ['Stale queues','warn','2 clinics idle > 12h'],
          ].map(([n,st,d]) => `<div class="qp-between">
            <div class="qp-flex"><span class="qp-dot qp-dot--${st === 'ok' ? 'live' : 'stale'}"></span>
              <span style="font-weight:500">${n}</span></div>
            <span class="qp-muted" style="font-size:var(--qp-text-xs)">${d}</span></div>`).join('')}</div>`,
        })}
        ${card({
          title: 'Payments awaiting verification', flush: true,
          body: `<div class="qp-rows">${[
            ['INV-0091','Serenity OPD','Rs 7,900','2 hours ago'],
            ['INV-0092','Kandy Family Practice','Rs 14,900','1 day ago'],
          ].map(([inv,c,a,w]) => `<div class="qp-row">
            <div class="qp-row__main"><div class="qp-row__title mono">${inv}</div>
            <div class="qp-row__meta">${c} · ${w}</div></div>
            <div style="font-weight:600">${a}</div>
            <div class="qp-row__actions">${button('Verify', { variant: 'primary', size: 'sm' })}${button('Reject', { size: 'sm' })}</div>
          </div>`).join('')}</div>`,
        })}
      </div>
    </div>`;

  return page('Platform console', shell({
    clinic: 'QueuePilot platform', sub: 'Administration', nav: NAV.admin,
    user: { name: 'Platform Admin', role: 'Super admin' },
    title: 'Platform overview', subtitle: 'All tenants',
    current: '/admin',
    actions: `${button('System health', { size: 'sm', icon: 'activity' })}${button('Add clinic', { variant: 'primary', size: 'sm', icon: 'plus' })}`,
    body,
  }));
}

/* --------------------------------------------------------------- display -- */

export function display() {
  const cards = [
    ['Dr Nuwan Perera', 'Room 2', 'A012', 'Anura D.', ['A013', 'A014', 'A015'], true],
    ['Dr Amara Silva', 'Room 3', 'B004', 'Suresh M.', ['B005', 'B006'], false],
    ['Dr Ruwan Jayasinghe', 'Room 1', 'C001', 'Nimali F.', ['C002'], false],
  ].map(([doc, room, tok, name, next, called]) => `
    <div class="qp-display__card${called ? ' qp-display__card--called' : ''}">
      <div class="qp-between">
        <div class="qp-display__doctor">${doc}</div>
        ${called ? chip('Called — please proceed', 'primary') : chip('Now serving', 'success')}
      </div>
      <div class="qp-display__token">${tok}</div>
      <div class="qp-display__name">${name}</div>
      <div class="qp-display__room">${room}</div>
      <div class="qp-display__next">Up next &nbsp; ${next.join(' · ')}</div>
    </div>`).join('');

  return page('Waiting-room display', `
    <div class="qp-display">
      <div class="qp-between" style="margin-bottom:var(--qp-space-8)">
        <div>
          <div style="font-size:var(--qp-text-xl);font-weight:700">Serenity OPD & Family Clinic</div>
          <div class="qp-muted">Now serving · ${live('live', 'Updated just now')}</div>
        </div>
        <div class="qp-flex">${chip('Open', 'success')}${button('Full screen', { size: 'sm', icon: 'monitor' })}</div>
      </div>
      <div class="qp-display__grid">${cards}</div>
    </div>`);
}

/* ------------------------------------------------------------ components -- */

export function components() {
  const swatches = [
    ['Primary', 'primary', ['600', '500', '700', '50']],
    ['Success', 'success', ['600', '500', '700', '50']],
    ['Warning', 'warning', ['600', '500', '700', '50']],
    ['Danger', 'danger', ['600', '500', '700', '50']],
  ].map(([name, fam, shades]) => `
    <div>
      <div style="font-size:var(--qp-text-sm);font-weight:600;margin-bottom:var(--qp-space-2)">${name}</div>
      <div style="display:flex;gap:var(--qp-space-2)">
        ${shades.map((s) => `<div style="flex:1">
          <div style="height:48px;border-radius:var(--qp-radius-md);background:var(--qp-${fam}-${s});
               border:1px solid var(--qp-border)"></div>
          <div class="qp-muted" style="font-size:10px;margin-top:4px;text-align:center">${s}</div>
        </div>`).join('')}
      </div>
    </div>`).join('');

  const typeScale = [
    ['--qp-text-xl', 'Page title'],
    ['--qp-text-lg', 'Section title'],
    ['--qp-text-md', 'Card title'],
    ['--qp-text-base', 'Body — the default'],
    ['--qp-text-sm', 'Table cell'],
    ['--qp-text-xs', 'Meta, badge, timestamp'],
  ].map(([v, label]) => `<div class="qp-between" style="padding:var(--qp-space-2) 0;border-bottom:1px solid var(--qp-border)">
      <code class="qp-muted" style="font-size:var(--qp-text-xs)">${v}</code>
      <span style="font-size:var(${v})">${label}</span>
    </div>`).join('');

  const body = `
    <div class="qp-between" style="margin-bottom:var(--qp-space-5)">
      <div><div class="qp-sect-title">Design system</div>
      <div class="qp-muted" style="font-size:var(--qp-text-sm)">Measured from the 10 reference screens. See docs/design-system.md.</div></div>
    </div>

    ${card({ title: 'Colour', hint: 'Primary #0555da and success #218e5a are measured from the references', body: `<div style="display:grid;gap:var(--qp-space-6);grid-template-columns:repeat(4,minmax(0,1fr))">${swatches}</div>
      <div class="qp-divider"></div>
      <div style="display:grid;gap:var(--qp-space-4);grid-template-columns:repeat(3,minmax(0,1fr))">
        <div><div style="height:48px;border-radius:var(--qp-radius-md);background:var(--qp-canvas);border:1px solid var(--qp-border)"></div>
          <div class="qp-muted" style="font-size:var(--qp-text-xs);margin-top:4px">canvas · #f2f5f9</div></div>
        <div><div style="height:48px;border-radius:var(--qp-radius-md);background:var(--qp-surface);border:1px solid var(--qp-border)"></div>
          <div class="qp-muted" style="font-size:var(--qp-text-xs);margin-top:4px">surface · #fefefe</div></div>
        <div><div style="height:48px;border-radius:var(--qp-radius-md);background:var(--qp-ink-900)"></div>
          <div class="qp-muted" style="font-size:var(--qp-text-xs);margin-top:4px">ink 900 · #14182b</div></div>
      </div>` })}

    <div class="qp-grid qp-grid--2 qp-mt">
      ${card({ title: 'Type scale', body: typeScale })}
      ${card({ title: 'Buttons', body: `<div style="display:grid;gap:var(--qp-space-3)">
        ${[['Primary','primary'],['Secondary','secondary'],['Ghost','ghost'],['Danger','danger']]
          .map(([l,v]) => `<div class="qp-flex">${button(l, { variant: v })}${button(l, { variant: v, size: 'sm' })}
            ${button(l, { variant: v, size: 'lg' })}</div>`).join('')}
        <div class="qp-divider"></div>
        <div class="qp-flex">${button('With icon', { variant: 'primary', icon: 'plus' })}
          ${button('Disabled', { variant: 'primary' })}</div>
      </div>` })}
    </div>

    <div class="qp-grid qp-grid--2 qp-mt">
      ${card({ title: 'Status chips — the nine queue states', body: `<div class="qp-flex qp-wrap">
        ${[['Waiting','warning'],['Called — please proceed','primary'],['With doctor','success'],
           ['Completed','success'],['Skipped','neutral'],['Not present','neutral'],
           ['On hold','warning'],['Moved to another queue','neutral'],['Cancelled','danger']]
          .map(([l,t]) => chip(l,t)).join('')}
      </div><div class="qp-divider"></div>
      <div class="qp-flex qp-wrap">${token('A001')}${token('B014')}${token('C007', true)}</div>` })}
      ${card({ title: 'Banners', body: `<div style="display:grid;gap:var(--qp-space-3)">
        ${banner({ tone: 'green', icon: 'check', title: 'Session running', text: 'Started 2:30 PM · 5 waiting' })}
        ${banner({ tone: 'amber', icon: 'alert', title: 'Session not started', text: 'Calling is blocked until you start.' })}
        ${banner({ tone: 'blue', icon: 'info', title: 'Please stay nearby', text: 'We will call your number.' })}
        ${banner({ tone: 'grey', icon: 'wifiOff', title: 'Offline', text: 'Showing reserved numbers.' })}
      </div>` })}
    </div>

    <div class="qp-grid qp-grid--2 qp-mt">
      ${card({ title: 'Inputs', body: `
        ${field({ label: 'Patient name', id: 'c1', control: input({ id: 'c1', placeholder: 'Full name' }) })}
        ${field({ label: 'Phone', id: 'c2', control: input({ id: 'c2', value: '077 123 4567' }), hint: 'Used for duplicate detection' })}
        ${field({ label: 'Queue', id: 'c3', control: select({ id: 'c3', options: [
          { value: 'a', label: 'OPD — Dr Perera (A)' }, { value: 'b', label: 'OPD — Dr Silva (B)' }] }) })}
        ${field({ label: 'Phone', id: 'c4', control: input({ id: 'c4', value: '077', error: true }), error: 'Enter a valid phone number' })}` })}
      ${card({ title: 'Empty state', body: emptyState({
        icon: 'inbox',
        title: 'No patients yet today',
        text: 'Issue the first token to start the queue.',
        action: button('Issue a token', { variant: 'primary', icon: 'plus' }),
      }) })}
    </div>`;

  return page('Components', shell({
    clinic: 'QueuePilot', sub: 'Design system', nav: NAV.owner,
    user: { name: 'Design review', role: 'Preview' },
    title: 'Components', subtitle: 'Tokens, type, buttons, chips, inputs, banners',
    current: '/components', body,
  }));
}
