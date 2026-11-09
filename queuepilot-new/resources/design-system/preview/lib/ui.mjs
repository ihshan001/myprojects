/**
 * QueuePilot design preview — reusable UI components.
 *
 * Every screen in the preview is composed from these functions, which is the
 * same "build reusable components, not per-screen markup" rule the build brief
 * requires. Nothing here contains business logic; it renders markup only.
 */

import { icon } from './icons.mjs';

const esc = (s = '') =>
  String(s).replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* ------------------------------------------------------------------ shell */

const PREVIEW_LINKS = [
  ['/', 'Homepage'],
  ['/login', 'Login'],
  ['/receptionist', 'Receptionist'],
  ['/doctor', 'Doctor'],
  ['/owner', 'Owner'],
  ['/patient', 'Patient'],
  ['/admin', 'Platform'],
  ['/display', 'TV display'],
  ['/components', 'Components'],
];

export function previewBar(current) {
  const links = PREVIEW_LINKS.map(
    ([href, label]) =>
      `<a href="${href}"${href === current ? ' aria-current="page"' : ''}>${esc(label)}</a>`
  ).join('');
  return `<div class="qp-preview-bar">
    <span class="qp-preview-bar__label">Design preview</span>${links}
    <span style="margin-left:auto;opacity:.5">Phase 0 — no backend</span>
  </div>`;
}

/**
 * The app shell. `nav` is an array of groups:
 *   { label?, items: [{ href, label, icon, active?, count? }] }
 */
export function shell({ clinic, sub, nav, user, title, subtitle, actions = '', body, bare = false, current }) {
  const navHtml = nav
    .map((group) => {
      const head = group.label ? `<div class="qp-navgroup">${esc(group.label)}</div>` : '';
      const items = group.items
        .map(
          (it) => `<a class="qp-navitem${it.active ? ' qp-navitem--active' : ''}" href="${it.href}">
            ${icon(it.icon)}${it.label ? `<span>${esc(it.label)}</span>` : ''}
            ${it.count != null ? `<span class="qp-navitem__count">${esc(it.count)}</span>` : ''}
          </a>`
        )
        .join('');
      return head + items;
    })
    .join('');

  const initials = (user?.name || '?')
    .split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

  const rail = bare ? '' : `<aside class="qp-rail">
    <div class="qp-rail__brand">
      <div class="qp-logo">QP</div>
      <div class="qp-rail__brandtext">
        <div class="qp-rail__clinic">${esc(clinic)}</div>
        ${sub ? `<div class="qp-rail__sub">${esc(sub)}</div>` : ''}
      </div>
    </div>
    <nav class="qp-rail__nav">${navHtml}</nav>
    <div class="qp-rail__foot">
      <a class="qp-user" href="#">
        <div class="qp-avatar">${esc(initials)}</div>
        <div class="qp-user__meta">
          <div class="qp-user__name">${esc(user?.name || '')}</div>
          <div class="qp-user__role">${esc(user?.role || '')}</div>
        </div>
      </a>
    </div>
  </aside>`;

  return `${previewBar(current)}
  <div class="qp-shell">${rail}
    <div class="qp-main">
      <header class="qp-topband">
        <div class="qp-grow">
          <h1 class="qp-page-title">${esc(title)}</h1>
          ${subtitle ? `<div class="qp-page-sub">${esc(subtitle)}</div>` : ''}
        </div>
        <div class="qp-topband__actions">${actions}</div>
      </header>
      <main class="qp-content">${body}</main>
    </div>
  </div>`;
}

export function page(title, html) {
  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${esc(title)} · QueuePilot design preview</title>
  <link rel="stylesheet" href="/public/tokens.css">
  <link rel="stylesheet" href="/public/app.css">
</head>
<body>${html}</body>
</html>`;
}

/* ------------------------------------------------------------------ cards */

export function card({ title, hint, actions = '', body, flush = false }) {
  return `<section class="qp-card">
    ${title ? `<div class="qp-card__head">
      <div><div class="qp-card__title">${esc(title)}</div>${hint ? `<div class="qp-card__hint">${esc(hint)}</div>` : ''}</div>
      ${actions}
    </div>` : ''}
    <div class="qp-card__body${flush ? ' qp-card__body--flush' : ''}">${body}</div>
  </section>`;
}

export function statTile({ label, value, hint, icon: ic, tint = 'primary' }) {
  return `<div class="qp-stat">
    <div class="qp-stat__top">
      <span class="qp-stat__label">${esc(label)}</span>
      <span class="qp-stat__icon tint-${tint}">${icon(ic)}</span>
    </div>
    <div class="qp-stat__value">${esc(value)}</div>
    ${hint ? `<div class="qp-stat__hint">${esc(hint)}</div>` : ''}
  </div>`;
}

export function statRow(stats) {
  return `<div class="qp-stats">${stats.map(statTile).join('')}</div>`;
}

/* -------------------------------------------------------------- fragments */

export const button = (label, { variant = 'secondary', size = '', icon: ic = '', href } = {}) => {
  const cls = `qp-btn qp-btn--${variant}${size ? ` qp-btn--${size}` : ''}`;
  const inner = `${ic ? icon(ic) : ''}${esc(label)}`;
  return href ? `<a class="${cls}" href="${href}">${inner}</a>` : `<button class="${cls}" type="button">${inner}</button>`;
};

export function chip(label, tint = 'neutral') {
  return `<span class="qp-chip tint-${tint}">${esc(label)}</span>`;
}

export const token = (t, big = false) =>
  `<span class="qp-token${big ? ' qp-token--lg' : ''}">${esc(t)}</span>`;

export function banner({ tone = 'grey', icon: ic, title, text, action = '' }) {
  return `<div class="qp-banner qp-banner--${tone}">
    ${icon(ic)}
    <div class="qp-grow">
      <div class="qp-banner__title">${esc(title)}</div>
      ${text ? `<div class="qp-banner__text">${text}</div>` : ''}
    </div>${action}
  </div>`;
}

export function emptyState({ icon: ic = 'inbox', title, text, action = '' }) {
  return `<div class="qp-empty">
    <div class="qp-empty__icon">${icon(ic)}</div>
    <div class="qp-empty__title">${esc(title)}</div>
    <div class="qp-empty__text">${esc(text)}</div>
    ${action}
  </div>`;
}

export function table({ head, rows, className = '' }) {
  const th = head
    .map(([label, cls]) => `<th class="${cls || ''}">${esc(label)}</th>`)
    .join('');
  const tr = rows
    .map(
      (cells) =>
        `<tr>${cells.map(([v, cls]) => `<td class="${cls || ''}">${v}</td>`).join('')}</tr>`
    )
    .join('');
  return `<div style="overflow-x:auto"><table class="qp-table ${className}">
    <thead><tr>${th}</tr></thead><tbody>${tr}</tbody></table></div>`;
}

export function kv(pairs) {
  return `<dl class="qp-kv">${pairs
    .map(([k, v]) => `<dt>${esc(k)}</dt><dd>${v}</dd>`)
    .join('')}</dl>`;
}

export function field({ label, hint, error, control, id }) {
  return `<div class="qp-field">
    <label class="qp-label" for="${esc(id)}">${esc(label)}</label>
    ${control}
    ${error
      ? `<div class="qp-field__error">${esc(error)}</div>`
      : hint ? `<div class="qp-field__hint">${esc(hint)}</div>` : ''}
  </div>`;
}

export const input = ({ id, value = '', placeholder = '', error = false, type = 'text' }) =>
  `<input class="qp-input${error ? ' qp-input--error' : ''}" id="${esc(id)}" type="${type}"
     value="${esc(value)}" placeholder="${esc(placeholder)}">`;

export const select = ({ id, options, selected }) =>
  `<select class="qp-select" id="${esc(id)}">${options
    .map(
      (o) =>
        `<option${o.value === selected ? ' selected' : ''}>${esc(o.label)}</option>`
    )
    .join('')}</select>`;

export const live = (state = 'live', text) =>
  `<span class="qp-live"><span class="qp-dot qp-dot--${state}"></span>${esc(text)}</span>`;

export { esc };
