<?php
/**
 * One live queue row with role-aware actions.
 * @var array $entry, @var int $position, @var bool $canOperate
 */
use App\Domain\Queue\QueueStateMachine;
$status = (string) $entry['status'];
$actions = $canOperate ? QueueStateMachine::actions($status) : [];
$isPriority = (int) $entry['priority'] === 1;
$json = htmlspecialchars(json_encode([
    'id' => (int) $entry['id'],
    'version' => (int) $entry['version'],
    'status' => $status,
    'token' => $entry['token_label'],
]), ENT_QUOTES, 'UTF-8');
?>
<div class="group flex flex-wrap items-center gap-x-2 gap-y-2 border-b border-slate-100 px-3 py-3 last:border-0 sm:gap-3 sm:px-4
            <?= $status === 'called' ? 'bg-sky-50/60' : '' ?> <?= $isPriority ? 'border-l-2 border-l-red-500' : '' ?>"
     data-entry-row data-entry='<?= $json ?>'>
  <div class="w-7 text-center sm:w-10">
    <?php if ($status === 'waiting'): ?>
      <span class="text-xs font-bold text-ink-400 tabular"><?= (int) $position ?></span>
    <?php else: ?>
      <span class="text-xs text-slate-300">—</span>
    <?php endif; ?>
  </div>

  <div class="min-w-0 flex-1">
    <div class="flex flex-wrap items-center gap-2">
      <span class="token-chip"><?= e($entry['token_label']) ?></span>
      <?php if ($isPriority): ?>
        <span class="badge-priority" title="<?= e((string) ($entry['priority_reason'] ?? '')) ?>">Priority</span>
      <?php endif; ?>
      <?php $b = ['status' => $status]; include QP_ROOT . '/resources/views/components/badge.php'; ?>
      <?php if (!empty($entry['queue_name'])): ?>
        <span class="text-xs text-ink-400">· <?= e($entry['queue_name']) ?></span>
      <?php endif; ?>
    </div>
    <p class="mt-1 truncate text-sm font-medium text-ink-900">
      <?= e($entry['full_name']) ?>
      <span class="ml-1 text-xs font-normal text-ink-400"><?= e($entry['patient_code'] ?? '') ?></span>
    </p>
    <p class="text-[11px] text-ink-500 tabular">
      joined <?= e(fmt_time($entry['joined_at'], 'g:i A')) ?>
      <?php if ((int) $entry['call_count'] > 1): ?> · called <?= (int) $entry['call_count'] ?>×<?php endif; ?>
      <?php if ($status === 'in_consultation' && !empty($entry['started_at'])): ?>
        · in room <?= e((string) minutes_between($entry['started_at'])) ?> min
      <?php endif; ?>
      <?php if ($status === 'on_hold'): ?>
        · <span class="font-semibold text-ink-700"><?= e((string) ($entry['hold_reason'] ?? 'Stepped away')) ?></span>
        <?php if (!empty($entry['hold_since'])): ?>since <?= e(fmt_time($entry['hold_since'], 'g:i A')) ?><?php endif; ?>
      <?php endif; ?>
    </p>
  </div>

  <?php if ($actions !== []): ?>
    <div class="flex w-full flex-wrap items-center gap-1.5 border-t border-slate-100 pt-2 sm:w-auto sm:border-0 sm:pt-0">
      <?php if (in_array('call', $actions, true)): ?>
        <button type="button" class="act-call" data-act="call"><svg class="h-3.5 w-3.5"><use href="#i-megaphone"/></svg>Call</button>
      <?php endif; ?>
      <?php if (in_array('recall', $actions, true)): ?>
        <button type="button" class="act-call" data-act="recall"><svg class="h-3.5 w-3.5"><use href="#i-megaphone"/></svg>Call again</button>
      <?php endif; ?>
      <?php if (in_array('start', $actions, true)): ?>
        <button type="button" class="act-start" data-act="start"><svg class="h-3.5 w-3.5"><use href="#i-play"/></svg>Start</button>
      <?php endif; ?>
      <?php if (in_array('complete', $actions, true)): ?>
        <button type="button" class="act-done" data-act="complete"><svg class="h-3.5 w-3.5"><use href="#i-check"/></svg>Complete</button>
      <?php endif; ?>
      <?php if (in_array('requeue', $actions, true)): ?>
        <button type="button" class="act-start" data-act="requeue"><svg class="h-3.5 w-3.5"><use href="#i-refresh"/></svg>Re-queue</button>
      <?php endif; ?>
      <?php if (in_array('skip', $actions, true)): ?>
        <button type="button" class="act-skip" data-act="skip">Skip</button>
      <?php endif; ?>
      <?php if (in_array('no_show', $actions, true)): ?>
        <button type="button" class="act-mute" data-act="no_show">No show</button>
      <?php endif; ?>
      <?php if (in_array('cancel', $actions, true)): ?>
        <button type="button" class="act-stop" data-act="cancel">Cancel</button>
      <?php endif; ?>
      <?php if (in_array('hold', $actions, true)): ?>
        <button type="button" class="act-mute" data-act="hold">Hold</button>
      <?php endif; ?>
      <?php if (in_array('release_hold', $actions, true)): ?>
        <button type="button" class="act-start" data-act="release_hold">Back to queue</button>
      <?php endif; ?>
      <?php if (in_array('transfer', $actions, true)): ?>
        <button type="button" class="act-mute" data-act="transfer">Transfer</button>
      <?php endif; ?>
      <a class="act-mute" href="/queue/entry?id=<?= (int) $entry['id'] ?>" title="Open details">
        <svg class="h-3.5 w-3.5"><use href="#i-chevron-right"/></svg>
      </a>
    </div>
  <?php endif; ?>
</div>
