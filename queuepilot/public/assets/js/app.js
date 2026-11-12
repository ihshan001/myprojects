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

  /* -------------------------------------------- CSP-safe inline actions
     The app sends `script-src 'self'`, which blocks inline event handlers
     exactly as it blocks inline <script>. Controls written as
     onchange=/onsubmit=/onclick= attributes never ran, and none of it logged
     anything the user would see:
       - filter selects meant to submit on change simply did not;
       - `return confirm(...)` guards on destructive forms were skipped, so
         suspending a clinic, deleting a room or revoking a screen went
         through with no confirmation whatsoever;
       - the print buttons and the template placeholder buttons did nothing.
     These delegated handlers replace them with data- attributes, which the
     CSP allows. */

  /* A <select> that submits its own form. */
  document.addEventListener('change', (ev) => {
    const sel = ev.target.closest('[data-submit-on-change]');
    if (sel && sel.form) sel.form.submit();
  });

  /* Print buttons. */
  document.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-print]')) {
      ev.preventDefault();
      window.print();
    }
  });

  /* Message-template placeholder buttons. */
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-insert-placeholder]');
    if (!btn) return;
    const area = btn.closest('form')?.querySelector('textarea');
    if (!area) return;
    area.value += btn.getAttribute('data-insert-placeholder');
    area.focus();
  });

  /* Forms that must ask before they fire. The message lives on the form, not
     on the button, because several of these are single-button forms where the
     button carries no room for it. */
  document.addEventListener('submit', async (ev) => {
    const form = ev.target;
    if (!form || !form.getAttribute) return;
    const message = form.getAttribute('data-confirm-submit');
    if (!message) return;
    if (form.dataset.confirmed === '1') {   // already asked: let this one pass
      delete form.dataset.confirmed;
      return;
    }
    ev.preventDefault();
    ev.stopImmediatePropagation();
    const ok = await askConfirm(message, { danger: form.hasAttribute('data-confirm-danger') });
    if (!ok) return;
    form.dataset.confirmed = '1';
    form.submit();
  }, true);

  /* ------------------------------------------------------------ dialogs
     The console runs inside an iframe on the preview host, so every native
     confirm()/prompt() the browser opens is labelled "An embedded page at
     <host> says". That reads like a phishing prompt, cannot be styled, and
     cannot be driven by a test. These are the in-page replacements. They
     return promises, so the call sites read the way they did before. */
  const dialogHost = document.createElement('div');
  dialogHost.className = 'qp-dialog-host';
  dialogHost.hidden = true;
  document.body.appendChild(dialogHost);

  let dialogOpen = null;

  function dialogKeydown(ev) {
    if (!dialogOpen) return;
    if (ev.key === 'Escape') {
      ev.preventDefault();
      dialogOpen.close(dialogOpen.nullish);
      return;
    }
    if (ev.key !== 'Tab') return;
    // Focus trap. A dialog that lets Tab walk out from behind itself is
    // unusable from the keyboard, and this app is worked at a reception
    // desk all day, often one-handed.
    const items = dialogHost.querySelectorAll('button, [href], input, textarea, select');
    if (items.length === 0) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (ev.shiftKey && document.activeElement === first) {
      ev.preventDefault();
      last.focus();
    } else if (!ev.shiftKey && document.activeElement === last) {
      ev.preventDefault();
      first.focus();
    }
  }

  /** @returns Promise<boolean> without an input, Promise<string|null> with one. */
  function openDialog(opts) {
    return new Promise((resolve) => {
      // A second dialog over an open one would strand the first promise.
      if (dialogOpen) dialogOpen.close(dialogOpen.nullish);

      const withInput = Boolean(opts.input);
      const nullish = withInput ? null : false;
      const returnFocus = document.activeElement;

      const close = (value) => {
        dialogOpen = null;
        dialogHost.hidden = true;
        dialogHost.innerHTML = '';
        document.removeEventListener('keydown', dialogKeydown, true);
        document.body.classList.remove('qp-dialog-locked');
        if (returnFocus && returnFocus.focus) returnFocus.focus();
        resolve(value);
      };

      dialogHost.innerHTML = '';
      dialogHost.hidden = false;

      const card = document.createElement('div');
      card.className = 'qp-dialog';
      card.setAttribute('role', 'dialog');
      card.setAttribute('aria-modal', 'true');

      const heading = document.createElement('h2');
      heading.className = 'qp-dialog-title';
      heading.textContent = opts.title || 'Are you sure?';
      card.appendChild(heading);

      if (opts.message) {
        const body = document.createElement('p');
        body.className = 'qp-dialog-text';
        body.textContent = opts.message;
        card.appendChild(body);
      }

      let field = null;
      if (withInput) {
        const spec = opts.input;
        const wrap = document.createElement('label');
        wrap.className = 'qp-dialog-field';
        const caption = document.createElement('span');
        caption.textContent = spec.label || '';
        wrap.appendChild(caption);

        if (spec.kind === 'select') {
          // Picking a destination by typing "2" into a prompt is how a
          // patient gets transferred to the wrong doctor. Show the names.
          field = document.createElement('select');
          field.className = 'input';
          (spec.choices || []).forEach((choice) => {
            const option = document.createElement('option');
            option.value = String(choice.value);
            option.textContent = choice.label;
            if (String(choice.value) === String(spec.value)) option.selected = true;
            field.appendChild(option);
          });
        } else {
          field = document.createElement(spec.multiline ? 'textarea' : 'input');
          if (!spec.multiline) field.type = 'text';
          field.className = 'input';
          field.placeholder = spec.placeholder || '';
          field.value = spec.value || '';
          if (spec.readonly) {
            field.readOnly = true;
            field.className += ' font-mono text-[12px]';
          }
        }
        wrap.appendChild(field);
        card.appendChild(wrap);
      }

      const actions = document.createElement('div');
      actions.className = 'qp-dialog-actions';
      const cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'btn-secondary';
      cancel.textContent = opts.cancelLabel || 'Cancel';
      const okBtn = document.createElement('button');
      okBtn.type = 'button';
      okBtn.className = opts.danger ? 'btn-danger' : 'btn-primary';
      okBtn.textContent = opts.confirmLabel || (withInput ? 'Continue' : 'Confirm');
      actions.appendChild(cancel);
      actions.appendChild(okBtn);
      card.appendChild(actions);
      dialogHost.appendChild(card);

      dialogOpen = { close, nullish };

      cancel.addEventListener('click', () => close(nullish));
      okBtn.addEventListener('click', () => close(withInput ? field.value : true));
      // Backdrop press on mousedown, not click: a click that started inside
      // the card and ended on the backdrop must not dismiss it.
      dialogHost.addEventListener('mousedown', (ev) => {
        if (ev.target === dialogHost) close(nullish);
      });

      document.addEventListener('keydown', dialogKeydown, true);
      document.body.classList.add('qp-dialog-locked');

      (field || okBtn).focus();
      if (field && field.select) field.select();
    });
  }

  /** Ask a yes/no question. Resolves true only on Confirm. */
  const askConfirm = (message, opts = {}) => openDialog(Object.assign({ message }, opts));

  /** Ask for a line of text, or a choice. Resolves null when dismissed. */
  const askText = (opts) => openDialog(Object.assign({}, opts, {
    input: {
      label: opts.label,
      value: opts.value,
      placeholder: opts.placeholder,
      multiline: opts.multiline,
      kind: opts.kind,
      choices: opts.choices,
      readonly: opts.readonly,
    },
  }));

  window.qpDialog = { openDialog, askConfirm, askText };

  /* -------------------------------------------------- mobile navigation */
  const menuBtn = $('#mobile-menu-btn');
  if (menuBtn) menuBtn.addEventListener('click', () => $('#mobile-menu')?.classList.toggle('hidden'));

  /* The bottom tab bar's More opens the same drawer, so there is one nav map
     and not two to keep in step. */
  document.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-open-nav]')) $('#mobile-menu')?.classList.toggle('hidden');
  });

  /* ------------------------------------------- header "More" dropdown */
  document.addEventListener('click', (ev) => {
    const toggle = ev.target.closest('[data-nav-more-toggle]');
    const menu = toggle && toggle.parentElement?.querySelector('[data-nav-more] .topnav-menu, .topnav-menu');
    if (!toggle) return;
    const panel = document.getElementById(toggle.getAttribute('aria-controls') || '');
    if (!panel) return;
    const open = panel.hidden;
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  // Dismiss on any click or Escape that is not inside the menu.
  document.addEventListener('click', (ev) => {
    $$('.topnav-menu').forEach((panel) => {
      if (panel.hidden) return;
      if (ev.target.closest('.topnav-more')) return;
      panel.hidden = true;
      const t = panel.closest('.topnav-more')?.querySelector('[data-nav-more-toggle]');
      if (t) t.setAttribute('aria-expanded', 'false');
    });
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Escape') return;
    $$('.topnav-menu').forEach((panel) => { panel.hidden = true; });
    $$('[data-nav-more-toggle]').forEach((t) => t.setAttribute('aria-expanded', 'false'));
  });

  /* ------------------------------------------------ confirmable actions
     Capture phase, and stopImmediatePropagation, so a destructive submit
     cannot fire on the same click that is still asking permission. */
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-confirm]');
    if (!btn) return;
    if (btn.dataset.confirmed === '1') {   // already asked: let this one pass
      delete btn.dataset.confirmed;
      return;
    }
    ev.preventDefault();
    ev.stopImmediatePropagation();

    const ok = await askConfirm(btn.getAttribute('data-confirm') || 'Are you sure?', {
      title: btn.getAttribute('data-confirm-title') || undefined,
      danger: btn.hasAttribute('data-confirm-danger'),
      confirmLabel: btn.getAttribute('data-confirm-ok') || undefined,
    });
    if (!ok) return;

    // Replay the click that preventDefault above swallowed.
    if (btn.type === 'submit' && btn.form) { btn.form.submit(); return; }
    if (btn.tagName === 'A' && btn.href) { window.location.href = btn.href; return; }
    btn.dataset.confirmed = '1';
    btn.click();
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
      reason = await askText({
        title: 'Clinical priority',
        label: 'Reason (recorded in the audit log)',
        placeholder: 'e.g. chest pain, triaged by nurse',
      });
      if (reason === null) return;
      if (!reason.trim()) {
        toast('A reason is required for priority.', 'warn');
        return;
      }
    }
    if (action === 'cancel' || action === 'hold') {
      // A hold without a reason is just a mystery row on the board, so the
      // prompt defaults to something a colleague can act on.
      reason = await askText({
        title: action === 'hold' ? 'Put on hold' : 'Cancel this token',
        label: action === 'hold' ? 'Why? (e.g. sent for X-ray)' : 'Reason (optional)',
        value: action === 'hold' ? 'Stepped away' : '',
        placeholder: action === 'hold' ? 'Sent for X-ray' : 'Optional',
      });
      if (reason === null) return;
    }

    // Transfer must name a destination: without one the patient would be
    // closed out with no live token anywhere.
    let toQueueId = null;
    if (action === 'transfer') {
      const target = await pickTransferTarget();
      if (!target) return;
      toQueueId = target.id;
      reason = await askText({
        title: 'Transfer patient',
        label: 'Reason (recorded in the audit log)',
        placeholder: 'e.g. wrong department, Dr. Perera is free',
      });
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
    // Names in a select, not numbers in a prompt: typing "2" wrong sends a
    // patient to the wrong doctor, and a prompt cannot show what "2" is
    // without the list scrolling off the top of it.
    const answer = await askText({
      title: 'Transfer to which queue?',
      label: 'Destination queue',
      kind: 'select',
      choices: queues.map((q) => ({ value: q.id, label: `${q.name} (${q.waiting} waiting)` })),
      value: queues[0].id,
      confirmLabel: 'Transfer here',
    });
    if (answer === null) return null;
    const target = queues.find((q) => String(q.id) === String(answer));
    if (!target) {
      toast('Pick one of the listed queues.', 'warn');
      return null;
    }
    return target;
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
      await askText({
        title: 'Copy this link',
        label: 'Clipboard was blocked — select and copy',
        value,
        readonly: true,
        confirmLabel: 'Done',
      });
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
          complete_next: 'Complete &amp; call next',
          skip: 'Skip', no_show: 'No show', cancel: 'Cancel', transfer: 'Transfer',
          requeue: 'Re-queue', priority: 'Priority', hold: 'Hold', release_hold: 'Back to queue',
        }[a] || a;
        const tone = {
          call: 'act-call', recall: 'act-call', start: 'act-start', complete: 'act-done',
          complete_next: 'act-done',
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
    const tvEsc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    // Which token each room was showing last time, so a change can flash and
    // be spoken. Keyed by queue id: two doctors can legitimately be on the
    // same label, and comparing one flat string would miss half the calls.
    const lastTokenByRoom = new Map();

    const apply = (data) => {
      const solo = data.solo === true;
      const soloEl = $('[data-tv-solo]');
      const roomsEl = $('[data-tv-rooms]');

      if (soloEl) soloEl.classList.toggle('hidden', !solo);
      if (roomsEl) {
        roomsEl.classList.toggle('hidden', solo);
        roomsEl.classList.toggle('grid', !solo);
      }

      const servingEl = $('[data-tv-serving]');
      if (servingEl && data.now_serving) {
        const prev = servingEl.getAttribute('data-token');
        servingEl.textContent = data.now_serving.token;
        servingEl.setAttribute('data-token', data.now_serving.token);
        const roomEl = $('[data-tv-serving-room]');
        if (roomEl) roomEl.textContent = [data.now_serving.room, data.now_serving.doctor].filter(Boolean).join(' · ');
        if (prev && prev !== data.now_serving.token) {
          servingEl.classList.add('call-flash');
          setTimeout(() => servingEl.classList.remove('call-flash'), 6000);
          speak(data.now_serving);
        }
      }

      if (roomsEl && !solo) {
        const rooms = data.serving || [];
        // Work out what changed BEFORE rebuilding the list: the flash and the
        // spoken announcement both need to know, and after the innerHTML swap
        // the old tokens are gone.
        const changed = new Map();
        rooms.forEach((r) => {
          const key = r.queue || r.room || '';
          if (lastTokenByRoom.has(key) && lastTokenByRoom.get(key) !== r.token) changed.set(key, r);
          lastTokenByRoom.set(key, r.token);
        });

        roomsEl.innerHTML = rooms.map((r) => {
          const key = r.queue || r.room || '';
          const cls = ['room-card'];
          if (changed.has(key)) cls.push('call-flash');
          if (r.status === 'called') cls.push('room-card-called');
          return `
            <div class="${cls.join(' ')}">
              <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-teal-400">${tvEsc(r.room || 'Room')}</p>
              <p class="tv-token mt-1 text-5xl leading-none text-white">${tvEsc(r.token || '\u2014')}</p>
              <p class="mt-1 truncate text-[11px] text-slate-400">${tvEsc(r.doctor || r.queue || '')}</p>
            </div>`;
        }).join('') || '<p class="text-slate-500">No session open yet.</p>';

        // Announce the rooms whose token just changed, so nobody misses a call
        // because they were looking at the wrong card.
        changed.forEach((r) => speak(r));
      }

      const next = $('[data-tv-next]');
      if (next) {
        next.innerHTML = (data.upcoming || []).map((u) => `
          <div class="flex items-center gap-4 rounded-xl bg-white/5 px-5 py-3">
            <span class="tv-token text-3xl text-white">${tvEsc(u.token)}</span>
            <span class="min-w-0 text-sm text-slate-400">
              <span class="block truncate text-slate-300">${tvEsc(u.doctor || '')}</span>
              ${tvEsc(u.room || '')}
            </span>
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


  /* ------------------------------------------- priority rules: reordering
     The ranked list is the clinic's policy, so it is editable in place.
     Drag is the fast path and the up/down buttons are the keyboard path;
     both end at POST /queues/priority-rules/reorder with the full order, so
     the two can never disagree about what the list is.

     Drag is an enhancement only — with no pointer, or no JS, the buttons
     still work and the page is fully usable. */
  (function () {
    const list = document.querySelector('[data-rule-list]');
    if (!list) return;

    const form = document.querySelector('[data-rule-form]');
    const rows = () => Array.from(list.querySelectorAll('[data-rule-row]'));
    let dragging = null;

    function clearMarks() {
      rows().forEach((r) => r.classList.remove('is-dragging', 'is-drop-target'));
    }

    function currentOrder() {
      return rows().map((r) => r.getAttribute('data-rule-row'));
    }

    async function sendOrder(order) {
      const body = new URLSearchParams();
      body.set('_token', csrf);
      body.set('order', order.join(','));
      const res = await fetch('/queues/priority-rules/reorder', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
        credentials: 'same-origin',
      });
      return res.ok;
    }

    rows().forEach((row) => {
      row.addEventListener('dragstart', (ev) => {
        dragging = row;
        row.classList.add('is-dragging');
        if (ev.dataTransfer) {
          ev.dataTransfer.effectAllowed = 'move';
          // Firefox refuses to start a drag with an empty payload.
          ev.dataTransfer.setData('text/plain', row.getAttribute('data-rule-row') || '');
        }
      });

      row.addEventListener('dragend', () => {
        dragging = null;
        clearMarks();
      });

      row.addEventListener('dragover', (ev) => {
        if (!dragging || dragging === row) return;
        ev.preventDefault();
        if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'move';
        clearMarks();
        dragging.classList.add('is-dragging');
        row.classList.add('is-drop-target');
      });

      row.addEventListener('drop', async (ev) => {
        if (!dragging || dragging === row) return;
        ev.preventDefault();

        // Move the dragged row to where it was dropped, then send the whole
        // list rather than a delta: two staff reordering at once is far safer
        // as two whole lists than as two patches of each other.
        const box = row.getBoundingClientRect();
        const after = ev.clientY > box.top + box.height / 2;
        list.insertBefore(dragging, after ? row.nextSibling : row);

        clearMarks();
        // Renumber immediately so the position shown matches what was saved,
        // even before a reload.
        rows().forEach((r, i) => {
          const badge = r.querySelector('.tabular');
          if (badge) badge.textContent = String(i + 1);
        });

        const ok = await sendOrder(currentOrder());
        if (!ok) {
          toast('Could not save the new order. Reloading.', 'error');
          window.location.reload();
          return;
        }
        toast('Order saved. It applies from the next token.', 'success', 2400);
      });
    });

    list.addEventListener('dragover', (ev) => ev.preventDefault());

    // Degrees in, tenths out. The column is an INTEGER so a 39.0 threshold
    // is exact; the form is for people, who think in degrees.
    if (form) {
      form.addEventListener('submit', function () {
        ['min', 'max'].forEach(function (prefix) {
          const visible = form.querySelector('[name="' + prefix + '_temp"]');
          if (!visible) return;
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = prefix + '_temp_x10';
          const value = (visible.value || '').trim();
          hidden.value = value === '' ? '' : String(Math.round(parseFloat(value) * 10));
          form.appendChild(hidden);
          // Rename so a degree string never reaches the service unscaled.
          visible.name = prefix + '_temp_degrees';
        });
      });
    }
  })();

/* --------------------------------------------- patients: add-to-register */
/* A plain toggle, kept here rather than inline so the CSP does not need
   'unsafe-inline'. No JS means no panel — so the panel starts hidden only
   when JS is present, and the page stays usable without it. */
(function () {
  var panel = document.getElementById('add-patient-panel');
  if (!panel) return;

  function open() {
    panel.classList.remove('hidden');
    var name = panel.querySelector('#ap-name');
    if (name) name.focus();
  }
  function close() {
    panel.classList.add('hidden');
    var form = panel.querySelector('form');
    if (form) form.reset();
  }

  document.addEventListener('click', function (e) {
    if (!e.target || !e.target.closest) return;
    if (e.target.closest('[data-open-add-patient]')) { open(); return; }
    if (e.target.closest('[data-close-add-patient]')) { close(); return; }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.classList.contains('hidden')) close();
  });
})();


/* ------- token desk: pick a found patient -------
 * Moved here from an inline <script> in new.php.
 *
 * The app sends `script-src 'self'` with no 'unsafe-inline' and no nonce, so
 * an inline block is refused silently: no console entry the nurse would see,
 * no error page, and a page that looks finished and simply does not respond.
 * That is how the phone board came to render once and never update, and how
 * the token desk came to find a patient and then create a duplicate anyway.
 *
 * Each block runs only on its own page, so adding them costs one existence
 * check per page load everywhere else. */
(function () {
  if (!document.getElementById('patient_id')) return;
  (function () {
    const patientId = document.getElementById('patient_id');
    const fields = document.getElementById('new-patient-fields');
    const picked = document.getElementById('picked-patient');
    const nameEl = document.querySelector('[data-picked-name]');
    const phoneEl = document.querySelector('[data-picked-phone]');

    function pick(btn) {
      patientId.value = btn.dataset.pickPatient;
      nameEl.textContent = btn.dataset.pickName;
      phoneEl.textContent = btn.dataset.pickPhone || 'no phone on file';
      picked.classList.remove('hidden');
      fields.classList.add('hidden');
      document.querySelectorAll('[data-pick-patient]').forEach((b) => b.classList.remove('border-teal-400', 'bg-teal-50/40'));
      btn.classList.add('border-teal-400', 'bg-teal-50/40');
      picked.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    document.querySelectorAll('[data-pick-patient]').forEach((btn) => {
      btn.addEventListener('click', () => pick(btn));
    });

    document.getElementById('clear-picked').addEventListener('click', () => {
      patientId.value = '';
      picked.classList.add('hidden');
      fields.classList.remove('hidden');
      document.getElementById('full_name').focus();
    });

    // Alt+Enter submits from anywhere in the form (receptionist speed)
    document.addEventListener('keydown', (e) => {
      if (e.altKey && e.key === 'Enter') {
        document.getElementById('token-form').requestSubmit();
      }
    });

    // Phone: show what will be used for the link, and validate length inline.
    const phone = document.querySelector('[data-phone]');
    const hint = document.getElementById('phone-hint');
    if (phone) {
      phone.addEventListener('blur', () => {
        const digits = phone.value.replace(/\D/g, '');
        if (digits === '') {
          hint.textContent = 'Optional, for SMS tracking link';
          hint.className = 'help';
        } else if (digits.length < 9) {
          hint.textContent = 'That looks short — use 07X XXX XXXX';
          hint.className = 'help text-rose-600';
        } else {
          hint.textContent = 'Tracking link will be sent to this number';
          hint.className = 'help text-teal-700';
        }
      });
    }
  })();
})();

/* ------- phone waiting-room board polling -------
 * Moved here from an inline <script> in live.php.
 *
 * The app sends `script-src 'self'` with no 'unsafe-inline' and no nonce, so
 * an inline block is refused silently: no console entry the nurse would see,
 * no error page, and a page that looks finished and simply does not respond.
 * That is how the phone board came to render once and never update, and how
 * the token desk came to find a patient and then create a duplicate anyway.
 *
 * Each block runs only on its own page, so adding them costs one existence
 * check per page load everywhere else. */
(function () {
  if (!document.body || !document.body.dataset.boardKey) return;
  (function () {
    var key = document.body.dataset.boardKey;
    var api = '/api/display/' + encodeURIComponent(key);
    var servingTok = document.getElementById('phone-serving-token');
    var servingRoom = document.getElementById('phone-serving-room');
    var servingDoc = document.getElementById('phone-serving-doctor');
    var soloBlock = document.getElementById('phone-serving-solo');
    var roomsBlock = document.getElementById('phone-serving-rooms');
    var upcoming = document.getElementById('phone-upcoming-list');
    var empty = document.getElementById('phone-empty');
    var waitingCount = document.getElementById('phone-waiting-count');
    var status = document.getElementById('phone-status');
    var notices = document.getElementById('phone-notices');
    var noticesText = document.getElementById('phone-notices-text');

    function render(data) {
      if (!data || !data.ok) {
        status.textContent = 'Offline — try again shortly.';
        return;
      }

      var now = new Date();
      document.getElementById('phone-clock').textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

      // Now serving — identical payload to the TV board.
      if (data.now_serving) {
        servingTok.textContent = data.now_serving.token || '—';
        servingRoom.textContent = data.now_serving.room || '—';
        servingDoc.textContent = data.now_serving.doctor ? ('With ' + data.now_serving.doctor) : '';
        status.textContent = 'Live';
      } else {
        servingTok.textContent = '—';
        servingRoom.textContent = '—';
        servingDoc.textContent = '';
        status.textContent = 'No one serving yet';
      }

      // Several rooms: one row per doctor. The hero stays for a solo practice,
      // where a list of one would just be the same thing twice.
      var rooms = data.serving || [];
      if (data.solo === true || rooms.length <= 1) {
        soloBlock.classList.remove('hidden');
        roomsBlock.classList.add('hidden');
      } else {
        soloBlock.classList.add('hidden');
        roomsBlock.classList.remove('hidden');
        roomsBlock.innerHTML = rooms.map(function (r) {
          return '<li class="flex items-center justify-between gap-3 py-2.5">' +
              '<span class="min-w-0">' +
                '<span class="block text-[13px] font-semibold text-slate-800">' + ehtml(r.doctor || r.queue || '') + '</span>' +
                '<span class="block text-[11px] text-slate-500">' + ehtml(r.room || '') + (r.status === 'called' ? ' · called now' : '') + '</span>' +
              '</span>' +
              '<span class="font-mono text-2xl font-bold text-slate-900 phone-upcoming">' + ehtml(r.token || '—') + '</span>' +
            '</li>';
        }).join('');
      }

      // Coming up — same tokens as the TV.
      upcoming.innerHTML = '';
      var list = data.upcoming || [];
      if (list.length === 0) {
        empty.classList.remove('hidden');
      } else {
        empty.classList.add('hidden');
        list.forEach(function (item) {
          var li = document.createElement('li');
          li.className = 'flex items-center justify-between p-3 rounded-lg border border-slate-100 bg-slate-50';
          li.innerHTML =
            '<span class="font-mono font-bold text-slate-900 phone-upcoming">' + ehtml(item.token || '—') + '</span>' +
            '<span class="min-w-0 text-right">' +
              '<span class="block phone-chip text-slate-700">' + ehtml(item.doctor || '') + '</span>' +
              '<span class="block text-[11px] text-slate-500">' + ehtml(item.room || '') + '</span>' +
            '</span>';
          upcoming.appendChild(li);
        });
        waitingCount.textContent = list.length + ' ' + (list.length === 1 ? 'waiting' : 'waiting');
      }

      // Notices — delays and pauses, same as the TV.
      var bits = [];
      (data.delays || []).forEach(function (d) {
        bits.push(d.queue + ' is running ' + d.minutes + ' min late' + (d.note ? ' — ' + d.note : ''));
      });
      (data.paused || []).forEach(function (q) {
        bits.push(q + ' is paused');
      });
      if (bits.length) {
        notices.classList.remove('hidden');
        noticesText.textContent = bits.join(' · ');
      } else {
        notices.classList.add('hidden');
      }
    }

    function ehtml(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function fetchBoard() {
      fetch(api, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
        return r.ok ? r.json() : null;
      }).then(render).catch(function () {
        status.textContent = 'Offline — try again shortly.';
      });
    }

    fetchBoard();
    setInterval(fetchBoard, 6000);
  })();
})();

/* ------- TV voice announcements toggle -------
 * Moved here from an inline <script> in screen.php.
 *
 * The app sends `script-src 'self'` with no 'unsafe-inline' and no nonce, so
 * an inline block is refused silently: no console entry the nurse would see,
 * no error page, and a page that looks finished and simply does not respond.
 * That is how the phone board came to render once and never update, and how
 * the token desk came to find a patient and then create a duplicate anyway.
 *
 * Each block runs only on its own page, so adding them costs one existence
 * check per page load everywhere else. */
(function () {
  var btn0 = document.getElementById('tv-speech');
      if (!btn0) return;
    // Voice announcements toggle (persisted; some clinics prefer silence).
    (function () {
      var btn = document.getElementById('tv-speech');
      var on = localStorage.getItem('qp_speech') !== 'off';
      function render() { btn.textContent = 'Voice announcements: ' + (on ? 'on' : 'off'); }
      btn.addEventListener('click', function () {
        on = !on;
        localStorage.setItem('qp_speech', on ? 'on' : 'off');
        render();
      });
      render();
    })();
})();
