/**
 * Manifest support for GitDock.
 *
 * Two jobs:
 *   1. parse `gitdock.yaml`          — a small, dependency-free YAML subset parser
 *   2. detect a project's setup      — the detection ladder, used when there is no manifest
 *
 * The subset deliberately covers what a manifest needs: nested maps, lists of maps,
 * inline {maps} and [lists], quoted scalars, comments. No anchors, no multi-doc, no block scalars.
 */
'use strict';
const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');

// ---------------------------------------------------------------------------
// 1. YAML subset parser
// ---------------------------------------------------------------------------

function stripComment(line) {
  let out = '';
  let quote = null;
  for (let i = 0; i < line.length; i++) {
    const c = line[i];
    if (quote) {
      out += c;
      if (c === quote && line[i - 1] !== '\\') quote = null;
      continue;
    }
    if (c === '"' || c === "'") { quote = c; out += c; continue; }
    if (c === '#' && (i === 0 || /\s/.test(line[i - 1]))) break;
    out += c;
  }
  return out.replace(/\s+$/, '');
}

function parseScalar(raw) {
  const v = String(raw).trim();
  if (v === '' || v === '~' || v === 'null') return null;
  if (v === 'true') return true;
  if (v === 'false') return false;
  if (/^-?\d+$/.test(v)) return Number(v);
  if (/^-?\d*\.\d+$/.test(v)) return Number(v);
  if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) {
    return v.slice(1, -1).replace(/\\"/g, '"');
  }
  if (v.startsWith('[') && v.endsWith(']')) return splitInline(v.slice(1, -1)).map(parseScalar);
  if (v.startsWith('{') && v.endsWith('}')) {
    const obj = {};
    for (const part of splitInline(v.slice(1, -1))) {
      const m = part.match(/^\s*([^:]+):\s*(.*)$/);
      if (m) obj[m[1].trim().replace(/^["']|["']$/g, '')] = parseScalar(m[2]);
    }
    return obj;
  }
  return v;
}

/** split on commas that are not inside quotes or brackets */
function splitInline(s) {
  const out = [];
  let depth = 0, quote = null, cur = '';
  for (const c of s) {
    if (quote) { cur += c; if (c === quote) quote = null; continue; }
    if (c === '"' || c === "'") { quote = c; cur += c; continue; }
    if (c === '[' || c === '{') depth++;
    if (c === ']' || c === '}') depth--;
    if (c === ',' && depth === 0) { out.push(cur); cur = ''; continue; }
    cur += c;
  }
  if (cur.trim()) out.push(cur);
  return out.map((x) => x.trim()).filter((x) => x.length);
}

function parseYaml(text) {
  const lines = String(text)
    .replace(/\r\n?/g, '\n')
    .split('\n')
    .map((raw) => {
      const stripped = stripComment(raw);
      if (!stripped.trim()) return null;
      const indent = stripped.match(/^ */)[0].length;
      return { indent, content: stripped.trim(), raw: stripped };
    })
    .filter(Boolean);

  let i = 0;

  function parseBlock(indent) {
    if (i >= lines.length) return [null, i];
    const isList = lines[i].content.startsWith('- ') || lines[i].content === '-';
    const container = isList ? [] : {};

    while (i < lines.length) {
      const line = lines[i];
      if (line.indent < indent) break;
      if (line.indent > indent && !isList) break; // shouldn't happen; handled by callers
      if (line.indent > indent && isList) break;

      if (isList) {
        if (!(line.content.startsWith('- ') || line.content === '-')) break;
        const rest = line.content.slice(1).trim();
        if (!rest) {
          i++;
          const [child] = parseBlock(nextIndent(indent));
          container.push(child);
          continue;
        }
        const kv = rest.match(/^([^:]+):\s*(.*)$/);
        if (kv) {
          // list item that starts a map: - id: website
          const item = {};
          const key = kv[1].trim();
          const val = kv[2].trim();
          i++;
          if (val === '') {
            const [child] = parseBlock(nextIndent(indent));
            item[key] = child;
          } else {
            item[key] = parseScalar(val);
          }
          // continuation lines of the same map (deeper than the dash)
          while (i < lines.length && lines[i].indent > indent && !lines[i].content.startsWith('- ')) {
            const cont = lines[i];
            const m = cont.content.match(/^([^:]+):\s*(.*)$/);
            if (!m) { i++; continue; }
            const k2 = m[1].trim();
            const v2 = m[2].trim();
            i++;
            if (v2 === '') {
              const base = lines[i] ? lines[i].indent : cont.indent + 2;
              const [child] = parseBlock(base);
              item[k2] = child;
            } else {
              item[k2] = parseScalar(v2);
            }
          }
          container.push(item);
          continue;
        }
        container.push(parseScalar(rest));
        i++;
        continue;
      }

      // mapping
      const m = line.content.match(/^([^:]+):\s*(.*)$/);
      if (!m) { i++; continue; }
      const key = m[1].trim().replace(/^["']|["']$/g, '');
      const val = m[2].trim();
      i++;
      if (val === '') {
        const next = lines[i];
        if (next && next.indent > indent) {
          const [child] = parseBlock(next.indent);
          container[key] = child;
        } else if (next && next.indent === indent && (next.content.startsWith('- ') || next.content === '-')) {
          const [child] = parseBlock(indent);
          container[key] = child;
        } else {
          container[key] = null;
        }
      } else {
        container[key] = parseScalar(val);
      }
    }
    return [container, i];
  }

  function nextIndent(currentIndent) {
    return lines[i] ? lines[i].indent : currentIndent + 2;
  }

  const [value] = parseBlock(lines.length ? lines[0].indent : 0);
  return value;
}

// ---------------------------------------------------------------------------
// 2. detection ladder
// ---------------------------------------------------------------------------

const readJsonSafe = async (file) => {
  try { return JSON.parse(await fsp.readFile(file, 'utf8')); } catch { return null; }
};

/**
 * Inspect a project directory and infer how it should run.
 * Every rule adds a human-readable reason, which the UI shows — never silent magic.
 */
async function detectProject(root) {
  const exists = (f) => fs.existsSync(path.join(root, f));
  const reasons = [];
  const has = (f, why) => { const ok = exists(f); if (ok) reasons.push(why); return ok; };

  const artisan = has('artisan', 'artisan present');
  const composer = await readJsonSafe(path.join(root, 'composer.json'));
  const pkg = await readJsonSafe(path.join(root, 'package.json'));
  const isLaravel = !!(composer && (composer.require || {})['laravel/framework']);
  if (isLaravel) reasons.push('composer.json requires laravel/framework');
  const isWordPress = has('wp-config.php', 'wp-config.php present') || has('wp-content', 'wp-content/ present');
  const hasIndexHtml = has('index.html', 'index.html present');
  const hasIndexPhp = has('index.php', 'index.php present');
  const hasDockerfile = has('Dockerfile', 'Dockerfile present');
  const publicDir = exists('public');
  if (publicDir) reasons.push('public/ directory present');

  // A static site is very often NOT at the repo root (you drop a "website/" folder into an app repo,
  // or a build writes dist/). Look one level down before concluding there is no entry point.
  const STATIC_ROOTS = ['dist', 'build', 'public', 'web', 'site', 'www', 'html', 'public_html'];
  const staticRootCandidates = [];
  for (const dir of STATIC_ROOTS) {
    if (fs.existsSync(path.join(root, dir, 'index.html'))) staticRootCandidates.push(dir);
  }
  let subEntries = [];
  try {
    subEntries = (await fsp.readdir(root, { withFileTypes: true })).filter((e) => e.isDirectory() && !e.name.startsWith('.') && e.name !== 'node_modules');
  } catch {}
  for (const e of subEntries) {
    if (STATIC_ROOTS.includes(e.name)) continue;
    if (fs.existsSync(path.join(root, e.name, 'index.html')) && !staticRootCandidates.includes(e.name)) {
      staticRootCandidates.push(e.name);
    }
  }
  const staticSubdir = staticRootCandidates[0] || null;
  if (staticSubdir) reasons.push(`index.html found in ${staticSubdir}/ — serving that folder`);

  const out = {
    runtime: 'static',
    docroot: staticSubdir || '.',
    install: [],
    services: [],
    databases: [],
    env: { requiredFrom: exists('.env.example') ? '.env.example' : null, defaults: {}, force: {} },
    preview: { ttlHours: 72, onPullRequest: false },
    resources: { sleepAfterIdleMinutes: 15 },
    confidence: 'low',
    reasons,
    flags: [],
  };

  if (isLaravel || artisan) {
    out.runtime = 'laravel';
    out.docroot = publicDir ? 'public' : '.';
    out.install.push('composer install --no-dev --optimize-autoloader');
    if (pkg && (pkg.devDependencies || pkg.dependencies) && exists('vite.config.js')) out.install.push('npm ci && npm run build');
    out.services.push({ id: 'website', runtime: 'laravel', path: '.', docroot: out.docroot, public: true });
    if (await mentions(root, ['queue:work', 'queue:listen'], ['app', 'routes', 'config'])) {
      out.services.push({ id: 'queue', runtime: 'worker', command: 'php artisan queue:work --tries=3' });
      reasons.push('queue worker referenced in source');
    }
    out.databases.push({ id: 'main', engine: envEngine(await readEnvExample(root)) || 'mysql' });
    out.confidence = 'high';
  } else if (isWordPress) {
    out.runtime = 'php';
    out.docroot = '.';
    out.install.push('composer install --no-dev --optimize-autoloader'); // only if vendor missing
    out.services.push({ id: 'website', runtime: 'php', path: '.', docroot: '.', public: true });
    out.databases.push({ id: 'main', engine: 'mysql' });
    out.flags.push('WordPress: homepage and siteurl live in the database — rewrite them on import or the site redirects to the old host');
    out.confidence = 'high';
  } else if (composer) {
    out.runtime = 'php';
    out.docroot = publicDir ? 'public' : '.';
    out.install.push('composer install --no-dev --optimize-autoloader');
    out.services.push({ id: 'website', runtime: 'php', path: '.', docroot: out.docroot, public: true });
    out.confidence = 'medium';
  } else if (pkg && (pkg.scripts && (pkg.scripts.start || pkg.scripts.dev))) {
    out.runtime = 'node';
    out.install.push('npm ci');
    out.services.push({ id: 'website', runtime: 'node', path: '.', docroot: '.', public: true });
    out.confidence = 'medium';
  } else if (hasIndexPhp) {
    out.runtime = 'php';
    out.services.push({ id: 'website', runtime: 'php', path: '.', docroot: '.', public: true });
    out.confidence = 'medium';
  } else if (hasIndexHtml || staticSubdir) {
    out.runtime = 'static';
    out.docroot = hasIndexHtml ? '.' : staticSubdir;
    out.services.push({ id: 'website', runtime: 'static', path: out.docroot, docroot: '.', public: true });
    out.confidence = hasIndexHtml ? 'high' : 'medium';
  }

  if (hasDockerfile) out.flags.push('Dockerfile found — GitDock ignored it; a Docker runtime is a later milestone');
  if (!out.services.length) out.flags.push('no entry point found — defaulting to static hosting of the project root');

  // A gitdock.yaml in the repo wins over guessing: the team wrote down what they want.
  const manifest = await readManifest(root);
  if (manifest.ok && manifest.parsed) {
    try {
      const plan = planFromManifest(manifest.parsed);
      const declared = (plan.services || []).filter((svc) => svc.runtime && !['worker', 'cron'].includes(svc.runtime));
      if (declared.length) {
        const missing = declared.find((svc) => !fs.existsSync(path.join(root, svc.path || '.')));
        if (missing) {
          out.flags.push(`${manifest.file}: service "${missing.id}" points at "${missing.path}", which does not exist`);
        } else {
          out.services = declared.map((svc) => ({
            id: svc.id,
            runtime: svc.runtime === 'php' || svc.runtime === 'laravel' ? 'php' : svc.runtime,
            path: svc.path || '.',
            docroot: svc.docroot || '.',
            public: svc.public !== false,
            command: svc.command || null,
          }));
          out.runtime = plan.runtime || declared[0].runtime;
          out.docroot = plan.docroot || declared[0].docroot || '.';
          out.manifest = { file: manifest.file, services: declared.length };
          out.confidence = 'high';
          reasons.push(`${manifest.file} declares ${declared.length} service(s): ${declared.map((x) => `${x.id} (${x.runtime} → ${x.path})`).join(', ')}`);
        }
      }
    } catch (e) {
      out.flags.push(`${manifest.file} could not be applied: ${e.message}`);
    }
  }
  return out;
}

async function mentions(root, needles, dirs) {
  for (const d of dirs) {
    const dir = path.join(root, d);
    if (!fs.existsSync(dir)) continue;
    try {
      const files = await fsp.readdir(dir, { recursive: true });
      for (const f of files.slice(0, 400)) {
        if (!/\.(php|js|json)$/.test(String(f))) continue;
        try {
          const txt = await fsp.readFile(path.join(dir, String(f)), 'utf8');
          if (needles.some((n) => txt.includes(n))) return true;
        } catch {}
      }
    } catch {}
  }
  return false;
}

async function readEnvExample(root) {
  for (const f of ['.env.example', '.env.sample', '.env.dist']) {
    try { return await fsp.readFile(path.join(root, f), 'utf8'); } catch {}
  }
  return '';
}

function envEngine(envText) {
  const m = String(envText).match(/^\s*DB_CONNECTION\s*=\s*(\w+)/m);
  return m ? m[1] : null;
}

// ---------------------------------------------------------------------------
// 3. manifest -> plan
// ---------------------------------------------------------------------------

const MANIFEST_FILES = ['gitdock.yaml', 'gitdock.yml', 'gitdock.json'];

async function readManifest(root) {
  for (const f of MANIFEST_FILES) {
    const full = path.join(root, f);
    if (!fs.existsSync(full)) continue;
    const text = await fsp.readFile(full, 'utf8');
    try {
      const parsed = f.endsWith('.json') ? JSON.parse(text) : parseYaml(text);
      return { file: f, text, parsed, errors: [], ok: true };
    } catch (e) {
      return { file: f, text, parsed: null, errors: [e.message], ok: false };
    }
  }
  return { file: null, text: '', parsed: null, errors: [], ok: true };
}

/** turn a parsed manifest into something the runner can execute */
function planFromManifest(parsed) {
  if (!parsed || typeof parsed !== 'object') throw new Error('manifest is not a mapping');
  const project = parsed.project || {};
  const services = (parsed.services || []).map((s) => ({
    id: String(s.id || 'website'),
    runtime: String(s.runtime || s.type || project.runtime || 'static').replace(/@[\d.]+$/, ''),
    path: s.path || '.',
    docroot: s.docroot || project.docroot || '.',
    public: s.public !== false,
    command: s.command || s.start || null,
    port: s.port ? Number(s.port) : null,
  }));
  const databases = (parsed.databases || []).map((d) => ({
    id: String(d.id || 'main'),
    engine: String(d.engine || 'sqlite'),
    import: d.import || null,
    inject: d.inject || null,
  }));
  const env = parsed.env || {};
  return {
    version: parsed.version || 1,
    name: project.name || null,
    runtime: project.runtime ? String(project.runtime).replace(/@[\d.]+$/, '') : null,
    docroot: project.docroot || null,
    install: Array.isArray(parsed.install) ? parsed.install.map(String) : [],
    services,
    databases,
    env: {
      requiredFrom: env.requiredFrom || (env.file ? null : null),
      defaults: env.defaults || {},
      force: env.force || {},
    },
    preview: {
      ttlHours: Number((parsed.preview || {}).ttlHours ?? 72),
      onPullRequest: !!(parsed.preview || {}).onPullRequest,
      public: !!(parsed.preview || {}).public,
    },
    resources: {
      sleepAfterIdleMinutes: Number((parsed.resources || {}).sleepAfterIdleMinutes ?? 15),
    },
  };
}

module.exports = { parseYaml, detectProject, readManifest, planFromManifest, MANIFEST_FILES };
