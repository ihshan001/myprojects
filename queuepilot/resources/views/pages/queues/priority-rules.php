<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Priority rules</h1>
    <p class="mt-1 max-w-2xl text-sm text-slate-500">
      Standing clinical decisions written once. A rule decides the order of a
      patient the desk has not flagged — children under five before adults,
      routine reviews behind acute cases, one doctor's list before another's.
      A member of staff flagging a patient by hand always overrides a rule.
    </p>
  </div>
  <div class="flex gap-2">
    <a class="btn-secondary btn-sm" href="/queues/config">Queue settings</a>
    <a class="btn-secondary btn-sm" href="/app">Today's board</a>
  </div>
</div>

<!-- Live and counted, not decorative: how many people in the building are
     being held ahead of the line by a person rather than a rule. -->
<div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
  <div class="card card-pad flex items-center gap-3">
    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
      <svg class="h-4 w-4"><use href="#i-check-circle"/></svg>
    </span>
    <div class="min-w-0">
      <p class="text-lg font-bold leading-tight text-slate-900"><?= (int) ($activeCount ?? 0) ?></p>
      <p class="truncate text-[11px] text-slate-500">Rules in action</p>
    </div>
  </div>
  <div class="card card-pad flex items-center gap-3">
    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
      <svg class="h-4 w-4"><use href="#i-shield"/></svg>
    </span>
    <div class="min-w-0">
      <p class="text-lg font-bold leading-tight text-slate-900"><?= (int) ($overridingNow ?? 0) ?></p>
      <p class="truncate text-[11px] text-slate-500">Overriding by hand, right now</p>
    </div>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
    <p class="font-semibold">That rule was not saved.</p>
    <ul class="mt-1 list-disc pl-5">
      <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.1fr_.9fr]">
  <div class="space-y-5">
    <div class="card">
      <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Rules, strongest first</p>
        <span class="text-[11px] text-slate-500">
          <?= count(array_filter($rules, static fn ($r) => (int) $r['is_active'] === 1)) ?> of <?= count($rules) ?> active
        </span>
      </div>

      <?php if ($rules === []): ?>
        <p class="px-4 py-6 text-sm text-slate-500">
          No rules yet. Every arrival is ordered by its source weight (appointments
          and walk-ins) and by when it joined. Add one on the right if this clinic
          has a standing clinical priority.
        </p>
      <?php else: ?>
        <div class="divide-y divide-slate-100">
          <?php foreach ($rules as $rule): ?>
            <?php $isActive = (int) $rule['is_active'] === 1; ?>
            <div class="flex flex-wrap items-start gap-3 px-4 py-3 <?= $isActive ? '' : 'bg-slate-50/70' ?>">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-medium text-slate-900"><?= e($rule['name']) ?></span>
                  <?php if ((int) $rule['effect'] > 0): ?>
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">+<?= (int) $rule['effect'] ?> ahead</span>
                  <?php else: ?>
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-900"><?= (int) $rule['effect'] ?> behind</span>
                  <?php endif; ?>
                  <?php if ((int) $rule['queue_id'] > 0): ?>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">this queue only</span>
                  <?php endif; ?>
                  <?php if (!$isActive): ?>
                    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600">Off</span>
                  <?php endif; ?>
                </div>
                <p class="mt-1 text-xs text-slate-500">Matches: <?= e($rule['matches']) ?></p>
                <p class="mt-1 text-xs text-slate-600">
                  Reason copied onto the entry: <span class="italic">“<?= e($rule['reason']) ?>”</span>
                </p>
              </div>
              <div class="flex shrink-0 gap-1.5">
                <form method="post" action="/queues/priority-rules/<?= (int) $rule['id'] ?>/toggle">
                  <?= csrf_field() ?>
                  <button class="btn-secondary btn-sm" type="submit"><?= $isActive ? 'Turn off' : 'Turn on' ?></button>
                </form>
                <form method="post" action="/queues/priority-rules/<?= (int) $rule['id'] ?>/delete"
                      onsubmit="return confirm('Delete this rule? Patients it already reordered keep their recorded reason.')">
                  <?= csrf_field() ?>
                  <button class="btn-secondary btn-sm text-red-700" type="submit">Delete</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600">
      <p class="font-semibold text-slate-700">How a rule is applied</p>
      <ul class="mt-1 list-disc space-y-1 pl-5">
        <li>The strongest matching boost wins. Two rules that both fit the same patient do not add together.</li>
        <li>A rule decides the <span class="font-medium">next</span> token issued. Patients already waiting are not reordered underneath them.</li>
        <li>The reason is copied onto the entry, not looked up later — editing a rule cannot rewrite history.</li>
        <li>A hand-set priority at the desk beats any rule.</li>
        <li>Age comes from the patient's year of birth. A patient with no birth year recorded never matches an age rule.</li>
      </ul>
    </div>
  </div>

  <div class="card h-fit">
    <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
      <p class="text-xs font-semibold text-slate-600">New rule</p>
    </div>

    <form method="post" action="/queues/priority-rules" class="space-y-3 p-4">
      <?= csrf_field() ?>

      <div>
        <label class="label" for="name">Name</label>
        <input class="input" id="name" name="name" required maxlength="80"
               value="<?= e($old['name'] ?? '') ?>" placeholder="Under-fives first">
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="effect">Priority</label>
          <select class="select" id="effect" name="effect" required>
            <?php for ($i = 9; $i >= -5; $i--): if ($i === 0) { continue; } ?>
              <option value="<?= $i ?>" <?= (string) ($old['effect'] ?? '') === (string) $i ? 'selected' : '' ?>>
                <?= $i > 0 ? '+' . $i . ' ahead' : $i . ' behind' ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <div>
          <label class="label" for="source">Source</label>
          <select class="select" id="source" name="source">
            <option value="">Any</option>
            <?php foreach ($sources as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= ($old['source'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="kind">Queue type</label>
          <select class="select" id="kind" name="kind">
            <option value="">Any</option>
            <?php foreach ($kinds as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= ($old['kind'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="doctor_id">Doctor</label>
          <select class="select" id="doctor_id" name="doctor_id">
            <option value="">Any</option>
            <?php foreach ($doctors as $doctor): ?>
              <option value="<?= (int) $doctor['id'] ?>" <?= (string) ($old['doctor_id'] ?? '') === (string) $doctor['id'] ? 'selected' : '' ?>>
                <?= e($doctor['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div>
        <label class="label" for="queue_id">Queue</label>
        <select class="select" id="queue_id" name="queue_id">
          <option value="">Any queue of this clinic</option>
          <?php foreach ($queues as $queue): ?>
            <option value="<?= (int) $queue['id'] ?>" <?= (string) ($old['queue_id'] ?? '') === (string) $queue['id'] ? 'selected' : '' ?>>
              <?= e($queue['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="mt-1 text-[11px] text-slate-500">A rule for one queue is how a doctor's own preference stays theirs.</p>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="min_age">Youngest age</label>
          <input class="input" id="min_age" name="min_age" type="number" min="0" max="130"
                 value="<?= e((string) ($old['min_age'] ?? '')) ?>" placeholder="any">
        </div>
        <div>
          <label class="label" for="max_age">Oldest age</label>
          <input class="input" id="max_age" name="max_age" type="number" min="0" max="130"
                 value="<?= e((string) ($old['max_age'] ?? '')) ?>" placeholder="any">
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="from_hour">From hour</label>
          <input class="input" id="from_hour" name="from_hour" type="number" min="0" max="23"
                 value="<?= e((string) ($old['from_hour'] ?? '')) ?>" placeholder="any">
        </div>
        <div>
          <label class="label" for="to_hour">To hour</label>
          <input class="input" id="to_hour" name="to_hour" type="number" min="1" max="24"
                 value="<?= e((string) ($old['to_hour'] ?? '')) ?>" placeholder="any">
        </div>
      </div>
      <p class="-mt-1 text-[11px] text-slate-500">
        Hours use the clinic's clock, 0–23. A window that crosses midnight is two rules.
      </p>

      <div>
        <label class="label" for="reason">Clinical reason</label>
        <input class="input" id="reason" name="reason" required maxlength="160"
               value="<?= e($old['reason'] ?? '') ?>" placeholder="Child under five — see before adults">
        <p class="mt-1 text-[11px] text-slate-500">
          Copied onto every entry the rule decides. Write it for a colleague reading the day's log.
        </p>
      </div>

      <button class="btn-primary w-full" type="submit">Save rule</button>
    </form>
  </div>
</div>

<!-- ==================== does prioritising actually help? ============== -->
<?php
// What priority DID to waiting times, measured rather than asserted.
//
// This is an observational comparison, not a controlled trial: a clinic that
// prioritises children is also a clinic whose children arrive at different
// hours. So the figure is always labelled "against unprioritised visits in
// the same period", never as time "saved", and the sample sizes travel with
// it so a reader can see how thin the evidence is.
//
// The sign is kept. Prioritising a group can come out SLOWER than baseline
// in a given month, and clamping that to a flattering zero would hide the
// one result a manager most needs to see.
$dash        = '—';
$baseMean    = $impact['baseline']['mean'] ?? null;
$ruleDiff    = $impact['rule']['diff'] ?? null;
$manualDiff  = $impact['manual']['diff'] ?? null;
$minutes     = static fn (?float $d): string => $d === null
    ? $dash
    : (($d < 0 ? '−' : '') . (int) round(abs($d)) . ' min');
$tooltip     = static fn (array $g): string => ($g['mean'] ?? null) === null
    ? 'Fewer than 5 finished visits in this group — a mean of three is noise.'
    : 'Compared with unprioritised visits in the same period. A minus means this group waited longer.';
?>
<div class="card mt-5 overflow-hidden">
  <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
    <p class="text-xs font-semibold text-slate-600">Did prioritising actually shorten the wait?</p>
    <p class="text-[11px] text-slate-400">
      <?= e($from) ?> to <?= e($to) ?> · measured on finished visits, against the
      <?= (int) ($impact['baseline']['n'] ?? 0) ?> unprioritised ones in the same period
    </p>
  </div>

  <?php if (!($impact['enough'] ?? false)): ?>
    <p class="px-4 py-4 text-[12px] leading-relaxed text-slate-500">
      Not enough finished visits in this period to compare yet — a group needs at least
      <?= App\Services\PriorityImpactService::MIN_SAMPLES ?> before its average means anything.
      Until then a dash is the honest answer, not a zero.
    </p>
  <?php else: ?>
    <div class="grid grid-cols-3 divide-x divide-slate-100 border-b border-slate-100 text-center">
      <div class="px-3 py-3">
        <p class="text-xl font-bold text-slate-900"><?= e($baseMean === null ? $dash : ((int) round($baseMean) . 'm')) ?></p>
        <p class="text-[10px] uppercase tracking-wide text-slate-500">
          Unprioritised avg. wait (<?= (int) ($impact['baseline']['n'] ?? 0) ?>)
        </p>
      </div>
      <div class="px-3 py-3" title="<?= e($tooltip($impact['rule'] ?? [])) ?>">
        <p class="text-xl font-bold <?= $ruleDiff === null ? 'text-slate-400' : (($ruleDiff < 0) ? 'text-amber-700' : 'text-emerald-700') ?>">
          <?= e($minutes($ruleDiff)) ?>
        </p>
        <p class="text-[10px] uppercase tracking-wide text-slate-500">
          By rule (<?= (int) ($impact['rule']['n'] ?? 0) ?>)
        </p>
      </div>
      <div class="px-3 py-3" title="<?= e($tooltip($impact['manual'] ?? [])) ?>">
        <p class="text-xl font-bold <?= $manualDiff === null ? 'text-slate-400' : (($manualDiff < 0) ? 'text-amber-700' : 'text-emerald-700') ?>">
          <?= e($minutes($manualDiff)) ?>
        </p>
        <p class="text-[10px] uppercase tracking-wide text-slate-500">
          By hand (<?= (int) ($impact['manual']['n'] ?? 0) ?>)
        </p>
      </div>
    </div>
    <p class="px-4 py-2.5 text-[11px] leading-relaxed text-slate-500">
      A minus means that group waited <b class="text-slate-700">longer</b> than unprioritised
      patients. That is a real result, not a broken number: it usually means the flagged
      patients were sicker to begin with, or that a rule is firing far more often than intended.
    </p>
  <?php endif; ?>
</div>

<!-- ==================== overuse ==================== -->
<?php
// Framed as a conversation, not a charge sheet. Every number here is paired
// with what it was divided by, because "12 overrides" means nothing without
// "out of how many tokens".
$severityTone = static fn (string $s): string => match ($s) {
    'high' => 'border-rose-200 bg-rose-50 text-rose-900',
    'watch' => 'border-amber-200 bg-amber-50 text-amber-900',
    default => 'border-slate-200 bg-slate-50 text-slate-700',
};
?>
<div class="card mt-5 overflow-hidden">
  <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
    <div>
      <p class="text-xs font-semibold text-slate-600">Who is overriding priority</p>
      <p class="text-[11px] text-slate-400"><?= e($from) ?> to <?= e($to) ?></p>
    </div>
    <form method="get" action="/queues/priority-rules" class="flex items-end gap-1.5">
      <input class="input !py-1.5 text-xs" type="date" name="from" value="<?= e($from) ?>">
      <input class="input !py-1.5 text-xs" type="date" name="to" value="<?= e($to) ?>">
      <button class="btn-secondary !py-1.5 text-xs">Show</button>
    </form>
  </div>

  <div class="grid grid-cols-3 divide-x divide-slate-100 border-b border-slate-100 text-center">
    <div class="px-3 py-3">
      <p class="text-xl font-bold text-slate-900"><?= (int) $overuse['overrides'] ?></p>
      <p class="text-[10px] uppercase tracking-wide text-slate-500">manual overrides</p>
    </div>
    <div class="px-3 py-3">
      <p class="text-xl font-bold text-slate-900"><?= e((string) $overuse['rate']) ?>%</p>
      <p class="text-[10px] uppercase tracking-wide text-slate-500">of <?= (int) $overuse['tokens'] ?> tokens</p>
    </div>
    <div class="px-3 py-3">
      <p class="text-xl font-bold text-slate-900"><?= (int) $overuse['rule_hits'] ?></p>
      <p class="text-[10px] uppercase tracking-wide text-slate-500">by a rule, not a person</p>
    </div>
  </div>

  <?php if ($overuse['flags'] !== []): ?>
    <div class="space-y-2 border-b border-slate-100 p-3">
      <?php foreach ($overuse['flags'] as $flag): ?>
        <div class="rounded-lg border <?= $severityTone((string) $flag['severity']) ?> px-3 py-2">
          <p class="text-[11px] font-semibold"><?= e((string) $flag['who']) ?></p>
          <p class="mt-0.5 text-[11px] leading-relaxed"><?= e((string) $flag['message']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($overuse['per_staff'] === []): ?>
    <p class="px-4 py-4 text-xs leading-relaxed text-slate-500">
      Nobody overrode priority in this period. That is the ordinary case — the rules are
      doing the work and no one is jumping the queue by hand.
    </p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Staff member</th>
          <th class="numeric">Overrides</th>
          <th class="numeric">Tokens issued</th>
          <th class="numeric">Rate</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($overuse['per_staff'] as $row): ?>
          <tr>
            <td class="font-medium"><?= e((string) $row['name']) ?></td>
            <td class="numeric"><?= (int) $row['overrides'] ?></td>
            <td class="numeric text-slate-500"><?= (int) $row['tokens'] ?></td>
            <td class="numeric">
              <?php if ($row['rate'] === null): ?>
                <span class="text-[10px] text-slate-400" title="They issued no tokens in this period, so there is no denominator">—</span>
              <?php else: ?>
                <span class="font-semibold <?= (float) $row['rate'] >= 40 ? 'text-rose-700' : ((float) $row['rate'] >= 25 ? 'text-amber-700' : 'text-slate-700') ?>">
                  <?= e((string) $row['rate']) ?>%
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($overuse['reasons'] !== []): ?>
    <details class="border-t border-slate-100">
      <summary class="cursor-pointer px-4 py-2.5 text-xs font-semibold text-teal-700 hover:bg-slate-50">
        The reasons given (<?= count($overuse['reasons']) ?>)
      </summary>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($overuse['reasons'] as $r): ?>
          <li class="flex items-center justify-between gap-3 px-4 py-2">
            <span class="min-w-0 text-xs text-slate-700"><?= e((string) $r['reason']) ?></span>
            <span class="shrink-0 text-[11px] tabular text-slate-400"><?= (int) $r['count'] ?>×</span>
          </li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>

  <p class="border-t border-slate-100 px-4 py-2.5 text-[10px] leading-relaxed text-slate-400">
    A high rate is not automatically wrong — an urgent-care service will legitimately override far
    more than a family OPD. Read it as a question to ask, not a verdict. Overrides are attributed to
    the signed-in account, so a shared login shows as one person; automated rule hits are counted
    separately because a policy is not a judgement.
  </p>
</div>
