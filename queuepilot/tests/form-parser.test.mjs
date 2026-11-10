/**
 * Tests for the dev server's PHP-style form parsing (tools/form-parser.mjs).
 *
 * The bug this guards: `URLSearchParams` gives flat literal keys, so
 * `name="config[endpoint]"` arrived as the string key 'config[endpoint]'.
 * No error, no warning — the field just quietly never saved. Anything that
 * regresses here fails silently in the browser, which is the worst kind of
 * failure to find by hand.
 *
 * Run with `npm run test:tools`.
 */

import assert from 'node:assert/strict';
import { expandPhpKeys } from '../tools/form-parser.mjs';

let checks = 0;
const failures = [];

function test(name, fn) {
  try {
    fn();
    checks++;
    console.log(`  \x1b[32m✓\x1b[0m ${name}`);
  } catch (error) {
    failures.push({ name, message: error.message });
    console.log(`  \x1b[31m✗\x1b[0m ${name}`);
  }
}

/** @param {Array<[string, string]>} entries */
const parse = (entries) => expandPhpKeys(entries);

test('a plain field stays a plain field', () => {
  assert.deepEqual(parse([['_token', 'abc']]), { _token: 'abc' });
});

test('a bracketed name becomes a nested array', () => {
  assert.deepEqual(parse([['config[endpoint]', 'https://x']]), {
    config: { endpoint: 'https://x' },
  });
});

test('several bracketed fields group under one key', () => {
  assert.deepEqual(
    parse([
      ['config[a]', '1'],
      ['config[b]', '2'],
    ]),
    { config: { a: '1', b: '2' } }
  );
});

test('nesting goes deeper than one level', () => {
  assert.deepEqual(parse([['a[b][c]', 'deep']]), { a: { b: { c: 'deep' } } });
});

test('the append form collects repeated values into a list', () => {
  assert.deepEqual(
    parse([
      ['list[]', 'one'],
      ['list[]', 'two'],
    ]),
    { list: ['one', 'two'] }
  );
});

test('a nested list is reachable at depth', () => {
  assert.deepEqual(
    parse([
      ['row[tags][]', 'urgent'],
      ['row[tags][]', 'repeat'],
    ]),
    { row: { tags: ['urgent', 'repeat'] } }
  );
});

test('an empty bracket at the end appends', () => {
  assert.deepEqual(parse([['a[]', 'x']]), { a: ['x'] });
});

test('a name with no brackets at all is untouched', () => {
  assert.deepEqual(parse([['provider', 'webhooks']]), { provider: 'webhooks' });
});

test('flat and nested fields coexist', () => {
  assert.deepEqual(
    parse([
      ['_token', 't'],
      ['provider', 'webhooks'],
      ['config[endpoint]', 'https://x'],
    ]),
    { _token: 't', provider: 'webhooks', config: { endpoint: 'https://x' } }
  );
});

test('an unbalanced bracket degrades to a flat key rather than throwing', () => {
  assert.deepEqual(parse([['weird[unclosed', 'v']]), { 'weird[unclosed': 'v' });
});

test('an empty value is preserved, not dropped', () => {
  assert.deepEqual(parse([['config[endpoint]', '']]), { config: { endpoint: '' } });
});

test('the real integrations form round-trips', () => {
  const body = new URLSearchParams();
  body.set('_token', 'csrf-123');
  body.set('provider', 'webhooks');
  body.set('config[endpoint]', 'https://hooks.example.com/qp');
  const parsed = parse([...body.entries()]);

  assert.equal(parsed._token, 'csrf-123');
  assert.equal(parsed.provider, 'webhooks');
  assert.deepEqual(parsed.config, { endpoint: 'https://hooks.example.com/qp' });
  // The service reads this shape; if it were flat, connect() would see no
  // whitelisted keys and store an empty config.
  assert.equal(typeof parsed.config, 'object');
  assert.ok(!Array.isArray(parsed.config));
});

console.log('');
if (failures.length > 0) {
  console.log(`\x1b[31m${failures.length} failed\x1b[0m, ${checks} passed\n`);
  for (const f of failures) {
    console.log(`   • ${f.name} → ${f.message}`);
  }
  process.exit(1);
}
console.log(`\x1b[32mAll ${checks} tool checks passed.\x1b[0m`);
