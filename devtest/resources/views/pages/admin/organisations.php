<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-building"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">Groups</h1>
    <p class="page-sub">
      A group lets a business that runs several branches see one roll-up. Attaching a clinic
      gives its owner a summary of the other branches — not their patients.
    </p>
  </div>
  </div>
</div>

<div class="mt-3 grid gap-3 sm:mt-6 sm:gap-5 lg:grid-cols-[1.5fr_.9fr]">
  <div class="space-y-3">
    <?php if ($orgs === []): ?>
      <?php $title = 'No groups yet'; $message = 'Create one on the right. A clinic can then be attached as a branch, and its owner gains a roll-up across the group.'; $icon = 'users'; ?>
      <div class="card p-4"><?php include QP_ROOT . '/resources/views/components/empty.php'; ?></div>
    <?php endif; ?>

    <?php foreach ($orgs as $org): ?>
      <div class="card card-pad">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold text-ink-900"><?= e($org['name']) ?></h2>
            <p class="mt-0.5 text-[11px] text-ink-500">
              <?= e((string) ($org['owner_email'] ?? 'no contact address')) ?>
              · <?= count($org['branch_list']) ?> branch<?= count($org['branch_list']) === 1 ? '' : 'es' ?>
            </p>
          </div>
          <span class="<?= (int) $org['is_active'] === 1 ? 'badge-emerald' : 'badge-slate' ?>">
            <?= (int) $org['is_active'] === 1 ? 'active' : 'inactive' ?>
          </span>
        </div>

        <?php if (!empty($org['notes'])): ?>
          <p class="mt-2 max-w-prose text-xs leading-relaxed text-ink-500"><?= e($org['notes']) ?></p>
        <?php endif; ?>

        <?php if ($org['rollup'] !== null): ?>
          <div class="mt-3 grid grid-cols-2 gap-2 border-t border-slate-100 pt-3 sm:grid-cols-4">
            <?php foreach ([
              ['Branches', $org['rollup']['branches'] ?? 0],
              ['Seen today', $org['rollup']['seen_today'] ?? 0],
              ['Waiting now', $org['rollup']['waiting_now'] ?? 0],
              ['In room', $org['rollup']['in_room'] ?? 0],
            ] as [$label, $value]): ?>
              <div>
                <p class="text-lg font-bold tabular-nums text-ink-900"><?= (int) $value ?></p>
                <p class="text-[10px] font-medium uppercase tracking-wide text-ink-500"><?= e($label) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="mt-3 border-t border-slate-100 pt-3">
          <?php if ($org['branch_list'] === []): ?>
            <p class="text-xs text-ink-500">No branches yet.</p>
          <?php else: ?>
            <ul class="space-y-1.5">
              <?php foreach ($org['branch_list'] as $b): ?>
                <li class="flex items-center justify-between gap-2">
                  <span class="text-xs text-ink-700">
                    <?= e($b['name']) ?>
                    <span class="text-ink-400">· <?= e((string) ($b['city'] ?? '')) ?></span>
                    <?php if ((int) ($b['is_active'] ?? 1) !== 1): ?>
                      <span class="badge-slate ml-1">inactive</span>
                    <?php endif; ?>
                  </span>
                  <form method="post" action="/admin/organisations/<?= (int) $org['id'] ?>/detach"
                        onsubmit="return confirm('Detach <?= e(addslashes((string) $b['name'])) ?> from this group?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="clinic_id" value="<?= (int) $b['id'] ?>">
                    <button class="text-[10px] font-medium text-ink-500 underline hover:text-rose-700">detach</button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if ($loose !== []): ?>
            <form method="post" action="/admin/organisations/<?= (int) $org['id'] ?>/attach" class="mt-2 flex items-center gap-2">
              <?= csrf_field() ?>
              <select class="select !py-1.5 text-xs" name="clinic_id" required>
                <option value="">Attach a clinic…</option>
                <?php foreach ($loose as $c): ?>
                  <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?><?= $c['city'] ? ' · ' . e($c['city']) : '' ?></option>
                <?php endforeach; ?>
              </select>
              <button class="act-mute">Attach</button>
            </form>
          <?php else: ?>
            <p class="mt-2 text-[11px] text-ink-400">Every clinic is already in a group.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="section-head">
      <p class="section-title">Create a group</p>
    </div>
    <form method="post" action="/admin/organisations" class="space-y-3 p-4">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="name">Group name</label>
        <input class="input" id="name" name="name" required minlength="2" maxlength="120"
               placeholder="Negombo Family Health">
      </div>
      <div>
        <label class="label" for="owner_email">Contact address</label>
        <input class="input" id="owner_email" name="owner_email" type="email" maxlength="190"
               placeholder="owner@group.lk">
        <p class="mt-1 text-[10px] text-ink-400">Who the platform contacts about this group.</p>
      </div>
      <div>
        <label class="label" for="notes">Notes</label>
        <textarea class="input min-h-16" id="notes" name="notes" maxlength="1000"
                  placeholder="Billing arrangement, whose decision it is to add a branch."></textarea>
      </div>
      <button class="btn-primary w-full">Create group</button>
    </form>

    <div class="border-t border-hairline px-4 py-3">
      <p class="text-[11px] leading-relaxed text-ink-500">
        A branch admin sees the roll-up of the whole group, but only the counts — never another
        branch's patients, queues or staff.
      </p>
    </div>
  </div>
</div>
