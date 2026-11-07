<?php
/**
 * Priority rules.
 *
 * The order on this screen IS the order the engine evaluates. Dragging a row
 * changes which rule wins for the next token issued.
 *
 * @var array $rules, $stats, $overuse, $history, $doctors, $queues, $sources, $kinds
 */
$activeCount = (int) $stats['active'];
$updated = $stats['updated_at'] ? fmt_time($stats['updated_at'], 'g:i A') : '—';
$updatedDay = $stats['updated_at']
    ? (substr((string) $stats['updated_at'], 0, 10) === \App\Core\Db::today()
        ? 'Today' : date('j M', strtotime((string) $stats['updated_at'])))
    : 'never';
$showForm = true; // the reference shows the form open on arrival
$val = static fn (string $k, string $fallback = ''): string => (string) ($old[$k] ?? $fallback);
// The form posts clock times; show them back as clock times after a failed save.
$clock = static function (string $k, bool $isEnd) use ($old): string {
    $v = $old[$k] ?? '';
    if ($v === '') { return ''; }
    if (str_contains((string) $v, ':')) { return (string) $v; }
    $h = (int) $v;
    return sprintf('%02d:00', $isEnd && $h === 24 ? 23 : $h);
};
$waitGap = $stats['wait_gap'];
$waitMinutes = ($stats['wait_flagged'] !== null && $stats['wait_plain'] !== null)
    ? (int) round((float) $stats['wait_plain'] - (float) $stats['wait_flagged'])
    : null;
?>

<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg><use href="#i-shield-check"/></svg></span>
    <div class="min-w-0">
      <h1 class="page-title">Priority rules</h1>
      <p class="page-sub">Set and manage rules that determine clinical priority in your live queue.</p>
    </div>
  </div>
  <div class="flex shrink-0 gap-2">
    <button type="button" class="btn-secondary btn-sm" data-scroll-to="#rule-guide">
      <svg class="h-4 w-4"><use href="#i-book"/></svg>
      Guide
    </button>
    <a class="btn-tint btn-sm" href="/app">
      <svg class="h-4 w-4"><use href="#i-calendar"/></svg>
      Today's board
    </a>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
    <p class="font-semibold">That rule was not saved.</p>
    <ul class="mt-1 list-disc pl-5">
      <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<!-- ------------------------------------------------------------ stats -->
<div class="mt-3 grid grid-cols-2 gap-2 sm:mt-4 sm:gap-4 lg:grid-cols-4">
  <?php
  $tiles = [
      ['Rules in action', (string) $activeCount, $activeCount === 1 ? 'Active rule' : 'Active rules', 'users', 'bg-brand-50 text-brand-600'],
      ['Overriding priority', (string) $stats['overrides_today'], 'Right now', 'shield-check', 'bg-amber-50 text-amber-500'],
      ['Avg. wait improvement', $waitGap !== null ? abs((int) $waitGap) . '%' : '—', 'This week', 'user-x', 'bg-violet-50 text-violet-500'],
      ['Last updated', $updated, $updatedDay, 'clock', 'bg-sky-50 text-sky-500'],
  ];
  foreach ($tiles as [$label, $value, $hint, $icon, $tone]):
  ?>
    <div class="stat-tile stat-soft">
      <span class="stat-ico <?= e($tone) ?>"><svg><use href="#i-<?= e($icon) ?>"/></svg></span>
      <div class="min-w-0 flex-1">
        <p class="stat-label"><?= e($label) ?></p>
        <p class="stat-value"><?= e($value) ?></p>
        <p class="stat-hint"><?= e($hint) ?></p>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="mt-3 grid gap-4 sm:mt-4 xl:grid-cols-[minmax(0,1fr)_18rem] xl:gap-8">

  <!-- ==================================================== main column -->
  <div class="min-w-0 space-y-4">

    <div class="card overflow-hidden">
      <!-- toolbar -->
      <div class="toolbar">
        <div class="flex min-w-0 items-center gap-4" role="tablist">
          <button type="button" class="utab utab-active" role="tab" aria-selected="true" data-rule-tab="active">Active rules</button>
          <button type="button" class="utab" role="tab" aria-selected="false" data-rule-tab="history">Rule history</button>
        </div>
        <div class="ml-auto flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto">
          <label class="search-mini min-w-0 flex-1 sm:w-[10rem] sm:flex-none">
            <svg class="h-3.5 w-3.5 shrink-0 text-ink-400"><use href="#i-search"/></svg>
            <input type="search" placeholder="Search rules..." data-rule-search aria-label="Search rules">
          </label>
          <button type="button" class="btn-secondary btn-sm" data-toggle="#rule-filters" aria-expanded="false">
            <svg class="h-3.5 w-3.5"><use href="#i-filter"/></svg>
            Filters
          </button>
          <button type="button" class="btn-primary btn-sm" data-open-form>
            <svg class="h-3.5 w-3.5"><use href="#i-plus"/></svg>
            New rule
          </button>
        </div>
      </div>

      <div id="rule-filters" class="hidden flex-wrap items-center gap-2 border-b border-hairline bg-canvas px-3 py-2.5 sm:px-4">
        <select class="select !w-auto !py-1.5 text-xs" data-rule-filter="priority" aria-label="Filter by strength">
          <option value="">Any strength</option>
          <option value="ahead">Ahead</option>
          <option value="slight">Slightly ahead</option>
          <option value="behind">Behind</option>
        </select>
        <select class="select !w-auto !py-1.5 text-xs" data-rule-filter="status" aria-label="Filter by status">
          <option value="">Any status</option>
          <option value="on">Active only</option>
          <option value="off">Turned off only</option>
        </select>
        <button type="button" class="btn-ghost text-xs" data-rule-filter-reset>Reset</button>
      </div>

      <!-- ============================================ active rules pane -->
      <div data-rule-pane="active" class="px-3 pb-3 sm:px-4 sm:pb-4">
        <div id="rule-guide" class="explainer mt-3">
          <div class="flex items-start justify-between gap-6">
            <div class="min-w-0">
              <p class="text-[13px] font-bold text-ink-900">How rule engine works</p>
              <ul class="explainer-list">
                <?php foreach ([
                    ['check-circle', 'Rules are evaluated from top to bottom when a patient joins or is updated.'],
                    ['check', 'The first matching rule sets the priority and queue position.'],
                    ['check-circle', 'If no rule matches, the default queue rules apply.'],
                    ['check-circle', 'Rules never reorder completed or in-consultation patients.'],
                ] as [$ico, $line]): ?>
                  <li>
                    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-500"><use href="#i-<?= e($ico) ?>"/></svg>
                    <span><?= e($line) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
              <a href="/queues/config" class="mt-2.5 inline-flex min-h-[32px] items-center gap-1.5 text-[11.5px] font-bold text-brand-700 hover:underline sm:min-h-0">
                Learn more about priority rules
                <svg class="h-3.5 w-3.5"><use href="#i-arrow-right"/></svg>
              </a>
            </div>
            <!-- The reference's little evaluation diagram: first row highlighted
                 because the first match is the one that wins. -->
            <div class="hidden w-[7.5rem] shrink-0 space-y-2 pt-1 sm:block" aria-hidden="true">
              <div class="flex items-center gap-1.5 rounded-md bg-sky-100/70 px-1.5 py-1">
                <svg class="h-3.5 w-3.5 shrink-0 text-brand-600"><use href="#i-info"/></svg>
                <span class="h-1.5 flex-1 rounded-full bg-brand-400"></span>
              </div>
              <div class="flex items-center gap-1.5 rounded-md bg-white/70 px-1.5 py-1">
                <svg class="h-3.5 w-3.5 shrink-0 text-brand-500"><use href="#i-sparkles"/></svg>
                <span class="h-1.5 flex-1 rounded-full bg-ink-300"></span>
              </div>
              <div class="flex items-center gap-1.5 rounded-md bg-white/70 px-1.5 py-1">
                <svg class="h-3.5 w-3.5 shrink-0 text-brand-600"><use href="#i-arrow-right"/></svg>
                <span class="h-1.5 flex-1 rounded-full bg-ink-300"></span>
              </div>
            </div>
          </div>
        </div>

        <?php if ($rules === []): ?>
          <div class="px-4 py-10 text-center">
            <p class="text-sm font-semibold text-ink-900">No rules yet</p>
            <p class="mx-auto mt-1 max-w-sm text-[12.5px] leading-relaxed text-ink-500">
              Every arrival is ordered by its source weight and when it joined. Add a rule
              if this clinic has a standing clinical priority.
            </p>
            <button type="button" class="btn-primary btn-sm mt-3" data-open-form>Create the first rule</button>
          </div>
        <?php else: ?>
          <div class="mt-3">
            <!-- Head sits outside the bordered body, as in the reference. -->
            <div class="hidden grid-cols-[minmax(0,1fr)_7.25rem_7rem_6.5rem_4.25rem_1.75rem] gap-2 px-3 pb-2 sm:grid">
              <?php foreach (['Rule', 'Priority', 'Applies to', 'Source', 'Status', ''] as $h): ?>
                <span class="text-[10px] font-bold uppercase tracking-[0.07em] text-ink-400"><?= e($h) ?></span>
              <?php endforeach; ?>
            </div>

            <div class="dtable-frame">
              <div data-rule-body>
                <?php foreach ($rules as $rule): ?>
                  <?php
                  $rid = (int) $rule['id'];
                  $on = (int) $rule['is_active'] === 1;
                  $eff = (int) $rule['effect'];
                  // Full literal class names: Tailwind tree-shakes component
                  // classes it cannot find in the source.
                  $strength = $eff >= 3 ? 'ahead' : ($eff > 0 ? 'slight' : 'behind');
                  $strengthClass = $eff >= 3 ? 'prio-ahead' : ($eff > 0 ? 'prio-slight' : 'prio-behind');
                  $strengthWord = $eff >= 3 ? 'Ahead' : ($eff > 0 ? 'Slightly ahead' : 'Behind');
                  $arrow = $eff >= 3 ? 'arrow-up' : ($eff > 0 ? 'arrow-right' : 'chevron-down');
                  ?>
                  <div data-rule-row data-id="<?= $rid ?>"
                       data-name="<?= e(mb_strtolower((string) $rule['name'])) ?>"
                       data-strength="<?= e($strength) ?>" data-status="<?= $on ? 'on' : 'off' ?>"
                       class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-2 gap-y-1.5 border-b border-hairline px-3 py-2.5 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_7.25rem_7rem_6.5rem_4.25rem_1.75rem] sm:gap-2 sm:py-0 <?= $on ? '' : 'bg-canvas/60' ?>">

                    <div class="col-span-2 flex min-w-0 items-center gap-2 sm:col-span-1 sm:h-[58px]">
                      <button type="button" class="rule-grip !h-8 !w-8 sm:!h-7 sm:!w-4" draggable="true" data-rule-grip
                              aria-label="Reorder <?= e($rule['name']) ?>. Use the up and down arrow keys.">
                        <svg class="h-4 w-4"><use href="#i-grip"/></svg>
                      </button>
                      <span class="rule-num" data-rule-num><?= (int) $rule['position'] ?></span>
                      <span class="min-w-0">
                        <span class="block truncate text-[13.5px] font-bold leading-tight text-ink-900"><?= e($rule['name']) ?></span>
                        <span class="mt-0.5 block truncate text-[11px] leading-tight text-ink-500"><?= e($rule['reason']) ?></span>
                      </span>
                    </div>

                    <div>
                      <span class="prio <?= e($strengthClass) ?>" title="Effect <?= $eff > 0 ? '+' : '' ?><?= $eff ?>">
                        <svg class="h-3.5 w-3.5 shrink-0"><use href="#i-<?= e($arrow) ?>"/></svg>
                        <?= e($strengthWord) ?>
                      </span>
                    </div>

                    <div class="col-span-2 truncate text-[12px] text-ink-700 sm:col-span-1" title="<?= e($rule['matches']) ?>">
                      <?= e($rule['queue_name'] ?? ($rule['kind'] !== null ? ($kinds[$rule['kind']] ?? $rule['kind']) : 'Any queue')) ?>
                    </div>

                    <div class="hidden truncate text-[12px] text-ink-500 sm:block">
                      <?= e($rule['source'] !== null ? ($sources[$rule['source']] ?? $rule['source']) : 'Any') ?>
                    </div>

                    <div><span class="<?= $on ? 'status-on' : 'status-off' ?>"><?= $on ? 'Active' : 'Off' ?></span></div>

                    <div class="justify-self-end">
                      <span class="relative">
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded text-ink-400 hover:bg-canvas hover:text-ink-700 sm:h-7 sm:w-7"
                                data-row-more aria-haspopup="true" aria-expanded="false"
                                aria-label="Actions for <?= e($rule['name']) ?>">
                          <svg class="h-4 w-4"><use href="#i-dots-h"/></svg>
                        </button>
                        <span class="absolute right-0 top-full z-40 mt-1 hidden min-w-[10rem] overflow-hidden rounded-lg border border-hairline bg-white p-1 shadow-pop" data-row-menu>
                          <form method="post" action="/queues/priority-rules/<?= $rid ?>/toggle">
                            <?= csrf_field() ?>
                            <button class="block w-full rounded px-2 py-1.5 text-left text-[12px] font-medium text-ink-700 hover:bg-canvas">
                              <?= $on ? 'Turn off' : 'Turn on' ?>
                            </button>
                          </form>
                          <form method="post" action="/queues/priority-rules/<?= $rid ?>/delete"
                                onsubmit="return confirm('Delete this rule? Patients it already reordered keep their recorded reason.');">
                            <?= csrf_field() ?>
                            <button class="block w-full rounded px-2 py-1.5 text-left text-[12px] font-medium text-rose-600 hover:bg-rose-50">Delete rule</button>
                          </form>
                        </span>
                      </span>
                    </div>
                  </div>
                <?php endforeach; ?>

                <!-- The fallback the engine actually uses when nothing matches.
                     Not a stored rule, so it is not draggable or deletable —
                     it is the last row because it is the last resort. -->
                <div data-rule-default
                     class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-2 gap-y-1.5 border-t border-hairline px-3 py-2.5 sm:grid-cols-[minmax(0,1fr)_7.25rem_7rem_6.5rem_4.25rem_1.75rem] sm:gap-2 sm:py-0">
                  <div class="col-span-2 flex min-w-0 items-center gap-2 sm:col-span-1 sm:h-[58px]">
                    <span class="h-8 w-8 shrink-0 sm:h-7 sm:w-4"></span>
                    <span class="rule-num" data-rule-default-num><?= count($rules) + 1 ?></span>
                    <span class="min-w-0">
                      <span class="block truncate text-[13.5px] font-bold leading-tight text-ink-900">Default queue order</span>
                      <span class="mt-0.5 block truncate text-[11px] leading-tight text-ink-500">First come, first served</span>
                    </span>
                  </div>
                  <div>
                    <span class="prio prio-normal">
                      <svg class="h-3.5 w-3.5 shrink-0"><use href="#i-chevron-down"/></svg>
                      Normal
                    </span>
                  </div>
                  <div class="col-span-2 text-[12px] text-ink-700 sm:col-span-1">Any queue</div>
                  <div class="hidden text-[12px] text-ink-500 sm:block">System</div>
                  <div><span class="status-on">Active</span></div>
                  <div></div>
                </div>
              </div>
            </div>
          </div>

          <p class="hidden px-4 py-6 text-center text-[12.5px] text-ink-500" data-rule-empty>No rule matches that search.</p>

          <div class="flex flex-wrap items-center justify-between gap-2 pt-2.5 text-[11.5px] text-ink-500">
            <p data-order-hint>
              Drag rules to reorder ·
              <button type="button" class="min-h-[32px] font-semibold text-sky-700 hover:underline sm:min-h-0" data-scroll-to="#rule-guide">View guidelines</button>
            </p>
            <p class="flex items-center gap-1.5">
              <span data-order-state>Last updated: <?= e($updated) ?></span>
              <svg class="h-3.5 w-3.5 text-ink-400" data-order-spin><use href="#i-refresh"/></svg>
            </p>
          </div>
        <?php endif; ?>
      </div>

      <!-- ================================================ history pane -->
      <div data-rule-pane="history" class="hidden">
        <?php if ($history === []): ?>
          <p class="px-4 py-10 text-center text-[12.5px] text-ink-500">No changes recorded yet.</p>
        <?php else: ?>
          <ul class="divide-y divide-hairline">
            <?php
            $verbs = [
                'priority_rule.created' => 'created',
                'priority_rule.enabled' => 'turned on',
                'priority_rule.disabled' => 'turned off',
                'priority_rule.deleted' => 'deleted',
                'priority_rule.reordered' => 'reordered the rules',
            ];
            foreach ($history as $h):
              $meta = json_decode((string) ($h['meta_json'] ?? ''), true) ?: [];
              $verb = $verbs[$h['action']] ?? str_replace('priority_rule.', '', (string) $h['action']);
              $isReorder = $h['action'] === 'priority_rule.reordered';
            ?>
              <li class="flex items-start gap-3 px-4 py-3">
                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-canvas text-ink-500">
                  <svg class="h-3.5 w-3.5"><use href="#i-<?= $isReorder ? 'grip' : 'edit' ?>"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                  <p class="text-[12.5px] text-ink-800">
                    <span class="font-semibold"><?= e($h['actor_name'] ?? 'System') ?></span>
                    <?= e($verb) ?>
                    <?php if (!$isReorder && !empty($meta['name'])): ?>
                      <span class="font-semibold"><?= e($meta['name']) ?></span>
                    <?php endif; ?>
                  </p>
                  <p class="text-[11px] text-ink-400 tabular"><?= e(fmt_time($h['created_at'], 'j M Y · g:i A')) ?></p>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <!-- ------------------------------------------- override report -->
    <div class="card flex flex-wrap items-center gap-y-3 px-4 py-4">
      <div class="w-full sm:min-w-[10rem] sm:flex-1 sm:pr-3">
        <p class="text-[13.5px] font-bold text-ink-900">Who is overriding priority</p>
        <p class="mt-0.5 text-[11.5px] text-ink-500">Based on last 7 days</p>
      </div>
      <div class="metric-strip w-full sm:w-auto sm:min-w-[16rem] sm:flex-[2.6] sm:border-l sm:border-hairline">
        <div>
          <p class="metric-value"><?= (int) $overuse['overrides'] ?></p>
          <p class="metric-label">Total overrides</p>
        </div>
        <div>
          <p class="metric-value"><?= $overuse['tokens'] > 0 ? e((string) $overuse['rate']) . '%' : '—' ?></p>
          <p class="metric-label">Of total patients</p>
        </div>
        <div>
          <p class="metric-value"><?= $waitMinutes !== null ? e((string) abs($waitMinutes)) . ' min' : '—' ?></p>
          <p class="metric-label">Avg. impact on wait time</p>
        </div>
      </div>
      <a href="/reports" class="btn-outline-brand btn-sm shrink-0 sm:ml-3">View report</a>
    </div>

    <?php if ($waitMinutes !== null): ?>
      <p class="-mt-1 px-1 text-[11px] leading-relaxed text-ink-400">
        Flagged patients waited <?= e((string) $stats['wait_flagged']) ?> min on average against
        <?= e((string) $stats['wait_plain']) ?> min for everyone else — a comparison of two different
        groups, not proof the rules caused the difference.
      </p>
    <?php endif; ?>

    <!-- ------------------------------------------------ audit strip -->
    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl px-4 py-3" style="background:#F7F8FB">
      <p class="flex min-w-0 items-start gap-2 text-[11.5px] text-ink-600">
        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-sky-500"><use href="#i-info"/></svg>
        Rules are automatically logged in Audit log with patient, rule, and reason.
      </p>
      <a href="/audit" class="inline-flex min-h-[32px] shrink-0 items-center gap-1.5 text-[11.5px] font-bold text-brand-700 hover:underline">
        Go to audit log <svg class="h-3.5 w-3.5"><use href="#i-arrow-right"/></svg>
      </a>
    </div>
  </div>

  <!-- ==================================================== side form -->
  <aside class="min-w-0">
    <div class="card overflow-hidden xl:sticky xl:top-4" id="rule-form-card">
      <button type="button" class="disclosure-btn" data-toggle="#rule-form" aria-expanded="<?= $showForm ? 'true' : 'false' ?>">
        Create new rule
        <svg class="h-4 w-4 shrink-0 text-ink-500 transition" data-chevron><use href="#i-chevron-down"/></svg>
      </button>

      <form method="post" action="/queues/priority-rules" id="rule-form" class="<?= $showForm ? '' : 'hidden' ?> px-4 pb-4">
        <?= csrf_field() ?>

        <label class="label-soft" for="name">Rule name</label>
        <input class="input !py-1.5 !text-[12.5px]" id="name" name="name" required maxlength="80"
               placeholder="e.g., Under-5 first" value="<?= e($val('name')) ?>">

        <div class="mt-4 grid grid-cols-2 gap-2">
          <div>
            <label class="label-soft" for="effect">Priority</label>
            <select class="select !py-1.5 !text-[12.5px] font-semibold" id="effect" name="effect" data-effect-select>
              <?php foreach ([3 => 'Ahead (+3)', 2 => 'Ahead (+2)', 1 => 'Slightly ahead (+1)', -1 => 'Behind (−1)', -2 => 'Behind (−2)'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= (int) $val('effect', '2') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label-soft" for="source">Source</label>
            <select class="select !py-1.5 !text-[12.5px]" id="source" name="source">
              <option value="">Any</option>
              <?php foreach ($sources as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $val('source') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mt-4">
          <label class="label-soft" for="queue_id">Applies to</label>
          <select class="select !py-1.5 !text-[12.5px]" id="queue_id" name="queue_id">
            <option value="">Any queue</option>
            <?php foreach ($queues as $q): ?>
              <option value="<?= (int) $q['id'] ?>" <?= (int) $val('queue_id') === (int) $q['id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-2">
          <div>
            <label class="label-soft" for="kind">Queue type</label>
            <select class="select !py-1.5 !text-[12.5px]" id="kind" name="kind">
              <option value="">Any</option>
              <?php foreach ($kinds as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $val('kind') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label-soft" for="doctor_id">Doctor</label>
            <select class="select !py-1.5 !text-[12.5px]" id="doctor_id" name="doctor_id">
              <option value="">Any</option>
              <?php foreach ($doctors as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $val('doctor_id') === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-2">
          <div>
            <label class="label-soft" for="min_age">Youngest age</label>
            <input class="input !py-1.5 !text-[12.5px]" id="min_age" name="min_age" type="number" min="0" max="130" placeholder="0" value="<?= e($val('min_age')) ?>">
          </div>
          <div>
            <label class="label-soft" for="max_age">Oldest age</label>
            <input class="input !py-1.5 !text-[12.5px]" id="max_age" name="max_age" type="number" min="0" max="130" placeholder="120" value="<?= e($val('max_age')) ?>">
          </div>
        </div>
        <p class="mt-1.5 text-[11px] text-ink-500">Leave blank for any age</p>

        <p class="label-soft mt-4 !mb-2">Time window (optional)</p>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="label-soft" for="from_hour">From</label>
            <input class="input !py-1.5 !text-[12.5px] tabular" id="from_hour" name="from_hour" type="text"
                   inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="00:00"
                   title="24-hour time, for example 07:30" value="<?= e($clock('from_hour', false)) ?>">
          </div>
          <div>
            <label class="label-soft" for="to_hour">To</label>
            <input class="input !py-1.5 !text-[12.5px] tabular" id="to_hour" name="to_hour" type="text"
                   inputmode="numeric" pattern="([01][0-9]|2[0-4]):[0-5][0-9]" placeholder="23:59"
                   title="24-hour time, for example 11:00" value="<?= e($clock('to_hour', true)) ?>">
          </div>
        </div>

        <div class="mt-4">
          <label class="label-soft" for="reason">Reason (optional)</label>
          <textarea class="input !py-1.5 !text-[12.5px]" id="reason" name="reason" rows="2" maxlength="160"
                    placeholder="Brief reason for this rule"><?= e($val('reason')) ?></textarea>
          <p class="mt-1.5 text-[10.5px] leading-tight text-ink-500">This helps your team understand why this rule exists.</p>
        </div>

        <button class="btn-primary mt-3 w-full" type="submit">Save rule</button>
        <button class="btn-secondary mt-2 w-full" type="button" data-close-form>Cancel</button>

        <p class="mt-3 text-[11px] leading-relaxed text-ink-500">
          Priority rules may delay the queue. Use with care.
        </p>
        <a href="/queues/config" class="mt-1.5 inline-flex min-h-[32px] items-center gap-1.5 text-[11.5px] font-bold text-brand-700 hover:underline sm:min-h-0">
          Learn more <svg class="h-3.5 w-3.5"><use href="#i-arrow-right"/></svg>
        </a>
      </form>
    </div>
  </aside>
</div>
