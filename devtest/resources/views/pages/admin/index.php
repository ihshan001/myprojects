<?php
/**
 * Platform owner console. Same shell, same panel, same stat tiles and rail
 * cards as the clinic board — only the data differs. A platform admin has no
 * clinic, so "today at a glance" counts tenants rather than patients.
 */
$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$parts = preg_split('/\s+/', trim((string) ($user['name'] ?? ''))) ?: [];
$shortName = implode(' ', array_slice($parts, 0, 2));
$healthOk = ($health['status'] ?? 'ok') === 'ok';
$stats = [
    ['Clinics', (string) $totals['clinics'], 'tenants', 'building', 'bg-brand-50 text-brand-600'],
    ['Active', (string) $totals['active'], 'subscribed', 'check-circle', 'bg-sky-50 text-sky-500'],
    ['MRR', 'Rs ' . number_format((float) $mrr), 'per month', 'chart', 'bg-emerald-50 text-emerald-500'],
    ['Suspended', (string) $totals['suspended'], 'data kept', 'lock', 'bg-violet-50 text-violet-500'],
    ['Tokens today', (string) $totals['patients_today'], 'all tenants', 'ticket', 'bg-amber-50 text-amber-500'],
];
$glance = [
    ['Clinics', (int) $totals['clinics']],
    ['Active', (int) $totals['active']],
    ['On trial', (int) $totals['trial']],
    ['Suspended', (int) $totals['suspended']],
    ['Tokens today', (int) $totals['patients_today']],
    ['Open alerts', count($alerts ?? [])],
];
?>
<div class="lg:board-panel">

  <div class="card p-4 lg:rounded-none lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-[17px] font-bold leading-snug tracking-tight text-ink-900 sm:text-[22px]">
          <?= e($greet) ?>, <?= e($shortName) ?> <span class="emoji align-middle">&#128075;</span>
        </h1>
        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-ink-500 sm:text-[12px]">
          <span class="tabular"><?= e(date('l, j F Y')) ?> &middot; <?= e(date('g:i A')) ?></span>
          <span class="rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-semibold text-violet-700">All tenants</span>
        </div>
        <p class="text-[11px] text-ink-400">Every clinic, one console.</p>
      </div>
      <a href="/api/health" target="_blank"
         class="flex shrink-0 items-center gap-2 rounded-xl border border-hairline bg-white px-3 py-2.5 text-[11px] font-semibold text-ink-700 shadow-card transition hover:bg-slate-50 sm:px-4 sm:text-[12px]">
        <svg class="h-[18px] w-[18px] shrink-0 text-ink-500"><use href="#i-shield-check"/></svg>
        <span class="max-w-[72px] text-left leading-tight sm:max-w-none">Health report</span>
      </a>
    </div>
  </div>

  <div class="mt-3 flex gap-1.5 overflow-x-auto pb-1 sm:mt-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:overflow-visible sm:pb-0 lg:grid-cols-5">
    <?php foreach ($stats as [$label, $value, $hint, $icon, $tone]): ?>
      <div class="stat-tile w-[104px] shrink-0 sm:w-auto">
        <span class="stat-ico <?= e($tone) ?>"><svg class="h-5 w-5"><use href="#i-<?= e($icon) ?>"/></svg></span>
        <div class="min-w-0 flex-1">
          <p class="stat-label"><?= e($label) ?></p>
          <p class="stat-value"><?= e($value) ?></p>
          <p class="stat-hint"><?= e($hint) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-3 grid min-w-0 gap-3 sm:mt-7 lg:grid-cols-[minmax(0,1fr)_352px] lg:gap-7">

    <section class="card min-w-0 p-3 sm:p-4">
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <h2 class="text-[17px] font-bold tracking-tight text-ink-900">Tenants</h2>
          <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700"><?= count($clinics) ?></span>
        </div>
        <a href="/admin/clinics" class="btn-primary !px-3 !py-1.5 !text-[12px]">
          <svg class="h-3.5 w-3.5"><use href="#i-building"/></svg>Manage clinics
        </a>
      </div>

      <div class="mt-3 rounded-xl border border-hairline">
        <div class="table-scroll">
          <table class="table">
            <thead>
              <tr>
                <th>Clinic</th>
                <th class="hidden sm:table-cell">Plan</th>
                <th>Status</th>
                <th class="numeric hidden md:table-cell">Staff</th>
                <th class="numeric hidden lg:table-cell">Tokens</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($clinics as $c): ?>
                <tr>
                  <td>
                    <div class="flex items-center gap-2.5">
                      <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-700 text-[10px] font-bold text-white">
                        <?= e(App\Core\Str::initials((string) $c['name'])) ?>
                      </span>
                      <div class="min-w-0">
                        <p class="truncate text-[13px] font-semibold text-ink-900"><?= e($c['name']) ?></p>
                        <p class="truncate text-[11px] text-ink-500"><?= e((string) $c['slug']) ?> &middot; <?= e((string) ($c['city'] ?? '')) ?></p>
                      </div>
                    </div>
                  </td>
                  <td class="hidden text-xs sm:table-cell"><?= e((string) ($c['plan_name'] ?? '—')) ?></td>
                  <td>
                    <span class="<?= $c['billing_status'] === 'active' ? 'badge-emerald' : ($c['billing_status'] === 'trial' ? 'badge-amber' : 'badge-rose') ?>">
                      <?= e((string) $c['billing_status']) ?>
                    </span>
                  </td>
                  <td class="numeric hidden text-xs md:table-cell"><?= (int) $c['staff_count'] ?></td>
                  <td class="numeric hidden text-xs lg:table-cell"><?= (int) $c['today_entries'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <div class="grid min-w-0 grid-cols-2 gap-3 lg:block lg:space-y-5">

      <?php if (!$healthOk || ($alerts ?? []) !== []): ?>
        <section class="card col-span-2 border-amber-200 bg-amber-50/50 p-3 sm:p-4">
          <h2 class="text-[12.5px] font-bold tracking-tight text-amber-900 sm:text-[15px]">Needs attention</h2>
          <ul class="mt-2 space-y-1.5">
            <?php foreach (($alerts ?? []) as $alert): ?>
              <li class="text-[11px] leading-snug text-amber-900">
                <strong><?= e((string) $alert['code']) ?></strong> — <?= e((string) $alert['message']) ?>
              </li>
            <?php endforeach; ?>
            <?php if (($alerts ?? []) === []): ?>
              <li class="text-[11px] text-amber-900">Live health check is not <code>ok</code>.</li>
            <?php endif; ?>
          </ul>
        </section>
      <?php endif; ?>

      <section class="card p-3 sm:p-4">
        <h2 class="text-[12.5px] font-bold tracking-tight text-ink-900 sm:text-[15px]">Today at a glance</h2>
        <div class="mt-1.5">
          <?php foreach ($glance as [$label, $val]): ?>
            <div class="glance-row">
              <span class="text-ink-500"><?= e($label) ?></span>
              <span class="font-bold text-ink-900 tabular"><?= e((string) $val) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <a href="/admin/plans" class="mt-2 flex items-center justify-center gap-1.5 py-1 text-[12px] font-semibold text-ink-700 hover:text-brand-700">
          <svg class="h-3.5 w-3.5"><use href="#i-external"/></svg>Plans &amp; billing
        </a>
      </section>

      <section class="card p-3 sm:p-4">
        <h2 class="text-[12.5px] font-bold tracking-tight text-ink-900 sm:text-[15px]">Support queue</h2>
        <p class="mt-1.5 text-[11.5px] leading-snug text-ink-500">
          Tenant requests and payment verifications land here.
        </p>
        <a href="/admin/support" class="btn-primary mt-2.5 w-full !py-2.5 !text-[13px]">
          <svg class="h-4 w-4"><use href="#i-lifebuoy"/></svg>Open support
        </a>
      </section>

      <section class="card col-span-2 p-3 sm:p-4">
        <h2 class="text-[12.5px] font-bold tracking-tight text-ink-900 sm:text-[15px]">System status</h2>
        <div class="mt-2 flex flex-wrap items-center gap-x-6 gap-y-1.5">
          <p class="flex items-center gap-2 text-[12px] font-semibold <?= $healthOk ? 'text-brand-700' : 'text-amber-700' ?>">
            <svg class="h-[18px] w-[18px]"><use href="#i-shield-check"/></svg>
            <?= $healthOk ? 'All systems operational' : 'Health: ' . e((string) ($health['status'] ?? 'unknown')) ?>
          </p>
          <p class="text-[11px] text-ink-500">Last updated: <span class="tabular"><?= e(date('g:i:s A')) ?></span></p>
        </div>
      </section>
    </div>
  </div>
</div>
