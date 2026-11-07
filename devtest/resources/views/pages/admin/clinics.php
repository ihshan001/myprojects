<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-building"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">Clinics</h1>
    <p class="page-sub">Suspend or re-plan a tenant without touching its data.</p>
  </div>
  </div>
  <a href="/admin" class="btn-ghost"><svg class="h-4 w-4"><use href="#i-arrow-left"/></svg>Overview</a>
</div>

<div class="mt-5 space-y-3">
  <?php foreach ($clinics as $c): ?>
    <div class="card card-pad">
      <div class="page-head">
        <div class="flex items-center gap-3">
          <span class="flex h-11 w-11 items-center justify-center rounded-xl text-xs font-bold text-white"
                style="background: <?= e((string) $c['brand_color']) ?>"><?= e(App\Core\Str::initials((string) $c['name'])) ?></span>
          <div>
            <p class="text-sm font-bold text-ink-900"><?= e($c['name']) ?></p>
            <p class="text-[11px] text-ink-500">
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

      <div class="mt-3 grid gap-2 border-t border-slate-100 pt-3 text-[11px] text-ink-500 sm:grid-cols-4">
        <p>Trial ended: <b class="text-ink-700"><?= e((string) ($c['trial_ends_on'] ?? '—')) ?></b></p>
        <p>Renews: <b class="text-ink-700"><?= e((string) ($c['renews_on'] ?? '—')) ?></b></p>
        <p>Timezone: <b class="text-ink-700"><?= e((string) $c['timezone']) ?></b></p>
        <p>Slug: <b class="font-mono text-ink-700"><?= e((string) $c['slug']) ?></b></p>
        <p class="sm:col-span-2">Owner:
          <b class="text-ink-700"><?= e((string) ($c['owner_email'] ?? '—')) ?></b>
          <?php if (($c['owner_verified_at'] ?? null) !== null): ?>
            <span class="badge-emerald ml-1">verified</span>
          <?php elseif (!empty($c['owner_email'])): ?>
            <span class="badge-amber ml-1">unverified — cannot issue tokens</span>
          <?php endif; ?>
        </p>
      </div>

      <?php
      $suspended = ($c['suspended_at'] ?? null) !== null;
      $closing = ($c['closure_requested_at'] ?? null) !== null;
      ?>
      <?php if ($suspended): ?>
        <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2">
          <p class="text-[11px] font-semibold text-rose-800">
            Suspended <?= e(ago($c['suspended_at'])) ?> — new tokens are refused, nothing deleted.
          </p>
          <p class="mt-0.5 text-[11px] text-rose-700"><?= e((string) $c['suspension_reason']) ?></p>
          <form method="post" action="/admin/clinics/<?= (int) $c['id'] ?>/reinstate" class="mt-2">
            <?= csrf_field() ?>
            <button class="btn-secondary btn-sm">Reinstate clinic</button>
          </form>
        </div>
      <?php elseif ($closing): ?>
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
          <p class="text-[11px] font-semibold text-amber-900">
            Closure requested <?= e(ago($c['closure_requested_at'])) ?>. Data purgeable after
            <?= e(substr((string) ($c['purge_after'] ?? ''), 0, 10)) ?>.
          </p>
        </div>
      <?php else: ?>
        <details class="mt-3">
          <summary class="cursor-pointer text-[11px] font-medium text-rose-600 hover:underline">Suspend this clinic…</summary>
          <form method="post" action="/admin/clinics/<?= (int) $c['id'] ?>/suspend" class="mt-2 flex flex-wrap gap-2"
                onsubmit="return confirm('Suspend <?= e(addslashes((string) $c['name'])) ?>? Patients cannot be issued tokens until reinstated.')">
            <?= csrf_field() ?>
            <input class="input !w-64 !py-1 text-xs" name="reason" required maxlength="255"
                   placeholder="Unpaid invoice since 3 Nov">
            <button class="btn-stop btn-sm">Suspend</button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
