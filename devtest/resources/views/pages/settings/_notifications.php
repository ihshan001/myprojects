<?php
/**
 * Notifications tab — which of the three clinic-wide alerts go out.
 *
 * The same three switches appear on the Clinic settings rail. That is not a
 * duplication mistake: the rail is where you notice them while fixing
 * something else, and this tab is where you come to think about them. Both
 * post to the same action and read the same rows.
 *
 * @var array<int,array<string,mixed>> $notifications
 * @var bool $canManage
 */
?>
<div class="card overflow-hidden">
  <div class="section-head">
    <p class="section-title">Alert preferences</p>
    <p class="text-[11px] text-ink-400">Applies to the whole clinic</p>
  </div>

  <form method="post" action="/settings/notifications" class="divide-y divide-slate-100">
    <?= csrf_field() ?>
    <?php foreach ($notifications as $n): ?>
      <label class="flex items-start gap-3 px-4 py-3.5 <?= $canManage ? 'cursor-pointer hover:bg-slate-50' : '' ?>">
        <input type="checkbox" class="checkbox mt-0.5" name="<?= e((string) $n['key']) ?>" value="1"
               <?= $n['on'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
        <span class="min-w-0">
          <span class="block text-[13px] font-semibold text-ink-900"><?= e((string) $n['name']) ?></span>
          <span class="mt-0.5 block text-[11px] leading-relaxed text-ink-500"><?= e((string) $n['blurb']) ?></span>
        </span>
      </label>
    <?php endforeach; ?>

    <?php if ($canManage): ?>
      <div class="flex items-center justify-between gap-3 bg-slate-50 px-4 py-3">
        <p class="text-[11px] leading-relaxed text-ink-500">
          Unticking a box turns that alert off — nothing is sent for a switch
          that is not ticked.
        </p>
        <button class="btn-primary shrink-0">Save notifications</button>
      </div>
    <?php else: ?>
      <p class="px-4 py-3 text-xs text-ink-500">Only a clinic owner can change these alerts.</p>
    <?php endif; ?>
  </form>
</div>
