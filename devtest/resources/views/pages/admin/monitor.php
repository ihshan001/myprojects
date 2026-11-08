<?php
/**
 * Platform health.
 *
 * The same checks /api/health returns, laid out for a person. Two reasons to
 * read it here rather than curl the endpoint: a human can judge "is 340 ms of
 * database latency actually a problem right now", and a probe cannot; and the
 * failing check can be read next to the incident someone already opened about
 * it instead of in isolation.
 *
 * Super admin only — these checks name tenants, queue depth and staff.
 */
$status = (string) ($status ?? 'ok');
$checks = $checks ?? [];
$scoped = $scoped ?? null;

$tone = match ($status) {
    'critical' => ['label' => 'Critical', 'chip' => 'border-red-200 bg-red-50 text-red-700', 'dot' => 'bg-red-500'],
    'warning'  => ['label' => 'Warning',  'chip' => 'border-amber-200 bg-amber-50 text-amber-800', 'dot' => 'bg-amber-500'],
    default    => ['label' => 'Healthy',  'chip' => 'border-emerald-200 bg-emerald-50 text-emerald-700', 'dot' => 'bg-emerald-500'],
};

$sevTone = [
    'critical' => 'border-red-200 bg-red-50 text-red-700',
    'warning'  => 'border-amber-200 bg-amber-50 text-amber-800',
    'ok'       => 'border-emerald-200 bg-emerald-50 text-emerald-700',
];
?>
<div class="flex flex-wrap items-start justify-between gap-3 mb-6">
  <div class="min-w-0">
    <h1 class="text-xl font-bold text-ink-900">Platform health</h1>
    <p class="mt-1 text-sm text-ink-500">
      <?php if ($scoped !== null): ?>
        Checks scoped to <strong class="font-semibold text-ink-700"><?= e((string) $scoped['name']) ?></strong>.
      <?php else: ?>
        Every check the external monitor runs, across all clinics.
      <?php endif; ?>
      Generated <span class="font-medium text-ink-700"><?= e((string) ($generated ?? '')) ?></span>.
    </p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold <?= $tone['chip'] ?>">
      <span class="h-1.5 w-1.5 rounded-full <?= $tone['dot'] ?>"></span><?= e($tone['label']) ?>
    </span>
    <?php if ($scoped !== null): ?>
      <a href="/admin/monitor" class="btn-secondary btn-sm">All clinics</a>
    <?php endif; ?>
    <a href="/incidents" class="btn-secondary btn-sm">Incidents</a>
  </div>
</div>

<?php if ($checks === []): ?>
  <div class="rounded-lg border border-hairline bg-white p-6 text-sm text-ink-500 shadow-card">
    No checks reported. The monitor service returned an empty set.
  </div>
<?php else: ?>
  <div class="grid gap-3 sm:grid-cols-2">
    <?php foreach ($checks as $c): ?>
      <?php
      $sev = (string) ($c['severity'] ?? 'ok');
      $detail = $c['detail'] ?? [];
      $name = (string) ($c['key'] ?? 'check');
      ?>
      <div class="rounded-lg border border-hairline bg-white p-4 shadow-card">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-[13px] font-semibold text-ink-900"><?= e(ucwords(str_replace('_', ' ', $name))) ?></p>
            <p class="mt-0.5 text-[12px] text-ink-600"><?= e((string) ($c['summary'] ?? '')) ?></p>
          </div>
          <span class="shrink-0 rounded-full border px-2 py-0.5 text-[10.5px] font-semibold uppercase <?= $sevTone[$sev] ?? $sevTone['ok'] ?>">
            <?= e($sev) ?>
          </span>
        </div>
        <?php if (is_array($detail) && $detail !== []): ?>
          <dl class="mt-3 space-y-1 border-t border-hairline pt-2.5">
            <?php foreach ($detail as $k => $v): ?>
              <div class="flex items-baseline justify-between gap-3 text-[11.5px]">
                <dt class="text-ink-500"><?= e(ucwords(str_replace('_', ' ', (string) $k))) ?></dt>
                <dd class="truncate font-mono text-ink-800"><?= e(is_scalar($v) ? (string) $v : json_encode($v)) ?></dd>
              </div>
            <?php endforeach; ?>
          </dl>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($incidents)): ?>
  <section class="mt-6">
    <div class="mb-2 flex items-baseline justify-between">
      <h2 class="text-[13px] font-semibold text-ink-900">Recent incidents</h2>
      <a href="/incidents" class="text-[11.5px] font-semibold text-brand-700 hover:underline">Open all</a>
    </div>
    <div class="overflow-hidden rounded-lg border border-hairline bg-white shadow-card">
      <table class="w-full text-[12px]">
        <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-ink-500">
          <tr>
            <th class="px-3 py-2 font-semibold">Reference</th>
            <th class="px-3 py-2 font-semibold">Title</th>
            <th class="px-3 py-2 font-semibold">Severity</th>
            <th class="px-3 py-2 font-semibold">Status</th>
            <th class="px-3 py-2 font-semibold">Detected</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
          <?php foreach ($incidents as $inc): ?>
            <tr class="hover:bg-slate-50">
              <td class="px-3 py-2 font-mono text-[11px] text-ink-700"><?= e((string) ($inc['reference'] ?? '')) ?></td>
              <td class="px-3 py-2 text-ink-800"><?= e((string) ($inc['title'] ?? '')) ?></td>
              <td class="px-3 py-2"><span class="rounded-full border px-2 py-0.5 text-[10.5px] font-semibold <?= $sevTone[$inc['severity'] ?? 'ok'] ?? $sevTone['ok'] ?>"><?= e((string) ($inc['severity'] ?? '')) ?></span></td>
              <td class="px-3 py-2 text-ink-600"><?= e((string) ($inc['status'] ?? '')) ?></td>
              <td class="px-3 py-2 text-ink-500"><?= e((string) ($inc['detected_at'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>
