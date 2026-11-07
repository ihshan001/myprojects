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
  // Declared before the action delegate: the delegate binds to this element,
  // and a `const` read above its declaration would throw.
  const fragRoot = $('[data-board-fragment]');

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

  /* ------------------------------------------------- mobile nav drawer */
  /* Off-canvas, not an inline list. Locks the page behind it, closes on
     Escape / overlay / navigation, and restores focus to the button that
     opened it — otherwise a keyboard user lands back at the top of the doc. */
  (() => {
    const drawer = $('#nav-drawer');
    const overlay = $('#nav-overlay');
    const openBtn = $('#mobile-menu-btn');
    const closeBtn = $('#nav-close');
    if (!drawer || !overlay || !openBtn) return;

    let lastFocus = null;

    const isOpen = () => drawer.classList.contains('is-open');

    function open() {
      if (isOpen()) return;
      lastFocus = document.activeElement;
      overlay.hidden = false;
      // Force a frame so the transition runs from the hidden state.
      requestAnimationFrame(() => {
        drawer.classList.add('is-open');
        overlay.classList.add('is-open');
      });
      document.body.classList.add('qp-nav-open');
      drawer.setAttribute('aria-hidden', 'false');
      openBtn.setAttribute('aria-expanded', 'true');
      (closeBtn || drawer.querySelector('a'))?.focus();
    }

    function close() {
      if (!isOpen()) return;
      drawer.classList.remove('is-open');
      overlay.classList.remove('is-open');
      document.body.classList.remove('qp-nav-open');
      drawer.setAttribute('aria-hidden', 'true');
      openBtn.setAttribute('aria-expanded', 'false');
      // Hide the overlay only after it has faded, so it does not blink out.
      setTimeout(() => { if (!isOpen()) overlay.hidden = true; }, 220);
      if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    }

    openBtn.addEventListener('click', () => (isOpen() ? close() : open()));
    closeBtn?.addEventListener('click', close);
    overlay.addEventListener('click', close);

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && isOpen()) { e.preventDefault(); close(); }
      // Keep Tab inside the drawer while it is open.
      if (e.key === 'Tab' && isOpen()) {
        const items = $$('a[href], button:not([disabled])', drawer);
        if (!items.length) return;
        const first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });

    // Tapping a link navigates; close first so the back button does not
    // return to a page with the drawer still hanging open.
    drawer.addEventListener('click', (e) => {
      if (e.target.closest('a[href]')) close();
    });

    // Rotating a phone to landscape crosses the lg breakpoint, where the
    // drawer is display:none but the body would stay scroll-locked.
    const wide = window.matchMedia('(min-width: 1024px)');
    (wide.addEventListener ? wide.addEventListener.bind(wide, 'change') : wide.addListener.bind(wide))((e) => {
      if (e.matches) close();
    });
  })();

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
  const board = fragRoot || $('[data-queue-board]');
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

  /* ------------------------------------------- role switcher popover */
  (() => {
    const wrap = $('[data-role-menu]');
    const btn = $('[data-role-toggle]', wrap || document);
    const pop = $('[data-role-pop]', wrap || document);
    if (!wrap || !btn || !pop) return;
    const close = () => { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = pop.hidden;
      pop.hidden = !open;
      btn.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  })();

  /* --------------------------------------- per-row overflow menu */
  // Delegated: the board is re-rendered every 5s, so listeners bound to the
  // buttons themselves would be dropped on the first refresh.
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-row-more]');
    const openMenu = $('[data-row-menu]:not(.hidden)');
    if (openMenu && (!trigger || !openMenu.parentElement.contains(trigger))) {
      openMenu.classList.add('hidden');
      openMenu.parentElement.querySelector('[data-row-more]')?.setAttribute('aria-expanded', 'false');
    }
    if (!trigger) return;
    e.stopPropagation();
    const menu = trigger.parentElement.querySelector('[data-row-menu]');
    if (!menu) return;
    const show = menu.classList.contains('hidden');
    menu.classList.toggle('hidden', !show);
    trigger.setAttribute('aria-expanded', String(show));
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    $$('[data-row-menu]:not(.hidden)').forEach((m) => m.classList.add('hidden'));
  });

  /* --------------------------------------------- mobile "More" tab */
  $('[data-more-toggle]')?.addEventListener('click', () => $('#mobile-menu-btn')?.click());

  /* --------------------------------------------------- copy link */
  $$('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const text = btn.getAttribute('data-copy') || '';
      try {
        await navigator.clipboard.writeText(text);
      } catch {
        // Clipboard API needs a secure context; the preview is https, but a
        // plain-http LAN kiosk is not. Fall back rather than silently failing.
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } finally { ta.remove(); }
      }
      const old = btn.innerHTML;
      btn.innerHTML = '<svg class="h-3 w-3"><use href="#i-check"/></svg>Copied';
      setTimeout(() => { btn.innerHTML = old; }, 1600);
    });
  });

  /* ------------------------------------ quick search filters the board */
  // Typing filters the rows already on screen instantly; submitting still
  // goes to the role's real search page for a full lookup.
  $$('[data-board-search]').forEach((form) => {
    const input = $('input[name=q]', form);
    if (!input) return;
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      const rows = $$('[data-prow]');
      if (!rows.length) return;
      rows.forEach((r) => {
        r.hidden = q !== '' && !r.textContent.toLowerCase().includes(q);
      });
    });
    if (fragRoot) form.addEventListener('submit', (e) => { e.preventDefault(); });
  });

  /* ------------------------------------------------- live board (design) */
  /* The redesigned board refreshes by swapping server-rendered HTML. One
     template, in PHP, so what the poller draws can never drift away from
     what the first paint drew. The legacy JSON board below still serves
     /queue, which has its own denser row layout. */
  if (fragRoot) {
    const url = fragRoot.getAttribute('data-board-fragment');
    let sig = '';
    const swap = async () => {
      try {
        const res = await fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error('board refresh failed');
        const html = await res.text();
        if (html !== sig) {
          sig = html;
          const cur = $('[data-queue-board]', fragRoot);
          const doc = new DOMParser().parseFromString(html, 'text/html');
          const next = doc.querySelector('[data-queue-board]');
          if (cur && next) cur.replaceWith(next);
        }
        const stamp = $('[data-last-updated]');
        if (stamp) stamp.textContent = new Date().toLocaleTimeString('en-US',
          { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        markLive(true);
      } catch (e) {
        markLive(false);
      }
    };
    window.qpRefreshQueue = swap;
    $('[data-refresh-now]')?.addEventListener('click', swap);
    setInterval(() => { if (!document.hidden) swap(); }, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) swap(); });
  }

  /* ------------------------------------------------- live board (reception) */
  const queueBoard = fragRoot ? null : $('[data-queue-board]');
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
      const meta = {
        call: ['act-call', 'Call', 'megaphone'], recall: ['act-call', 'Call again', 'megaphone'],
        start: ['act-start', 'Start', 'play'], complete: ['act-done', 'Complete', 'check'],
        requeue: ['act-start', 'Re-queue', 'refresh'], skip: ['act-skip', 'Skip', null],
        no_show: ['act-mute', 'No show', null], cancel: ['act-stop', 'Cancel', null],
        hold: ['act-mute', 'Hold', null], release_hold: ['act-start', 'Back to queue', null],
        transfer: ['act-mute', 'Transfer', null], priority: ['act-stop', 'Priority', null],
      };
      const actions = (r.actions || []).map((a) => {
        const [tone, label, icon] = meta[a] || ['act-mute', a, null];
        const svg = icon ? `<svg class="h-3.5 w-3.5"><use href="#i-${icon}"/></svg>` : '';
        return `<button type="button" class="${tone}" data-act="${a}">${svg}${esc(label)}</button>`;
      }).join('');

      // Mirrors components/queue-row.php. When this drifted before, the row
      // reverted to the old design five seconds after load.
      return `<div class="group flex flex-wrap items-center gap-x-2 gap-y-2 border-b border-hairline px-3 py-3 last:border-0 sm:gap-3 sm:px-4
        ${r.status === 'called' ? 'bg-sky-50/60' : ''} ${priority ? 'border-l-2 border-l-red-500' : ''}"
        data-entry-row data-entry='${esc(JSON.stringify({ id: r.id, version: r.version, status: r.status, token: r.token }))}'>
        <div class="w-7 text-center sm:w-10">
          <span class="text-xs ${r.status === 'waiting' ? 'font-bold text-ink-400 tabular' : 'text-ink-300'}">${r.status === 'waiting' ? r.position : '—'}</span>
        </div>
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="token-chip">${esc(r.token)}</span>
            ${priority ? '<span class="badge-priority">Priority</span>' : ''}
            <span class="${r.badge_class || 'badge-slate'}">${esc(r.status_label)}</span>
            ${r.queue_name ? `<span class="text-xs text-ink-400">· ${esc(r.queue_name)}</span>` : ''}
          </div>
          <p class="mt-1 truncate text-sm font-medium text-ink-900">${esc(r.full_name)}
            <span class="ml-1 text-xs font-normal text-ink-400">${esc(r.patient_code || '')}</span></p>
          <p class="text-[11px] text-ink-500 tabular">joined ${esc(r.joined)}${Number(r.call_count) > 1 ? ` · called ${r.call_count}×` : ''}</p>
        </div>
        ${actions ? `<div class="flex w-full flex-wrap items-center gap-1.5 border-t border-hairline pt-2 sm:w-auto sm:border-0 sm:pt-0">
          ${actions}
          <a class="act-mute" href="/queue/entry?id=${encodeURIComponent(r.id)}" title="Open details">
            <svg class="h-3.5 w-3.5"><use href="#i-chevron-right"/></svg>
          </a>
        </div>` : ''}
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
      // Mirror the server: show the real status, never a bare dash.
      set('[data-track-position]', d.status === 'waiting' && d.position > 0
        ? String(d.position)
        : (d.status_label || ''));
      // Match the server: an estimate is meaningless after the call.
      const estOverride = { called: 'Now', in_consultation: 'In progress',
        completed: 'Finished', cancelled: '—', no_show: '—' }[d.status];
      set('[data-track-estimate]', estOverride || d.estimate?.label || '');
      set('[data-track-confidence]', d.status !== 'waiting' ? ''
        : (d.estimate?.confidence === 'data' ? 'Based on recent visit times' : 'Estimating — we learn as the clinic runs'));
      const caveat = $('[data-track-caveat]');
      if (caveat) caveat.classList.toggle('hidden', d.status !== 'waiting');
      set('[data-track-updated]', d.updated_at ? d.updated_at.slice(11, 16) : '');

      const posLabel = $('[data-track-position-label]');
      if (posLabel) {
        posLabel.textContent = d.status === 'waiting'
          ? (d.position === 1 ? 'You are next' : 'People ahead of you')
          : 'Your status';
      }
      const posEl = $('[data-track-position]');
      if (posEl) {
        posEl.className = d.status === 'waiting'
          ? 'mt-1 stat-value text-4xl'
          : 'mt-1 text-xl font-bold leading-tight text-ink-900';
      }
      const pill = $('[data-track-pill]');
      if (pill) {
        pill.textContent = d.status_label;
        pill.className = `inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold ${
          d.status === 'called' ? 'bg-sky-100 text-sky-800'
            : d.status === 'in_consultation' ? 'bg-brand-100 text-brand-800'
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

  /* ------------------------------------------------ shared disclosures */
  /* Generic show/hide used by filters, the create form and any future rail
     card. Keeps aria-expanded honest so a screen reader knows the state. */
  document.addEventListener('click', (ev) => {
    const t = ev.target.closest('[data-toggle]');
    if (!t) return;
    const panel = $(t.getAttribute('data-toggle'));
    if (!panel) return;
    const open = panel.classList.toggle('hidden') === false;
    t.setAttribute('aria-expanded', String(open));
    if (open && panel.classList.contains('flex-wrap')) panel.classList.add('flex');
    else panel.classList.remove('flex');
  });
  document.addEventListener('click', (ev) => {
    const t = ev.target.closest('[data-scroll-to]');
    if (!t) return;
    const el = $(t.getAttribute('data-scroll-to'));
    if (!el) return;
    if (el.tagName === 'DETAILS') el.open = true;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  /* ---------------------------------------------------- priority rules */
  const ruleBody = $('[data-rule-body]');
  if (ruleBody) {
    const rows = () => [...ruleBody.querySelectorAll('[data-rule-row]')];
    const state = $('[data-order-state]');
    const spin = $('[data-order-spin]');
    const empty = $('[data-rule-empty]');

    /* tabs */
    $$('[data-rule-tab]').forEach((tab) => {
      tab.addEventListener('click', () => {
        const want = tab.getAttribute('data-rule-tab');
        $$('[data-rule-tab]').forEach((t) => {
          const on = t === tab;
          t.classList.toggle('utab-active', on);
          t.setAttribute('aria-selected', String(on));
        });
        $$('[data-rule-pane]').forEach((p) => {
          p.classList.toggle('hidden', p.getAttribute('data-rule-pane') !== want);
        });
      });
    });

    /* search + filters */
    const search = $('[data-rule-search]');
    const filters = $$('[data-rule-filter]');
    function applyFilters() {
      const q = (search?.value || '').trim().toLowerCase();
      const strength = filters.find((f) => f.dataset.ruleFilter === 'priority')?.value || '';
      const status = filters.find((f) => f.dataset.ruleFilter === 'status')?.value || '';
      let shown = 0;
      rows().forEach((r) => {
        const ok = (!q || (r.dataset.name || '').includes(q))
          && (!strength || r.dataset.strength === strength)
          && (!status || r.dataset.status === status);
        r.classList.toggle('hidden', !ok);
        if (ok) shown++;
      });
      if (empty) empty.classList.toggle('hidden', shown !== 0);
    }
    search?.addEventListener('input', applyFilters);
    filters.forEach((f) => f.addEventListener('change', applyFilters));
    $('[data-rule-filter-reset]')?.addEventListener('click', () => {
      if (search) search.value = '';
      filters.forEach((f) => { f.value = ''; });
      applyFilters();
    });

    /* create form */
    const form = $('#rule-form');
    const formToggle = $('#rule-form-card [data-toggle]');
    const setForm = (open) => {
      form?.classList.toggle('hidden', !open);
      formToggle?.setAttribute('aria-expanded', String(open));
      if (open) {
        $('#rule-form-card')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        $('#name')?.focus();
      }
    };
    $$('[data-open-form]').forEach((b) => b.addEventListener('click', () => setForm(true)));
    $('[data-close-form]')?.addEventListener('click', () => setForm(false));

    /* ------------------------------------------------------- reordering */
    const renumber = () => rows().forEach((r, i) => {
      const n = r.querySelector('[data-rule-num]');
      if (n) n.textContent = String(i + 1);
    });

    /* Two arrow presses in a row used to lose the second one: the guard
       returned early while the first save was in flight, so the screen showed
       an order the server never received. Now moves coalesce into a short
       debounce, and any change made during a save is re-sent afterwards. The
       order actually on screen is always the order that gets stored. */
    let saveTimer = null;
    let inFlight = false;
    let again = false;

    async function flush() {
      if (inFlight) { again = true; return; }
      inFlight = true;
      const order = rows().map((r) => Number(r.dataset.id));
      spin?.classList.add('animate-spin');
      if (state) state.textContent = 'Saving order…';
      try {
        await window.qpApi('/queues/priority-rules/reorder', { method: 'POST', body: { order } });
        if (state) {
          state.textContent = 'Order saved · ' + new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        }
      } catch (err) {
        window.qpToast?.(err.message || 'Could not save the order', 'error');
        if (state) state.textContent = 'Not saved — reload to see the stored order';
      } finally {
        inFlight = false;
        if (again) { again = false; flush(); }
        else spin?.classList.remove('animate-spin');
      }
    }

    function persist() {
      if (state) state.textContent = 'Saving order…';
      clearTimeout(saveTimer);
      saveTimer = setTimeout(flush, 350);
    }

    /* Do not let a navigate-away swallow a pending save. sendBeacon cannot set
       headers, so this goes form-encoded: the router reads $_POST['_token']
       and Request::payload() merges $_POST, which a JSON body would not do. */
    window.addEventListener('pagehide', () => {
      if (!saveTimer && !inFlight && !again) return;
      clearTimeout(saveTimer);
      const body = new URLSearchParams();
      body.set('_token', csrf);
      body.set('order', JSON.stringify(rows().map((r) => Number(r.dataset.id))));
      navigator.sendBeacon?.('/queues/priority-rules/reorder', body);
    });

    function move(row, delta) {
      const list = rows();
      const i = list.indexOf(row);
      const j = i + delta;
      if (j < 0 || j >= list.length) return;
      if (delta < 0) ruleBody.insertBefore(row, list[j]);
      else ruleBody.insertBefore(list[j], row);
      renumber();
      persist();
      row.querySelector('[data-rule-grip]')?.focus();
    }

    let dragged = null;
    const clearMarks = () => rows().forEach((r) => r.classList.remove('row-drop-before', 'row-drop-after'));

    rows().forEach((row) => {
      const grip = row.querySelector('[data-rule-grip]');
      if (!grip) return;

      // Keyboard first: a drag-only control locks out anyone not using a mouse.
      grip.addEventListener('keydown', (ev) => {
        if (ev.key === 'ArrowUp') { ev.preventDefault(); move(row, -1); }
        if (ev.key === 'ArrowDown') { ev.preventDefault(); move(row, 1); }
      });

      grip.addEventListener('dragstart', (ev) => {
        dragged = row;
        row.classList.add('row-dragging');
        ev.dataTransfer.effectAllowed = 'move';
        // Firefox needs data set or the drag never starts.
        ev.dataTransfer.setData('text/plain', row.dataset.id);
      });
      grip.addEventListener('dragend', () => {
        row.classList.remove('row-dragging');
        clearMarks();
        dragged = null;
      });
    });

    ruleBody.addEventListener('dragover', (ev) => {
      if (!dragged) return;
      ev.preventDefault();
      const over = ev.target.closest('[data-rule-row]');
      if (!over || over === dragged) return;
      const box = over.getBoundingClientRect();
      const after = ev.clientY > box.top + box.height / 2;
      clearMarks();
      over.classList.add(after ? 'row-drop-after' : 'row-drop-before');
    });

    ruleBody.addEventListener('drop', (ev) => {
      if (!dragged) return;
      ev.preventDefault();
      const over = ev.target.closest('[data-rule-row]');
      if (!over || over === dragged) { clearMarks(); return; }
      const box = over.getBoundingClientRect();
      const after = ev.clientY > box.top + box.height / 2;
      ruleBody.insertBefore(dragged, after ? over.nextSibling : over);
      clearMarks();
      renumber();
      persist();
    });
  }

  /* Waiting-room controls. Lives here rather than inline in the view because
     the CSP is script-src 'self' — an inline block is blocked, which left the
     voice and full-screen buttons doing nothing. */
  const tvSpeech = $('#tv-speech');
  if (tvSpeech) {
    let on = localStorage.getItem('qp_speech') !== 'off';
    const paint = () => { tvSpeech.textContent = 'Voice announcements: ' + (on ? 'on' : 'off'); };
    tvSpeech.addEventListener('click', () => {
      on = !on;
      localStorage.setItem('qp_speech', on ? 'on' : 'off');
      paint();
    });
    paint();
  }
  const tvFull = $('#tv-full');
  if (tvFull) {
    if (!document.documentElement.requestFullscreen) {
      tvFull.remove();
    } else {
      tvFull.addEventListener('click', () => {
        if (document.fullscreenElement) document.exitFullscreen();
        else document.documentElement.requestFullscreen().catch(() => {});
      });
      document.addEventListener('fullscreenchange', () => {
        tvFull.textContent = document.fullscreenElement ? 'Exit full screen' : 'Full screen';
      });
    }
  }

  /* ------------------------------------------ display board (TV mode) */
  const tvBoard = $('[data-display-board]');
  if (tvBoard) {
    const key = tvBoard.getAttribute('data-display-board');
    // Room tiles are built here with the same classes the server renders, so
    // a refresh never changes the shape of the board.
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
    const seen = new Map();
    const roomHtml = (r) => {
      const call = r.status === 'called';
      return `<div class="tv-room ${call ? 'tv-room-call' : 'tv-room-busy'}" data-token="${esc(r.token)}">
        <div>
          <p class="tv-eyebrow">${esc(r.room)}</p>
          <p class="tv-room-token mt-2">${esc(r.token)}</p>
        </div>
        <div>
          <p class="tv-room-meta">${esc(r.doctor || r.queue || '')}</p>
          <p class="tv-room-sub">${call ? 'Please proceed to the room' : 'In consultation'}</p>
        </div>
      </div>`;
    };
    const emptyHtml = `<div class="tv-empty" data-tv-empty>
      <svg class="h-9 w-9 text-white/25" aria-hidden="true"><use href="#i-clock"/></svg>
      <p class="mt-4 text-lg font-bold text-white/70 sm:text-xl">No token called yet</p>
      <p class="mt-1.5 max-w-sm text-[13px] leading-relaxed text-white/40">
        Take a number at reception or on the tablet, then watch this screen for your token.
      </p>
    </div>`;

    const apply = (data) => {
      const rooms = $('[data-tv-rooms]');
      if (rooms) {
        // Fall back to the single headline for an older payload.
        const list = data.serving || (data.now_serving ? [data.now_serving] : []);
        rooms.innerHTML = list.length ? list.map(roomHtml).join('') : emptyHtml;
        list.forEach((r) => {
          const id = (r.room || '') + '|' + (r.queue || '');
          const prev = seen.get(id);
          // Announce once per room per token, and only when it is a call the
          // patient must act on — not on every four-second poll.
          if (prev !== undefined && prev !== r.token && r.status === 'called') {
            const tile = rooms.querySelector(`[data-token="${CSS.escape(r.token)}"]`);
            if (tile) tile.classList.add('call-flash');
            speak(r);
          }
          seen.set(id, r.token);
        });
      }
      const next = $('[data-tv-next]');
      if (next) {
        next.innerHTML = (data.upcoming || []).slice(0, 6).map((u) => `
          <div class="tv-chip">
            <span class="tv-chip-token">${esc(u.token)}</span>
            <span class="min-w-0">
              <span class="block truncate text-[13px] font-semibold text-white/75">${esc(u.room)}</span>
              <span class="block truncate text-[11px] text-white/40">${esc(u.doctor || u.queue || '')}</span>
            </span>
          </div>`).join('')
          || '<p class="py-8 text-center text-[13px] text-white/35" data-tv-next-empty>No one waiting.</p>';
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
