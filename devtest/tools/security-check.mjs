#!/usr/bin/env node
/**
 * Security posture check.
 *
 * Two halves, because the runtime forces it: this script runs the commands
 * that need a shell (npm audit, git), writes what they said to
 * storage/security-facts.json, and then invokes the PHP half which makes every
 * judgement. php-wasm has no proc_open, so doing this the other way round is
 * not possible — and doing it all in Node would put the checks somewhere a
 * reader of the PHP codebase would never look.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';

const root = path.resolve(process.cwd());
const factsFile = path.join(root, 'storage', 'security-facts.json');

function run(cmd, args) {
  try {
    return execFileSync(cmd, args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  } catch {
    return null;
  }
}

// 1. Dependency advisories.
let audit = null;
const auditOut = run('npm', ['audit', '--json']);
if (auditOut) {
  try {
    audit = JSON.parse(auditOut);
  } catch {
    audit = null;
  }
}

// 2. Is .env tracked? A committed secret is a credential leak, not a style nit.
const envTracked = run('git', ['ls-files', '--error-unmatch', '.env']) !== null;

fs.mkdirSync(path.dirname(factsFile), { recursive: true });
fs.writeFileSync(factsFile, JSON.stringify({ audit, env_tracked: envTracked, gathered_at: new Date().toISOString() }, null, 2));

// 3. Hand over to PHP for the judgement.
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
await useHostFilesystem(php);

const strictFlag = process.argv.includes('--strict') ? ' --strict' : '';
const r = await php.run({ code: `<?php $_SERVER['argv'] = ['security-check']; require ${JSON.stringify(path.join(root, 'tools', 'security-check.php'))};` });
process.stdout.write(r.text ?? '');
if (r.errors) process.stderr.write(String(r.errors));

try {
  fs.unlinkSync(factsFile);
} catch {
  /* the file is recreated on every run */
}

const failed = /[1-9]\d* failure\(s\)/.test(r.text ?? '') || !!r.errors;
process.exit(process.argv.includes('--strict') && failed ? 1 : 0);
