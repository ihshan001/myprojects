<?php $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']; ?>

<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Schedules &amp; holidays</h1>
    <p class="mt-1 text-sm text-slate-500">
      Who works when, and which days the clinic is shut. The public booking page builds its times
      from this — nothing here is cosmetic.
    </p>
  </div>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.3fr_.9fr]">
  <!-- doctor schedules -->
  <div class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
      <p class="text-xs font-semibold text-slate-600">Doctor weekly schedule</p>
      <form method="get" action="/calendar" class="flex items-center gap-2">
        <select class="select py-1 text-xs" name="doctor_id" onchange="this.form.submit()" aria-label="Doctor">
          <?php foreach ($doctors as $doc): ?>
            <option value="<?= (int) $doc['id'] ?>" <?= (int) ($selectedDoctor['id'] ?? 0) === (int) $doc['id'] ? 'selected' : '' ?>>
              <?= e($doc['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <?php if ($selectedDoctor === null): ?>
      <div class="p-6 text-center text-sm text-slate-500">Add a doctor first — there is nobody to schedule.</div>
    <?php else: ?>
      <div class="px-4 pb-2 pt-3">
        <?php if (!$hasSchedule): ?>
          <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] leading-relaxed text-amber-900">
            <b>No schedule set.</b> <?= e($selectedDoctor['name']) ?> currently follows the clinic's opening
            hours on every open day. Fill in the grid below to restrict that to real shifts.
          </p>
        <?php else: ?>
          <p class="text-[11px] leading-relaxed text-slate-500">
            This doctor works the shifts below, intersected with clinic hours. Days left blank are
            days they do not work — the booking page will not offer them.
          </p>
        <?php endif; ?>
      </div>

      <form method="post" action="/calendar/schedule" class="space-y-1.5 px-4 pb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="doctor_id" value="<?= (int) $selectedDoctor['id'] ?>">

        <?php foreach ($dayNames as $dow => $dayName): ?>
          <?php $shiftRanges = $week[$dow] ?? []; ?>
          <?php $rowCount = max(1, count($shiftRanges) + 1); ?>
          <?php for ($i = 0; $i < $rowCount; $i++): ?>
            <?php $shiftFrom = $shiftRanges[$i][0] ?? ''; $shiftTo = $shiftRanges[$i][1] ?? ''; ?>
            <div class="grid grid-cols-[7rem_1fr_1fr] items-center gap-2">
              <?php if ($i === 0): ?>
                <span class="text-xs font-medium text-slate-600"><?= e($dayName) ?></span>
              <?php else: ?>
                <span class="text-[10px] text-slate-400 pl-1">+ shift</span>
              <?php endif; ?>
              <input class="input py-1.5 text-xs" type="time" name="day_<?= $dow ?>_from[]"
                     value="<?= e($shiftFrom) ?>" aria-label="<?= e($dayName) ?> shift <?= $i + 1 ?> start"
                     <?= $canManage ? '' : 'disabled' ?>>
              <input class="input py-1.5 text-xs" type="time" name="day_<?= $dow ?>_to[]"
                     value="<?= e($shiftTo) ?>" aria-label="<?= e($dayName) ?> shift <?= $i + 1 ?> end"
                     <?= $canManage ? '' : 'disabled' ?>>
            </div>
          <?php endfor; ?>
        <?php endforeach; ?>

        <?php if ($canManage): ?>
          <div class="flex flex-wrap gap-2 pt-2">
            <button class="btn-primary btn-sm flex-1">Save schedule</button>
          </div>
        <?php endif; ?>
      </form>

      <?php if ($canManage && $hasSchedule): ?>
        <form method="post" action="/calendar/schedule/clear" class="border-t border-slate-100 px-4 py-3"
              onsubmit="return confirm('Clear this schedule? The doctor will follow clinic hours again.')">
          <?= csrf_field() ?>
          <input type="hidden" name="doctor_id" value="<?= (int) $selectedDoctor['id'] ?>">
          <button class="text-xs font-medium text-rose-600 hover:underline">Clear schedule and follow clinic hours</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- holidays -->
  <div class="space-y-5">
    <div class="card overflow-hidden">
      <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Clinic closure days</p>
      </div>
      <?php if ($holidays === []): ?>
        <div class="p-4 text-[11px] leading-relaxed text-slate-500">
          No closure days ahead. Adding one closes the public booking page for that date, and
          outranks both clinic hours and any doctor's schedule.
        </div>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($holidays as $h): ?>
            <li class="flex items-center justify-between gap-3 px-4 py-2.5">
              <div>
                <p class="text-xs font-semibold text-slate-800"><?= e($h['name']) ?></p>
                <p class="text-[11px] text-slate-500"><?= e(date('D, j M Y', strtotime((string) $h['date']))) ?></p>
              </div>
              <?php if ($canManage): ?>
                <form method="post" action="/calendar/holidays/<?= (int) $h['id'] ?>/delete"
                      onsubmit="return confirm('Reopen this date for booking?')">
                  <?= csrf_field() ?>
                  <button class="btn-ghost btn-sm text-rose-600">Reopen</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
      <div class="card">
        <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
          <p class="text-xs font-semibold text-slate-600">Close a date</p>
        </div>
        <form method="post" action="/calendar/holidays" class="space-y-3 p-4">
          <?= csrf_field() ?>
          <div>
            <label class="label" for="date">Date</label>
            <input class="input" type="date" id="date" name="date" required min="<?= e(App\Core\Db::today()) ?>">
          </div>
          <div>
            <label class="label" for="holiday-name">Reason</label>
            <input class="input" id="holiday-name" name="name" required maxlength="120" placeholder="Vesak Full Moon Poya">
          </div>
          <button class="btn-primary w-full">Close the clinic that day</button>
          <p class="text-[10px] leading-relaxed text-slate-400">
            Existing appointments on that date are not cancelled — the confirmation tells you how
            many need rebooking, because that is a decision for a person.
          </p>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>
