<?php
/** @var string $label @var string|int $value @var string $icon @var string|null $hint */
$tone = $tone ?? 'teal';
$iconTone = match ($tone) {
    'amber' => 'bg-amber-50 text-amber-600', 'sky' => 'bg-sky-50 text-sky-600',
    'emerald' => 'bg-emerald-50 text-emerald-600', 'rose' => 'bg-rose-50 text-rose-600',
    'violet' => 'bg-violet-50 text-violet-600', default => 'bg-teal-50 text-teal-700',
};
?>
<div class="card card-pad">
  <div class="flex items-start justify-between">
    <div>
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?= e($label) ?></p>
      <p class="mt-2 text-2xl font-bold tabular text-slate-900"><?= e((string) $value) ?></p>
      <?php if (!empty($hint)): ?><p class="mt-1 text-xs text-slate-500"><?= e($hint) ?></p><?php endif; ?>
    </div>
    <div class="flex h-10 w-10 items-center justify-center rounded-xl <?= $iconTone ?>">
      <svg class="h-5 w-5"><use href="#i-<?= e($icon) ?>"/></svg>
    </div>
  </div>
</div>
