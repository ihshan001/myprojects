#!/usr/bin/env node
/**
 * DEV SANDBOX fleet — run every role port at once.
 *
 *   node tools/dev-fleet.mjs            # all ports in dev/roles.json
 *   node tools/dev-fleet.mjs 8080 8082  # just these
 *
 * Each port is a child process running dev-serve.mjs. They share one SQLite
 * database (WAL + busy_timeout, see app/Core/Db.php), so an action taken as
 * the receptionist on 8081 is immediately visible to the doctor on 8082 —
 * which is the entire reason to run a fleet rather than one server.
 *
 * Ctrl-C stops every child. A child that dies is reported, not silently
 * restarted, because a port that keeps crashing is a bug you need to see.
 */
import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';

const ROOT = path.resolve(process.cwd());
const roster = JSON.parse(fs.readFileSync(path.join(ROOT, 'dev/roles.json'), 'utf8'));

const requested = process.argv.slice(2).filter((a) => /^\d+$/.test(a)).map(Number);
const ports = requested.length
  ? roster.ports.filter((p) => requested.includes(Number(p.port)))
  : roster.ports;

if (!ports.length) {
  console.error('[fleet] no matching ports in dev/roles.json');
  process.exit(1);
}

const dbFile = path.join(ROOT, 'storage/db/queuepilot.sqlite');
if (!fs.existsSync(dbFile)) {
  console.error(
    '[fleet] no database yet. Run this first:\n' +
    '    npm run migrate && npm run seed'
  );
  process.exit(1);
}

console.log('');
console.log('  QueuePilot DEV fleet — development sandbox, not the main system');
console.log('  ' + '─'.repeat(66));
for (const p of ports) {
  console.log(`  ${p.port}  ${p.label.padEnd(26)} ${p.email.padEnd(26)} ${p.home}`);
}
console.log('  ' + '─'.repeat(66));
console.log('  One shared database. Actions on one port appear on the others.');
console.log('');

const children = [];
let stopping = false;

for (const p of ports) {
  const child = spawn(process.execPath, ['tools/dev-serve.mjs', '--port', String(p.port)], {
    cwd: ROOT,
    stdio: ['ignore', 'pipe', 'pipe'],
    env: { ...process.env },
  });

  const prefix = `[${p.port}]`;
  const relay = (stream, out) => {
    let buf = '';
    stream.on('data', (chunk) => {
      buf += chunk.toString();
      const lines = buf.split('\n');
      buf = lines.pop() ?? '';
      for (const line of lines) {
        if (line.trim()) out.write(`${prefix} ${line.replace(/^\[dev:\d+\]\s*/, '')}\n`);
      }
    });
  };
  relay(child.stdout, process.stdout);
  relay(child.stderr, process.stderr);

  child.on('exit', (code, signal) => {
    if (stopping) return;
    console.error(`${prefix} EXITED (code=${code} signal=${signal}) — this port is now down.`);
  });

  children.push(child);
}

function stopAll(signal = 'SIGTERM') {
  if (stopping) return;
  stopping = true;
  console.log('\n[fleet] stopping all ports…');
  for (const c of children) {
    try { c.kill(signal); } catch {}
  }
  // Give the children a moment to unlink their generated front controllers.
  setTimeout(() => process.exit(0), 1200);
}

process.on('SIGINT', () => stopAll());
process.on('SIGTERM', () => stopAll());
