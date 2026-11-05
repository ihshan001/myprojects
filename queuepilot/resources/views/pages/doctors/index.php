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
          </div>
        </div>

        <?php if ($canManage): ?>
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
          <div class="grid grid-cols-2 gap-3">
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
          <div class="grid grid-cols-2 gap-3">
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
