<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Payment verification</h1>
    <p class="mt-1 text-sm text-slate-500">
      Clinics claim a payment with a bank reference. Nothing activates until someone here
      matches it against the statement — that is the whole point of this screen.
    </p>
  </div>
  <?php if ($openCount > 0): ?>
    <span class="badge-amber"><?= (int) $openCount ?> awaiting checking</span>
  <?php endif; ?>
</div>

<div class="card mt-5 overflow-hidden">
  <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
    <p class="text-xs font-semibold text-slate-600">Awaiting verification</p>
  </div>

  <?php if ($pending === []): ?>
    <div class="p-6 text-center">
      <p class="text-sm font-semibold text-slate-700">Nothing to check</p>
      <p class="mt-1 text-xs text-slate-500">Every claimed payment has been matched or rejected.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="table">
        <thead>
          <tr>
            <th>Invoice</th><th>Clinic</th><th>Amount</th>
            <th class="hidden sm:table-cell">Reference</th><th class="hidden md:table-cell">Claimed</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $inv): ?>
            <tr>
              <td class="font-mono text-xs"><?= e($inv['number']) ?></td>
              <td>
                <p class="text-sm font-medium text-slate-900"><?= e($inv['clinic_name']) ?></p>
                <p class="text-[11px] text-slate-500">
                  <?= e($inv['billing_status']) ?>
                  <?php if (!empty($inv['renews_on'])): ?> · renews <?= e($inv['renews_on']) ?><?php endif; ?>
                </p>
              </td>
              <td class="font-semibold"><?= e(money((float) $inv['amount'], (string) $inv['currency'])) ?></td>
              <td class="hidden sm:table-cell font-mono text-xs"><?= e($inv['reference'] ?? '—') ?></td>
              <td class="hidden md:table-cell text-xs text-slate-500"><?= e(ago($inv['claimed_at'])) ?></td>
              <td>
                <div class="flex flex-wrap items-center justify-end gap-2">
                  <form method="post" action="/admin/billing/<?= (int) $inv['id'] ?>/verify"
                        onsubmit="return confirm('Confirm the bank shows this payment? The subscription activates immediately.')">
                    <?= csrf_field() ?>
                    <button class="btn-primary btn-sm">Verify &amp; activate</button>
                  </form>
                  <details class="relative">
                    <summary class="btn-secondary btn-sm cursor-pointer list-none">Reject</summary>
                    <form method="post" action="/admin/billing/<?= (int) $inv['id'] ?>/reject"
                          class="absolute right-0 z-20 mt-2 w-64 space-y-2 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                      <?= csrf_field() ?>
                      <label class="label" for="reason-<?= (int) $inv['id'] ?>">Why? (the clinic sees this)</label>
                      <textarea class="input min-h-16 text-xs" id="reason-<?= (int) $inv['id'] ?>" name="reason"
                                required maxlength="255" placeholder="No transfer with that reference on 3 Nov."></textarea>
                      <button class="btn-sm w-full justify-center border border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100">
                        Reject claim
                      </button>
                    </form>
                  </details>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card mt-5 overflow-hidden">
  <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
    <p class="text-xs font-semibold text-slate-600">Recently settled</p>
  </div>
  <?php if ($recent === []): ?>
    <div class="p-4 text-xs text-slate-500">Nothing settled yet.</div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="table">
        <thead>
          <tr><th>Invoice</th><th>Clinic</th><th>Amount</th><th>Status</th><th class="hidden sm:table-cell">By</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $inv): ?>
            <tr>
              <td class="font-mono text-xs"><?= e($inv['number']) ?></td>
              <td class="text-sm"><?= e($inv['clinic_name']) ?></td>
              <td><?= e(money((float) $inv['amount'], (string) $inv['currency'])) ?></td>
              <td>
                <span class="<?= $inv['status'] === 'paid' ? 'badge-emerald' : 'badge-rose' ?>"><?= e($inv['status']) ?></span>
                <?php if ($inv['status'] === 'rejected' && !empty($inv['rejection_reason'])): ?>
                  <p class="mt-0.5 max-w-xs text-[10px] text-slate-500"><?= e($inv['rejection_reason']) ?></p>
                <?php endif; ?>
              </td>
              <td class="hidden sm:table-cell text-xs text-slate-500"><?= e($inv['verifier'] ?? 'system') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>


<!-- ── Every submission, including rejected ones ────────────────────── -->
<div class="card mt-5 overflow-hidden">
  <div class="border-b border-slate-100 px-4 py-3">
    <h2 class="text-sm font-bold text-slate-900">All payment submissions</h2>
    <p class="mt-0.5 text-xs text-slate-500">
      The durable record. A rejected claim leaves the invoice unpaid, so the invoice list above cannot show
      it — this can. A clinic that claims, is rejected, and claims again appears twice.
    </p>
  </div>
  <?php if (($claimed ?? []) === []): ?>
    <p class="px-4 py-8 text-center text-sm text-slate-500">
      Nothing waiting. Submissions that have been decided stay in the payment history.
    </p>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="table">
        <thead>
          <tr><th>Clinic</th><th>Invoice</th><th>Amount</th><th>Reference</th><th>Submitted</th></tr>
        </thead>
        <tbody>
          <?php foreach ($claimed as $p2): ?>
            <tr>
              <td class="text-sm font-medium text-slate-900"><?= e($p2['clinic_name']) ?></td>
              <td class="font-mono text-xs"><?= e($p2['invoice_number']) ?></td>
              <td class="font-semibold"><?= e(money((float) $p2['amount'], (string) $p2['currency'])) ?></td>
              <td class="font-mono text-xs text-slate-600"><?= e($p2['reference'] ?? '—') ?></td>
              <td class="text-xs text-slate-500"><?= e(ago($p2['submitted_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
