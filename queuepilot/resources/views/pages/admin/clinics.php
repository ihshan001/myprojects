<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Clinics</h1>
    <p class="mt-1 text-sm text-slate-500">Suspend or re-plan a tenant without touching its data.</p>
  </div>
  <a href="/admin" class="btn-ghost"><svg class="h-4 w-4"><use href="#i-arrow-left"/></svg>Overview</a>
</div>

<?php /* ---------------------------------------------------------------
       The approval queue.

       A clinic that registers cannot log in until a person decides. That
       makes this list the most important screen in the platform: it is the
       only thing standing between a stranger and a live queue system, and
       it is the first thing a new owner is waiting on.

       Oldest first, because the queue is worked from the top — a clinic
       that applied eleven days ago must not sit behind today's arrival.
     --------------------------------------------------------------- */ ?>
<?php if (!empty($awaiting)): ?>
  <div class="mt-5 overflow-hidden rounded-xl border border-amber-200 bg-amber-50/50">
    <div class="flex items-center justify-between gap-3 border-b border-amber-200 px-4 py-3">
      <div>
        <h2 class="text-sm font-bold text-amber-900">
          <?= count($awaiting) ?> clinic<?= count($awaiting) === 1 ? '' : 's' ?> awaiting a decision
        </h2>
        <p class="text-[11px] text-amber-700">These accounts cannot use the system until you approve them.</p>
      </div>
      <span class="badge-amber"><?= count($awaiting) ?> pending</span>
    </div>

    <div class="divide-y divide-amber-200/70 bg-white">
      <?php foreach ($awaiting as $a): ?>
        <?php $days = (int) ($a['days_waiting'] ?? 0); ?>
        <div class="px-4 py-4">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
              <p class="text-sm font-bold text-slate-900"><?= e($a['name']) ?></p>
              <p class="mt-0.5 text-[11px] text-slate-500">
                <?= e((string) ($a['owner_name'] ?? '')) ?>
                <?php if (!empty($a['owner_email'])): ?>
                  · <a href="mailto:<?= e($a['owner_email']) ?>" class="text-teal-700 hover:underline"><?= e($a['owner_email']) ?></a>
                <?php endif; ?>
              </p>
              <p class="mt-0.5 text-[11px] text-slate-400">
                <?= e((string) ($a['city'] ?? '') ?: 'No city given') ?>
                <?php if (!empty($a['phone'])): ?> · <?= e((string) $a['phone']) ?><?php endif; ?>
                · applied <?= e(date('j M Y', strtotime((string) $a['created_at']))) ?>
              </p>
              <p class="mt-1.5">
                <span class="<?= $days >= 3 ? 'badge-rose' : 'badge-slate' ?>">
                  waiting <?= $days ?> day<?= $days === 1 ? '' : 's' ?>
                </span>
                <span class="badge-slate">slug: <?= e((string) $a['slug']) ?></span>
              </p>
            </div>

            <div class="flex flex-wrap items-start gap-2">
              <form method="post" action="/admin/clinics/<?= (int) $a['id'] ?>" class="flex items-center gap-1.5">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <!-- The billing cycle is chosen here, at the moment the clinic is
                     approved, because it is the platform's decision and it cannot
                     be changed afterwards without changing the next invoice. It
                     used to be impossible to record at all: every clinic was
                     monthly whether or not they had paid for a year. -->
                <select class="select !py-1.5 text-xs" name="billing_cycle" aria-label="Billing cycle for <?= e($a['name']) ?>">
                  <option value="monthly">Monthly</option>
                  <option value="yearly">Yearly &middot; 20% off</option>
                </select>
                <button class="act-start" data-confirm-submit="Approve <?= e($a['name']) ?>? They will be able to put patients in a queue immediately.">
                  <svg class="h-4 w-4"><use href="#i-check-circle"/></svg>Approve
                </button>
              </form>

              <form method="post" action="/admin/clinics/<?= (int) $a['id'] ?>" class="flex items-center gap-1.5">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="trial">
                <input type="number" name="trial_days" value="14" min="1" max="90"
                       class="input w-16 py-1 text-xs" aria-label="Trial length in days">
                <button class="btn py-1 text-xs">Grant trial</button>
              </form>

              <form method="post" action="/admin/clinics/<?= (int) $a['id'] ?>" class="flex items-center gap-1.5">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="decline">
                <input type="text" name="reason" placeholder="Reason the clinic will see" required minlength="5"
                       class="input w-48 py-1 text-xs">
                <button class="act-stop" data-confirm-danger="Decline <?= e($a['name']) ?>? They will see the reason you type.">
                  Decline
                </button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

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

          <?php if (($c['owner_verified_at'] ?? null) === null && !empty($c['owner_id'])): ?>
            <form method="post" action="/admin/clinics/<?= (int) $c['owner_id'] ?>/verify-email"
                  data-confirm-submit="Mark this address as verified by hand? Only do this once you have spoken to the owner.">
              <?= csrf_field() ?>
              <button class="act-mute" title="<?= e((string) $c['owner_email']) ?>">Verify email</button>
            </form>
          <?php endif; ?>

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
        <p class="sm:col-span-2">Owner:
          <b class="text-slate-700"><?= e((string) ($c['owner_email'] ?? '—')) ?></b>
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
        <?php
        $due = !empty($c['purge_after']) && strtotime((string) $c['purge_after']) <= time();
        $approved = !empty($c['purge_approved_at']);
        ?>
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
          <p class="text-[11px] font-semibold text-amber-900">
            Closed <?= e(ago($c['closure_requested_at'])) ?>.
            <?php if ($due): ?>
              Past the retention window since <?= e(substr((string) $c['purge_after'], 0, 10)) ?>.
            <?php else: ?>
              Retention window ends <?= e(substr((string) $c['purge_after'], 0, 10)) ?>.
            <?php endif; ?>
          </p>

          <?php if ($approved): ?>
            <?php /* The decision exists and is reversible. Deleting is still a
                     separate command, because it is the one thing here that
                     cannot be undone from this page. */ ?>
            <div class="mt-2 rounded-lg border border-rose-300 bg-rose-50 px-2.5 py-2">
              <p class="text-[11px] font-bold text-rose-900">
                Marked for deletion <?= e(ago($c['purge_approved_at'])) ?>.
              </p>
              <p class="mt-0.5 text-[11px] text-rose-800">
                Nothing has been deleted yet. An operator runs
                <code class="rounded bg-white/70 px-1">php tools/purge.php --yes</code> to do it.
                Until then this can be reversed.
              </p>
              <form method="post" action="/admin/clinics/<?= (int) $c['id'] ?>/purge-approval" class="mt-2">
                <?= csrf_field() ?>
                <input type="hidden" name="decision" value="revoke">
                <button class="btn-secondary btn-sm">Keep the data</button>
              </form>
            </div>
          <?php elseif ($due): ?>
            <div class="mt-2">
              <p class="text-[11px] leading-relaxed text-amber-800">
                <strong>Email them, then phone them.</strong> Ask what happened and whether the
                data is still needed. Only record a decision once they have answered — a clinic
                closed for renovation is not a clinic to delete.
              </p>
              <?php if (!empty($c['phone'])): ?>
                <p class="mt-1 text-[11px] text-amber-900">
                  Phone: <a class="underline" href="tel:<?= e((string) $c['phone']) ?>"><?= e((string) $c['phone']) ?></a>
                </p>
              <?php endif; ?>
              <details class="mt-2">
                <summary class="cursor-pointer text-[11px] font-medium text-rose-600 hover:underline">
                  They answered — record the decision…
                </summary>
                <form method="post" action="/admin/clinics/<?= (int) $c['id'] ?>/purge-approval"
                      class="mt-2 space-y-2"
                      data-confirm-submit="Mark <?= e(addslashes((string) $c['name'])) ?> for deletion? Data is destroyed when an operator runs the purge tool."
                      data-confirm-danger>
                  <?= csrf_field() ?>
                  <input type="hidden" name="decision" value="approve">
                  <textarea class="input text-xs" name="note" rows="2" required minlength="10" maxlength="500"
                            placeholder="Spoke to Dr Perera 6 Nov: clinic closed permanently, records not needed"></textarea>
                  <p class="text-[10px] text-slate-500">Required. This becomes the audit trail for deleting the data.</p>
                  <button class="btn-stop btn-sm">Mark for deletion</button>
                </form>
              </details>
            </div>
          <?php else: ?>
            <p class="mt-1 text-[11px] text-amber-800">
              No action yet. Contact them once the retention window has passed.
            </p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <details class="mt-3">
          <summary class="cursor-pointer text-[11px] font-medium text-rose-600 hover:underline">Suspend this clinic…</summary>
          <form method="post" action="/admin/clinics/<?= (int) $c['id'] ?>/suspend" class="mt-2 flex flex-wrap gap-2"
                data-confirm-submit="Suspend <?= e(addslashes((string) $c['name'])) ?>? Patients cannot be issued tokens until reinstated." data-confirm-danger>
            <?= csrf_field() ?>
            <input class="input !w-64 !py-1 text-xs" name="reason" required maxlength="255"
                   placeholder="Unpaid invoice since 3 Nov">
            <button class="btn-stop btn-sm">Suspend</button>
          </form>
        </details>
      <?php endif; ?>

      <?php /* Only for a clinic that is live and was approved: there is an
               owner account to send a link to. Re-sending supersedes the old
               link, which is also how you close off one that has been sitting
               in a mailbox for weeks. */ ?>
      <?php if (!$suspended && !$closing
                && (($c['approved_at'] ?? null) !== null || ($c['trial_ends_on'] ?? null) !== null)): ?>
        <details class="mt-3">
          <summary class="cursor-pointer text-[11px] font-medium text-teal-700 hover:underline">
            Welcome link did not arrive?
          </summary>
          <form method="post" action="/admin/clinics/<?= (int) $c['id'] ?>/resend-welcome" class="mt-2"
                data-confirm-submit="Send <?= e(addslashes((string) $c['name'])) ?>'s owner a new welcome link? The link they have now will stop working."
                data-confirm-danger>
            <?= csrf_field() ?>
            <button class="btn-secondary btn-sm">Resend welcome link</button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
