<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Platform overview</h1>
    <p class="mt-1 text-sm text-slate-500">Every tenant, one console. You are viewing all clinics, not one.</p>
  </div>
  <span class="badge-violet">Super admin</span>
</div>

<?php if (($health['status'] ?? 'ok') !== 'ok' || ($alerts ?? []) !== []): ?>
  <div class="mt-5 rounded-xl border <?= ($health['status'] ?? 'ok') === 'critical' ? 'border-rose-300 bg-rose-50' : 'border-amber-300 bg-amber-50' ?> px-4 py-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-xs font-bold <?= ($health['status'] ?? 'ok') === 'critical' ? 'text-rose-900' : 'text-amber-900' ?>">
        Platform health: <?= e((string) ($health['status'] ?? 'ok')) ?>
      </p>
      <a href="/api/health" class="text-[11px] font-semibold underline <?= ($health['status'] ?? 'ok') === 'critical' ? 'text-rose-800' : 'text-amber-800' ?>">
        Full report
      </a>
    </div>
    <ul class="mt-2 space-y-1">
      <?php foreach ($alerts as $alert): ?>
        <li class="text-[11px] leading-relaxed <?= $alert['severity'] === 'critical' ? 'text-rose-800' : 'text-amber-800' ?>">
          <strong><?= e((string) $alert['code']) ?></strong> — <?= e((string) $alert['message']) ?>
          <span class="text-[10px] opacity-70">(since <?= e(ago((string) $alert['created_at'])) ?>)</span>
        </li>
      <?php endforeach; ?>
      <?php if (($alerts ?? []) === []): ?>
        <li class="text-[11px] text-amber-800">No stored alerts, but the live health check is not <code>ok</code>.</li>
      <?php endif; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <?php
  $cards = [
      ['Clinics', $totals['clinics'], 'building', 'teal', $totals['active'] . ' active · ' . $totals['trial'] . ' trial'],
      ['MRR', money($mrr), 'chart', 'emerald', 'active subscriptions'],
      ['Suspended', $totals['suspended'], 'lock', 'rose', 'data preserved'],
      ['Tokens today', $totals['patients_today'], 'queue', 'sky', 'across all tenants'],
  ];
  foreach ($cards as [$label, $value, $icon, $tone, $hint]) {
      include QP_ROOT . '/resources/views/components/stat.php';
  }
  ?>
</div>

<div class="card mt-5 overflow-hidden">
  <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
    <p class="text-xs font-semibold text-slate-600">Tenants</p>
    <a href="/admin/clinics" class="text-[11px] font-semibold text-teal-700 hover:underline">Manage clinics →</a>
  </div>
  <div class="table-scroll">
    <table class="table">
    <thead>
    <tr><th>Clinic</th><th class="hidden sm:table-cell">Plan</th><th>Status</th><th class="numeric hidden md:table-cell">Staff</th><th class="numeric hidden lg:table-cell">Tokens today</th><th class="hidden lg:table-cell">Joined</th></tr>
    </thead>
    <tbody>
    <?php foreach ($clinics as $c): ?>
    <tr>
    <td>
    <div class="flex items-center gap-2.5">
    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[10px] font-bold text-white"
    style="background: <?= e((string) $c['brand_color']) ?>"><?= e(App\Core\Str::initials((string) $c['name'])) ?></span>
    <div class="min-w-0">
    <p class="truncate font-medium text-slate-900"><?= e($c['name']) ?></p>
    <p class="truncate text-[11px] text-slate-400"><?= e((string) $c['slug']) ?>.queuepilot · <?= e((string) ($c['city'] ?? '')) ?></p>
  </div>
              </div>
            </td>
            <td class="hidden sm:table-cell text-xs"><?= e((string) ($c['plan_name'] ?? '—')) ?></td>
            <td>
              <span class="<?= $c['billing_status'] === 'active' ? 'badge-emerald' : ($c['billing_status'] === 'trial' ? 'badge-amber' : 'badge-rose') ?>">
                <?= e((string) $c['billing_status']) ?>
              </span>
            </td>
            <td class="numeric hidden md:table-cell text-xs"><?= (int) $c['staff_count'] ?></td>
            <td class="numeric hidden lg:table-cell text-xs"><?= (int) $c['today_entries'] ?></td>
            <td class="hidden lg:table-cell text-xs text-slate-500"><?= e(date('j M Y', strtotime((string) $c['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
