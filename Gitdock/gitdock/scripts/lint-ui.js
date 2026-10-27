#!/usr/bin/env node
/**
 * Static audit of the panel's own script — catches the class of bug that has no syntax error and no
 * server error, only a broken click at runtime:
 *
 *   · a function that is called but never defined        (e.g. `openSettings is not defined`)
 *   · a selector pointing at an element id that is not in the markup and never assigned
 *
 * The first real catch: the dock's Setup icon and the ⌘ palette's Settings entry both called
 * `openSettings()`, which did not exist anywhere — clicking either one threw at runtime and showed a
 * toast. `node --check` cannot see that, and no server test touches the client's function graph.
 *
 * It parses the script with acorn (a real JS parser), so nothing here is fooled by a function name
 * appearing inside a string, a comment or a line of prose in the HTML.
 *
 * usage:  node scripts/lint-ui.js          → prints findings, exit 1 if any
 */
'use strict';
const fs = require('fs');
const path = require('path');

let acorn;
try {
  acorn = require('acorn');
} catch {
  console.error('acorn is not installed. Run:  cd gitdock && npm install');
  process.exit(2);
}

const FILE = path.join(__dirname, '..', 'app', 'public', 'index.html');
const html = fs.readFileSync(FILE, 'utf8');
const script = html.match(/<script>([\s\S]*)<\/script>/);
if (!script) {
  console.error('no <script> block found in app/public/index.html');
  process.exit(2);
}
const ast = acorn.parse(script[1], { ecmaVersion: 2023, allowReturnOutsideFunction: true, locations: true });

/** everything the browser and the language already provide */
const GLOBALS = new Set(`fetch JSON Object Array String Number Boolean Math Date RegExp Error TypeError RangeError Promise Map Set WeakMap WeakSet
decodeURIComponent encodeURIComponent parseInt parseFloat isNaN isFinite setTimeout clearTimeout setInterval clearInterval
console document window navigator location history alert confirm prompt requestAnimationFrame cancelAnimationFrame
URL URLSearchParams FormData Blob File FileReader XMLHttpRequest Headers Request Response AbortSignal AbortController
TextEncoder TextDecoder structuredClone queueMicrotask crypto btoa atob Intl Symbol BigInt Proxy Reflect
Int8Array Uint8Array Uint8ClampedArray DataView getComputedStyle matchMedia CustomEvent Event MouseEvent KeyboardEvent
HTMLElement Node Element Image Audio ResizeObserver IntersectionObserver MutationObserver DecompressionStream
self top parent frames localStorage sessionStorage eval Function Boolean Number.parseInt Number.parseFloat`.split(/\s+/));

// ---------------------------------------------------------------- collect declarations
const declared = new Set();
function addPattern(node) {
  if (!node) return;
  switch (node.type) {
    case 'Identifier': declared.add(node.name); break;
    case 'ObjectPattern': node.properties.forEach((p) => addPattern(p.value || p.argument)); break;
    case 'ArrayPattern': node.elements.forEach(addPattern); break;
    case 'AssignmentPattern': addPattern(node.left); break;
    case 'RestElement': addPattern(node.argument); break;
    default: break;
  }
}
function walk(node, fn) {
  if (!node || typeof node.type !== 'string') return;
  fn(node);
  for (const k of Object.keys(node)) {
    if (k === 'type' || k === 'loc' || k === 'start' || k === 'end') continue;
    const v = node[k];
    if (Array.isArray(v)) v.forEach((x) => walk(x, fn));
    else if (v && typeof v.type === 'string') walk(v, fn);
  }
}
walk(ast, (n) => {
  switch (n.type) {
    case 'FunctionDeclaration': case 'FunctionExpression': case 'ArrowFunctionExpression':
      if (n.id) declared.add(n.id.name);
      n.params.forEach(addPattern);
      break;
    case 'VariableDeclarator': addPattern(n.id); break;
    case 'ClassDeclaration': case 'ClassExpression':
      if (n.id) declared.add(n.id.name);
      break;
    case 'CatchClause': addPattern(n.param); break;
    case 'ForOfStatement': case 'ForInStatement': addPattern(n.left); break;
    case 'ImportDefaultSpecifier': case 'ImportSpecifier': case 'ImportNamespaceSpecifier':
      if (n.local) declared.add(n.local.name);
      break;
    default: break;
  }
});

// ---------------------------------------------------------------- calls with no definition
const called = new Map();
walk(ast, (n) => {
  if (n.type !== 'CallExpression') return;
  if (n.callee.type !== 'Identifier') return;                 // foo.bar() is a method, not our concern
  const name = n.callee.name;
  if (GLOBALS.has(name)) return;
  if (!called.has(name)) called.set(name, n.loc ? n.loc.start.line : 0);
});
const undefinedCalls = [...called].filter(([name]) => !declared.has(name));

// ---------------------------------------------------------------- selectors with no element
const markupIds = new Set();
for (const m of html.matchAll(/\bid=["']([A-Za-z][\w:-]*)["']/g)) markupIds.add(m[1]);
// ids the script assigns itself (document.createElement then .id = 'x')
walk(ast, (n) => {
  if (n.type === 'AssignmentExpression' && n.left && n.left.type === 'MemberExpression'
      && n.left.property && n.left.property.name === 'id' && n.right && n.right.type === 'Literal'
      && typeof n.right.value === 'string') {
    markupIds.add(n.right.value);
  }
});
const selectorRefs = new Map();
walk(ast, (n) => {
  if (n.type !== 'CallExpression') return;
  const callee = n.callee;
  const isDollar = (callee.type === 'Identifier' && (callee.name === '$' || callee.name === '$$'));
  const isGetById = callee.type === 'Identifier' && callee.name === 'getElementById';
  const isQuery = callee.type === 'MemberExpression' && callee.property
    && (callee.property.name === 'querySelector' || callee.property.name === 'querySelectorAll');
  if (!isDollar && !isGetById && !isQuery) return;
  const arg = n.arguments[0];
  if (!arg || arg.type !== 'Literal' || typeof arg.value !== 'string') return;
  const value = arg.value;
  if (isGetById) { if (!markupIds.has(value)) selectorRefs.set(value, n.loc ? n.loc.start.line : 0); return; }
  // '#foo' exactly (an id selector) — anything more complex is a class/path query, left alone
  // only '#id' is an id selector — 'header' is a tag and 'div.card' is a class
  const m = value.match(/^#([A-Za-z][\w:-]*)$/);
  if (!m) return;
  const id = m[1];
  if (!markupIds.has(id) && !declared.has(id)) selectorRefs.set(id, n.loc ? n.loc.start.line : 0);
});
const danglingSelectors = [...selectorRefs].filter(([id]) => {
  // an id that the script builds into markup as a string ("id=\"foo\"") is fine
  return !new RegExp(`id=["']${id}["']`).test(html);
});

// ---------------------------------------------------------------- report
let bad = 0;
if (undefinedCalls.length) {
  bad += undefinedCalls.length;
  console.log('calls with no definition:');
  for (const [name, line] of undefinedCalls) console.log(`  ${name}()  first used at line ${line} of the script`);
}
if (danglingSelectors.length) {
  bad += danglingSelectors.length;
  console.log('selectors with no element:');
  for (const [id, line] of danglingSelectors) console.log(`  #${id}  used at line ${line} of the script, no element defines it`);
}
console.log(bad
  ? `\nFAIL: ${bad} problem(s) — a click on one of these throws at runtime, with no syntax error to warn you.`
  : `\nok: ${declared.size} names declared, ${called.size} called, ${markupIds.size} element ids — nothing dangling.`);
process.exit(bad ? 1 : 0);
