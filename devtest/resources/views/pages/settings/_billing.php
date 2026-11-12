<?php
/**
 * Billing tab — what this clinic is on, and where the invoices live.
 *
 * Deliberately a summary rather than a second copy of /billing: the full
 * invoice table, plan comparison and payment history already exist on their
 * own page. Repeating them here would mean two screens to keep in step.
 *
 * @var array<string,mixed> $plan
 * @var array<string,mixed> $clinicRecord
 */
$status = (string) ($clinicRecord['billing_status'] ?? 'trial');
$statusNote = match ($status) {
    'trial'     => 'Free trial — ends ' . e(fmt_time((string) ($clinicRecord['trial_ends_on'] ?? ''), 'M j, Y')),
    'active'    => 'Active — renews ' . e(fmt_time((string) ($clinicRecord['renews_on'] ?? ''), 'M j, Y')),
    'suspended' => 'Suspended — ' . e((string) ($clinicRecord['suspension_reason'] ?? 'contact support')),
    default     => e(ucfirst($status)),
};
?>
<div class="card card-pad">
  <div class="flex flex-wrap items-start justify-between gap-4">
    <div>
      <p class="text-[11px] font-semibold uppercase tracking-wider text-ink-400">Current plan</p>
      <p class="mt-1 text-xl font-bold text-ink-900"><?= e((string) ($plan['name'] ?? 'No plan')) ?></p>
      <p class="mt-0.5 text-xs text-ink-500"><?= $statusNote ?></p>
    </div>
    <div class="text-right">
      <p class="text-xl font-bold text-ink-900"><?= e(money($plan['price_monthly'] ?? 0, (string) ($plan['currency'] ?? 'LKR'))) ?></p>
      <p class="text-[11px] text-ink-400">per month</p>
    </div>
  </div>

  <?php
    $usage = [
        ['Staff accounts', count($staff), (int) ($plan['max_staff'] ?? 0)],
        ['Doctors', (int) \App\Core\Db::scalar('SELECT COUNT(*) FROM doctors WHERE clinic_id = ? AND is_active = 1', [$clinicId]), (int) ($plan['max_doctors'] ?? 0)],
        ['Queues today', (int) \App\Core\Db::scalar('SELECT COUNT(*) FROM queues WHERE clinic_id = ? AND session_date = ?', [$clinicId, \App\Core\Db::today()]), (int) ($plan['max_queues'] ?? 0)],
    ];
  ?>
  <div class="mt-5 space-y-3 border-t border-slate-100 pt-4">
    <?php foreach ($usage as [$label, $used, $max]): ?>
      <?php $pct = $max > 0 ? min(100, (int) round($used / $max * 100)) : 0; ?>
      <div>
        <div class="flex items-center justify-between text-[11px]">
          <span class="font-semibold text-ink-700"><?= e((string) $label) ?></span>
          <span class="text-ink-500"><?= (int) $used ?> of <?= $max === 0 ? 'unlimited' : (int) $max ?></span>
        </div>
        <?php if ($max > 0): ?>
          <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full <?= $pct >= 90 ? 'bg-rose-500' : ($pct >= 70 ? 'bg-amber-500' : 'bg-brand-600') ?>"
                 style="width: <?= $pct ?>%"></div>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
    <a class="btn-primary" href="/billing">Open billing</a>
    <a class="btn-secondary" href="/billing">View invoices</a>
  </div>
</div>
