<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Support queue</h1>
    <p class="mt-1 text-sm text-slate-500">
      Every clinic's tickets. A ticket lands in <span class="font-medium">unanswered</span> until
      somebody on the platform replies to it.
    </p>
  </div>
</div>

<div class="mt-4 grid grid-cols-3 gap-3">
  <?php foreach ([
    ['Unanswered', $counts['unanswered'], 'text-rose-700 bg-rose-50 ring-rose-200'],
    ['Open — on the clinic', $counts['open'], 'text-amber-700 bg-amber-50 ring-amber-200'],
    ['Waiting on us', $counts['waiting'], 'text-teal-700 bg-teal-50 ring-teal-200'],
  ] as [$label, $value, $class]): ?>
    <div class="rounded-xl px-4 py-3 ring-1 <?= $class ?>">
      <p class="text-2xl font-bold tabular-nums"><?= (int) $value ?></p>
      <p class="mt-0.5 text-[11px] font-medium"><?= e($label) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mt-4 overflow-hidden">
  <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
    <p class="text-xs font-semibold text-slate-600">All tickets</p>
  </div>
  <?php if ($tickets === []): ?>
    <?php $title = 'No tickets'; $message = 'Nothing has been raised. The queue fills when a clinic opens a ticket from its own Support page.'; $icon = 'lifebuoy'; ?>
    <div class="p-4"><?php include QP_ROOT . '/resources/views/components/empty.php'; ?></div>
  <?php else: ?>
    <ul class="divide-y divide-slate-100">
      <?php foreach ($tickets as $t): ?>
        <li class="px-4 py-3 hover:bg-slate-50">
          <a href="/admin/support/<?= (int) $t['id'] ?>" class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-slate-900">
                #<?= (int) $t['id'] ?> · <?= e($t['subject']) ?>
              </p>
              <p class="mt-0.5 text-[11px] text-slate-500">
                <span class="font-medium text-slate-700"><?= e($t['clinic_name']) ?></span> ·
                <?= e($t['opened_by'] ?? 'unknown') ?> ·
                <?= e(ago($t['updated_at'])) ?>
                <?php if ((int) $t['patient_replies'] > 0): ?>
                  · <?= (int) $t['patient_replies'] ?> message<?= (int) $t['patient_replies'] === 1 ? '' : 's' ?> from the clinic
                <?php endif; ?>
              </p>
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
              <?php if (in_array((string) $t['priority'], ['high', 'urgent'], true)): ?>
                <span class="badge-rose"><?= e($t['priority']) ?></span>
              <?php endif; ?>
              <span class="<?= match ((string) $t['status']) {
                  'open' => 'badge-amber',
                  'waiting' => 'badge-teal',
                  'resolved' => 'badge-emerald',
                  default => 'badge-slate',
              } ?>"><?= e($t['status']) ?></span>
            </div>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
