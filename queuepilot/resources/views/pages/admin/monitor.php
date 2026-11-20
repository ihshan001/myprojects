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
 *
 * @var string $status
 * @var array<int, array<string, mixed>> $checks
 * @var array<int, array<string, mixed>> $incidents
 * @var array<int, array<string, mixed>> $alerts
 * @var array<int, array<string, mixed>> $clinics
 * @var array<string, mixed>|null $scoped
 * @var string|null $generated
 */
$status    = (string) ($status ?? 'ok');
$checks    = $checks ?? [];
$incidents = $incidents ?? [];
$alerts    = $alerts ?? [];
$clinics   = $clinics ?? [];
$scoped    = $scoped ?? null;

$tone = match ($status) {
    'critical' => ['label' => 'Critical', 'chip' => 'border-red-200 bg-red-50 text-red-700', 'dot' => 'bg-red-500'],
    'warning'  => ['label' => 'Warning',  'chip' => 'border-amber-200 bg-amber-50 text-amber-800', 'dot' => 'bg-amber-500'],
    default    => ['label' => 'Healthy',  'chip' => 'border-emerald-200 bg-emerald-50 text-emerald-700', 'dot' => 'bg-emerald-500'],
};

// Two vocabularies meet on this page and they are not the same list. The
// health checks say ok / warning / critical; incidents say minor / major /
// critical (IncidentService::SEVERITIES). Mapping both, because a fallback
// to the 'ok' green would paint a major incident as healthy — the exact
// misreading a health page exists to prevent.
$sevTone = [
    'critical' => 'border-red-200 bg-red-50 text-red-700',
    'warning'  => 'border-amber-200 bg-amber-50 text-amber-800',
    'major'    => 'border-amber-200 bg-amber-50 text-amber-800',
    'minor'    => 'border-sky-200 bg-sky-50 text-sky-800',
    'ok'       => 'border-emerald-200 bg-emerald-50 text-emerald-700',
];

$human = static fn (string $key): string => ucwords(str_replace('_', ' ', $key));
?>

<div class="flex flex-wrap items-start justify-between gap-3">
  <div class="min-w-0">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Platform health</h1>
    <p class="mt-1 text-sm text-slate-500">
      <?php if ($scoped !== null): ?>
        Checks scoped to <strong class="font-semibold text-slate-700"><?= e((string) $scoped['name']) ?></strong>.
      <?php else: ?>
        Every check the external monitor runs, across all clinics.
      <?php endif; ?>
      <?php if ($generated !== null): ?>
        Generated <span class="font-medium text-slate-700"><?= e((string) $generated) ?></span>.
      <?php endif; ?>
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

<?php if ($alerts !== []): ?>
  <!-- The checks that are not OK, above the fold. A green grid of twelve
       cards is useless at 3 a.m.; what matters is the two that are not. -->
  <div class="mt-4 space-y-2">
    <?php foreach ($alerts as $alert): ?>
      <div class="rounded-lg border px-4 py-2.5 text-[13px] <?= $sevTone[(string) ($alert['severity'] ?? 'warning')] ?? $sevTone['warning'] ?>">
        <span class="font-semibold"><?= e($human((string) ($alert['key'] ?? 'check'))) ?></span>
        <span class="ml-1"><?= e((string) ($alert['summary'] ?? '')) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="mt-4 flex flex-wrap items-end gap-2">
  <form method="get" action="/admin/monitor" class="flex items-end gap-2">
    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500" for="clinic">
      Scope to one clinic
    </label>
    <select class="select !py-1.5 text-xs" id="clinic" name="clinic">
      <option value="0">All clinics</option>
      <?php foreach ($clinics as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $scoped !== null && (int) $scoped['id'] === (int) $c['id'] ? 'selected' : '' ?>>
          <?= e((string) $c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn-secondary !py-1.5 text-xs">Show</button>
  </form>
</div>

<?php if ($checks === []): ?>
  <div class="mt-4 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500">
    No checks reported. The monitor service returned an empty set.
  </div>
<?php else: ?>
  <div class="mt-4 grid gap-3 sm:grid-cols-2">
    <?php foreach ($checks as $c): ?>
      <?php
      $sev    = (string) ($c['severity'] ?? 'ok');
      $detail = $c['detail'] ?? [];
      $name   = (string) ($c['key'] ?? 'check');
      ?>
      <div class="card card-pad">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-[13px] font-semibold text-slate-900"><?= e($human($name)) ?></p>
            <p class="mt-0.5 text-[12px] text-slate-600"><?= e((string) ($c['summary'] ?? '')) ?></p>
          </div>
          <span class="shrink-0 rounded-full border px-2 py-0.5 text-[10.5px] font-semibold uppercase <?= $sevTone[$sev] ?? $sevTone['ok'] ?>">
            <?= e($sev) ?>
          </span>
        </div>
        <?php if (is_array($detail) && $detail !== []): ?>
          <dl class="mt-3 space-y-1 border-t border-slate-100 pt-2.5">
            <?php foreach ($detail as $k => $v): ?>
              <div class="flex items-baseline justify-between gap-3 text-[11.5px]">
                <dt class="text-slate-500"><?= e($human((string) $k)) ?></dt>
                <dd class="truncate font-mono text-slate-800"><?= e(is_scalar($v) ? (string) $v : json_encode($v)) ?></dd>
              </div>
            <?php endforeach; ?>
          </dl>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($incidents !== []): ?>
  <section class="mt-6">
    <div class="mb-2 flex items-baseline justify-between">
      <h2 class="text-[13px] font-semibold text-slate-900">Recent incidents</h2>
      <a href="/incidents" class="text-[11.5px] font-semibold text-brand-700 hover:underline">Open all</a>
    </div>
    <div class="card overflow-hidden">
      <table class="table text-[12px]">
        <thead>
          <tr>
            <th>Reference</th>
            <th>Title</th>
            <th>Severity</th>
            <th>Status</th>
            <th>Detected</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($incidents as $inc): ?>
            <tr>
              <td class="font-mono text-[11px] text-slate-700"><?= e((string) ($inc['reference'] ?? '')) ?></td>
              <td class="text-slate-800"><?= e((string) ($inc['title'] ?? '')) ?></td>
              <td>
                <span class="rounded-full border px-2 py-0.5 text-[10.5px] font-semibold <?= $sevTone[(string) ($inc['severity'] ?? 'ok')] ?? $sevTone['ok'] ?>">
                  <?= e((string) ($inc['severity'] ?? '')) ?>
                </span>
              </td>
              <td class="text-slate-600"><?= e((string) ($inc['status'] ?? '')) ?></td>
              <td class="text-slate-500"><?= e((string) ($inc['detected_at'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>
