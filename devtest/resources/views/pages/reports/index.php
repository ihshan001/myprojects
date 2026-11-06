<?php
$completed = (int) ($summary['completed'] ?? 0);
$total = (int) ($summary['total'] ?? 0);
$noShow = (int) ($summary['no_show'] ?? 0);
$skipped = (int) ($summary['skipped'] ?? 0);
$completionRate = $total > 0 ? round($completed / $total * 100) : 0;
$peak = 0;
foreach ($byHour as $h) { $peak = max($peak, (int) $h['total']); }
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Reports</h1>
    <p class="page-sub">Recorded, not estimated — every figure comes from real token timestamps.</p>
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

<form method="get" action="/reports" class="filter-bar no-print">
  <!-- Day and range are separate questions; on a phone each gets a full row
       so the three date fields never end up in a ragged 2 + 1 arrangement. -->
  <div class="w-full sm:w-auto">
    <label class="label" for="date">Report day</label>
    <input class="input !py-2" type="date" id="date" name="date" value="<?= e($date) ?>">
  </div>
  <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:items-end">
    <div><label class="label" for="from">Doctor range from</label>
      <input class="input !py-2" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div><label class="label" for="to">To</label>
      <input class="input !py-2" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
  </div>
  <div class="flex items-center gap-2">
    <button class="btn-secondary">Apply</button>
    <a href="/reports" class="btn-ghost text-xs">Reset</a>
  </div>
</form>

<div class="mt-3 grid grid-cols-2 gap-2 sm:mt-6 sm:gap-4 lg:grid-cols-4">
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

<div class="mt-3 grid gap-3 sm:mt-6 sm:gap-5 lg:grid-cols-2">
  <!-- arrivals by hour -->
  <div class="card card-pad">
    <div class="flex items-baseline justify-between">
      <h2 class="text-sm font-bold text-ink-900">Arrivals by hour</h2>
      <p class="text-[11px] text-ink-400"><?= e(date('j M Y', strtotime($date))) ?></p>
    </div>
    <?php if ($byHour === []): ?>
      <p class="py-10 text-center text-sm text-ink-400">No arrivals recorded on this day.</p>
    <?php else: ?>
      <div class="mt-4 space-y-2">
        <?php foreach ($byHour as $h): ?>
          <?php $pct = $peak > 0 ? max(4, round((int) $h['total'] / $peak * 100)) : 4; ?>
          <div class="flex items-center gap-3">
            <span class="w-10 shrink-0 text-right font-mono text-[11px] text-ink-500"><?= e((string) $h['hour']) ?>:00</span>
            <div class="h-6 flex-1 overflow-hidden rounded bg-slate-100">
              <div class="flex h-full items-center justify-end rounded bg-brand-600/85 px-2 text-[10px] font-bold text-white tabular"
                   style="width: <?= $pct ?>%"><?= (int) $h['total'] ?></div>
            </div>
            <span class="w-14 shrink-0 text-right text-[10px] text-ink-400 tabular">
              <?= $h['avg_wait'] !== null ? round((float) $h['avg_wait']) . 'm wait' : '—' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="mt-3 text-[11px] text-ink-400">Bar = tokens issued. Right label = average wait for that hour.</p>
    <?php endif; ?>
  </div>

  <!-- outcomes -->
  <div class="card card-pad">
    <h2 class="text-sm font-bold text-ink-900">Outcomes</h2>
    <div class="mt-4 space-y-3">
      <?php
      // Named distinctly from the controller's $outcomes: a local of the same
      // name shadows it for the rest of the file, which is exactly how the
      // outcome table below came to read from the wrong array.
      $outcomeBars = [
          ['Completed', $completed, 'bg-emerald-500'],
          ['No show', $noShow, 'bg-rose-500'],
          ['Skipped', $skipped, 'bg-amber-500'],
          ['Cancelled', (int) ($summary['cancelled'] ?? 0), 'bg-slate-400'],
      ];
      foreach ($outcomeBars as [$label, $n, $bar]):
          $pct = $total > 0 ? round($n / $total * 100) : 0;
      ?>
        <div>
          <div class="flex items-baseline justify-between text-xs">
            <span class="font-medium text-ink-700"><?= e($label) ?></span>
            <span class="tabular text-ink-500"><?= $n ?> · <?= $pct ?>%</span>
          </div>
          <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full <?= $bar ?>" style="width: <?= $pct ?>%"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-5 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-xs">
      <div>
        <p class="text-ink-500">Avg consultation</p>
        <p class="mt-0.5 text-lg font-bold tabular text-ink-900">
          <?= ($summary['avg_consult'] ?? null) !== null ? round((float) $summary['avg_consult']) . ' min' : '—' ?>
        </p>
      </div>
      <div>
        <p class="text-ink-500">Clinical priority</p>
        <p class="mt-0.5 text-lg font-bold tabular text-ink-900"><?= (int) ($summary['prioritised'] ?? 0) ?></p>
      </div>
    </div>
  </div>
</div>

<!-- by doctor -->
<div class="card mt-3 sm:mt-5 overflow-hidden">
  <div class="section-head">
    <p class="section-title">By doctor</p>
    <p class="text-[11px] text-ink-400"><?= e($from) ?> → <?= e($to) ?></p>
  </div>
  <?php if ($byDoctor === []): ?>
    <p class="px-4 py-10 text-center text-sm text-ink-400">No doctors to report on.</p>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table">
      <thead>
      <tr>
      <th>Doctor</th>
      <th class="numeric">Seen</th>
      <th class="numeric hidden sm:table-cell">Completed</th>
      <th class="numeric hidden md:table-cell">Avg wait</th>
      <th class="numeric hidden md:table-cell">Avg consult</th>
      <th class="numeric hidden lg:table-cell">Priority</th>
      <th class="numeric hidden lg:table-cell" title="Announced delays in this period">Delays</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($byDoctor as $d): ?>
      <tr>
      <td>
      <p class="font-medium text-ink-900"><?= e($d['name']) ?></p>
      <p class="text-[11px] text-ink-400"><?= e($d['specialty'] ?? 'General') ?></p>
      </td>
      <td class="numeric"><?= (int) $d['seen'] ?></td>
      <td class="numeric hidden sm:table-cell"><?= (int) $d['completed'] ?></td>
      <td class="numeric hidden md:table-cell"><?= $d['avg_wait'] !== null ? round((float) $d['avg_wait']) . 'm' : '—' ?></td>
      <td class="numeric hidden md:table-cell"><?= $d['avg_consult'] !== null ? round((float) $d['avg_consult']) . 'm' : '—' ?></td>
      <td class="numeric hidden lg:table-cell"><?= (int) $d['prioritised'] ?></td>
      <td class="numeric hidden lg:table-cell">
      <?php $delayCount = (int) ($d['delays'] ?? 0); ?>
      <?php if ($delayCount > 0): ?>
      <span class="badge-amber"><?= $delayCount ?>×</span>
      <?php else: ?>
      <span class="text-slate-300">—</span>
      <?php endif; ?>
      </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ==================== outcomes ==================== -->
<div class="mt-3 grid gap-3 sm:mt-6 sm:gap-5 lg:grid-cols-2">
  <div class="card overflow-hidden">
    <div class="section-head">
      <p class="section-title">Outcomes · <?= e($from) ?> to <?= e($to) ?></p>
    </div>
    <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4">
      <?php foreach ([
          ['completed', 'Completed', 'emerald'],
          ['no_show', 'Not seen', 'amber'],
          ['cancelled', 'Cancelled', 'rose'],
          ['transferred', 'Transferred', 'slate'],
      ] as [$key, $label, $tone]): ?>
        <div class="rounded-lg bg-<?= $tone ?>-50 py-2.5 text-center">
          <p class="text-xl font-bold text-<?= $tone ?>-800"><?= (int) ($outcomeStats['totals'][$key] ?? 0) ?></p>
          <p class="text-[10px] font-medium uppercase tracking-wide text-<?= $tone ?>-700"><?= e($label) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="border-t border-slate-100 px-4 py-2 text-[11px] text-ink-500">
      Lost-visit rate <b class="text-ink-700"><?= e((string) $outcomeStats['rate']) ?>%</b>
      of <?= (int) $outcomeStats['totals']['total'] ?> entries (not seen + cancelled).
    </p>
  </div>

  <div class="card overflow-hidden">
    <div class="section-head">
      <p class="section-title">Waiting time — <?= e($from) ?> to <?= e($to) ?></p>
    </div>
    <div class="grid grid-cols-4 gap-2 p-4 text-center">
      <?php foreach ([['p50', 'Median'], ['p90', '90th'], ['p99', '99th'], ['max', 'Worst']] as [$k, $label]): ?>
        <div>
          <p class="text-lg font-bold text-ink-900"><?= (int) round((float) $waits[$k]) ?><span class="text-xs font-normal text-ink-400">m</span></p>
          <p class="text-[10px] uppercase tracking-wide text-ink-500"><?= e($label) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="border-t border-slate-100 px-4 py-2 text-[10px] leading-relaxed text-ink-400">
      From <?= (int) $waits['count'] ?> called tokens. Percentiles, not an average — one
      ninety-minute outlier should not hide a fast queue.
    </p>
  </div>
</div>

<!-- ==================== revenue ==================== -->
<?php
  $rev = $revenue ?? ['rows' => [], 'total' => 0.0, 'currency' => 'LKR', 'by_day' => [], 'missing_fee' => [], 'completed' => 0];
  $revMax = 0.0;
  foreach ($rev['by_day'] as $v) { $revMax = max($revMax, (float) $v); }
?>
<div class="card mt-3 sm:mt-5 overflow-hidden">
  <div class="section-head">
    <p class="section-title">Consultation revenue</p>
    <p class="text-[11px] text-ink-400"><?= (int) $rev['completed'] ?> completed visit(s)</p>
  </div>

  <div class="grid gap-4 p-4 sm:grid-cols-[auto_1fr]">
    <div class="text-center sm:border-r sm:border-slate-100 sm:pr-5">
      <p class="text-3xl font-black tracking-tight text-ink-900"><?= e(money((float) $rev['total'])) ?></p>
      <p class="text-[10px] uppercase tracking-wide text-ink-500">billed at your fees</p>
    </div>
    <div>
      <p class="text-[11px] leading-relaxed text-ink-700">
        <b>This is what these consultations are worth at each doctor's fee — not what has been
        collected.</b> QueuePilot does not take payments: money is handed over at the desk or by
        transfer, and the system never sees it. Use this figure to reconcile a day's cash drawer,
        not as a bank balance.
      </p>
      <?php if ((float) $rev['total'] <= 0.0): ?>
        <p class="mt-2 text-[11px] leading-relaxed text-amber-700">
          No fees are set, so there is nothing to add up. Set a consultation fee on each doctor
          under Doctors, and this report starts working.
        </p>
      <?php endif; ?>
      <?php if (($rev['missing_fee'] ?? []) !== []): ?>
        <p class="mt-2 text-[11px] leading-relaxed text-amber-700">
          <b>Excluded, because no fee is set:</b> <?= e(implode(', ', $rev['missing_fee'])) ?>.
          Their visits are counted above but contribute nothing to the total.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($revMax > 0): ?>
    <div class="flex items-end gap-1 overflow-hidden border-t border-slate-100 px-4 pb-3 pt-4">
      <?php foreach ($rev['by_day'] as $day => $amount): ?>
        <div class="flex-1 min-w-0" title="<?= e((string) $day) ?>: <?= e(money((float) $amount)) ?>">
          <div class="rounded-t bg-brand-500/80" style="height: <?= max(2, (int) round(((float) $amount / $revMax) * 48)) ?>px"></div>
          <p class="mt-1 truncate text-center text-[10px] text-ink-400"><?= e(substr((string) $day, 8, 2)) ?>/<?= e(substr((string) $day, 5, 2)) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($rev['rows'] !== []): ?>
    <div class="table-scroll">
      <table class="w-full border-t border-slate-100 text-left text-xs">
      <thead class="bg-white text-[10px] uppercase tracking-wide text-ink-500">
      <tr>
      <th class="px-4 py-1.5 font-semibold">Doctor</th>
      <th class="px-4 py-1.5 text-right font-semibold">Visits</th>
      <th class="px-4 py-1.5 text-right font-semibold">Fee</th>
      <th class="px-4 py-1.5 text-right font-semibold">Billed</th>
      </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
      <?php foreach ($rev['rows'] as $row): ?>
      <tr>
      <td class="px-4 py-1.5 text-ink-700"><?= e((string) $row['name']) ?></td>
      <td class="px-4 py-1.5 text-right tabular-nums text-ink-700"><?= (int) $row['completed'] ?></td>
      <td class="px-4 py-1.5 text-right tabular-nums text-ink-500"><?= e(money((float) ($row['consult_fee'] ?? 0))) ?></td>
      <td class="px-4 py-1.5 text-right tabular-nums font-semibold text-ink-900"><?= e(money((float) $row['billed'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ==================== satisfaction ==================== -->
<div class="card mt-3 sm:mt-5 overflow-hidden">
  <div class="section-head">
    <p class="section-title">Patient satisfaction</p>
    <p class="text-[11px] text-ink-400">
      <?= (int) $feedback['responses'] ?> answer(s) from <?= (int) $feedback['visits'] ?> completed visit(s)
    </p>
  </div>

  <?php if ((int) $feedback['responses'] === 0): ?>
    <div class="p-4 text-xs leading-relaxed text-ink-500">
      No ratings in this range. The question is asked on the tracking link once a visit is
      marked complete, so a clinic that never completes visits will never see one.
    </div>
  <?php else: ?>
    <div class="grid gap-4 p-4 sm:grid-cols-[auto_1fr]">
      <div class="text-center sm:border-r sm:border-slate-100 sm:pr-5">
        <p class="text-4xl font-black text-ink-900"><?= e((string) $feedback['mean']) ?></p>
        <p class="text-[10px] uppercase tracking-wide text-ink-500">out of 5</p>
        <p class="mt-2 text-[11px] font-semibold <?= (float) $feedback['response_rate'] < 10 ? 'text-amber-700' : 'text-emerald-700' ?>">
          <?= e((string) $feedback['response_rate']) ?>% replied
        </p>
        <?php if ((float) $feedback['response_rate'] < 10): ?>
          <p class="mt-1 max-w-[11rem] text-[10px] leading-relaxed text-amber-700">
            Few enough that one answer moves the average. Treat it as a hint, not a measurement.
          </p>
        <?php endif; ?>
      </div>

      <div>
        <?php foreach ([5, 4, 3, 2, 1] as $stars): ?>
          <?php
          $count = (int) ($feedback['distribution'][$stars] ?? 0);
          $pct = (int) $feedback['responses'] > 0 ? round($count / (int) $feedback['responses'] * 100) : 0;
          ?>
          <div class="flex items-center gap-2 py-0.5">
            <span class="w-3 text-right text-[11px] font-semibold text-ink-500"><?= $stars ?></span>
            <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
              <div class="h-full rounded-full <?= $stars >= 4 ? 'bg-emerald-500' : ($stars === 3 ? 'bg-amber-400' : 'bg-rose-400') ?>"
                   style="width: <?= $pct ?>%"></div>
            </div>
            <span class="w-8 text-right text-[10px] tabular text-ink-400"><?= $count ?></span>
          </div>
        <?php endforeach; ?>
        <p class="mt-2 text-[10px] leading-relaxed text-ink-400">
          <?= (int) $feedback['promoters'] ?> rated 4–5,
          <?= (int) $feedback['detractors'] ?> rated 1–2<?php if ($feedback['wait_mean'] !== null): ?>,
          waiting averaged <?= e((string) $feedback['wait_mean']) ?>/5<?php endif; ?>.
        </p>
      </div>
    </div>

    <?php if ($feedback['by_doctor'] !== []): ?>
      <div class="table-scroll">
        <table class="table border-t border-slate-100">
        <thead><tr><th>Doctor</th><th class="numeric">Answers</th><th class="numeric">Average</th></tr></thead>
        <tbody>
        <?php foreach ($feedback['by_doctor'] as $d): ?>
        <tr>
        <td class="font-medium"><?= e((string) $d['doctor']) ?></td>
        <td class="numeric"><?= (int) $d['count'] ?></td>
        <td class="numeric font-semibold"><?= e((string) $d['mean']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($feedback['comments'] !== []): ?>
      <details class="border-t border-slate-100">
        <summary class="cursor-pointer px-4 py-2.5 text-xs font-semibold text-brand-700 hover:bg-slate-50">
          What people wrote (<?= count($feedback['comments']) ?>)
        </summary>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($feedback['comments'] as $c): ?>
            <li class="px-4 py-2.5">
              <p class="text-xs leading-relaxed text-ink-700"><?= e((string) $c['comment']) ?></p>
              <p class="mt-1 text-[10px] text-ink-400">
                <?= (int) $c['rating'] ?>/5
                <?php if ($c['doctor'] !== null): ?> · <?= e((string) $c['doctor']) ?><?php endif; ?>
                · <?= e(date('j M', strtotime((string) $c['at']))) ?>
              </p>
            </li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ==================== departments ==================== -->
<div class="card mt-3 sm:mt-5 overflow-hidden">
  <div class="section-head">
    <p class="section-title">By department</p>
  </div>
  <?php if ($byDepartment === []): ?>
    <div class="p-4 text-xs text-ink-500">No visits in this range.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table">
      <thead><tr><th>Department</th><th class="numeric">Seen</th><th class="numeric hidden sm:table-cell">Completed</th><th class="numeric hidden md:table-cell">Avg wait</th></tr></thead>
      <tbody>
      <?php foreach ($byDepartment as $d): ?>
      <tr>
      <td class="font-medium"><?= e((string) $d['department']) ?></td>
      <td class="numeric"><?= (int) $d['seen'] ?></td>
      <td class="numeric hidden sm:table-cell"><?= (int) $d['completed'] ?></td>
      <td class="numeric hidden md:table-cell"><?= $d['avg_wait_minutes'] !== null ? round((float) $d['avg_wait_minutes']) . 'm' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
    </div>
    <p class="border-t border-slate-100 px-4 py-2 text-[10px] text-ink-400">
      Queues not assigned to a department appear as (unassigned) rather than disappearing.
    </p>
  <?php endif; ?>
</div>

<!-- ==================== delays ==================== -->
<div class="card mt-3 sm:mt-5 overflow-hidden">
  <div class="section-head">
    <p class="section-title">Doctor delays</p>
  </div>
  <?php if ($delays === []): ?>
    <div class="p-4 text-xs text-ink-500">No delays or pauses recorded in this range.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table">
      <thead><tr><th>Doctor</th><th class="numeric">Events</th><th class="numeric">Average</th><th class="numeric">Worst</th></tr></thead>
      <tbody>
      <?php foreach ($delays as $d): ?>
      <tr>
      <td class="font-medium"><?= e((string) $d['doctor']) ?></td>
      <td class="numeric"><?= (int) $d['delay_events'] ?></td>
      <td class="numeric"><?= $d['avg_minutes'] !== null ? (int) round((float) $d['avg_minutes']) . 'm' : '—' ?></td>
      <td class="numeric"><?= $d['worst_minutes'] !== null ? (int) round((float) $d['worst_minutes']) . 'm' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ==================== staff activity ==================== -->
<?php if (!empty($staffActivity)): ?>
  <div class="card mt-3 sm:mt-5 overflow-hidden">
    <div class="section-head">
      <p class="section-title">Staff activity</p>
    </div>
    <div class="table-scroll">
      <table class="table">
      <thead>
      <tr><th>Staff member</th><th>Role</th><th class="numeric">Actions</th>
      <th class="numeric hidden sm:table-cell">Queue</th><th class="numeric hidden sm:table-cell">Patients</th>
      <th class="numeric hidden md:table-cell">Cancels / no-shows</th></tr>
      </thead>
      <tbody>
      <?php foreach ($staffActivity as $s): ?>
      <tr>
      <td class="font-medium"><?= e((string) $s['name']) ?></td>
      <td class="text-xs text-ink-500"><?= e(str_replace('_', ' ', (string) $s['role'])) ?></td>
      <td class="numeric font-semibold"><?= (int) $s['actions'] ?></td>
      <td class="numeric hidden sm:table-cell"><?= (int) $s['queue_actions'] ?></td>
      <td class="numeric hidden sm:table-cell"><?= (int) $s['patient_actions'] ?></td>
      <td class="numeric hidden md:table-cell">
      <?php if ((int) $s['destructive'] > 0): ?>
      <span class="badge-amber"><?= (int) $s['destructive'] ?></span>
      <?php else: ?>
      <span class="text-slate-300">—</span>
      <?php endif; ?>
      </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
    </div>
    <p class="border-t border-slate-100 px-4 py-2 text-[10px] leading-relaxed text-ink-400">
      Built from the audit log, so it shows only actions the system records — every queue
      transition, patient edit and settings change. Reading a page is not an action.
    </p>
  </div>
<?php endif; ?>
