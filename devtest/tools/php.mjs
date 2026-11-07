#!/usr/bin/env node
/** Run a PHP script with the local PHP 8.3 wasm runtime (host FS mounted). */
import fs from 'node:fs';
import path from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';

const script = process.argv[2];
if (!script) { console.error('usage: node tools/php.mjs <file.php> [args...]'); process.exit(1); }
const root = path.resolve(process.cwd());
const file = path.resolve(root, script);

const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
await useHostFilesystem(php);
const args = process.argv.slice(3);
const code = `<?php
$_SERVER['argv'] = json_decode(${JSON.stringify(JSON.stringify(['php', file, ...args]))}, true);
$_SERVER['argc'] = ${args.length + 2};
$_SERVER['REQUEST_METHOD'] = 'CLI';
chdir(${JSON.stringify(root)});
require ${JSON.stringify(file)};
`;
const r = await php.run({ code });
process.stdout.write(r.text ?? '');
if (r.errors) process.stderr.write(String(r.errors));
process.exit(r.errors ? 1 : 0);
