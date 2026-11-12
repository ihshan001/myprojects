/**
 * PHP-style form field parsing for the development server.
 *
 * A browser posting `<input name="config[endpoint]">` sends the literal key
 * `config[endpoint]`. Real PHP (mod_php / php-fpm) turns that into
 * $_POST['config']['endpoint']; `URLSearchParams` hands us the flat literal.
 * Without this translation every array-shaped form field silently arrives as
 * a string that nothing reads, and the value is dropped on the floor — the
 * form looks like it saved and it did not.
 *
 * Kept in its own module so the behaviour is testable without booting a
 * server; see tests/form-parser.test.mjs.
 */

/**
 * Expand PHP-style bracket field names into nested arrays.
 *
 * A browser posting `<input name="config[endpoint]">` sends the literal key
 * `config[endpoint]`; real PHP (mod_php / php-fpm) turns that into
 * $_POST['config']['endpoint']. URLSearchParams gives us the flat literal,
 * so without this every array-shaped form field silently arrives as a
 * string nobody reads and the value is dropped on the floor — the form
 * looks like it saved and didn't.
 *
 * Handles the three shapes the app uses: `a[b]`, `a[b][c]` and the
 * append form `a[]`.
 */
export function expandPhpKeys(entries) {
  const out = {};
  for (const [rawKey, value] of entries) {
    const segments = [];
    const head = rawKey.slice(0, rawKey.indexOf('[') === -1 ? rawKey.length : rawKey.indexOf('['));

    // Walk the bracketed part, tolerating a stray or unbalanced bracket:
    // an unparseable name degrades to a flat key rather than throwing.
    const bracketed = rawKey.slice(head.length);
    const parts = bracketed.match(/\[[^\]]*\]/g) || [];
    // Anything outside brackets after the head means the name was malformed.
    const consumed = parts.join('').length;
    if (head === '' || consumed !== bracketed.length) {
      out[rawKey] = value;
      continue;
    }
    segments.push(head, ...parts.map((p) => p.slice(1, -1)));

    let cursor = out;
    for (let i = 0; i < segments.length; i++) {
      const seg = segments[i];
      const last = i === segments.length - 1;
      if (seg === '') {
        // `a[]` appends. Only valid at the end; elsewhere it is a 0 key.
        if (!Array.isArray(cursor)) {
          out[rawKey] = value;
          cursor = null;
          break;
        }
        if (last) {
          cursor.push(value);
        } else {
          cursor.push([]);
          cursor = cursor[cursor.length - 1];
        }
        continue;
      }
      if (last) {
        if (Array.isArray(cursor)) {
          out[rawKey] = value;
          cursor = null;
          break;
        }
        cursor[seg] = value;
      } else {
        const nextIsList = segments[i + 1] === '';
        if (!Array.isArray(cursor) && (typeof cursor[seg] !== 'object' || cursor[seg] === null)) {
          cursor[seg] = nextIsList ? [] : {};
        }
        cursor = cursor[seg];
      }
    }
  }
  return out;
}

