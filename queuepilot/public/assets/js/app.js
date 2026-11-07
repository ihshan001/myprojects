/* =====================================================================
   QueuePilot client runtime
   - no framework, ~10 kB, works on a 3G Android phone
   - every live surface degrades to "stale" rather than lying
   - all mutations carry CSRF + entry version (optimistic concurrency)
   ===================================================================== */
(() => {
  'use strict';

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  /* ------------------------------------------------------------ toasts */
  function toast(message, kind = 'success', ms = 3800) {
    const root = $('#toast-root');
    if (!root) return;
    const el = document.createElement('div');
    const tone = {
      success: 'border-emerald-200 bg-white text-emerald-900',
      error: 'border-rose-200 bg-white text-rose-900',
      info: 'border-sky-200 bg-white text-sky-900',
      warn: 'border-amber-200 bg-white text-amber-900',
    }[kind] || 'border-slate-200 bg-white text-slate-800';
    el.className = `pointer-events-auto rise flex items-start gap-2.5 rounded-xl border ${tone} px-4 py-3 text-sm shadow-lg`;
    el.innerHTML = `<span class="mt-0.5 h-2 w-2 shrink-0 rounded-full ${
      kind === 'error' ? 'bg-rose-500' : kind === 'warn' ? 'bg-amber-500' : kind === 'info' ? 'bg-sky-500' : 'bg-emerald-500'
    }"></span><p class="flex-1"></p><button class="text-slate-400 hover:text-slate-700" aria-label="Dismiss">&times;</button>`;
    el.querySelector('p').textContent = message;
    el.querySelector('button').onclick = () => el.remove();
    root.appendChild(el);
    if (ms) setTimeout(() => el.remove(), ms);
  }
  window.qpToast = toast;

  /* --------------------------------------------------------- api helper */
  async function api(url, { method = 'GET', body = null, timeout = 12000 } = {}) {
    // Failing immediately is the right answer offline: a clinic waiting on a
    // 12-second timeout before being told to use the paper pad is twelve
    // seconds of a queue not moving. Callers that can work offline catch this
    // and switch; the rest get one clear sentence.
    if (!navigator.onLine) throw new Error('No connection. This action needs the server.');
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeout);
    try {
      const res = await fetch(url, {
        method,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {}),
        },
        body: body ? JSON.stringify(body) : null,
        credentials: 'same-origin',
        signal: ctrl.signal,
      });
      const data = await res.json().catch(() => ({}));
      if (res.status === 419) throw new Error('Your session expired. Reload the page to continue.');
      if (res.status === 401) {
        window.location.href = '/login';
        throw new Error('Signed out');
      }
      if (!res.ok || data.ok === false) {
        throw new Error(data.message || data.error || `Request failed (${res.status})`);
      }
      return data;
    } finally {
      clearTimeout(timer);
    }
  }
  window.qpApi = api;

  /* -------------------------------------------------- mobile navigation */
  const menuBtn = $('#mobile-menu-btn');
  if (menuBtn) menuBtn.addEventListener('click', () => $('#mobile-menu')?.classList.toggle('hidden'));

  /* ------------------------------------------------ confirmable actions */
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-confirm]');
    if (!btn) return;
    const text = btn.getAttribute('data-confirm') || 'Are you sure?';
    if (!window.confirm(text)) {
      ev.preventDefault();
      ev.stopImmediatePropagation();
    }
  }, true);

  /* --------------------------------------------------- queue row actions */
  const board = $('[data-queue-board]');
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-act]');
    if (!btn) return;
    const row = btn.closest('[data-entry-row]');
    if (!row) return;

    ev.preventDefault();
    let entry;
    try {
      entry = JSON.parse(row.getAttribute('data-entry') || '{}');
    } catch {
      return;
    }

    const action = btn.getAttribute('data-act');
    let reason = null;

    // Clinical priority is the one action that must capture a reason (audit).
    if (action === 'priority') {
      reason = window.prompt('Reason for clinical priority (recorded in the audit log):', '');
      if (reason === null) return;
      if (!reason.trim()) {
        toast('A reason is required for priority.', 'warn');
        return;
      }
    }
    if (action === 'cancel' || action === 'hold') {
      // A hold without a reason is just a mystery row on the board, so the
      // prompt defaults to something a colleague can act on.
      reason = window.prompt(
        action === 'hold' ? 'Why is this patient on hold? (e.g. sent for X-ray)' : 'Reason (optional):',
        action === 'hold' ? 'Stepped away' : ''
      );
      if (reason === null) return;
    }

    // Transfer must name a destination: without one the patient would be
    // closed out with no live token anywhere.
    let toQueueId = null;
    if (action === 'transfer') {
      const target = await pickTransferTarget();
      if (!target) return;
      toQueueId = target.id;
      reason = window.prompt('Reason for transfer (recorded in the audit log):', '');
      if (reason === null) return;
    }

    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent"></span>';
    row.classList.add('opacity-60');

    try {
      const data = await api('/api/queue/action', {
        method: 'POST',
        body: { entry_id: entry.id, action, version: entry.version, reason, to_queue_id: toQueueId },
      });
      toast(data.message || 'Updated', 'success', 2200);
      if (window.qpRefreshQueue) window.qpRefreshQueue();
    } catch (err) {
      toast(err.message || 'Could not update', 'error');
      btn.disabled = false;
      btn.innerHTML = original;
      row.classList.remove('opacity-60');
    }
  });

  /**
   * Ask for the destination queue. Uses the same-origin summary endpoint so no
   * extra markup has to be embedded in every board row.
   */
  async function pickTransferTarget() {
    let queues = [];
    try {
      const data = await api('/api/summary', { timeout: 9000 });
      queues = (data.queues || []).filter((q) => q.id !== null);
    } catch {
      toast('Could not load the other queues. Try again.', 'error');
      return null;
    }
    if (!queues.length) {
      toast('There is no other queue open today to transfer to.', 'warn');
      return null;
    }
    const list = queues.map((q, i) => `${i + 1}. ${q.name} (${q.waiting} waiting)`).join('\n');
    const answer = window.prompt('Transfer to which queue?\n\n' + list, '1');
    if (answer === null) return null;
    const index = parseInt(answer, 10) - 1;
    if (Number.isNaN(index) || !queues[index]) {
      toast('Enter one of the listed numbers.', 'warn');
      return null;
    }
    return queues[index];
  }

  /* ------------------------------------------------------- copy buttons */
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    const value = btn.getAttribute('data-copy');
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
      } else {
        const ta = document.createElement('textarea');
        ta.value = value;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }
      toast('Link copied', 'success', 1800);
    } catch {
      window.prompt('Copy this link:', value);
    }
  });

  /* ---------------------------------------------- live polling (shared) */
  /**
   * Poller with staleness discipline:
   *  - pauses when the tab is hidden, resumes immediately on focus
   *  - backs off on failure, and marks the UI stale after 30 s without data
   *  - never renders a partial/aborted response
   */
  function createPoller({ url, interval = 5000, onData, onStale, onLive }) {
    let timer = null;
    let lastOk = Date.now();
    let failures = 0;
    let stopped = false;
    let inFlight = false;

    async function tick() {
      if (stopped || document.hidden || inFlight) return;
      inFlight = true;
      try {
        const data = await api(url, { timeout: 9000 });
        failures = 0;
        lastOk = Date.now();
        onData && onData(data);
        onLive && onLive();
      } catch {
        failures++;
        if (Date.now() - lastOk > 30000) {
          onStale && onStale();
        }
      } finally {
        inFlight = false;
        schedule();
      }
    }

    function schedule() {
      clearTimeout(timer);
      if (stopped) return;
      const backoff = Math.min(30000, interval * Math.pow(1.6, Math.min(failures, 4)));
      timer = setTimeout(tick, failures ? backoff : interval);
    }

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        lastOk = Date.now();
        tick();
      }
    });

    return {
      start() { tick(); },
      stop() { stopped = true; clearTimeout(timer); },
      poke() { tick(); },
    };
  }
  window.qpPoller = createPoller;

  /* ------------------------------------------------- live board (reception) */
  const queueBoard = $('[data-queue-board]');
  if (queueBoard) {
    const queueId = queueBoard.getAttribute('data-queue-board');
    const listEl = $('[data-queue-list]', queueBoard);
    const statsEl = $('[data-queue-stats]', queueBoard);

    window.qpRefreshQueue = async () => {
      try {
        const data = await api(`/api/queue/board?queue_id=${encodeURIComponent(queueId)}`);
        renderBoard(data);
      } catch (err) {
        toast(err.message, 'error');
      }
    };

    function renderBoard(data) {
      if (statsEl) {
        statsEl.querySelectorAll('[data-stat]').forEach((el) => {
          const key = el.getAttribute('data-stat');
          const val = data.stats?.[key];
          if (val !== undefined && el.textContent !== String(val)) {
            el.textContent = String(val);
            el.classList.add('rise');
            setTimeout(() => el.classList.remove('rise'), 250);
          }
        });
      }
      if (!listEl || !data.rows) return;
      // Only re-render when the payload actually changed, so buttons don't
      // lose focus under the receptionist's finger.
      const signature = JSON.stringify(data.rows.map((r) => [r.id, r.status, r.version, r.position]));
      if (listEl.getAttribute('data-signature') === signature) return;
      listEl.setAttribute('data-signature', signature);
      listEl.innerHTML = data.rows.map(rowHtml).join('') ||
        '<p class="px-4 py-10 text-center text-sm text-slate-500">No patients in this queue right now.</p>';

      const serving = $('[data-now-serving]', queueBoard);
      if (serving) {
        serving.textContent = data.now_serving?.token_label || '—';
        const sub = $('[data-now-serving-sub]', queueBoard);
        if (sub) sub.textContent = data.now_serving?.full_name || 'No one in the room';
      }
    }

    function esc(s) {
      return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function rowHtml(r) {
      const priority = Number(r.priority) === 1;
      const actions = (r.actions || []).map((a) => {
        const label = {
          call: 'Call', recall: 'Call again', start: 'Start', complete: 'Complete',
          skip: 'Skip', no_show: 'No show', cancel: 'Cancel', transfer: 'Transfer',
          requeue: 'Re-queue', priority: 'Priority', hold: 'Hold', release_hold: 'Back to queue',
        }[a] || a;
        const tone = {
          call: 'act-call', recall: 'act-call', start: 'act-start', complete: 'act-done',
          skip: 'act-skip', cancel: 'act-stop', no_show: 'act-mute', transfer: 'act-mute',
          requeue: 'act-start', priority: 'act-stop', hold: 'act-mute', release_hold: 'act-start',
        }[a] || 'act-mute';
        return `<button type="button" class="${tone}" data-act="${a}">${label}</button>`;
      }).join('');

      return `<div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3 last:border-0
        ${r.status === 'called' ? 'bg-sky-50/60' : ''} ${priority ? 'border-l-2 border-l-red-500' : ''}"
        data-entry-row data-entry='${esc(JSON.stringify({ id: r.id, version: r.version, status: r.status, token: r.token }))}'>
        <div class="w-10 text-center text-xs font-bold text-slate-400 tabular">${r.status === 'waiting' ? r.position : '—'}</div>
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="token-chip">${esc(r.token)}</span>
            ${priority ? '<span class="badge-priority">Priority</span>' : ''}
            <span class="${r.badge_class || 'badge-slate'}">${esc(r.status_label)}</span>
          </div>
          <p class="mt-1 truncate text-sm font-medium text-slate-800">${esc(r.full_name)}
            <span class="ml-1 text-xs font-normal text-slate-400">${esc(r.patient_code || '')}</span></p>
          <p class="text-[11px] text-slate-500 tabular">joined ${esc(r.joined)}${Number(r.call_count) > 1 ? ` · called ${r.call_count}×` : ''}</p>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">${actions}</div>
      </div>`;
    }

    const livePoller = createPoller({
      url: `/api/queue/board?queue_id=${encodeURIComponent(queueId)}`,
      interval: 5000,
      onData: renderBoard,
      onStale: () => markLive(false),
      onLive: () => markLive(true),
    });
    livePoller.start();
  }

  function markLive(isLive) {
    const el = $('#live-status');
    if (!el) return;
    if (isLive) {
      el.className = 'live-pill';
      el.innerHTML = '<span class="pulse-dot"><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span></span><span>Live</span>';
    } else {
      el.className = 'stale-pill';
      el.innerHTML = '<svg class="h-3 w-3"><use href="#i-wifi-off"/></svg><span>Connection lost — showing last known state</span>';
    }
  }

  /* -------------------------------------- patient tracking (own token) */
  const tracker = $('[data-track-token]');
  if (tracker) {
    const token = tracker.getAttribute('data-track-token');
    const apply = (d) => {
      const set = (sel, val) => { const el = $(sel); if (el && val !== undefined && val !== null) el.textContent = val; };
      set('[data-track-status]', d.status_label);
      set('[data-track-position]', d.position > 0 ? String(d.position) : '—');
      set('[data-track-estimate]', d.estimate?.label || '');
      set('[data-track-confidence]', d.estimate?.confidence === 'data' ? 'Based on recent visit times' : 'Estimating — we learn as the clinic runs');
      set('[data-track-updated]', d.updated_at ? d.updated_at.slice(11, 16) : '');

      const posLabel = $('[data-track-position-label]');
      if (posLabel) posLabel.textContent = d.status === 'waiting' ? (d.position === 1 ? 'You are next' : 'People ahead') : 'Status';
      const pill = $('[data-track-pill]');
      if (pill) {
        pill.textContent = d.status_label;
        pill.className = `inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold ${
          d.status === 'called' ? 'bg-sky-100 text-sky-800'
            : d.status === 'in_consultation' ? 'bg-teal-100 text-teal-800'
            : d.status === 'completed' ? 'bg-emerald-100 text-emerald-800'
            : 'bg-amber-100 text-amber-800'}`;
      }
      const banner = $('[data-track-banner]');
      if (banner) {
        if (d.status === 'called') {
          banner.className = 'rise rounded-xl border border-sky-300 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-900';
          banner.textContent = 'It is your turn — please go to ' + (d.room || 'the room') + '.';
        } else if (d.status === 'completed') {
          banner.className = 'rise rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900';
          banner.textContent = 'Your visit is complete. Thank you!';
        }
      }
    };

    createPoller({
      url: '/api/track/' + encodeURIComponent(token),
      interval: 6000,
      onData: apply,
      onStale: () => markLive(false),
      onLive: () => markLive(true),
    }).start();
  }

  /* ------------------------------------------ display board (TV mode) */
  const tvBoard = $('[data-display-board]');
  if (tvBoard) {
    const key = tvBoard.getAttribute('data-display-board');
    const apply = (data) => {
      const servingEl = $('[data-tv-serving]');
      if (servingEl && data.now_serving) {
        const prev = servingEl.getAttribute('data-token');
        servingEl.textContent = data.now_serving.token;
        servingEl.setAttribute('data-token', data.now_serving.token);
        if (prev && prev !== data.now_serving.token) {
          servingEl.classList.add('call-flash');
          setTimeout(() => servingEl.classList.remove('call-flash'), 6000);
          speak(data.now_serving);
        }
      }
      const next = $('[data-tv-next]');
      if (next) {
        next.innerHTML = (data.upcoming || []).map((u) => `
          <div class="flex items-center gap-4 rounded-xl bg-white/5 px-5 py-3">
            <span class="tv-token text-3xl text-white">${u.token}</span>
            <span class="text-sm text-slate-400">${u.room || ''}</span>
          </div>`).join('') || '<p class="text-slate-500">No one waiting</p>';
      }
      // Announced delays and paused queues are the clinic talking to the room.
      const noticeBox = $('[data-tv-notices]');
      const noticeText = $('[data-tv-notices-text]');
      if (noticeBox && noticeText) {
        const parts = [];
        (data.delays || []).forEach((d) => {
          parts.push(`${d.queue}: about ${d.minutes} min behind${d.note ? ' — ' + d.note : ''}`);
        });
        (data.paused || []).forEach((q) => parts.push(`${q}: temporarily paused`));
        if (parts.length) {
          noticeText.textContent = parts.join('  ·  ');
          noticeBox.classList.remove('hidden');
        } else {
          noticeBox.classList.add('hidden');
        }
      }

      const clinics = $('[data-tv-counts]');
      if (clinics) {
        clinics.textContent = `${data.stats?.waiting ?? 0} waiting · ${data.stats?.completed ?? 0} seen today`;
      }
      const clock = $('[data-tv-clock]');
      if (clock) clock.textContent = data.server_time || '';
    };

    function speak(item) {
      if (!('speechSynthesis' in window) || localStorage.getItem('qp_speech') === 'off') return;
      try {
        const msg = new SpeechSynthesisUtterance(`Token ${item.token.split('').join(' ')}, please proceed to ${item.room || 'the consultation room'}`);
        msg.rate = 0.92;
        msg.pitch = 1;
        speechSynthesis.speak(msg);
      } catch { /* non-critical */ }
    }

    createPoller({
      url: '/api/display/' + encodeURIComponent(key),
      interval: 4000,
      onData: apply,
      onStale: () => {
        const el = $('[data-tv-offline]');
        if (el) el.classList.remove('hidden');
        // The pill is the signal staff are told to watch in the continuity
        // procedure, so it has to move. A permanently green "Live" next to an
        // amber banner is worse than no pill at all.
        // Inline, not utility classes: .live-pill sets its own emerald colours
        // with @apply, and a dynamically-added utility cannot be relied on to
        // win that specificity fight (or to exist in the built CSS at all).
        const pill = $('[data-tv-live]');
        const text = $('[data-tv-live-text]');
        if (pill) {
          pill.style.background = '#f59e0b';
          pill.style.color = '#451a03';
          pill.style.boxShadow = 'inset 0 0 0 1px #d97706';
        }
        if (text) text.textContent = 'Offline — not live';
      },
      onLive: () => {
        const el = $('[data-tv-offline]');
        if (el) el.classList.add('hidden');
        const pill = $('[data-tv-live]');
        const text = $('[data-tv-live-text]');
        if (pill) {
          pill.style.background = '';
          pill.style.color = '';
          pill.style.boxShadow = '';
        }
        if (text) text.textContent = 'Live';
      },
    }).start();

    setInterval(() => {
      const c = $('[data-tv-clock]');
      if (c) c.textContent = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    }, 30000);

    // display screens must never sleep
    if ('wakeLock' in navigator) {
      navigator.wakeLock.request('screen').catch(() => {});
    }
  }

  /* --------------------------------------------- automatic page refresh */
  // Server-rendered pages that declare data-auto-refresh (seconds) reload
  // themselves, but only while visible, so a lobby TV never goes blank.
  const autoRefresh = document.body.getAttribute('data-auto-refresh');
  if (autoRefresh) {
    setInterval(() => {
      if (!document.hidden && navigator.onLine) window.location.reload();
    }, Number(autoRefresh) * 1000);
  }

  /* ------------------------------------------------------------- PWA */
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
  }

  window.addEventListener('offline', () => toast('You are offline. The board will refresh when the connection returns.', 'warn', 6000));
  window.addEventListener('online', () => toast('Back online', 'success', 2000));
})();

/* ============================================================ offline desk */
/**
 * Issue tokens when the connection is down.
 *
 * This is the browser half of the scheme in `App\Services\OfflineService`:
 * numbers are set aside on the server while the line is up, and consumed here
 * when it is not. The localStorage key is a small ledger:
 *
 *     blocks:  [{ block_id, queue_id, from, to, prefix, padding, labels }]
 *     used:    [{ reservation, queue_id, token_no, token_label, patient_name,
 *                 patient_phone, joined_at }]
 *
 * A number is removed from `blocks` the instant it is used and pushed onto
 * `used`, so the same number cannot be handed to two patients even if two tabs
 * are open on the same machine (localStorage is shared and the write is
 * synchronous). `reservation` is a random id made at the moment of issue —
 * that is the value the server deduplicates on, which is why syncing twice is
 * harmless.
 *
 * What this deliberately does NOT do: invent numbers. If there is no block,
 * the desk is told to use paper. A made-up number is worse than no token,
 * because it looks valid.
 */
(function offlineDesk() {
  const root = document.querySelector('[data-offline-desk]');
  if (!root) return;

  const KEY = 'qp.offline.v1';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  const read = () => {
    try {
      return JSON.parse(localStorage.getItem(KEY) || '{"blocks":[],"used":[]}');
    } catch {
      return { blocks: [], used: [] };
    }
  };
  const write = (state) => {
    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch { /* private mode */ }
  };

  const byQueue = (state, queueId) => state.blocks.filter((b) => Number(b.queue_id) === Number(queueId));
  const available = (state, queueId) => byQueue(state, queueId).reduce((n, b) => n + b.labels.length, 0);

  function render() {
    const state = read();
    root.querySelectorAll('[data-offline-available]').forEach((el) => {
      const q = el.getAttribute('data-offline-available');
      el.textContent = String(available(state, q));
    });
    const pending = state.used.length;
    const badge = root.querySelector('[data-offline-pending]');
    if (badge) {
      badge.textContent = pending === 0 ? 'Nothing waiting to sync' : `${pending} token${pending === 1 ? '' : 's'} waiting to sync`;
      badge.classList.toggle('text-amber-700', pending > 0);
    }
    const syncBtn = root.querySelector('[data-offline-sync]');
    if (syncBtn) syncBtn.disabled = pending === 0 || !navigator.onLine;
  }

  /* -------------------------------------------------------- take a block */
  root.querySelectorAll('[data-offline-take]').forEach((form) => {
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const btn = form.querySelector('button');
      const size = Number(form.querySelector('[name=size]').value || 10);
      const queueId = Number(form.querySelector('[name=queue_id]').value);
      btn.disabled = true;
      try {
        const res = await window.qpApi('/api/queue/offline/block', { method: 'POST', body: { queue_id: queueId, size } });
        const state = read();
        state.blocks.push({
          block_id: res.block_id, queue_id: queueId, from: res.from, to: res.to,
          prefix: res.prefix, padding: res.padding, labels: res.labels, expires_at: res.expires_at,
        });
        write(state);
        render();
        window.qpToast?.(`${res.labels.length} numbers set aside for this queue. You can go offline now.`, 'success', 5000);
      } catch (err) {
        window.qpToast?.(err.message || 'Could not set numbers aside.', 'error', 7000);
      } finally {
        btn.disabled = false;
      }
    });
  });

  /* ------------------------------------------------------ issue offline */
  root.querySelectorAll('[data-offline-issue]').forEach((form) => {
    form.addEventListener('submit', (ev) => {
      ev.preventDefault();
      const queueId = Number(form.querySelector('[name=queue_id]').value);
      const name = form.querySelector('[name=patient_name]').value.trim();
      const phone = form.querySelector('[name=patient_phone]').value.trim();
      if (!name) {
        window.qpToast?.('Type the patient\'s name — an offline token needs one.', 'error', 5000);
        return;
      }

      const state = read();
      const block = byQueue(state, queueId).find((b) => b.labels.length > 0);
      if (!block) {
        window.qpToast?.('No numbers are set aside for this queue. Use the paper pad and enter them when the line is back.', 'error', 8000);
        return;
      }

      // `labels` are the finished slips — "A023" — exactly as the server
      // built them. The numeric part is what gets reconciled against a block,
      // so it is kept alongside the label rather than recomputed from it.
      const label = String(block.labels.shift());
      const numeric = Number(label.replace(/\D/g, ''));
      if (!numeric) {
        window.qpToast?.('That block is unreadable. Take a fresh one while you have a connection.', 'error', 8000);
        return;
      }

      const reservation = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(36).slice(2)).replace(/-/g, '');
      state.used.push({
        reservation, queue_id: queueId, token_no: numeric,
        token_label: label, patient_name: name, patient_phone: phone || null,
        joined_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      });
      if (block.labels.length === 0) {
        state.blocks = state.blocks.filter((b) => b.block_id !== block.block_id);
      }
      write(state);
      render();

      form.querySelector('[name=patient_name]').value = '';
      form.querySelector('[name=patient_phone]').value = '';

      const out = root.querySelector('[data-offline-slip]');
      if (out) {
        out.innerHTML = '';
        const wrap = document.createElement('div');
        wrap.className = 'rounded-xl border-2 border-slate-900 bg-white p-4 text-center';
        const num = document.createElement('p');
        num.className = 'text-3xl font-black tracking-tight text-slate-900';
        num.textContent = label;
        const who = document.createElement('p');
        who.className = 'mt-1 text-xs text-slate-600';
        who.textContent = name;
        const note = document.createElement('p');
        note.className = 'mt-2 text-[10px] leading-relaxed text-amber-700';
        note.textContent = 'Offline token — no SMS link until it syncs. Keep this slip.';
        wrap.append(num, who, note);
        out.appendChild(wrap);
      }
      window.qpToast?.(`${label} issued to ${name}.`, 'success', 5000);
    });
  });

  /* ------------------------------------------------------------- sync */
  async function sync() {
    const state = read();
    if (state.used.length === 0) return;
    try {
      const res = await window.qpApi('/api/queue/offline/sync', { method: 'POST', body: { items: state.used } });
      // Only remove what the server accepted or already had. A refusal stays
      // in the ledger so somebody can look at it with the patient in front of
      // them — silently dropping a refused token loses the only record.
      const settled = new Set();
      (res.results || []).forEach((r) => {
        if (r.status === 'applied' || r.status === 'duplicate') settled.add(r.reservation);
      });
      const latest = read();
      latest.used = latest.used.filter((u) => !settled.has(u.reservation));
      write(latest);
      render();

      const refused = (res.results || []).filter((r) => r.status === 'refused');
      if (refused.length) {
        window.qpToast?.(`${refused.length} offline token${refused.length === 1 ? '' : 's'} could not be synced. Open the reconciliation report.`, 'error', 9000);
      } else if (settled.size) {
        window.qpToast?.(`${settled.size} offline token${settled.size === 1 ? '' : 's'} synced.`, 'success', 5000);
      }
    } catch (err) {
      window.qpToast?.(err.message || 'Sync failed — the tokens are still saved and will retry.', 'error', 7000);
    }
  }
  window.qpOfflineSync = sync;

  root.querySelector('[data-offline-sync]')?.addEventListener('click', sync);
  window.addEventListener('online', () => {
    // Auto-sync the moment the connection returns, then refresh the page so
    // the board shows what the server now knows.
    sync().then(() => setTimeout(() => { if (navigator.onLine) window.location.reload(); }, 600));
  });

  const netPill = root.querySelector('[data-offline-net]');
  const paintNet = () => {
    if (!netPill) return;
    const on = navigator.onLine;
    netPill.textContent = on ? 'Online' : 'Offline — issuing from the block';
    netPill.className = on
      ? 'rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700'
      : 'rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800';
  };
  window.addEventListener('online', paintNet);
  window.addEventListener('offline', paintNet);
  paintNet();
  render();
})();

/* ---- sign-in demo chips ------------------------------------------------
   Inline handlers are impossible on this page: the response ships
   `Content-Security-Policy: script-src 'self'`, so the fill lives here, in
   an external file — same reason every other script is external. */
(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('[data-demo-email]') : null;
    if (!btn) return;
    var form = btn.closest('form');
    var email = (form && form.querySelector('[name="email"]')) || document.getElementById('email');
    if (email) {
      email.value = btn.getAttribute('data-demo-email') || '';
      email.focus();
    }
    // Only fill a password if one was actually supplied. No page ships one any
    // more, and defaulting to '' would silently wipe what the user just typed.
    var pass = btn.getAttribute('data-demo-pass');
    if (pass !== null) {
      var password = (form && form.querySelector('[name="password"]')) || document.getElementById('password');
      if (password) password.value = pass;
    }
  });
})();
