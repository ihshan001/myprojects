<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Getting started</h1>
    <p class="mt-1 max-w-2xl text-sm text-slate-500">
      Everything below is read from your clinic's own data — a step is done when
      the clinic is actually set up for it, not when a box was ticked. Nothing
      here nags about work that is finished.
    </p>
  </div>
  <div class="flex items-center gap-3">
    <div class="text-right">
      <p class="text-2xl font-bold tabular-nums text-slate-900"><?= (int) $summary['done'] ?><span class="text-sm font-normal text-slate-400">/<?= (int) $summary['total'] ?></span></p>
      <p class="text-[11px] text-slate-500">steps complete</p>
    </div>
  </div>
</div>

<div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
  <div class="h-full rounded-full bg-teal-600 transition-all" style="width: <?= (int) $summary['percent'] ?>%"></div>
</div>

<?php if ($summary['blocking_open']): ?>
  <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
    <p class="font-semibold">Your clinic cannot issue tokens yet.</p>
    <p class="mt-1 text-[13px]">
      The owner's email address is not confirmed. The queue service refuses every
      token until it is — deliberately, so an address nobody can receive mail at
      is found on day one rather than when a patient is waiting.
    </p>
  </div>
<?php elseif ($summary['done'] === $summary['total']): ?>
  <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
    <p class="font-semibold">This clinic is fully set up.</p>
    <p class="mt-1 text-[13px]">Every step below is satisfied by real data. Nothing further is needed before the first patient walks in.</p>
  </div>
<?php endif; ?>

<div class="mt-5 space-y-3">
  <?php foreach ($steps as $i => $step): ?>
    <div class="card flex flex-wrap items-start gap-4 p-4 <?= $step['done'] ? 'opacity-75' : '' ?>">
      <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[12px] font-bold
        <?= $step['done'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">
        <?= $step['done'] ? '✓' : ($i + 1) ?>
      </span>

      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
          <p class="font-medium text-slate-900 <?= $step['done'] ? 'line-through decoration-slate-300' : '' ?>">
            <?= e($step['title']) ?>
          </p>
          <?php if ($step['blocking'] && !$step['done']): ?>
            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800">Blocks tokens</span>
          <?php endif; ?>
        </div>
        <p class="mt-1 text-xs leading-relaxed text-slate-500"><?= e($step['why']) ?></p>
      </div>

      <div class="shrink-0">
        <?php if ($step['done']): ?>
          <span class="text-xs font-semibold text-emerald-700">Done</span>
        <?php else: ?>
          <a class="btn-primary btn-sm" href="<?= e($step['fix']) ?>"><?= e($step['fix_label']) ?></a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600">
  <p class="font-semibold text-slate-700">Two things worth knowing before the first patient</p>
  <ul class="mt-1 list-disc space-y-1 pl-5">
    <li><span class="font-medium">Reception, not the doctor, should issue tokens.</span> Staff can be invited from Settings with a single-use link that expires; everyone sharing one login means the audit log cannot say who did what.</li>
    <li><span class="font-medium">This page stays available.</span> If the clinic later changes its hours or removes every doctor, the steps go back to incomplete rather than staying green from one good day.</li>
  </ul>
</div>
