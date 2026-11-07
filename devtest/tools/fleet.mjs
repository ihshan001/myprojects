#!/usr/bin/env node
/**
 * DEVTEST fleet — every role on its own port, at once.
 *
 *   node tools/fleet.mjs            # all ports in dev/roles.json
 *   node tools/fleet.mjs 8081 8082  # a subset
 *
 * All ports share one SQLite database (WAL + busy_timeout in app/Core/Db.php),
 * so issuing a token as the receptionist on 8081 is immediately visible to the
 * doctor on 8082. That cross-role visibility is the reason to run a fleet.
 */
import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';

const ROOT = path.resolve(process.cwd());
const roster = JSON.parse(fs.readFileSync(path.join(ROOT, 'dev/roles.json'), 'utf8'));

const wanted = process.argv.slice(2).filter((a) => /^\d+$/.test(a)).map(Number);
const ports = wanted.length ? roster.ports.filter((p) => wanted.includes(Number(p.port))) : roster.ports;

if (!ports.length) {
  console.error('[fleet] no matching ports in dev/roles.json');
  process.exit(1);
}

if (!fs.existsSync(path.join(ROOT, 'storage/db/queuepilot.sqlite'))) {
  console.error('[fleet] no database. Run first:\n    npm run migrate && npm run seed');
  process.exit(1);
}

const pad = (s, n) => String(s).padEnd(n);
console.log('');
console.log('  DEVTEST — no sign-in, no landing page. The port is the identity.');
console.log('  ' + '─'.repeat(78));
console.log(`  ${pad('PORT', 6)}${pad('ROLE', 16)}${pad('IDENTITY', 26)}${pad('CLINIC', 22)}`);
console.log('  ' + '─'.repeat(78));
for (const p of ports) {
  console.log(`  ${pad(p.port, 6)}${pad(p.role, 16)}${pad(p.email, 26)}${pad(p.clinic || '— platform —', 22)}`);
}
console.log('  ' + '─'.repeat(78));
console.log('  Open any port at "/" — it lands on that role\'s own workspace.');
console.log('');

const children = [];
let stopping = false;

for (const p of ports) {
  const child = spawn(process.execPath, ['tools/serve-role.mjs', '--port', String(p.port)], {
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
        if (line.trim()) out.write(`${prefix} ${line.replace(/^\[devtest:\d+\]\s*/, '')}\n`);
      }
    });
  };
  relay(child.stdout, process.stdout);
  relay(child.stderr, process.stderr);
  child.on('exit', (code, signal) => {
    if (!stopping) console.error(`${prefix} EXITED (code=${code} signal=${signal}) — port is down.`);
  });
  children.push(child);
}

function stopAll() {
  if (stopping) return;
  stopping = true;
  console.log('\n[fleet] stopping all ports…');
  for (const c of children) { try { c.kill('SIGTERM'); } catch {} }
  setTimeout(() => process.exit(0), 1200);
}
process.on('SIGINT', stopAll);
process.on('SIGTERM', stopAll);
