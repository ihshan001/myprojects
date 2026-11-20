<?php
use App\Core\Auth;
/**
 * Priority rules.
 *
 * The list IS the policy: rules are evaluated top to bottom and the first
 * match decides, so the order on screen is the order the engine uses. Every
 * figure on this page is measured — the tiles count rows and finished visits,
 * and anything without enough samples behind it prints a dash rather than a
 * flattering zero.
 *
 * @var array $rules  each with `matches` and `position`
 * @var array $impact from PriorityImpactService
 */
$chip = static function (int $effect): array {
    return match (true) {
        $effect >= 5  => ['Ahead', 'bg-rose-50 text-rose-600', 'arrow-up'],
        $effect > 0   => ['Slightly ahead', 'bg-amber-50 text-amber-600', 'arrow-right'],
        default       => ['Behind', 'bg-sky-50 text-sky-600', 'chevron-down'],
    };
};
$dash = '—';
// Reading the policy is open to the clinic's staff; changing it is not. The
// mutations are all `clinic.manage`, so the controls are only rendered for
// someone who can actually use them.
$canManage = App\Core\Auth::can('clinic.manage');
$pct = $impact['rule']['pct'];
$waitTile = $pct === null ? $dash : ((int) $pct . '%');
$manualDiff = $impact['manual']['diff'];
$changedTs = $lastChangedAt !== null ? strtotime((string) $lastChangedAt) : false;
$changedTime = $changedTs !== false ? date('g:i A', $changedTs) : $dash;
$changedHint = $changedTs === false ? 'No changes yet'
    : (date('Y-m-d', $changedTs) === date('Y-m-d') ? 'Today' : date('j M', $changedTs));

$stats = [
    ['Rules in action', (string) $activeCount, 'Active rules', 'users', 'bg-emerald-50 text-emerald-500'],
    ['Overriding priority', (string) $overridingNow, 'Right now', 'shield', 'bg-amber-50 text-amber-500'],
    ['Avg. wait improvement', $waitTile, 'vs unprioritised · 7d', 'activity', 'bg-violet-50 text-violet-500'],
    ['Last updated', $changedTime, $changedHint, 'clock', 'bg-sky-50 text-sky-500'],
];
?>

<!-- ============================================================ head ==== -->
<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
      <svg class="h-5 w-5"><use href="#i-shield-check"/></svg>
    </span>
    <div class="min-w-0">
      <h1 class="page-title">Priority rules</h1>
      <p class="mt-1 text-sm text-ink-500">
        Set and manage rules that determine clinical priority in your live queue.
      </p>
    </div>
  </div>
  <div class="flex shrink-0 gap-2">
    <a class="btn-secondary btn-sm" href="#how-rules-work">
      <svg class="h-4 w-4"><use href="#i-info"/></svg> Guide
    </a>
    <a class="btn-secondary btn-sm !bg-brand-50 !text-brand-700 !border-brand-100" href="/app">
      <svg class="h-4 w-4"><use href="#i-calendar"/></svg> Today's board
    </a>
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

<!-- =========================================================== tiles ==== -->
<div class="pr-stats mt-3 flex gap-1.5 overflow-x-auto pb-1 sm:mt-6 sm:grid sm:grid-cols-2 sm:gap-4 sm:overflow-visible sm:pb-0 lg:grid-cols-4">
  <?php foreach ($stats as [$label, $value, $hint, $icon, $tone]): ?>
    <div class="stat-tile w-[150px] shrink-0 sm:w-auto">
      <span class="stat-ico <?= e($tone) ?>">
        <svg class="h-5 w-5"><use href="#i-<?= e($icon) ?>"/></svg>
      </span>
      <div class="min-w-0 flex-1">
        <p class="stat-label"><?= e($label) ?></p>
        <p class="stat-value"><?= e($value) ?></p>
        <p class="stat-hint"><?= e($hint) ?></p>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="mt-3 grid min-w-0 gap-3 sm:mt-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-5">
  <div class="min-w-0 space-y-3 sm:space-y-5">

    <!-- ================================================== rules card ==== -->
    <div class="card overflow-hidden">
      <div class="flex flex-wrap items-center gap-3 border-b border-hairline px-4 pt-3">
        <nav class="flex min-w-0 gap-4" aria-label="Rule views">
          <a class="qtab <?= $tab === 'active' ? 'qtab-active' : '' ?>" href="/queues/priority-rules?tab=active">Active rules</a>
          <a class="qtab <?= $tab === 'history' ? 'qtab-active' : '' ?>" href="/queues/priority-rules?tab=history">Rule history</a>
        </nav>
        <?php if ($tab === 'active'): ?>
          <form method="get" action="/queues/priority-rules" class="ml-auto flex w-full flex-wrap items-center gap-1.5 pb-2 sm:w-auto">
            <input type="hidden" name="tab" value="active">
            <div class="relative min-w-0 flex-1 sm:w-44 sm:flex-none">
              <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"><use href="#i-search"/></svg>
              <input class="input !py-1.5 !pl-8 text-xs" type="search" name="q" value="<?= e($q) ?>" placeholder="Search rules…">
            </div>
            <details class="relative" <?= $status !== 'all' ? 'open' : '' ?>>
              <summary class="btn-secondary !py-1.5 cursor-pointer list-none text-xs">
                <svg class="h-3.5 w-3.5"><use href="#i-filter"/></svg> Filters
                <?php if ($status !== 'all'): ?><span class="ml-0.5 h-1.5 w-1.5 rounded-full bg-brand-600"></span><?php endif; ?>
              </summary>
              <div class="absolute right-0 z-30 mt-1 w-44 rounded-lg border border-hairline bg-white p-2 shadow-pop">
                <p class="px-1 pb-1 text-[10px] font-semibold uppercase tracking-wide text-ink-400">Status</p>
                <?php // Links, not submit buttons: a button named `status` beside
                      // the hidden `status` field sends the name twice and PHP
                      // keeps the LAST one, so the choice silently did nothing. ?>
                <?php foreach (['all' => 'All rules', 'active' => 'Active only', 'off' => 'Turned off'] as $val => $label): ?>
                  <a class="block w-full rounded px-2 py-1.5 text-left text-xs <?= $status === $val ? 'bg-brand-50 font-semibold text-brand-700' : 'text-ink-700 hover:bg-slate-50' ?>"
                     href="<?= e('/queues/priority-rules?' . http_build_query(['tab' => 'active', 'q' => $q, 'status' => $val])) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
              </div>
            </details>
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <?php if ($canManage): ?>
              <a class="btn-primary !py-1.5 text-xs" href="#create-rule">
                <svg class="h-3.5 w-3.5"><use href="#i-plus"/></svg> New rule
              </a>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($tab === 'history'): ?>
        <!-- ------------------------------------------------- history --- -->
        <?php if ($history === []): ?>
          <p class="px-4 py-8 text-sm text-ink-500">No changes recorded yet.</p>
        <?php else: ?>
          <ul class="divide-y divide-slate-100">
            <?php foreach ($history as $h): ?>
              <li class="flex flex-wrap items-start gap-3 px-4 py-3">
                <span class="mt-0.5 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-700">
                  <?= e($h['action']) ?>
                </span>
                <div class="min-w-0 flex-1">
                  <p class="text-[13px] font-medium text-ink-900"><?= e($h['rule_name']) ?></p>
                  <?php if (!empty($h['detail'])): ?>
                    <p class="mt-0.5 text-xs text-ink-500"><?= e($h['detail']) ?></p>
                  <?php endif; ?>
                </div>
                <div class="text-right text-[11px] text-ink-400">
                  <p><?= e(date('j M, g:i A', strtotime((string) $h['created_at']))) ?></p>
                  <p><?= e($h['actor_name'] ?? 'System') ?></p>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

      <?php else: ?>
        <!-- ------------------------------------------ engine explainer --- -->
        <div class="p-4" id="how-rules-work">
          <div class="rounded-xl bg-slate-50 px-4 py-3.5">
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0">
            <p class="text-[13px] font-semibold text-ink-900">How the rule engine works</p>
            <ul class="mt-2 space-y-1.5">
              <?php foreach ([
                  'Rules are evaluated from top to bottom when a patient joins.',
                  'The first matching rule sets the priority — nothing stacks.',
                  'If no rule matches, the default queue order applies.',
                  'Rules never reorder patients already called or in consultation.',
              ] as $line): ?>
                <li class="flex items-start gap-2 text-xs text-ink-700">
                  <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-500"><use href="#i-check-circle"/></svg>
                  <span><?= e($line) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
            <a class="mt-2.5 inline-flex items-center gap-1 text-xs font-semibold text-brand-700" href="#create-rule">
              Learn more about priority rules <svg class="h-3 w-3"><use href="#i-arrow-right"/></svg>
            </a>
              </div>
              <?php // Decorative: a ranked list, echoing the table below. ?>
              <svg class="hidden h-16 w-24 shrink-0 sm:block" viewBox="0 0 96 64" fill="none" aria-hidden="true">
                <rect x="30" y="8" width="52" height="7" rx="3.5" fill="#a7f3d0"/>
                <rect x="30" y="28" width="38" height="7" rx="3.5" fill="#d1fae5"/>
                <rect x="30" y="48" width="44" height="7" rx="3.5" fill="#e2e8f0"/>
                <circle cx="14" cy="11.5" r="7" stroke="#0d7459" stroke-width="2"/>
                <path d="M14 8v7M10.5 11.5h7" stroke="#0d7459" stroke-width="2" stroke-linecap="round"/>
                <circle cx="14" cy="31.5" r="7" stroke="#34d399" stroke-width="2"/>
                <circle cx="14" cy="51.5" r="7" stroke="#cbd5e1" stroke-width="2"/>
                <path d="M11 51.5h6" stroke="#cbd5e1" stroke-width="2" stroke-linecap="round"/>
              </svg>
            </div>
          </div>
        </div>

        <?php if ($rules === []): ?>
          <p class="px-4 pb-6 text-sm text-ink-500">
            <?php if ($filtering): ?>
              No rule matches these filters. <a class="font-semibold text-brand-700" href="/queues/priority-rules">Clear filters</a>.
            <?php else: ?>
              No rules yet. Every arrival is ordered by its source weight and when it joined.
              Add one on the right if this clinic has a standing clinical priority.
            <?php endif; ?>
          </p>
        <?php else: ?>
          <!-- ------------------------------------------------ the list --- -->
          <?php // Reordering needs the WHOLE list: the endpoint refuses a partial
                // order, so dragging is only offered when nothing is filtered out. ?>
          <form method="post" action="/queues/priority-rules/reorder" id="rule-order-form"
                <?= ($filtering || !$canManage) ? '' : 'data-reorderable' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="order" id="rule-order" value="">
            <div class="overflow-x-auto">
              <table class="w-full min-w-[640px] border-collapse text-left">
                <thead>
                  <tr class="border-y border-hairline bg-slate-50/60">
                    <th class="w-14"></th>
                    <th class="px-2 py-2 text-[10px] font-semibold uppercase tracking-[0.06em] text-ink-500">Rule</th>
                    <th class="px-2 py-2 text-[10px] font-semibold uppercase tracking-[0.06em] text-ink-500">Priority</th>
                    <th class="px-2 py-2 text-[10px] font-semibold uppercase tracking-[0.06em] text-ink-500">Applies to</th>
                    <th class="px-2 py-2 text-[10px] font-semibold uppercase tracking-[0.06em] text-ink-500">Source</th>
                    <th class="px-2 py-2 text-[10px] font-semibold uppercase tracking-[0.06em] text-ink-500">Status</th>
                    <th class="w-10"></th>
                  </tr>
                </thead>
                <tbody id="rule-rows" class="divide-y divide-slate-100">
                  <?php foreach ($rules as $rule): ?>
                    <?php
                      $isActive = (int) $rule['is_active'] === 1;
                      [$chipText, $chipTone, $chipIcon] = $chip((int) $rule['effect']);
                    ?>
                    <tr class="pr-rule <?= $isActive ? '' : 'opacity-60' ?>" data-rule-id="<?= (int) $rule['id'] ?>">
                      <td class="pl-3">
                        <div class="flex items-center gap-1.5">
                          <button type="button" class="pr-grip hidden cursor-grab text-slate-300 hover:text-ink-500"
                                  aria-label="Reorder <?= e($rule['name']) ?>">
                            <svg class="h-4 w-4"><use href="#i-grip"/></svg>
                          </button>
                          <span class="pr-pos flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-ink-700 tabular">
                            <?= (int) $rule['position'] ?>
                          </span>
                        </div>
                      </td>
                      <td class="px-2 py-3">
                        <p class="text-[13px] font-semibold text-ink-900"><?= e($rule['name']) ?></p>
                        <p class="mt-0.5 text-[11px] text-ink-500"><?= e($rule['matches']) ?></p>
                      </td>
                      <td class="px-2 py-3">
                        <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-1 text-[11px] font-semibold <?= e($chipTone) ?>">
                          <svg class="h-3 w-3"><use href="#i-<?= e($chipIcon) ?>"/></svg><?= e($chipText) ?>
                        </span>
                      </td>
                      <td class="px-2 py-3 text-xs text-ink-700"><?= e($rule['queue_name'] ?? 'Any queue') ?></td>
                      <td class="px-2 py-3 text-xs text-ink-700"><?= e($sources[$rule['source']] ?? 'Any') ?></td>
                      <td class="px-2 py-3">
                        <?php if ($isActive): ?>
                          <span class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700">Active</span>
                        <?php else: ?>
                          <span class="rounded-md bg-slate-100 px-2 py-1 text-[11px] font-semibold text-ink-500">Off</span>
                        <?php endif; ?>
                      </td>
                      <td class="pr-2">
                        <?php if ($canManage): ?>
                        <details class="relative">
                          <summary class="flex h-7 w-7 cursor-pointer list-none items-center justify-center rounded-md text-ink-400 hover:bg-slate-100">
                            <svg class="h-4 w-4"><use href="#i-dots"/></svg>
                          </summary>
                          <div class="absolute right-0 z-20 mt-1 w-40 overflow-hidden rounded-lg border border-hairline bg-white py-1 shadow-pop">
                            <button class="block w-full px-3 py-1.5 text-left text-xs text-ink-700 hover:bg-slate-50"
                                    type="submit" form="rule-toggle-<?= (int) $rule['id'] ?>">
                              <?= $isActive ? 'Turn off' : 'Turn on' ?>
                            </button>
                            <button class="block w-full px-3 py-1.5 text-left text-xs text-rose-600 hover:bg-rose-50"
                                    type="submit" form="rule-delete-<?= (int) $rule['id'] ?>">
                              Delete rule
                            </button>
                          </div>
                        </details>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                  <!-- The built-in fallback. Not a rule: `effect` can never be
                       zero, so this row is the engine's own default and is
                       deliberately not draggable. -->
                  <tr class="bg-slate-50/40">
                    <td class="pl-3">
                      <span data-system-pos class="ml-[26px] flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-ink-400">
                        <?= count($rules) + 1 ?>
                      </span>
                    </td>
                    <td class="px-2 py-3">
                      <p class="text-[13px] font-semibold text-ink-900">Default queue order</p>
                      <p class="mt-0.5 text-[11px] text-ink-500">Source weight, then first come, first served</p>
                    </td>
                    <td class="px-2 py-3">
                      <span class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-2 py-1 text-[11px] font-semibold text-sky-600">
                        <svg class="h-3 w-3"><use href="#i-chevron-down"/></svg>Normal
                      </span>
                    </td>
                    <td class="px-2 py-3 text-xs text-ink-700">Any queue</td>
                    <td class="px-2 py-3 text-xs text-ink-700">System</td>
                    <td class="px-2 py-3">
                      <span class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700">Built in</span>
                    </td>
                    <td></td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-hairline px-4 py-2.5">
              <p class="text-[11px] text-ink-500">
                <?php if (!$canManage): ?>
                  Read-only · ask a clinic admin to change the order ·
                <?php elseif ($filtering): ?>
                  <a class="font-semibold text-brand-700" href="/queues/priority-rules">Clear filters to reorder</a> ·
                <?php else: ?>
                  <span id="pr-drag-hint" class="hidden">Drag rules to reorder · </span>
                <?php endif; ?>
                <a class="font-semibold text-brand-700" href="#how-rules-work">View guidelines</a>
              </p>
              <div class="flex items-center gap-2">
                <button type="submit" id="pr-save-order" class="btn-primary !py-1.5 text-xs hidden">Save order</button>
                <p class="text-[11px] text-ink-400">Last updated: <?= e($changedTime) ?></p>
                <a class="text-ink-400 hover:text-ink-700" href="/queues/priority-rules" aria-label="Refresh">
                  <svg class="h-3.5 w-3.5"><use href="#i-refresh"/></svg>
                </a>
              </div>
            </div>
          </form>

          <?php // Row actions live outside the reorder form: nested forms are invalid HTML. ?>
          <?php foreach ($canManage ? $rules : [] as $rule): ?>
            <form id="rule-toggle-<?= (int) $rule['id'] ?>" method="post"
                  action="/queues/priority-rules/<?= (int) $rule['id'] ?>/toggle" class="hidden"><?= csrf_field() ?></form>
            <form id="rule-delete-<?= (int) $rule['id'] ?>" method="post"
                  action="/queues/priority-rules/<?= (int) $rule['id'] ?>/delete" class="hidden"><?= csrf_field() ?></form>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- ================================================== overuse ======== -->
    <?php
      $windowDays = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
      // A positive difference means the prioritised group waited LESS. The sign
      // is kept on screen rather than absolute-valued, because "priority made
      // this group slower" is a real result the manager needs to see.
      $impactText = $manualDiff === null ? $dash
          : (($manualDiff < 0 ? '−' : '') . (int) round(abs($manualDiff)) . ' min');
    ?>
    <div class="card overflow-hidden">
      <div class="flex flex-wrap items-center gap-4 px-4 py-3.5">
        <div class="min-w-0">
          <p class="section-title">Who is overriding priority</p>
          <p class="text-[11px] text-ink-400">Based on last <?= $windowDays ?> days</p>
        </div>
        <div class="flex flex-1 flex-wrap items-center justify-around gap-3 text-center">
          <div>
            <p class="text-[17px] font-bold leading-tight text-ink-900 tabular"><?= (int) $overuse['overrides'] ?></p>
            <p class="text-[10px] text-ink-500">Total overrides</p>
          </div>
          <div>
            <p class="text-[17px] font-bold leading-tight text-ink-900 tabular"><?= e((string) $overuse['rate']) ?>%</p>
            <p class="text-[10px] text-ink-500">Of <?= (int) $overuse['tokens'] ?> tokens</p>
          </div>
          <div>
            <p class="text-[17px] font-bold leading-tight text-ink-900 tabular"
               title="<?= $manualDiff === null ? 'Fewer than 5 finished visits in this group' : 'Compared with unprioritised visits in the same period. A minus means they waited longer.' ?>"><?= e($impactText) ?></p>
            <p class="text-[10px] text-ink-500">Avg. impact on wait time</p>
          </div>
        </div>
        <details class="relative shrink-0">
          <summary class="btn-secondary btn-sm cursor-pointer list-none">View report</summary>
          <div class="absolute right-0 z-20 mt-1 w-56 rounded-lg border border-hairline bg-white p-3 shadow-pop">
            <form method="get" action="/queues/priority-rules" class="space-y-2">
              <input type="hidden" name="tab" value="<?= e($tab) ?>">
              <div>
                <label class="label" for="ov-from">From</label>
                <input class="input !py-1.5 text-xs" id="ov-from" type="date" name="from" value="<?= e($from) ?>">
              </div>
              <div>
                <label class="label" for="ov-to">To</label>
                <input class="input !py-1.5 text-xs" id="ov-to" type="date" name="to" value="<?= e($to) ?>">
              </div>
              <button class="btn-primary w-full !py-1.5 text-xs">Show</button>
            </form>
          </div>
        </details>
      </div>
      <?php if (!empty($overuse['per_staff'])): ?>
        <ul class="divide-y divide-slate-100 border-t border-slate-100">
          <?php foreach (array_slice($overuse['per_staff'], 0, 5) as $person): ?>
            <li class="flex items-center justify-between gap-3 px-4 py-2 text-xs">
              <span class="min-w-0 truncate text-ink-700"><?= e((string) ($person['name'] ?? 'Unknown')) ?></span>
              <span class="shrink-0 text-ink-500 tabular">
                <?= (int) ($person['overrides'] ?? 0) ?> of <?= (int) ($person['tokens'] ?? 0) ?> tokens
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- ==================================================== audit note === -->
    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-100/70 px-4 py-3">
      <p class="flex items-start gap-2 text-xs text-ink-700">
        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-sky-500"><use href="#i-info"/></svg>
        Every rule change is kept in Rule history, and every priority a rule applies is logged with its reason.
      </p>
      <?php if (Auth::can('clinic.manage')): ?>
        <a class="shrink-0 text-xs font-semibold text-brand-700" href="/audit">
          Go to audit log <svg class="inline h-3 w-3"><use href="#i-arrow-right"/></svg>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ==================================================== create form === -->
  <?php if (!$canManage): ?>
    <div class="card h-fit p-4 lg:sticky lg:top-20">
      <p class="section-title">Read-only</p>
      <p class="mt-1.5 text-xs text-ink-500">
        You can see how this clinic prioritises patients. Creating, reordering or
        switching off rules is a clinic admin action — ask one of them to change it.
      </p>
    </div>
  <?php else: ?>
  <div class="card h-fit lg:sticky lg:top-20" id="create-rule">
    <details open>
      <summary class="section-head cursor-pointer list-none">
        <p class="section-title">Create new rule</p>
        <svg class="h-4 w-4 text-ink-400"><use href="#i-chevron-down"/></svg>
      </summary>

      <form method="post" action="/queues/priority-rules" class="pr-form space-y-3 p-4">
        <?= csrf_field() ?>

        <div>
          <label class="label" for="name">Rule name</label>
          <input class="input" id="name" name="name" required maxlength="80"
                 value="<?= e($old['name'] ?? '') ?>" placeholder="e.g., Under-5 first">
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label" for="effect">Priority</label>
            <select class="select" id="effect" name="effect" required>
              <?php for ($i = 9; $i >= -5; $i--): if ($i === 0) { continue; } ?>
                <option value="<?= $i ?>" <?= (string) ($old['effect'] ?? '2') === (string) $i ? 'selected' : '' ?>>
                  <?= $i >= 5 ? 'Ahead (+' . $i . ')' : ($i > 0 ? 'Slightly ahead (+' . $i . ')' : 'Behind (' . $i . ')') ?>
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

        <div>
          <label class="label" for="queue_id">Applies to</label>
          <select class="select" id="queue_id" name="queue_id">
            <option value="">Any queue</option>
            <?php foreach ($queues as $queue): ?>
              <option value="<?= (int) $queue['id'] ?>" <?= (string) ($old['queue_id'] ?? '') === (string) $queue['id'] ? 'selected' : '' ?>>
                <?= e($queue['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
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
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label" for="min_age">Youngest age</label>
              <input class="input" id="min_age" name="min_age" type="number" min="0" max="130"
                     value="<?= e((string) ($old['min_age'] ?? '')) ?>" placeholder="0">
            </div>
            <div>
              <label class="label" for="max_age">Oldest age</label>
              <input class="input" id="max_age" name="max_age" type="number" min="0" max="130"
                     value="<?= e((string) ($old['max_age'] ?? '')) ?>" placeholder="120">
            </div>
          </div>
          <p class="mt-1 text-[11px] text-ink-500">Leave blank for any age.</p>
        </div>

        <div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label" for="min_temp">Temp at least</label>
              <input class="input" id="min_temp" name="min_temp" type="number" step="0.1" min="30" max="45"
                     value="<?= e((string) ($old['min_temp'] ?? '')) ?>" placeholder="39.0">
            </div>
            <div>
              <label class="label" for="max_temp">Temp at most</label>
              <input class="input" id="max_temp" name="max_temp" type="number" step="0.1" min="30" max="45"
                     value="<?= e((string) ($old['max_temp'] ?? '')) ?>" placeholder="°C">
            </div>
          </div>
          <p class="mt-1 text-[11px] text-ink-500">
            Only matches when a reading was taken at the desk. An unmeasured patient is not assumed afebrile.
          </p>
        </div>

        <div>
          <p class="label">Time window (optional)</p>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="sr-only" for="from_hour">From hour</label>
              <input class="input" id="from_hour" name="from_hour" type="number" min="0" max="23"
                     value="<?= e((string) ($old['from_hour'] ?? '')) ?>" placeholder="From hour">
            </div>
            <div>
              <label class="sr-only" for="to_hour">To hour</label>
              <input class="input" id="to_hour" name="to_hour" type="number" min="1" max="24"
                     value="<?= e((string) ($old['to_hour'] ?? '')) ?>" placeholder="To hour">
            </div>
          </div>
          <p class="mt-1 text-[11px] text-ink-500">Whole hours on the clinic's clock, 0–23. A window past midnight is two rules.</p>
        </div>

        <div>
          <label class="label" for="reason">Reason</label>
          <textarea class="input min-h-[64px] resize-y" id="reason" name="reason" required maxlength="160"
                    placeholder="Brief reason for this rule"><?= e($old['reason'] ?? '') ?></textarea>
          <p class="mt-1 text-[11px] text-ink-500">Copied onto every entry it decides, so the order is explainable later.</p>
        </div>

        <button class="btn-primary w-full" type="submit">Save rule</button>
        <a class="btn-secondary w-full" href="/queues/priority-rules">Cancel</a>
        <p class="text-[11px] text-ink-500">
          A new rule goes to the bottom of the list. Drag it up if it should outrank the others.
          Rules that push patients back delay them — use with care.
        </p>
      </form>
    </details>
  </div>
  <?php endif; ?>
</div>