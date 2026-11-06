<?php
/**
 * @var string $status  queue entry status
 * @var string|null $label
 */
$meta = App\Domain\Queue\QueueStateMachine::meta($status ?? 'waiting');
$tone = $meta['tone'];
$badgeClass = match ($tone) {
    'teal' => 'badge-teal', 'amber' => 'badge-amber', 'sky' => 'badge-sky',
    'emerald' => 'badge-emerald', 'rose' => 'badge-rose', 'violet' => 'badge-violet',
    'orange' => 'badge-orange', default => 'badge-slate',
};
?>
<span class="<?= $badgeClass ?>">
  <?= e($label ?? $meta['label']) ?>
</span>
