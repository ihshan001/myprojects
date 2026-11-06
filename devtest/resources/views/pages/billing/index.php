<?php $c = $clinicRecord; ?>
<?php if (!empty($suspended)): ?>
  <div class="mb-5 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3.5">
    <p class="flex items-start gap-2.5 text-sm font-semibold text-rose-900">
      <svg class="mt-0.5 h-4 w-4 shrink-0"><use href="#i-alert"/></svg>
      <span>This clinic's subscription is <?= e($c['billing_status']) ?>. Regain access by settling the invoice below.
      Your queue history, patients and audit log are intact and will be exactly as you left them.</span>
    </p>
  </div>
<?php endif; ?>

<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Subscription</h1>
    <p class="mt-1 text-sm text-slate-500">Plan, usage and invoices. SMS/WhatsApp usage is metered separately.</p>
  </div>
  <span class="<?= $c['billing_status'] === 'active' ? 'badge-emerald' : ($c['billing_status'] === 'trial' ? 'badge-amber' : 'badge-rose') ?>">
    <?= e(ucfirst(str_replace('_', ' ', (string) $c['billing_status']))) ?>
  </span>
</div>

<div class="mt-5 grid gap-4 sm:grid-cols-3">
  <div class="card card-pad">
    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Current plan</p>
    <p class="mt-1 text-xl font-bold text-slate-900"><?= e($plan['name'] ?? 'None') ?></p>
    <p class="text-xs text-slate-500">
      <?= $plan && (float) $plan['price_monthly'] > 0 ? money((float) $plan['price_monthly']) . ' / month' : 'Custom pricing' ?>
    </p>
  </div>
  <div class="card card-pad">
    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Renews</p>
    <p class="mt-1 text-xl font-bold text-slate-900"><?= e($c['renews_on'] ? date('j M Y', strtotime((string) $c['renews_on'])) : '—') ?></p>
    <p class="text-xs text-slate-500">
      <?= $c['billing_status'] === 'trial' ? 'Trial ends ' . e((string) $c['trial_ends_on']) : 'Automatic reminder by email' ?>
    </p>
  </div>
  <div class="card card-pad">
    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Outstanding</p>
    <?php
    $due = 0.0;
    foreach ($invoices as $inv) { if ($inv['status'] === 'unpaid') { $due += (float) $inv['amount']; } }
    ?>
    <p class="mt-1 text-xl font-bold <?= $due > 0 ? 'text-rose-600' : 'text-emerald-600' ?>"><?= e(money($due)) ?></p>
    <p class="text-xs text-slate-500"><?= $due > 0 ? 'Settle to keep services active' : 'Nothing due' ?></p>
  </div>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.1fr_.9fr]">
  <div class="space-y-5">
    <div class="card card-pad">
      <h2 class="text-sm font-bold text-slate-900">Usage against your plan</h2>
      <div class="mt-4 space-y-3.5">
        <?php foreach ([
            ['Active doctors', $usage['doctors'], (int) ($plan['max_doctors'] ?? 0)],
            ['Staff accounts', $usage['staff'], (int) ($plan['max_staff'] ?? 0)],
            ['Queues today', $usage['queues'], (int) ($plan['max_queues'] ?? 0)],
        ] as [$label, $used, $max]): ?>
          <?php $pct = $max > 0 ? min(100, round($used / $max * 100)) : 0; ?>
          <div>
            <div class="flex items-baseline justify-between text-xs">
              <span class="font-medium text-slate-700"><?= e($label) ?></span>
              <span class="tabular text-slate-500"><?= (int) $used ?> / <?= $max >= 999 ? '∞' : (int) $max ?></span>
            </div>
            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100">
              <div class="h-full rounded-full <?= $pct >= 90 ? 'bg-rose-500' : ($pct >= 70 ? 'bg-amber-500' : 'bg-teal-600') ?>"
                   style="width: <?= max(2, $pct) ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card overflow-hidden">
      <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Invoices</p>
      </div>
      <?php if ($invoices === []): ?>
        <p class="px-4 py-8 text-center text-sm text-slate-400">No invoices yet — your trial is running.</p>
      <?php else: ?>
        <div class="table-scroll">
          <table class="table">
          <thead><tr><th>Invoice</th><th class="hidden sm:table-cell">Period</th><th class="numeric">Amount</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($invoices as $inv): ?>
          <tr>
          <td class="font-mono text-[11px]"><?= e((string) $inv['number']) ?></td>
          <td class="hidden sm:table-cell text-[11px] text-slate-500"><?= e((string) $inv['period_from']) ?> → <?= e((string) $inv['period_to']) ?></td>
          <td class="numeric text-xs"><?= e(money((float) $inv['amount'])) ?></td>
          <td>
          <span class="<?= match ((string) $inv['status']) {
          'paid' => 'badge-emerald',
          'claimed' => 'badge-teal',
          'rejected' => 'badge-rose',
          'void' => 'badge-slate',
          default => 'badge-amber',
          } ?>"><?= e((string) $inv['status']) ?></span>
          <?php if ($inv['status'] === 'rejected' && !empty($inv['rejection_reason'])): ?>
          <p class="mt-0.5 max-w-[16rem] text-[10px] leading-snug text-rose-600"><?= e($inv['rejection_reason']) ?></p>
          <?php endif; ?>
          </td>
          <td class="text-right">
          <?php if (in_array((string) $inv['status'], ['unpaid', 'rejected'], true)): ?>
          <form method="post" action="/billing/pay" class="inline-flex gap-1">
          <?= csrf_field() ?>
          <input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
          <input class="input !w-24 !px-2 !py-1 text-[11px]" name="reference" placeholder="Ref #" required>
          <button class="act-done">I have paid</button>
          </form>
          <?php elseif ($inv['status'] === 'claimed'): ?>
          <div class="flex flex-col items-end gap-1">
          <span class="text-[10px] text-slate-500">ref <?= e((string) ($inv['reference'] ?? '')) ?> · awaiting check</span>
          <form method="post" action="/billing/withdraw-claim">
          <?= csrf_field() ?>
          <input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
          <button class="text-[10px] text-slate-500 underline hover:text-slate-700">entered it wrong?</button>
          </form>
        </div>
                    <?php else: ?>
                      <span class="text-[10px] text-slate-400"><?= e((string) ($inv['reference'] ?? '')) ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card card-pad">
      <h2 class="text-sm font-bold text-slate-900">SMS &amp; WhatsApp usage</h2>
      <div class="mt-3 grid grid-cols-3 gap-2 text-center">
        <?php foreach ([['Queued', $notifStats['queued'] ?? 0, 'text-amber-600'], ['Delivered', $notifStats['sent'] ?? 0, 'text-emerald-600'], ['Failed', $notifStats['failed'] ?? 0, 'text-rose-600']] as [$l, $v, $tone]): ?>
          <div class="rounded-lg bg-slate-50 py-2.5">
            <p class="text-lg font-bold tabular <?= $tone ?>"><?= (int) $v ?></p>
            <p class="text-[10px] text-slate-500"><?= e($l) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="mt-3 text-[11px] leading-relaxed text-slate-500">
        Charged at cost plus a small margin on the next invoice — never bundled into the plan price.
        Hardware (display screens, printers) and onboarding are quoted separately and never auto-billed.
      </p>
    </div>
  </div>

  <div class="space-y-3">
    <h2 class="text-sm font-bold text-slate-900">Change plan</h2>
    <?php foreach ($plans as $p): ?>
      <?php $current = $plan && (int) $plan['id'] === (int) $p['id']; ?>
      <div class="card card-pad <?= $current ? 'ring-1 ring-teal-600/30' : '' ?>">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="text-sm font-bold text-slate-900"><?= e($p['name']) ?></p>
            <p class="text-[11px] text-slate-500">
              <?= (float) $p['price_monthly'] > 0 ? money((float) $p['price_monthly']) . '/mo' : 'Custom' ?>
              · <?= (int) $p['max_doctors'] ?> doctors · <?= (int) $p['max_staff'] ?> staff
            </p>
          </div>
          <?php if ($current): ?><span class="badge-teal">Current</span><?php endif; ?>
        </div>
        <p class="mt-1.5 text-[11px] leading-relaxed text-slate-500"><?= e((string) $p['features']) ?></p>
        <?php if (!$current && ($canManage ?? true)): ?>
          <form method="post" action="/billing/plan" class="mt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
            <button class="btn-secondary w-full text-xs" data-confirm="Switch to the <?= e($p['name']) ?> plan?">Switch to <?= e($p['name']) ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
