#!/usr/bin/env node
/**
 * Seeds GitDock with demo projects, using exactly the same HTTP API the UI uses.
 * Run AFTER the panel is up:  node scripts/seed.js
 */
'use strict';
const BASE = process.env.GITDOCK_URL || 'http://127.0.0.1:3000';

const api = async (path, opts = {}) => {
  const res = await fetch(BASE + path, { headers: { 'content-type': 'application/json' }, ...opts });
  const j = await res.json().catch(() => ({ ok: false, error: 'bad json' }));
  if (!j.ok) throw new Error(`${path} -> ${j.error || res.status}`);
  return j;
};
const post = (p, body) => api(p, { method: 'POST', body: JSON.stringify(body || {}) });
const put = (p, body) => api(p, { method: 'PUT', body: JSON.stringify(body) });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const PHP_MYSQL_PAGE = `<?php
// A real dynamic page: reads its database credentials from the .env GitDock injected.
$cfg = [
  'host' => getenv('DB_HOST') ?: '127.0.0.1',
  'port' => getenv('DB_PORT') ?: '3306',
  'name' => getenv('DB_DATABASE') ?: 'app',
  'user' => getenv('DB_USERNAME') ?: 'root',
  'pass' => getenv('DB_PASSWORD') ?: '',
];
$error = null; $products = []; $total = 0;
try {
  $pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4",
                 $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $products = $pdo->query('SELECT name, price, stock FROM products ORDER BY price DESC')->fetchAll(PDO::FETCH_ASSOC);
  $total = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
} catch (Throwable $e) { $error = $e->getMessage(); }
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acme Shop — PHP + MySQL</title>
<style>
 :root{color-scheme:dark}
 body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b1020;color:#e6edf7;padding:2rem}
 h1{margin:0 0 .2rem;font-size:1.6rem} .sub{color:#8b9cc0;margin-bottom:1.2rem}
 .card{background:#121a2f;border:1px solid #232f4d;border-radius:12px;padding:1rem;max-width:760px;margin-bottom:1rem}
 table{border-collapse:collapse;width:100%} th,td{text-align:left;padding:.45rem .5rem;border-bottom:1px solid #1a2440}
 th{color:#8b9cc0;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em}
 .pill{display:inline-block;background:#1b2540;border:1px solid #232f4d;border-radius:999px;padding:.1rem .55rem;font-size:.8rem;color:#9fb6e6}
 .ok{color:#4ade80} .err{color:#ff8f8f} code{background:#161c33;padding:.1rem .35rem;border-radius:5px}
 a{color:#5b8cff}
</style></head><body>
<h1>Acme Shop</h1>
<div class="sub">PHP <?= PHP_VERSION ?> · served from a port GitDock started · data from MySQL</div>

<?php if ($error): ?>
  <div class="card"><b class="err">Database not reachable</b><div class="sub" style="margin:.4rem 0 0"><?= htmlspecialchars($error) ?></div>
  <div class="sub">Open the Database tab and press “provision &amp; inject”, then restart this service.</div></div>
<?php else: ?>
  <div class="card">
    <span class="pill">db: <?= htmlspecialchars($cfg['name']) ?></span>
    <span class="pill"><?= count($products) ?> products</span>
    <span class="pill"><?= $total ?> orders</span>
    <table style="margin-top:.8rem">
      <tr><th>product</th><th>price</th><th>stock</th></tr>
      <?php foreach ($products as $p): ?>
        <tr><td><?= htmlspecialchars($p['name']) ?></td>
            <td>$<?= number_format((float)$p['price'], 2) ?></td>
            <td class="<?= $p['stock'] > 0 ? 'ok' : 'err' ?>"><?= (int)$p['stock'] ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<div class="card">
  <b>What this proves</b>
  <div class="sub" style="margin-top:.3rem">
    A folder was dropped in the browser → committed as one git commit → started on its own port →
    connected to a MySQL database that GitDock created and injected into <code>.env</code>.
  </div>
</div>
</body></html>
`;

const SCHEMA_SQL = `
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  qty INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO products (name, price, stock) VALUES
  ('Mechanical keyboard', 89.00, 12),
  ('USB-C dock', 129.50, 4),
  ('27" monitor', 249.99, 0),
  ('Laptop stand', 39.00, 25);

INSERT INTO orders (product_id, qty) VALUES (1,2),(1,1),(2,1),(4,3),(2,2);
`;

async function ensureProject(name, template) {
  const list = await api('/api/projects');
  const existing = list.projects.find((p) => p.name === name);
  if (existing) return { ...existing, fresh: false };
  const r = await post('/api/projects', { name, template });
  await sleep(300);
  return { ...r, fresh: true };
}

async function main() {
  console.log('GitDock seed →', BASE);

  // ---- 1. static site ------------------------------------------------------
  const stat = await ensureProject('Storefront', 'static');
  console.log('• static project:', stat.slug, stat.fresh ? '(created)' : '(already there)');
  if (stat.fresh) {
  await put(`/api/projects/${stat.slug}/file?path=${encodeURIComponent('about.html')}`, {
    content: `<!doctype html><html><head><meta charset="utf-8"><title>About</title>
<style>body{font-family:system-ui;background:#0b1020;color:#e6edf7;padding:2rem;max-width:640px;margin:auto}</style></head>
<body><h1>About</h1><p>Second page — proves the whole folder came across, not just index.html.</p>
<p><a href="/" style="color:#5b8cff">← home</a></p></body></html>`,
    message: 'Add about page',
  });
  }
  await post(`/api/projects/${stat.slug}/services/website/start`, {});

  // ---- 2. php + mysql ------------------------------------------------------
  const php = await ensureProject('Acme Shop (PHP + MySQL)', 'php');
  console.log('• php project:', php.slug, php.fresh ? '(created)' : '(already there)');
  if (php.fresh) {
  await put(`/api/projects/${php.slug}/file?path=${encodeURIComponent('index.php')}`, {
    content: PHP_MYSQL_PAGE, message: 'Wire the homepage to MySQL',
  });
  await put(`/api/projects/${php.slug}/file?path=${encodeURIComponent('db/schema.sql')}`, {
    content: SCHEMA_SQL.trim() + '\n', message: 'Add schema.sql',
  });
  const prov = await post(`/api/projects/${php.slug}/db/provision`, {});
  console.log('  provisioned MySQL db:', prov.database);
  const res = await fetch(`${BASE}/api/projects/${php.slug}/db/import`, { method: 'POST', body: SCHEMA_SQL });
  const imp = await res.json();
  console.log('  imported tables:', (imp.tables || []).join(', '));
  }
  await post(`/api/projects/${php.slug}/services/website/start`, {});
  await sleep(1200);

  // ---- 3. a second service on another port (multi-app demo) ----------------
  await post(`/api/projects/${php.slug}/services`, {
    id: 'admin', runtime: 'php', path: 'admin', docroot: '.',
  }).catch((e) => console.log('  (admin service:', e.message + ')'));
  await put(`/api/projects/${php.slug}/file?path=${encodeURIComponent('admin/index.php')}`, {
    content: `<?php
// Second app in the same repo, on its own port — the "admin" backend.
$orders = @new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_DATABASE'),
                   getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
$rows = $orders ? $orders->query('SELECT id, product_id, qty, created_at FROM orders ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) : [];
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acme Admin</title><style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b1020;color:#e6edf7;padding:2rem}
table{border-collapse:collapse;margin-top:1rem}th,td{border-bottom:1px solid #1a2440;padding:.4rem .6rem;text-align:left}
th{color:#8b9cc0;font-size:.85rem;text-transform:uppercase}.badge{background:#1b2540;border:1px solid #232f4d;border-radius:999px;padding:.1rem .5rem;font-size:.8rem;color:#9fb6e6}</style>
</head><body>
<h1>Acme Admin</h1><div class="badge">same repo · different port · same database</div>
<table><tr><th>order</th><th>product</th><th>qty</th><th>placed</th></tr>
<?php foreach ($rows as $r): ?><tr><td>#<?= (int)$r['id'] ?></td><td><?= (int)$r['product_id'] ?></td><td><?= (int)$r['qty'] ?></td><td><?= htmlspecialchars($r['created_at']) ?></td></tr><?php endforeach; ?>
</table></body></html>`,
    message: 'Add admin app (second service)',
  }).catch(() => {});
  await post(`/api/projects/${php.slug}/services/admin/start`, {}).catch((e) => console.log('  start admin:', e.message));

  // ---- 4. manifest-driven project -----------------------------------------
  const man = await ensureProject('Manifest Demo', 'static');
  console.log('• manifest project:', man.slug, man.fresh ? '(created)' : '(already there)');
  if (man.fresh) {
    await put(`/api/projects/${man.slug}/file?path=${encodeURIComponent('internal/index.html')}`, {
      content: `<!doctype html><html><head><meta charset="utf-8"><title>Internal</title>
<style>body{font-family:system-ui;background:#0b1020;color:#e6edf7;padding:2rem}</style></head>
<body><h1>Internal tool</h1><p>Second service, declared in <code>gitdock.yaml</code> — same repo, its own port.</p></body></html>`,
      message: 'Add internal app',
    });
    await put(`/api/projects/${man.slug}/file?path=.env.example`, {
      content: 'APP_ENV=\nAPP_NAME=\nDB_HOST=\n', message: 'Add .env.example',
    });
    await put(`/api/projects/${man.slug}/setup/manifest`, {
      text: [
        'version: 1', '', 'project:',
        `  name: ${man.slug}`, '  runtime: static', '', 'services:',
        '  - id: website', '    path: .', '    runtime: static', '    public: true',
        '  - id: internal', '    path: internal', '    runtime: static', '    public: true',
        '', 'databases:', '  - id: main', '    engine: mysql', '',
        'env:', '  requiredFrom: .env.example', '  defaults:',
        '    APP_ENV: local', `    APP_NAME: Manifest Demo`, '',
        'preview:', '  ttlHours: 2', '  onPullRequest: true', '',
        'resources:', '  sleepAfterIdleMinutes: 10', '',
      ].join('\n'),
      message: 'Add gitdock.yaml',
    });
    await post(`/api/projects/${man.slug}/setup/apply`, { runInstall: false, start: true });
    console.log('  applied manifest: services + database + env defaults');
  }

  // ---- 5. Laravel ----------------------------------------------------------
  const lar = await ensureProject('Laravel Starter', 'laravel');
  console.log('• laravel project:', lar.slug, lar.fresh ? '(composer is installing in the background)' : '(already there)');

  const list = await api('/api/projects');
  for (const p of list.projects) {
    const svc = await api(`/api/projects/${p.slug}/services`);
    const ports = svc.services.filter((s) => s.running).map((s) => `${s.id}:${s.port}`).join(' ');
    console.log(`  ${p.slug.padEnd(28)} ${p.runtime.padEnd(8)} ${ports || '(no services running)'}`);
  }
  console.log('\nDone. Open the GitDock panel and poke around.');
}

main().catch((e) => { console.error('seed failed:', e.message); process.exit(1); });
