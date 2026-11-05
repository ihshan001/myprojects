<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Clinics</h1>
    <p class="mt-1 text-sm text-slate-500">Suspend or re-plan a tenant without touching its data.</p>
  </div>
  <a href="/admin" class="btn-ghost"><svg class="h-4 w-4"><use href="#i-arrow-left"/></svg>Overview</a>
</div>

<div class="mt-5 space-y-3">
  <?php foreach ($clinics as $c): ?>
    <div class="card card-pad">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-3">
          <span class="flex h-11 w-11 items-center justify-center rounded-xl text-xs font-bold text-white"
                style="background: <?= e((string) $c['brand_color']) ?>"><?= e(App\Core\Str::initials((string) $c['name'])) ?></span>
          <div>
            <p class="text-sm font-bold text-slate-900"><?= e($c['name']) ?></p>
            <p class="text-[11px] text-slate-500">
              <?= e((string) ($c['email'] ?? '')) ?>
              <?= !empty($c['phone']) ? ' · ' . e((string) $c['phone']) : '' ?>
              · <?= e((string) ($c['city'] ?? '')) ?>
            </p>
            <p class="mt-1 flex flex-wrap gap-1.5">
              <span class="badge-slate"><?= (int) $c['staff'] ?> staff</span>
              <span class="badge-slate"><?= (int) $c['patients'] ?> patients</span>
              <span class="badge-slate"><?= (int) $c['entries'] ?> tokens</span>
              <?php if (!empty($c['plan_name'])): ?>
                <span class="badge-teal"><?= e((string) $c['plan_name']) ?></span>
              <?php endif; ?>
            </p>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <span class="<?= $c['billing_status'] === 'active' ? 'badge-emerald' : ($c['billing_status'] === 'trial' ? 'badge-amber' : 'badge-rose') ?>">
            <?= e((string) $c['billing_status']) ?>
          </span>

          <form method="post" action="/admin/clinics/<?= (int) $c['id'] ?>" class="flex items-center gap-1.5">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="plan">
            <select class="select !py-1.5 text-xs" name="plan_id">
              <?php foreach ($plans as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $c['plan_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                  <?= e($p['name']) ?> · <?= e(money((float) $p['price_monthly'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="act-mute">Apply</button>
          </form>

          <form method="post" action="/admin/clinics/<?= (int) $c['id'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $c['billing_status'] === 'suspended' ? 'activate' : 'suspend' ?>">
            <button class="<?= $c['billing_status'] === 'suspended' ? 'act-start' : 'act-stop' ?>"
                    data-confirm="<?= $c['billing_status'] === 'suspended'
                        ? 'Reactivate this clinic?'
                        : 'Suspend this clinic? They lose access until payment, but NO data is deleted.' ?>">
              <?= $c['billing_status'] === 'suspended' ? 'Reactivate' : 'Suspend' ?>
            </button>
          </form>
        </div>
      </div>

      <div class="mt-3 grid gap-2 border-t border-slate-100 pt-3 text-[11px] text-slate-500 sm:grid-cols-4">
        <p>Trial ended: <b class="text-slate-700"><?= e((string) ($c['trial_ends_on'] ?? '—')) ?></b></p>
        <p>Renews: <b class="text-slate-700"><?= e((string) ($c['renews_on'] ?? '—')) ?></b></p>
        <p>Timezone: <b class="text-slate-700"><?= e((string) $c['timezone']) ?></b></p>
        <p>Slug: <b class="font-mono text-slate-700"><?= e((string) $c['slug']) ?></b></p>
      </div>
    </div>
  <?php endforeach; ?>
</div>
