<div class="page-head">
  <div>
    <h1 class="page-title">Plans &amp; billing</h1>
    <p class="page-sub">Limits and pricing are data, not code — change them without a deploy.</p>
  </div>
  <a href="/admin" class="btn-ghost"><svg class="h-4 w-4"><use href="#i-arrow-left"/></svg>Overview</a>
</div>

<div class="mt-5 grid gap-3 sm:grid-cols-3">
  <?php foreach ($revenue as $r): ?>
    <div class="card card-pad">
      <p class="text-[10px] font-bold uppercase tracking-wider text-ink-400"><?= e((string) $r['status']) ?> invoices</p>
      <p class="stat-value mt-1"><?= e(money((float) ($r['total'] ?? 0))) ?></p>
      <p class="text-xs text-ink-500"><?= (int) $r['n'] ?> invoice(s)</p>
    </div>
  <?php endforeach; ?>
</div>

<div class="mt-3 grid gap-3 sm:mt-6 sm:gap-4 md:grid-cols-2 xl:grid-cols-4">
  <?php foreach ($plans as $p): ?>
    <div class="card card-pad">
      <div class="flex items-start justify-between">
        <div>
          <p class="text-sm font-bold text-ink-900"><?= e($p['name']) ?></p>
          <p class="text-[10px] font-mono uppercase text-ink-400"><?= e($p['code']) ?></p>
        </div>
        <span class="<?= $p['is_active'] ? 'badge-emerald' : 'badge-slate' ?>"><?= $p['is_active'] ? 'active' : 'hidden' ?></span>
      </div>
      <form method="post" action="/admin/plans" class="mt-3 space-y-2">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <div><label class="label">Name</label><input class="input !py-1.5 text-xs" name="name" value="<?= e((string) $p['name']) ?>"></div>
        <div><label class="label">Price (LKR/mo)</label>
          <input class="input !py-1.5 text-xs" name="price_monthly" value="<?= (float) $p['price_monthly'] ?>" inputmode="decimal"></div>
        <div class="grid grid-cols-3 gap-1.5">
          <div><label class="label !text-[10px]">Doctors</label>
            <input class="input !px-1.5 !py-1.5 text-xs" name="max_doctors" value="<?= (int) $p['max_doctors'] ?>" inputmode="numeric"></div>
          <div><label class="label !text-[10px]">Staff</label>
            <input class="input !px-1.5 !py-1.5 text-xs" name="max_staff" value="<?= (int) $p['max_staff'] ?>" inputmode="numeric"></div>
          <div><label class="label !text-[10px]">Queues</label>
            <input class="input !px-1.5 !py-1.5 text-xs" name="max_queues" value="<?= (int) $p['max_queues'] ?>" inputmode="numeric"></div>
        </div>
        <div><label class="label">Description</label>
          <textarea class="textarea !py-1.5 text-xs" name="features" rows="2"><?= e((string) $p['features']) ?></textarea></div>
        <label class="flex items-center gap-2 text-[11px] text-ink-700">
          <input type="checkbox" class="checkbox" name="is_active" value="1" <?= $p['is_active'] ? 'checked' : '' ?>> Visible on pricing page
        </label>
        <button class="btn-secondary w-full text-xs">Save plan</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mt-3 sm:mt-5 overflow-hidden">
  <div class="section-head">
    <p class="section-title">Recent invoices</p>
  </div>
  <div class="table-scroll">
    <table class="table">
    <thead><tr><th>Invoice</th><th>Clinic</th><th class="numeric">Amount</th><th>Status</th><th class="hidden sm:table-cell">Issued</th></tr></thead>
    <tbody>
    <?php foreach ($invoices as $inv): ?>
    <tr>
    <td class="font-mono text-[11px]"><?= e((string) $inv['number']) ?></td>
    <td class="text-xs"><?= e($inv['clinic_name']) ?></td>
    <td class="numeric text-xs"><?= e(money((float) $inv['amount'])) ?></td>
    <td><span class="<?= $inv['status'] === 'paid' ? 'badge-emerald' : 'badge-amber' ?>"><?= e((string) $inv['status']) ?></span></td>
    <td class="hidden sm:table-cell text-[11px] text-ink-500"><?= e(date('j M Y', strtotime((string) $inv['created_at']))) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
  </div>
</div>
