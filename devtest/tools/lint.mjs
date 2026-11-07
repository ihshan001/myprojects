#!/usr/bin/env node
/**
 * PHP lint via token_get_all(TOKEN_PARSE) — throws on parse errors without
 * needing proc_open() (unsupported in the WASM runtime).
 */
import fs from 'node:fs';
import path from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';

const root = path.resolve(process.cwd());
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
await useHostFilesystem(php);

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', '.git', 'storage'].includes(e.name)) continue;
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.php')) out.push(p.replace(root + '/', ''));
  }
  return out;
}

const files = walk(root);
const code = `<?php
$files = json_decode(${JSON.stringify(JSON.stringify(files))}, true);
$root = ${JSON.stringify(root)};
$bad = 0;
foreach ($files as $rel) {
    $f = $root . '/' . $rel;
    try {
        token_get_all(file_get_contents($f), TOKEN_PARSE);
    } catch (Throwable $e) {
        echo "SYNTAX {$rel}: " . $e->getMessage() . " (line " . $e->getLine() . ")\\n";
        $bad++;
    }
}
echo "checked " . count($files) . " files, " . $bad . " parse errors\\n";
`;
const r = await php.run({ code });
process.stdout.write(r.text ?? '');
if (r.errors) process.stderr.write(String(r.errors));
// The WASM PHP runtime keeps the event loop alive; without an explicit exit
// the process never terminates and any `cmd && cmd` chain stalls forever.
process.exit((r.errors || /parse errors/.test(r.text ?? '')) && !/ 0 parse errors/.test(r.text ?? '') ? 1 : 0);
