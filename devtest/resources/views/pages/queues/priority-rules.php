<?php
/**
 * Priority rules.
 *
 * The order on this screen IS the order the engine evaluates. Dragging a row
 * changes which rule wins for the next token issued, so the list is numbered
 * and the save is explicit rather than implied by a sort arrow.
 *
 * @var array $rules, $stats, $overuse, $history, $doctors, $queues, $sources, $kinds
 */
$activeCount = (int) $stats['active'];
$totalCount = (int) $stats['total'];
$updated = $stats['updated_at'] ? fmt_time($stats['updated_at'], 'g:i A') : '—';
$updatedDay = $stats['updated_at'] ? (substr((string) $stats['updated_at'], 0, 10) === \App\Core\Db::today() ? 'Today' : date('j M', strtotime((string) $stats['updated_at']))) : 'never';
$showForm = !empty($errors) || !empty($old);
$val = static fn (string $k, string $fallback = ''): string => (string) ($old[$k] ?? $fallback);
?>

<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon">
      <svg class="h-5 w-5"><use href="#i-shield-check"/></svg>
    </span>
    <div class="min-w-0">
      <h1 class="page-title">Priority rules</h1>
      <p class="page-sub">Standing clinical decisions, evaluated top to bottom. Staff flagging a patient by hand always overrides a rule.</p>
    </div>
  </div>
  <div class="flex shrink-0 gap-2">
    <button type="button" class="btn-secondary btn-sm" data-scroll-to="#rule-guide">
      <svg class="h-4 w-4"><use href="#i-info"/></svg>
      Guide
    </button>
    <a class="btn-secondary btn-sm" href="/app">
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
<div class="mt-3 grid grid-cols-2 gap-2 sm:mt-5 sm:gap-4 lg:grid-cols-4">
  <?php
  $tiles = [
      ['Rules in action', (string) $activeCount, $activeCount === 1 ? 'Active rule' : 'Active rules', 'users', 'bg-brand-50 text-brand-600'],
      ['Overriding priority', (string) $stats['overrides_today'], 'By hand today', 'shield', 'bg-amber-50 text-amber-600'],
      ['Wait difference', $stats['wait_gap'] !== null ? abs((int) $stats['wait_gap']) . '%' : '—',
       $stats['wait_gap'] === null ? 'Not enough visits yet'
         : ((int) $stats['wait_gap'] >= 0 ? 'shorter for flagged' : 'longer for flagged'),
       'hourglass', 'bg-violet-50 text-violet-600'],
      ['Last updated', $updated, $updatedDay, 'clock', 'bg-sky-50 text-sky-600'],
  ];
  foreach ($tiles as [$label, $value, $hint, $icon, $tone]):
  ?>
    <div class="stat-tile">
      <span class="stat-ico <?= e($tone) ?>"><svg class="h-5 w-5"><use href="#i-<?= e($icon) ?>"/></svg></span>
      <div class="min-w-0 flex-1">
        <p class="stat-label"><?= e($label) ?></p>
        <p class="stat-value"><?= e($value) ?></p>
        <p class="stat-hint"><?= e($hint) ?></p>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="mt-3 grid gap-3 sm:mt-5 sm:gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">

  <!-- ==================================================== main column -->
  <div class="min-w-0 space-y-3 sm:space-y-5">

    <div class="card overflow-hidden">
      <!-- toolbar -->
      <div class="toolbar">
        <div class="flex min-w-0 items-center gap-3" role="tablist">
          <button type="button" class="utab utab-active" role="tab" aria-selected="true" data-rule-tab="active">Active rules</button>
          <button type="button" class="utab" role="tab" aria-selected="false" data-rule-tab="history">Rule history</button>
        </div>
        <div class="ml-auto flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto">
          <label class="search-mini min-w-0 flex-1 sm:w-[11rem] sm:flex-none">
            <svg class="h-3.5 w-3.5 shrink-0 text-ink-400"><use href="#i-search"/></svg>
            <input type="search" placeholder="Search rules…" data-rule-search aria-label="Search rules">
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

      <!-- filters -->
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
      <div data-rule-pane="active">
        <div class="px-3 pt-3 sm:px-4">
          <details id="rule-guide" class="explainer" open>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2">
              <span class="text-[13px] font-bold text-ink-900">How the rule engine works</span>
              <svg class="h-4 w-4 shrink-0 text-ink-400"><use href="#i-chevron-down"/></svg>
            </summary>
            <ul class="explainer-list">
              <?php foreach ([
                  'Rules are evaluated top to bottom when a patient joins. Drag to change the order.',
                  'The first rule that matches sets the priority — later matches are ignored.',
                  'If nothing matches, the queue’s own appointment/walk-in order applies.',
                  'Rules never reorder patients already called or in consultation.',
                  'A staff member flagging someone by hand overrides every rule.',
              ] as $line): ?>
                <li>
                  <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600"><use href="#i-check-circle"/></svg>
                  <span><?= e($line) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
            <a href="/queues/config" class="mt-2.5 inline-flex items-center gap-1 text-[12px] font-semibold text-brand-700 hover:underline">
              Queue settings and source weights
              <svg class="h-3.5 w-3.5"><use href="#i-arrow-right"/></svg>
            </a>
          </details>
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
          <div class="table-scroll mt-3">
            <table class="dtable dtable-stack min-w-[46rem]" data-rule-table>
              <thead>
                <tr>
                  <th class="w-[4.5rem]"><span class="sr-only">Order</span>Rule</th>
                  <th class="w-[10.5rem]">Priority</th>
                  <th>Applies to</th>
                  <th class="w-[6.5rem]">Source</th>
                  <th class="w-[5.5rem]">Status</th>
                  <th class="w-[3rem]"><span class="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody data-rule-body>
                <?php foreach ($rules as $rule): ?>
                  <?php
                  $rid = (int) $rule['id'];
                  $on = (int) $rule['is_active'] === 1;
                  $eff = (int) $rule['effect'];
                  $strength = $eff >= 3 ? 'ahead' : ($eff > 0 ? 'slight' : 'behind');
                  // Full literal class names: Tailwind tree-shakes component
                  // classes it cannot find in the source, so composing the
                  // name from a fragment silently drops the styling.
                  $strengthClass = $eff >= 3 ? 'prio-ahead' : ($eff > 0 ? 'prio-slight' : 'prio-behind');
                  $strengthWord = $eff >= 3 ? 'Ahead' : ($eff > 0 ? 'Slightly ahead' : 'Behind');
                  $arrow = $eff > 0 ? 'arrow-up' : 'chevron-down';
                  ?>
                  <tr data-rule-row data-id="<?= $rid ?>"
                      data-name="<?= e(mb_strtolower((string) $rule['name'])) ?>"
                      data-strength="<?= e($strength) ?>" data-status="<?= $on ? 'on' : 'off' ?>"
                      class="<?= $on ? '' : 'bg-canvas/60' ?>">
                    <td class="dt-main">
                      <div class="flex items-start gap-2">
                        <button type="button" class="rule-grip" draggable="true" data-rule-grip
                                aria-label="Reorder <?= e($rule['name']) ?>. Use the up and down arrow keys.">
                          <svg class="h-4 w-4"><use href="#i-list"/></svg>
                        </button>
                        <span class="rule-num" data-rule-num><?= (int) $rule['position'] ?></span>
                        <span class="min-w-0">
                          <span class="block truncate text-[13px] font-semibold text-ink-900"><?= e($rule['name']) ?></span>
                          <span class="block truncate text-[11px] text-ink-500"><?= e($rule['reason']) ?></span>
                        </span>
                      </div>
                    </td>
                    <td class="dt-inline">
                      <span class="flex items-center gap-1.5 whitespace-nowrap">
                        <span class="prio <?= e($strengthClass) ?>">
                          <svg class="h-3 w-3 shrink-0"><use href="#i-<?= e($arrow) ?>"/></svg>
                          <?= e($strengthWord) ?>
                        </span>
                        <span class="text-[10.5px] text-ink-400 tabular"><?= $eff > 0 ? '+' : '' ?><?= $eff ?></span>
                      </span>
                    </td>
                    <td class="dt-meta text-[12px] leading-snug text-ink-600"><span class="line-clamp-2" title="<?= e($rule['matches']) ?>"><?= e($rule['matches']) ?></span></td>
                    <td class="dt-hide text-[12px] text-ink-500"><?= e($rule['source'] !== null ? ($sources[$rule['source']] ?? $rule['source']) : 'Any') ?></td>
                    <td class="dt-inline">
                      <span class="<?= $on ? 'badge-emerald' : 'badge-slate' ?>"><?= $on ? 'Active' : 'Off' ?></span>
                    </td>
                    <td class="dt-act">
                      <span class="relative">
                        <button type="button" class="act-mute !px-1.5" data-row-more aria-haspopup="true" aria-expanded="false"
                                aria-label="Actions for <?= e($rule['name']) ?>">
                          <svg class="h-3.5 w-3.5"><use href="#i-dots"/></svg>
                        </button>
                        <span class="absolute right-0 top-full z-40 mt-1 hidden min-w-[10rem] overflow-hidden rounded-lg border border-hairline bg-white p-1 shadow-pop" data-row-menu>
                          <form method="post" action="/queues/priority-rules/<?= $rid ?>/toggle">
                            <?= csrf_field() ?>
                            <button class="block w-full rounded px-2 py-1.5 text-left text-[12px] font-medium text-ink-700 hover:bg-canvas">
                              <?= $on ? 'Turn off' : 'Turn on' ?>
                            </button>
                          </form>
                          <form method="post" action="/queues/priority-rules/<?= $rid ?>/delete"
                                onsubmit="return confirm('Delete “<?= e($rule['name']) ?>”? Patients it already reordered keep their recorded reason.');">
                            <?= csrf_field() ?>
                            <button class="block w-full rounded px-2 py-1.5 text-left text-[12px] font-medium text-rose-600 hover:bg-rose-50">Delete rule</button>
                          </form>
                        </span>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <p class="hidden px-4 py-6 text-center text-[12.5px] text-ink-500" data-rule-empty>No rule matches that search.</p>

          <div class="flex flex-wrap items-center justify-between gap-2 border-t border-hairline px-3 py-2.5 text-[11px] text-ink-500 sm:px-4">
            <p data-order-hint>Drag a row to change which rule wins · <span class="text-ink-400">arrow keys work on the handle</span></p>
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
                  <svg class="h-3.5 w-3.5"><use href="#i-<?= $isReorder ? 'list' : 'edit' ?>"/></svg>
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
    <div class="card">
      <div class="section-head">
        <div class="min-w-0">
          <p class="section-title">Who is overriding priority</p>
          <p class="mt-0.5 text-[11px] text-ink-500">Manual flags, <?= e(date('j M', strtotime($from))) ?> – <?= e(date('j M', strtotime($to))) ?></p>
        </div>
        <form method="get" action="/queues/priority-rules" class="flex flex-wrap items-center gap-1.5">
          <input class="input !w-auto !py-1.5 text-xs" type="date" name="from" value="<?= e($from) ?>" aria-label="From">
          <input class="input !w-auto !py-1.5 text-xs" type="date" name="to" value="<?= e($to) ?>" aria-label="To">
          <button class="btn-secondary btn-sm">Show</button>
        </form>
      </div>

      <div class="flex flex-wrap items-center gap-4 px-3 py-4 sm:px-4">
        <div class="metric-strip min-w-[15rem] flex-1">
          <div>
            <p class="metric-value"><?= (int) $overuse['overrides'] ?></p>
            <p class="metric-label">Total overrides</p>
          </div>
          <div>
            <p class="metric-value"><?= $overuse['tokens'] > 0 ? e((string) $overuse['rate']) . '%' : '—' ?></p>
            <p class="metric-label">Of tokens issued</p>
          </div>
          <div>
            <p class="metric-value"><?= (int) $overuse['rule_hits'] ?></p>
            <p class="metric-label">Decided by a rule</p>
          </div>
        </div>
        <a href="/reports" class="btn-secondary btn-sm shrink-0">View report</a>
      </div>

      <?php if (!empty($overuse['per_staff'])): ?>
        <div class="table-scroll border-t border-hairline">
          <table class="dtable min-w-[26rem]">
            <thead><tr><th>Staff</th><th class="w-[6rem]">Overrides</th><th class="w-[6rem]">Tokens</th><th class="w-[6rem]">Rate</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($overuse['per_staff'], 0, 5) as $ps): ?>
                <tr>
                  <td class="text-[12.5px] font-medium text-ink-800"><?= e($ps['name']) ?></td>
                  <td class="text-[12.5px] text-ink-700 tabular"><?= (int) $ps['overrides'] ?></td>
                  <td class="text-[12.5px] text-ink-500 tabular"><?= (int) $ps['tokens'] ?></td>
                  <td class="text-[12.5px] text-ink-500 tabular"><?= $ps['rate'] !== null ? e((string) $ps['rate']) . '%' : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($stats['wait_gap'] !== null): ?>
        <p class="border-t border-hairline px-4 py-2.5 text-[11px] leading-relaxed text-ink-500">
          Flagged patients waited <?= e((string) $stats['wait_flagged']) ?> min on average against
          <?= e((string) $stats['wait_plain']) ?> min for everyone else. That is a comparison of two
          different groups, not proof the rules caused the difference.
        </p>
      <?php endif; ?>
    </div>

    <!-- ------------------------------------------------ audit strip -->
    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-hairline bg-canvas px-4 py-3">
      <p class="flex min-w-0 items-start gap-2 text-[12px] text-ink-600">
        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-sky-600"><use href="#i-info"/></svg>
        Every rule change and every manual override is written to the audit log with patient, rule and reason.
      </p>
      <a href="/audit" class="inline-flex min-h-[32px] shrink-0 items-center gap-1 text-[12px] font-semibold text-brand-700 hover:underline">
        Go to audit log <svg class="h-3.5 w-3.5"><use href="#i-arrow-right"/></svg>
      </a>
    </div>
  </div>

  <!-- ==================================================== side form -->
  <aside class="min-w-0">
    <div class="card overflow-hidden xl:sticky xl:top-4" id="rule-form-card">
      <button type="button" class="disclosure-btn" data-toggle="#rule-form" aria-expanded="<?= $showForm ? 'true' : 'false' ?>">
        Create new rule
        <svg class="h-4 w-4 shrink-0 text-ink-400"><use href="#i-chevron-down"/></svg>
      </button>

      <form method="post" action="/queues/priority-rules" id="rule-form" class="<?= $showForm ? '' : 'hidden' ?> border-t border-hairline px-4 py-4">
        <?= csrf_field() ?>

        <label class="label" for="name">Rule name</label>
        <input class="input" id="name" name="name" required maxlength="80"
               placeholder="e.g. Under-5 first" value="<?= e($val('name')) ?>">

        <div class="mt-3 grid grid-cols-2 gap-2">
          <div>
            <label class="label" for="effect">Priority</label>
            <select class="select" id="effect" name="effect">
              <?php foreach ([3 => 'Ahead (+3)', 2 => 'Ahead (+2)', 1 => 'Slightly ahead (+1)', -1 => 'Behind (−1)', -2 => 'Behind (−2)'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= (int) $val('effect', '2') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="source">Source</label>
            <select class="select" id="source" name="source">
              <option value="">Any</option>
              <?php foreach ($sources as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $val('source') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mt-3">
          <label class="label" for="queue_id">Applies to</label>
          <select class="select" id="queue_id" name="queue_id">
            <option value="">Any queue</option>
            <?php foreach ($queues as $q): ?>
              <option value="<?= (int) $q['id'] ?>" <?= (int) $val('queue_id') === (int) $q['id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2">
          <div>
            <label class="label" for="kind">Queue type</label>
            <select class="select" id="kind" name="kind">
              <option value="">Any</option>
              <?php foreach ($kinds as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $val('kind') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="doctor_id">Doctor</label>
            <select class="select" id="doctor_id" name="doctor_id">
              <option value="">Any</option>
              <?php foreach ($doctors as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $val('doctor_id') === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2">
          <div>
            <label class="label" for="min_age">Youngest age</label>
            <input class="input" id="min_age" name="min_age" type="number" min="0" max="130" placeholder="0" value="<?= e($val('min_age')) ?>">
          </div>
          <div>
            <label class="label" for="max_age">Oldest age</label>
            <input class="input" id="max_age" name="max_age" type="number" min="0" max="130" placeholder="120" value="<?= e($val('max_age')) ?>">
          </div>
        </div>
        <p class="field-help">Leave both blank for any age.</p>

        <div class="mt-3 grid grid-cols-2 gap-2">
          <div>
            <label class="label" for="from_hour">From hour</label>
            <input class="input" id="from_hour" name="from_hour" type="number" min="0" max="23" placeholder="0" value="<?= e($val('from_hour')) ?>">
          </div>
          <div>
            <label class="label" for="to_hour">To hour</label>
            <input class="input" id="to_hour" name="to_hour" type="number" min="1" max="24" placeholder="24" value="<?= e($val('to_hour')) ?>">
          </div>
        </div>
        <p class="field-help">24-hour clock, clinic local. Blank means all day.</p>

        <div class="mt-3">
          <label class="label" for="reason">Clinical reason</label>
          <textarea class="input" id="reason" name="reason" rows="2" required maxlength="160"
                    placeholder="Why this rule exists"><?= e($val('reason')) ?></textarea>
          <p class="field-help">Shown on the patient’s record and in the audit log.</p>
        </div>

        <button class="btn-primary mt-4 w-full" type="submit">Save rule</button>
        <button class="btn-secondary mt-2 w-full" type="button" data-close-form>Cancel</button>

        <p class="mt-3 text-[11px] leading-relaxed text-ink-500">
          A new rule applies to the next token issued, never to patients already waiting.
          Rules that push people ahead delay everyone else — use them deliberately.
        </p>
      </form>
    </div>
  </aside>
</div>
