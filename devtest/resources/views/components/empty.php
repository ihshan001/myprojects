<?php /** @var string $title @var string $message @var string $icon */ ?>
<div class="flex flex-col items-center justify-center px-6 py-16 text-center">
  <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-ink-400">
    <svg class="h-7 w-7"><use href="#i-<?= e($icon ?? 'queue') ?>"/></svg>
  </div>
  <h3 class="text-base font-semibold text-ink-900"><?= e($title ?? 'Nothing here yet') ?></h3>
  <p class="mt-1 max-w-sm text-sm text-ink-500"><?= e($message ?? '') ?></p>
  <?php if (!empty($action)): ?>
    <div class="mt-5"><?= $action ?></div>
  <?php endif; ?>
</div>
