<?php
/**
 * The same stat tile the Live board uses, so a number looks identical on
 * every page. Icon left, label / value / hint right.
 *
 * @var string $label
 * @var string|int $value
 * @var string $icon
 * @var string|null $hint
 * @var string|null $tone
 */
$tone = $tone ?? 'brand';
$iconTone = match ($tone) {
    'amber'   => 'bg-amber-50 text-amber-500',
    'sky'     => 'bg-sky-50 text-sky-500',
    'emerald' => 'bg-emerald-50 text-emerald-500',
    'rose'    => 'bg-rose-50 text-rose-500',
    'violet'  => 'bg-violet-50 text-violet-500',
    'teal', 'brand' => 'bg-brand-50 text-brand-600',
    default   => 'bg-slate-100 text-ink-500',
};
?>
<div class="stat-tile">
  <span class="stat-ico <?= $iconTone ?>">
    <svg class="h-5 w-5"><use href="#i-<?= e($icon) ?>"/></svg>
  </span>
  <div class="min-w-0 flex-1">
    <p class="stat-label"><?= e($label) ?></p>
    <p class="stat-value"><?= e((string) $value) ?></p>
    <?php if (!empty($hint)): ?><p class="stat-hint"><?= e($hint) ?></p><?php endif; ?>
  </div>
</div>
