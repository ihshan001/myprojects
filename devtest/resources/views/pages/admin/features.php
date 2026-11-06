<?php
/**
 * Platform feature entitlements. The operator decides which clinic gets
 * which feature — SMS, WhatsApp, display screens, online booking, reports,
 * departments — by setting an override per clinic per feature.
 *
 * Override wins over the plan default. No override means the plan's
 * `features` list applies. A clinic can only use a feature when it is
 * effectively enabled — enforcement is in the service layer, not just the UI.
 */
?>
<div class="flex flex-wrap items-start justify-between gap-3 mb-6">
  <div>
    <h1 class="page-title">Feature entitlements</h1>
    <p class="page-sub">
      The platform operator decides which clinic gets which feature. An
      override beats the plan default; without an override the plan's feature
      list applies. Enforcement happens server-side — a disabled feature
      cannot be used even if the UI still shows it.
    </p>
  </div>
  <a href="/admin" class="mt-2 inline-flex items-center gap-1.5 rounded border border-hairline bg-white px-3 py-1.5 text-sm font-medium text-ink-700 hover:bg-slate-50">
    <span><svg class="h-4 w-4"><use href="#i-arrow-left"/></svg></span>
    <span>Back to platform console</span>
  </a>
</div>

<?php if (!empty($clinics)): ?>
  <div class="overflow-x-auto">
    <table class="w-full text-left border-collapse">
      <thead>
        <tr class="border-b border-hairline">
          <th class="pb-3 text-sm font-semibold text-ink-500">Clinic</th>
          <?php foreach ($features as $code => $label): ?>
            <th class="pb-3 text-sm font-semibold text-ink-500 text-center w-16"><?= e($label) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clinics as $clinic): ?>
          <?php
          $rows = $clinicFeatures[(int) $clinic['id']] ?? [];
          $byFeature = [];
          foreach ($rows as $r) { $byFeature[$r['feature']] = $r; }
          $planName = (string) ($clinic['plan_name'] ?? '—');
          ?>
          <tr class="border-b border-slate-100 hover:bg-slate-50">
            <td class="py-4">
              <div class="font-medium text-ink-900"><?= e((string) $clinic['name']) ?></div>
              <div class="text-[10px] text-ink-500">Plan: <?= e($planName) ?> · ID <?= (int) $clinic['id'] ?></div>
            </td>
            <?php foreach ($features as $code => $label): ?>
              <?php
              $row = $byFeature[$code] ?? null;
              $effective = $row !== null ? (bool) $row['enabled'] : in_array($code, explode(',', (string) ($clinic['features'] ?? '')), true);
              $overridden = $row !== null;
              $badgeClass = $effective
                ? 'badge-emerald'
                : 'badge-slate';
              ?>
              <td class="py-4 text-center">
                <form method="post" action="/admin/features/<?= (int) $clinic['id'] ?>" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="feature" value="<?= e($code) ?>">
                  <button type="submit"
                          name="enabled" value="1"
                          class="<?= $overridden && $effective ? 'badge-emerald' : ($effective ? 'badge-emerald' : 'badge-slate') ?>
                                 rounded border px-2.5 py-1 text-xs font-medium
                                 <?= $overridden ? 'border-emerald-200 bg-emerald-50/50 text-emerald-700' : 'border-hairline bg-white text-ink-700' ?>"
                          <?= $overridden ? '' : 'title="Plan default — click to override"' ?>>
                    <?= $effective ? 'On' : 'Off' ?>
                    <?php if ($overridden): ?>
                      <span class="ml-1 text-[10px] opacity-70">(override)</span>
                    <?php endif; ?>
                  </button>
                </form>
                <?php if ($overridden): ?>
                  <form method="post" action="/admin/features/<?= (int) $clinic['id'] ?>/unset" class="mt-1.5 inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="<?= e($code) ?>">
                    <button type="submit"
                            class="rounded border border-hairline bg-white px-2 py-0.5 text-[10px] text-ink-500 hover:bg-slate-50">
                      Remove override
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-6 rounded-xl border border-hairline bg-white p-5">
    <h2 class="text-sm font-bold uppercase tracking-wider text-ink-400 mb-3">Known features</h2>
    <ul class="grid gap-2 sm:grid-cols-3 text-sm text-ink-700">
      <?php foreach ($features as $code => $label): ?>
        <li class="flex items-start gap-2">
          <span class="text-ink-400">·</span>
          <span><strong><?= e($label) ?></strong> (<span class="text-ink-500"><?= e($code) ?></span>)</span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="mt-3 text-xs text-ink-500">
      SMS costs 0.64 LKR per message via text.lk. Metered per clinic per
      month — see the billing page for usage.
    </p>
  </div>
<?php else: ?>
  <div class="card card-pad">
    <p class="text-sm text-ink-500">No clinics yet.</p>
  </div>
<?php endif; ?>

<style>
  .badge-emerald { border-color: #10b981; color: #047857; }
  .badge-emerald.bg-emerald-50\/50 { background-color: #ecfdf5; }
  .badge-slate { border-color: #94a3b8; color: #64748b; }
</style>
