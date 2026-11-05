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

<?php
// The day-cancel call list. Kept on the page after the action rather than on a
// separate screen: the person who just cancelled twenty bookings needs the
// phone numbers in front of them, not a link to go and find them.
$cancelResult = \App\Core\Session::takeFlash('cancel_day_result');
?>
<?php if (is_array($cancelResult)): ?>
  <div class="card mt-5 overflow-hidden ring-2 ring-amber-200">
    <div class="border-b border-amber-200 bg-amber-50 px-4 py-3">
      <p class="text-sm font-bold text-amber-900">
        <?= (int) $cancelResult['count'] ?> patient(s) need a call
      </p>
      <p class="mt-0.5 text-xs text-amber-800">
        <?= e((string) $cancelResult['doctor']) ?> · <?= e(date('l j M', strtotime((string) $cancelResult['date']))) ?>.
        Their slots are free again; these people do not know yet.
      </p>
    </div>
    <ul class="divide-y divide-slate-100">
      <?php foreach ($cancelResult['patients'] as $p): ?>
        <li class="flex items-center justify-between gap-3 px-4 py-2.5">
          <div class="min-w-0">
            <p class="truncate text-sm text-slate-800"><?= e((string) $p['name']) ?></p>
            <p class="text-[11px] text-slate-400">was booked for <?= e((string) $p['time']) ?></p>
          </div>
          <?php if (!empty($p['phone'])): ?>
            <a class="btn-secondary btn-sm shrink-0 text-xs" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $p['phone'])) ?>">
              Call <?= e((string) $p['phone']) ?>
            </a>
          <?php else: ?>
            <span class="text-[11px] text-rose-600">no number on file</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="border-t border-slate-100 px-4 py-2.5 text-[11px] leading-relaxed text-slate-500">
      Nothing was deleted — the day's history still shows what was booked and that it was cancelled.
    </p>
  </div>
<?php endif; ?>

<?php
// Only offered when there is something to cancel, and only for a day that has
// not happened. A form that can only fail is worse than no form.
$cancelDoctor = $doctorId ?? null;
$futureDay = strtotime($date) >= strtotime(\App\Core\Db::today());
?>
<?php if ($futureDay && $appointments !== [] && $cancelDoctor !== null): ?>
  <details class="card mt-5 p-4">
    <summary class="cursor-pointer text-sm font-medium text-slate-700">
      The doctor cannot come in — cancel this whole day
    </summary>
    <form method="post" action="/appointments/cancel-day" class="mt-3 space-y-3"
          onsubmit="return confirm('Cancel every live booking for this doctor on this day? Each patient will need a call.');">
      <?= csrf_field() ?>
      <input type="hidden" name="date" value="<?= e($date) ?>">
      <input type="hidden" name="doctor_id" value="<?= (int) $cancelDoctor ?>">
      <div>
        <label class="label" for="reason">Reason (recorded, shown to staff)</label>
        <input class="input" id="reason" name="reason" maxlength="120" placeholder="Doctor unwell, rescheduled theatre list…">
      </div>
      <p class="text-[11px] leading-relaxed text-slate-500">
        Every live booking for this doctor on <?= e(date('D j M', strtotime($date))) ?> becomes
        <b>cancelled</b>, and the next screen lists every patient with their number so someone can
        ring them. Bookings are not deleted. This cannot be undone from here.
      </p>
      <button class="btn w-full justify-center border border-rose-300 bg-white text-sm text-rose-700 hover:bg-rose-50"
              <?= $doctors === [] ? 'disabled' : '' ?>>
        Cancel <?= count(array_filter($appointments, static fn ($a) => in_array($a['status'], ['booked', 'checked_in'], true))) ?>
        live booking(s)
      </button>
    </form>
  </details>
<?php endif; ?>
