<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= e((string) ($org['name'] ?? 'Group')) ?></h1>
    <p class="mt-1 text-sm text-slate-500">
      Branch performance side by side. Counts only — no patient names cross a branch boundary.
    </p>
  </div>
  <a href="/settings" class="btn-secondary"><svg class="h-4 w-4"><use href="#i-settings"/></svg>Settings</a>
</div>

<?php if ($org === null): ?>
  <div class="mt-5 card card-pad">
    <h2 class="text-sm font-bold text-slate-900">This clinic is not part of a group</h2>
    <p class="mt-2 text-xs leading-relaxed text-slate-600">
      If you run several branches, a platform administrator can place them in one organisation.
      Each branch keeps its own staff, its own queue and its own data — the group only adds the
      summary below. Ask your account manager to set it up.
    </p>
  </div>
<?php else: ?>

  <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <?php foreach ([
        ['Branches', (string) ($rollup['branches'] ?? 0), 'monitor'],
        ['Seen today', (string) ($rollup['seen_today'] ?? 0), 'users'],
        ['Waiting now', (string) ($rollup['waiting_now'] ?? 0), 'clock'],
        ['Average wait', $rollup['avg_wait'] === null ? '—' : ($rollup['avg_wait'] . ' min'), 'chart'],
    ] as [$label, $value, $icon]): ?>
      <div class="card card-pad">
        <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
          <svg class="h-3.5 w-3.5"><use href="#i-<?= e($icon) ?>"/></svg><?= e($label) ?>
        </p>
        <p class="mt-1.5 text-2xl font-bold tabular text-slate-900"><?= e($value) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($rollup['busiest']) && (int) $rollup['busiest']['waiting_now'] > 0): ?>
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
      <p class="text-xs font-bold text-amber-900">
        Busiest branch: <?= e((string) $rollup['busiest']['name']) ?>
        — <?= (int) $rollup['busiest']['waiting_now'] ?> waiting
      </p>
      <p class="mt-0.5 text-[11px] text-amber-800">
        Consider moving a doctor there for the rest of the session.
      </p>
    </div>
  <?php endif; ?>

  <div class="mt-5 card">
    <div class="border-b border-slate-100 px-4 py-3">
      <h2 class="text-sm font-bold text-slate-900">Branches</h2>
    </div>
    <div class="table-scroll">
      <table class="table">
      <thead>
      <tr>
      <th>Branch</th>
      <th class="hidden sm:table-cell">City</th>
      <th class="numeric">Seen today</th>
      <th class="numeric">Waiting</th>
      <th class="numeric hidden md:table-cell">Avg wait</th>
      <th class="numeric hidden md:table-cell">Staff</th>
      <th>Status</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($branches as $b): ?>
      <tr class="<?= (int) $b['id'] === $currentClinicId ? 'bg-teal-50/40' : '' ?>">
      <td>
      <span class="font-semibold text-slate-800"><?= e((string) $b['name']) ?></span>
      <?php if ((int) $b['id'] === $currentClinicId): ?>
      <span class="ml-1 badge-teal">you</span>
      <?php endif; ?>
      </td>
      <td class="hidden sm:table-cell text-xs text-slate-500"><?= e((string) ($b['city'] ?? '—')) ?></td>
      <td class="numeric tabular"><?= (int) $b['seen_today'] ?></td>
      <td class="numeric tabular <?= (int) $b['waiting_now'] > 8 ? 'font-bold text-amber-700' : '' ?>">
      <?= (int) $b['waiting_now'] ?>
      </td>
      <td class="numeric hidden md:table-cell tabular text-slate-600">
      <?= $b['avg_wait'] === null ? '—' : (int) $b['avg_wait'] . ' min' ?>
      </td>
      <td class="numeric hidden md:table-cell tabular text-slate-600"><?= (int) $b['staff'] ?></td>
      <td>
      <span class="<?= $b['billing_status'] === 'active' ? 'badge-emerald' : ($b['billing_status'] === 'suspended' ? 'badge-rose' : 'badge-amber') ?>">
      <?= e((string) $b['billing_status']) ?>
      </span>
      </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
    </div>
  </div>

  <p class="mt-4 text-[11px] leading-relaxed text-slate-400">
    Each branch is a separate tenant with its own staff accounts, patients and audit log. This page
    is a read-only roll-up: it cannot open another branch's queue, and no patient identifier appears here.
  </p>
<?php endif; ?>
