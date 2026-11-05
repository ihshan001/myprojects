<?php
$completed = (int) ($summary['completed'] ?? 0);
$total = (int) ($summary['total'] ?? 0);
$noShow = (int) ($summary['no_show'] ?? 0);
$skipped = (int) ($summary['skipped'] ?? 0);
$completionRate = $total > 0 ? round($completed / $total * 100) : 0;
$peak = 0;
foreach ($byHour as $h) { $peak = max($peak, (int) $h['total']); }
?>
<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Reports</h1>
    <p class="mt-1 text-sm text-slate-500">Recorded, not estimated — every figure comes from real token timestamps.</p>
  </div>
  <div class="flex flex-wrap gap-2 no-print">
    <a href="/reports/export?from=<?= e($date) ?>&to=<?= e($date) ?>" class="btn-secondary">
      <svg class="h-4 w-4"><use href="#i-download"/></svg>
      Export this day
    </a>
    <button onclick="window.print()" class="btn-secondary">
      <svg class="h-4 w-4"><use href="#i-print"/></svg>
      Print
    </button>
  </div>
</div>

<form method="get" action="/reports" class="mt-5 flex flex-wrap items-end gap-2 no-print">
  <div><label class="label" for="date">Report day</label>
    <input class="input !py-2" type="date" id="date" name="date" value="<?= e($date) ?>"></div>
  <div><label class="label" for="from">Doctor range from</label>
    <input class="input !py-2" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
  <div><label class="label" for="to">to</label>
    <input class="input !py-2" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
  <button class="btn-secondary">Apply</button>
  <a href="/reports" class="btn-ghost text-xs">Reset</a>
</form>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <?php
  $cards = [
      ['Tokens issued', $total, 'queue', 'teal', e(date('j M', strtotime($date)))],
      ['Completed', $completed, 'check-circle', 'emerald', $completionRate . '% of tokens'],
      ['No shows + skips', $noShow + $skipped, 'user-x', 'rose', $noShow . ' no-show · ' . $skipped . ' skipped'],
      ['Average wait', ($summary['avg_wait'] ?? null) !== null ? round((float) $summary['avg_wait']) . ' min' : '—', 'clock', 'amber',
       ($summary['max_wait'] ?? null) !== null ? 'longest ' . round((float) $summary['max_wait']) . ' min' : 'no data'],
  ];
  foreach ($cards as [$label, $value, $icon, $tone, $hint]) {
      include QP_ROOT . '/resources/views/components/stat.php';
  }
  ?>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-2">
  <!-- arrivals by hour -->
  <div class="card card-pad">
    <div class="flex items-baseline justify-between">
      <h2 class="text-sm font-bold text-slate-900">Arrivals by hour</h2>
      <p class="text-[11px] text-slate-400"><?= e(date('j M Y', strtotime($date))) ?></p>
    </div>
    <?php if ($byHour === []): ?>
      <p class="py-10 text-center text-sm text-slate-400">No arrivals recorded on this day.</p>
    <?php else: ?>
      <div class="mt-4 space-y-2">
        <?php foreach ($byHour as $h): ?>
          <?php $pct = $peak > 0 ? max(4, round((int) $h['total'] / $peak * 100)) : 4; ?>
          <div class="flex items-center gap-3">
            <span class="w-10 shrink-0 text-right font-mono text-[11px] text-slate-500"><?= e((string) $h['hour']) ?>:00</span>
            <div class="h-6 flex-1 overflow-hidden rounded bg-slate-100">
              <div class="flex h-full items-center justify-end rounded bg-teal-600/85 px-2 text-[10px] font-bold text-white tabular"
                   style="width: <?= $pct ?>%"><?= (int) $h['total'] ?></div>
            </div>
            <span class="w-14 shrink-0 text-right text-[10px] text-slate-400 tabular">
              <?= $h['avg_wait'] !== null ? round((float) $h['avg_wait']) . 'm wait' : '—' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="mt-3 text-[11px] text-slate-400">Bar = tokens issued. Right label = average wait for that hour.</p>
    <?php endif; ?>
  </div>

  <!-- outcomes -->
  <div class="card card-pad">
    <h2 class="text-sm font-bold text-slate-900">Outcomes</h2>
    <div class="mt-4 space-y-3">
      <?php
      $outcomes = [
          ['Completed', $completed, 'bg-emerald-500'],
          ['No show', $noShow, 'bg-rose-500'],
          ['Skipped', $skipped, 'bg-amber-500'],
          ['Cancelled', (int) ($summary['cancelled'] ?? 0), 'bg-slate-400'],
      ];
      foreach ($outcomes as [$label, $n, $bar]):
          $pct = $total > 0 ? round($n / $total * 100) : 0;
      ?>
        <div>
          <div class="flex items-baseline justify-between text-xs">
            <span class="font-medium text-slate-700"><?= e($label) ?></span>
            <span class="tabular text-slate-500"><?= $n ?> · <?= $pct ?>%</span>
          </div>
          <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full <?= $bar ?>" style="width: <?= $pct ?>%"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-5 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-xs">
      <div>
        <p class="text-slate-500">Avg consultation</p>
        <p class="mt-0.5 text-lg font-bold tabular text-slate-900">
          <?= ($summary['avg_consult'] ?? null) !== null ? round((float) $summary['avg_consult']) . ' min' : '—' ?>
        </p>
      </div>
      <div>
        <p class="text-slate-500">Clinical priority</p>
        <p class="mt-0.5 text-lg font-bold tabular text-slate-900"><?= (int) ($summary['prioritised'] ?? 0) ?></p>
      </div>
    </div>
  </div>
</div>

<!-- by doctor -->
<div class="card mt-5 overflow-hidden">
  <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
    <p class="text-xs font-semibold text-slate-600">By doctor</p>
    <p class="text-[11px] text-slate-400"><?= e($from) ?> → <?= e($to) ?></p>
  </div>
  <?php if ($byDoctor === []): ?>
    <p class="px-4 py-10 text-center text-sm text-slate-400">No doctors to report on.</p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Doctor</th>
          <th class="numeric">Seen</th>
          <th class="numeric hidden sm:table-cell">Completed</th>
          <th class="numeric hidden md:table-cell">Avg wait</th>
          <th class="numeric hidden md:table-cell">Avg consult</th>
          <th class="numeric hidden lg:table-cell">Priority</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($byDoctor as $d): ?>
          <tr>
            <td>
              <p class="font-medium text-slate-900"><?= e($d['name']) ?></p>
              <p class="text-[11px] text-slate-400"><?= e($d['specialty'] ?? 'General') ?></p>
            </td>
            <td class="numeric"><?= (int) $d['seen'] ?></td>
            <td class="numeric hidden sm:table-cell"><?= (int) $d['completed'] ?></td>
            <td class="numeric hidden md:table-cell"><?= $d['avg_wait'] !== null ? round((float) $d['avg_wait']) . 'm' : '—' ?></td>
            <td class="numeric hidden md:table-cell"><?= $d['avg_consult'] !== null ? round((float) $d['avg_consult']) . 'm' : '—' ?></td>
            <td class="numeric hidden lg:table-cell"><?= (int) $d['prioritised'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
