<div class="page-head">
  <div>
    <h1 class="page-title">Payment verification</h1>
    <p class="page-sub">
      Clinics claim a payment with a bank reference. Nothing activates until someone here
      matches it against the statement — that is the whole point of this screen.
    </p>
  </div>
  <?php if ($openCount > 0): ?>
    <span class="badge-amber"><?= (int) $openCount ?> awaiting checking</span>
  <?php endif; ?>
</div>

<div class="card mt-3 sm:mt-5 overflow-hidden">
  <div class="section-head">
    <p class="section-title">Awaiting verification</p>
  </div>

  <?php if ($pending === []): ?>
    <div class="p-6 text-center">
      <p class="text-sm font-semibold text-ink-700">Nothing to check</p>
      <p class="mt-1 text-xs text-ink-500">Every claimed payment has been matched or rejected.</p>
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
                <p class="text-sm font-medium text-ink-900"><?= e($inv['clinic_name']) ?></p>
                <p class="text-[11px] text-ink-500">
                  <?= e($inv['billing_status']) ?>
                  <?php if (!empty($inv['renews_on'])): ?> · renews <?= e($inv['renews_on']) ?><?php endif; ?>
                </p>
              </td>
              <td class="font-semibold"><?= e(money((float) $inv['amount'], (string) $inv['currency'])) ?></td>
              <td class="hidden sm:table-cell font-mono text-xs"><?= e($inv['reference'] ?? '—') ?></td>
              <td class="hidden md:table-cell text-xs text-ink-500"><?= e(ago($inv['claimed_at'])) ?></td>
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
                          class="absolute right-0 z-20 mt-2 w-64 space-y-2 rounded-xl border border-hairline bg-white p-3 shadow-lg">
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

<div class="card mt-3 sm:mt-5 overflow-hidden">
  <div class="section-head">
    <p class="section-title">Recently settled</p>
  </div>
  <?php if ($recent === []): ?>
    <div class="p-4 text-xs text-ink-500">Nothing settled yet.</div>
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
                  <p class="mt-0.5 max-w-xs text-[10px] text-ink-500"><?= e($inv['rejection_reason']) ?></p>
                <?php endif; ?>
              </td>
              <td class="hidden sm:table-cell text-xs text-ink-500"><?= e($inv['verifier'] ?? 'system') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
