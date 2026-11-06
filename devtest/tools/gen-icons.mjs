#!/usr/bin/env node
/** Render the PWA icons from an SVG string (no external tools). */
import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const out = path.resolve('public/assets/img');
const svg = fs.readFileSync(path.join(out, 'icon.svg'));

for (const size of [192, 512]) {
  await sharp(svg, { density: 384 }).resize(size, size).png().toFile(path.join(out, `icon-${size}.png`));
  console.log(`icon-${size}.png`);
}
// also a small favicon
await sharp(svg, { density: 384 }).resize(32, 32).png().toFile(path.join(out, 'favicon.png'));
console.log('favicon.png');
