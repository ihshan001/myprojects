#!/usr/bin/env node
/**
 * Promote queuepilot-dev -> queuepilot (the main system).
 *
 *   node tools/promote.mjs            # DRY RUN: show what would change
 *   node tools/promote.mjs --apply    # actually copy
 *
 * Run from inside queuepilot-dev/.
 *
 * WHAT THIS COPIES
 * ----------------
 * Application directories only: app/, config/, database/, resources/, routes/,
 * public/, docs/, tests/, and the standard project files. These are byte-for-
 * byte identical between the two trees except for changes you deliberately
 * made in the sandbox, which is what makes a diff here trustworthy.
 *
 * WHAT IT NEVER COPIES — the safety boundary
 * -----------------------------------------
 *   dev/                    the auto-sign-in harness and role map
 *   tools/dev-serve.mjs     the server that injects it
 *   tools/dev-fleet.mjs     the fleet runner
 *   tools/promote.mjs       this file
 *   storage/                databases, logs, sessions, generated front files
 *   node_modules/ .env      dependencies and local secrets
 *   DEV-README.md           sandbox documentation
 *
 * The login bypass therefore cannot reach the main system by promotion. It
 * lives only in files this tool refuses to copy, and no application file
 * references it.
 *
 * A dry run is the default because promotion overwrites the reference system.
 * Nothing is deleted from the target: a file removed in dev is reported, and
 * you remove it by hand if that is what you meant.
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const DEV = path.resolve(process.cwd());
const MAIN = path.resolve(DEV, '..', 'queuepilot');

if (path.basename(DEV) !== 'queuepilot-dev') {
  console.error('[promote] run this from inside queuepilot-dev/');
  process.exit(1);
}
if (!fs.existsSync(MAIN)) {
  console.error(`[promote] main system not found at ${MAIN}`);
  process.exit(1);
}

const APPLY = process.argv.includes('--apply');

// Application payload. Anything not listed here is not promoted.
const PROMOTE_DIRS = ['app', 'config', 'database', 'resources', 'routes', 'public', 'docs', 'tests'];
// package.json and .gitignore are deliberately NOT promoted. In the sandbox
// they carry the `queuepilot-dev` name, the fleet/promote scripts and the
// dev-only ignore rules; copying them would rename the main system and point
// its `npm run dev` at sandbox tooling. Dependency changes are promoted via
// package-lock.json plus a deliberate, hand-reviewed edit of the main
// package.json — never by overwriting it wholesale.
const PROMOTE_FILES = [
  'README.md', 'package-lock.json', '.env.example',
  '.dockerignore', 'docker-compose.yml',
];

// Reported so a dependency change is not silently forgotten.
const SANDBOX_LOCAL = ['package.json', '.gitignore', 'DEV-README.md'];

// Never promoted, even if it appears inside a promoted directory.
const EXCLUDE_DIRS = new Set(['node_modules', '.git', 'storage', 'dev', '.arena']);
const EXCLUDE_FILES = new Set([
  'tools/dev-serve.mjs', 'tools/dev-fleet.mjs', 'tools/promote.mjs', 'DEV-README.md',
]);

function walk(dir, base = dir, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (EXCLUDE_DIRS.has(e.name)) continue;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, base, out);
    else out.push(path.relative(base, full));
  }
  return out;
}

const hash = (p) => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');

// Build the candidate list relative to the project root.
const candidates = [];
for (const d of PROMOTE_DIRS) {
  for (const rel of walk(path.join(DEV, d))) candidates.push(path.join(d, rel));
}
for (const f of PROMOTE_FILES) {
  if (fs.existsSync(path.join(DEV, f))) candidates.push(f);
}
// Standard tools, minus the dev-only ones.
for (const rel of walk(path.join(DEV, 'tools'))) {
  const relPosix = path.join('tools', rel).split(path.sep).join('/');
  if (!EXCLUDE_FILES.has(relPosix)) candidates.push(path.join('tools', rel));
}

const added = [];
const changed = [];
const same = [];

for (const rel of candidates) {
  const src = path.join(DEV, rel);
  const dst = path.join(MAIN, rel);
  if (!fs.existsSync(dst)) added.push(rel);
  else if (hash(src) !== hash(dst)) changed.push(rel);
  else same.push(rel);
}

// Files that exist in main but not in dev — reported, never auto-deleted.
const mainOnly = [];
for (const d of PROMOTE_DIRS) {
  for (const rel of walk(path.join(MAIN, d))) {
    const r = path.join(d, rel);
    if (!fs.existsSync(path.join(DEV, r))) mainOnly.push(r);
  }
}

console.log('');
console.log(`  Promote queuepilot-dev → queuepilot   ${APPLY ? '[APPLYING]' : '[DRY RUN]'}`);
console.log('  ' + '─'.repeat(64));

if (!added.length && !changed.length) {
  console.log('  No differences. The sandbox matches the main system.');
} else {
  if (added.length) {
    console.log(`\n  NEW (${added.length}):`);
    for (const f of added) console.log(`    + ${f}`);
  }
  if (changed.length) {
    console.log(`\n  MODIFIED (${changed.length}):`);
    for (const f of changed) console.log(`    ~ ${f}`);
  }
}
console.log(`\n  unchanged: ${same.length} file(s)`);

if (mainOnly.length) {
  console.log(`\n  IN MAIN ONLY (${mainOnly.length}) — not deleted, review by hand:`);
  for (const f of mainOnly) console.log(`    ? ${f}`);
}

console.log('\n  Never promoted: dev/, dev-serve.mjs, dev-fleet.mjs, promote.mjs,');
console.log('                  storage/, node_modules/, .env, DEV-README.md');

// If a sandbox-local file differs, say so loudly: the usual reason is a new
// dependency, and forgetting to add it to the main package.json produces a
// main system that installs cleanly and then cannot find a module.
const localDiffs = SANDBOX_LOCAL.filter((f) => {
  const a = path.join(DEV, f);
  const b = path.join(MAIN, f);
  return fs.existsSync(a) && fs.existsSync(b) && hash(a) !== hash(b);
});
if (localDiffs.length) {
  console.log(`\n  SANDBOX-LOCAL, differs on purpose (${localDiffs.length}) — review by hand:`);
  for (const f of localDiffs) console.log(`    ! ${f}`);
  console.log('    (package.json holds the dev name + fleet scripts; do not copy it.)');
}

if (!APPLY) {
  console.log('\n  Dry run. Nothing was written. Re-run with --apply to copy.\n');
  process.exit(0);
}

if (!added.length && !changed.length) {
  console.log('\n  Nothing to do.\n');
  process.exit(0);
}

let n = 0;
for (const rel of [...added, ...changed]) {
  const dst = path.join(MAIN, rel);
  fs.mkdirSync(path.dirname(dst), { recursive: true });
  fs.copyFileSync(path.join(DEV, rel), dst);
  n++;
}
console.log(`\n  Copied ${n} file(s) into queuepilot/.`);
console.log('  Next: cd ../queuepilot && npm run lint && npm test && npm run health\n');
