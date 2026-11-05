<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Appointments</h1>
    <p class="mt-1 text-sm text-slate-500">Booked patients keep their slot priority when they check in.</p>
  </div>
</div>

<form method="get" action="/appointments" class="mt-5 flex flex-wrap items-end gap-2">
  <div>
    <label class="label" for="date">Date</label>
    <input class="input !py-2" type="date" id="date" name="date" value="<?= e($date) ?>">
  </div>
  <div>
    <label class="label" for="doctor">Doctor</label>
    <select class="select !py-2" id="doctor" name="doctor">
      <option value="">All doctors</option>
      <?php foreach ($doctors as $d): ?>
        <option value="<?= (int) $d['id'] ?>" <?= (int) $doctorId === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn-secondary">Show</button>
  <a href="/appointments?date=<?= e(date('Y-m-d')) ?>" class="btn-ghost text-xs">Today</a>
</form>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.4fr_.6fr]">
  <div class="card overflow-hidden">
    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
      <p class="text-xs font-semibold text-slate-600"><?= e(date('l, j F Y', strtotime($date))) ?></p>
      <p class="text-[11px] text-slate-400"><?= count($appointments) ?> booking(s)</p>
    </div>
    <?php if ($appointments === []): ?>
      <?php $title = 'Nothing booked'; $message = 'Use the form on the right to book an appointment.'; $icon = 'calendar'; include QP_ROOT . '/resources/views/components/empty.php'; ?>
    <?php else: ?>
      <div class="divide-y divide-slate-100">
        <?php foreach ($appointments as $a): ?>
          <?php $open = in_array($a['status'], ['booked'], true); ?>
          <div class="flex flex-wrap items-center gap-3 px-4 py-3">
            <div class="w-16 shrink-0">
              <p class="font-mono text-sm font-bold text-slate-800"><?= e(date('H:i', strtotime((string) $a['scheduled_for']))) ?></p>
              <p class="text-[10px] text-slate-400"><?= (int) $a['duration_minutes'] ?> min</p>
            </div>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-semibold text-slate-800"><?= e($a['full_name']) ?></p>
              <p class="truncate text-[11px] text-slate-400">
                <?= e($a['doctor_name']) ?>
                <?php if (!empty($a['phone'])): ?> · <?= e($a['phone']) ?><?php endif; ?>
                · <?= e($a['source']) ?>
              </p>
            </div>
            <?php if ($a['status'] === 'booked'): ?>
              <form method="post" action="/appointments/<?= (int) $a['id'] ?>/checkin">
                <?= csrf_field() ?>
                <button class="act-start"><svg class="h-3.5 w-3.5"><use href="#i-plus"/></svg>Check in</button>
              </form>
            <?php else: ?>
              <span class="<?= $a['status'] === 'checked_in' ? 'badge-teal' : ($a['status'] === 'served' ? 'badge-emerald' : 'badge-slate') ?>">
                <?= e(str_replace('_', ' ', $a['status'])) ?>
              </span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card card-pad lg:sticky lg:top-6 lg:self-start">
    <h2 class="text-sm font-bold text-slate-900">Book an appointment</h2>
    <form method="post" action="/appointments" class="mt-3 space-y-3">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="full_name">Patient name</label>
        <input class="input" id="full_name" name="full_name" required placeholder="Walk-in or known patient">
      </div>
      <div>
        <label class="label" for="phone">Phone</label>
        <input class="input" id="phone" name="phone" inputmode="tel" placeholder="077 123 4567">
      </div>
      <div>
        <label class="label" for="doctor_id">Doctor</label>
        <select class="select" id="doctor_id" name="doctor_id" required>
          <?php foreach ($doctors as $d): ?>
            <option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?> · <?= e($d['specialty'] ?? 'General') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="d">Date</label>
          <input class="input" type="date" id="d" name="date" value="<?= e($date) ?>" required></div>
        <div><label class="label" for="t">Time</label>
          <input class="input" type="time" id="t" name="time" value="09:00" required></div>
      </div>
      <button class="btn-primary w-full" <?= $doctors === [] ? 'disabled' : '' ?>>Book appointment</button>
      <?php if ($doctors === []): ?><p class="help text-rose-600">Add a doctor first.</p><?php endif; ?>
    </form>
  </div>
</div>
