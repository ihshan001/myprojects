<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Doctors & rooms</h1>
    <p class="mt-1 text-sm text-slate-500">Average consultation time drives the wait ranges patients see.</p>
  </div>
  <?php if (!$planLimit): ?>
    <span class="badge-amber">Plan limit reached — upgrade to add doctors</span>
  <?php endif; ?>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.5fr_.7fr]">
  <div class="space-y-3">
    <?php if ($doctors === []): ?>
      <div class="card">
        <?php $title = 'No doctors yet'; $message = 'Add the first doctor to open a queue and start issuing tokens.'; $icon = 'stethoscope'; include QP_ROOT . '/resources/views/components/empty.php'; ?>
      </div>
    <?php endif; ?>

    <?php foreach ($doctors as $doc): ?>
      <?php $q = $queues[(int) $doc['id']] ?? null; ?>
      <div class="card card-pad <?= (int) $doc['is_active'] === 1 ? '' : 'opacity-60' ?>">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-50 text-xs font-bold text-teal-700">
              <?= e(App\Core\Str::initials((string) $doc['name'])) ?>
            </span>
            <div>
              <p class="text-sm font-bold text-slate-900"><?= e($doc['name']) ?></p>
              <p class="text-[11px] text-slate-500">
                <?= e($doc['specialty'] ?? 'General') ?>
                <?php if (!empty($doc['room_name'])): ?> · <?= e($doc['room_name']) ?><?php endif; ?>
                · <?= e(money((float) $doc['consult_fee'])) ?>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <?php if ($q): ?>
              <span class="<?= $q['status'] === 'open' ? 'badge-emerald' : 'badge-amber' ?>"><?= e($q['status']) ?></span>
              <a href="/queue?queue_id=<?= (int) $q['id'] ?>" class="act-mute">Open queue</a>
            <?php else: ?>
              <span class="badge-slate">No session today</span>
            <?php endif; ?>
            <?php if (!$doc['is_active']): ?><span class="badge-slate">Inactive</span><?php endif; ?>
            <?php if (in_array((int) $doc['id'], $awayToday, true)): ?><span class="badge-rose">On leave today</span><?php endif; ?>
          </div>
        </div>

        <?php if ($canManage): ?>
          <details class="mt-3 border-t border-slate-100 pt-3">
            <summary class="cursor-pointer text-[11px] font-semibold text-slate-500 hover:text-slate-800">Add leave</summary>
            <form method="post" action="/doctors/leave" class="mt-3 grid gap-2 sm:grid-cols-4">
              <?= csrf_field() ?>
              <input type="hidden" name="doctor_id" value="<?= (int) $doc['id'] ?>">
              <label class="text-[11px] font-semibold text-slate-600">From
                <input type="date" class="input mt-0.5" name="starts_on" value="<?= e(App\Core\Db::today()) ?>" required>
              </label>
              <label class="text-[11px] font-semibold text-slate-600">To
                <input type="date" class="input mt-0.5" name="ends_on" value="<?= e(App\Core\Db::today()) ?>" required>
              </label>
              <label class="text-[11px] font-semibold text-slate-600 sm:col-span-1">Reason
                <input class="input mt-0.5" name="reason" placeholder="Annual leave" maxlength="120">
              </label>
              <div class="flex items-end">
                <button class="btn-ghost w-full">Record leave</button>
              </div>
            </form>
            <p class="mt-1.5 text-[10px] leading-relaxed text-slate-500">
              A doctor on leave is not offered on the booking form. Existing appointments are
              <strong>not</strong> cancelled — you are shown their count and can call the patients.
            </p>
          </details>
          <details class="mt-3 border-t border-slate-100 pt-3">
            <summary class="cursor-pointer text-[11px] font-semibold text-slate-500 hover:text-slate-800">Edit details</summary>
            <form method="post" action="/doctors/<?= (int) $doc['id'] ?>" class="mt-3 grid gap-3 sm:grid-cols-2">
              <?= csrf_field() ?>
              <div class="sm:col-span-2"><label class="label">Name</label>
                <input class="input" name="name" value="<?= e((string) $doc['name']) ?>" required></div>
              <div><label class="label">Specialty</label>
                <select class="select" name="specialty_id">
                  <option value="">—</option>
                  <?php foreach ($specialties as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int) $doc['specialty_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="label">Room</label>
                <select class="select" name="room_id">
                  <option value="">—</option>
                  <?php foreach ($rooms as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= (int) $doc['room_id'] === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="label">Fee (LKR)</label>
                <input class="input" name="consult_fee" value="<?= e((string) $doc['consult_fee']) ?>" inputmode="decimal"></div>
              <div><label class="label">Average consult (min)</label>
                <input class="input" name="avg_consult_minutes" value="<?= (int) $doc['avg_consult_minutes'] ?>" inputmode="numeric">
                <p class="help">Used before there is enough real data.</p></div>
              <label class="flex items-center gap-2 text-xs text-slate-700 sm:col-span-2">
                <input type="checkbox" class="checkbox" name="is_active" value="1" <?= $doc['is_active'] ? 'checked' : '' ?>>
                Active (visible on the board and available for tokens)
              </label>
              <button class="btn-secondary sm:col-span-2">Save doctor</button>
            </form>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="space-y-4">
    <?php if ($canManage): ?>
      <div class="card card-pad">
        <h2 class="text-sm font-bold text-slate-900">Add a doctor</h2>
        <form method="post" action="/doctors" class="mt-3 space-y-3">
          <?= csrf_field() ?>
          <div><label class="label" for="name">Name</label>
            <input class="input" id="name" name="name" required placeholder="Dr. Kasun Bandara"></div>
          <div><label class="label" for="reg_no">SLMC registration</label>
            <input class="input" id="reg_no" name="reg_no" placeholder="Optional"></div>
          <div class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
            <div><label class="label" for="specialty_id">Specialty</label>
              <select class="select" id="specialty_id" name="specialty_id">
                <option value="">General</option>
                <?php foreach ($specialties as $s): ?>
                  <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div><label class="label" for="room_id">Room</label>
              <select class="select" id="room_id" name="room_id">
                <option value="">—</option>
                <?php foreach ($rooms as $r): ?>
                  <option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>
          <div class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
            <div><label class="label" for="consult_fee">Fee (LKR)</label>
              <input class="input" id="consult_fee" name="consult_fee" value="2000" inputmode="decimal"></div>
            <div><label class="label" for="avg">Avg consult (min)</label>
              <input class="input" id="avg" name="avg_consult_minutes" value="10" inputmode="numeric"></div>
          </div>
          <button class="btn-primary w-full" <?= $planLimit ? '' : 'disabled' ?>>Add doctor & open queue</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="card card-pad">
      <h2 class="text-sm font-bold text-slate-900">Upcoming leave</h2>
      <?php if (($leave ?? []) === []): ?>
        <p class="mt-2 text-xs text-slate-400">No leave recorded. The booking form offers every active doctor.</p>
      <?php else: ?>
        <div class="table-scroll">
          <table class="table mt-2">
          <thead><tr><th>Doctor</th><th>Dates</th><th class="hidden sm:table-cell">Reason</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($leave as $l): ?>
          <tr>
          <td class="text-xs font-semibold text-slate-800"><?= e($l['doctor_name']) ?></td>
          <td class="text-xs text-slate-600"><?= e($l['starts_on']) ?><?= $l['ends_on'] !== $l['starts_on'] ? ' → ' . e($l['ends_on']) : '' ?></td>
          <td class="hidden sm:table-cell text-xs text-slate-500"><?= e((string) ($l['reason'] ?? '—')) ?></td>
          <td class="text-right">
          <?php if ($canManage): ?>
          <form method="post" action="/doctors/leave/<?= (int) $l['id'] ?>/delete" class="inline">
          <?= csrf_field() ?>
          <button class="act-mute">Remove</button>
          </form>
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
      <h2 class="text-sm font-bold text-slate-900">Rooms</h2>
      <div class="mt-2.5 flex flex-wrap gap-1.5">
        <?php foreach ($rooms as $r): ?>
          <span class="badge-slate"><?= e($r['name']) ?><?= !empty($r['floor']) ? ' · ' . e($r['floor']) : '' ?></span>
        <?php endforeach; ?>
        <?php if ($rooms === []): ?><p class="text-xs text-slate-400">No rooms configured.</p><?php endif; ?>
      </div>
      <h2 class="mt-4 text-sm font-bold text-slate-900">Specialties</h2>
      <div class="mt-2.5 flex flex-wrap gap-1.5">
        <?php foreach ($specialties as $s): ?>
          <span class="badge-teal"><?= e($s['name']) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
