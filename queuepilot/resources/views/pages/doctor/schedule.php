<?php
/**
 * "My schedule" — read-only for the doctor.
 *
 * The PDF gives a doctor "My schedule" but keeps clinic configuration with
 * the owner, so this screen shows the hours the clinic has set and any
 * approved leave without offering to change them. Letting a doctor edit
 * their own recurring hours from here would quietly move the clinic's
 * booking availability out from under reception.
 *
 * @var array|null $doctor
 * @var array      $week, $leave, $holidays
 */
$dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** '08:30' → '8:30 AM'. Stored as plain HH:MM, so no date parsing. */
$clock = static function (string $hhmm): string {
    if ($hhmm === '') {
        return '';
    }
    [$h, $m] = array_pad(explode(':', $hhmm, 2), 2, '0');
    $hour = (int) $h;
    $ampm = $hour < 12 ? 'AM' : 'PM';
    $hour12 = $hour % 12 === 0 ? 12 : $hour % 12;

    return $hour12 . ':' . $m . ' ' . $ampm;
};
?>

<div class="card card-pad mb-4">
  <h2 class="text-base font-semibold text-slate-800">My working hours</h2>
  <p class="mt-1 text-sm text-slate-500">
    These are the hours the clinic has set for you. To change them, ask a clinic owner — they manage
    schedules from <span class="font-medium text-slate-700">Schedules &amp; holidays</span>.
  </p>
</div>

<div class="card mb-4">
  <div class="border-b border-slate-100 px-4 py-3">
    <h2 class="text-sm font-semibold text-slate-700">Weekly schedule</h2>
  </div>

  <?php if ($week === []): ?>
    <p class="px-4 py-10 text-center text-sm text-slate-500">No recurring hours have been set for you yet.</p>
  <?php else: ?>
    <ul class="divide-y divide-slate-100">
      <?php foreach ($week as $day => $windows): ?>
        <li class="flex items-center gap-4 px-4 py-3">
          <span class="w-12 text-sm font-semibold text-slate-600"><?= e($dayNames[$day] ?? (string) $day) ?></span>
          <?php if ($windows === []): ?>
            <span class="text-sm text-slate-400">Not working</span>
          <?php else: ?>
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ($windows as $w): ?>
                <span class="badge-teal"><?= e($clock((string) ($w[0] ?? ''))) ?>
                  – <?= e($clock((string) ($w[1] ?? ''))) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="grid gap-4 md:grid-cols-2">
  <div class="card">
    <div class="border-b border-slate-100 px-4 py-3">
      <h2 class="text-sm font-semibold text-slate-700">My leave</h2>
    </div>
    <?php if ($leave === []): ?>
      <p class="px-4 py-10 text-center text-sm text-slate-500">No leave booked.</p>
    <?php else: ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($leave as $l): ?>
          <li class="px-4 py-3">
            <p class="text-sm font-medium text-slate-800">
              <?= e(fmt_time($l['starts_on'], 'D j M')) ?> – <?= e(fmt_time($l['ends_on'], 'D j M')) ?>
            </p>
            <?php if (!empty($l['reason'])): ?>
              <p class="text-xs text-slate-500"><?= e($l['reason']) ?></p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="border-b border-slate-100 px-4 py-3">
      <h2 class="text-sm font-semibold text-slate-700">Clinic holidays</h2>
    </div>
    <?php if ($holidays === []): ?>
      <p class="px-4 py-10 text-center text-slate-500">No upcoming closures.</p>
    <?php else: ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($holidays as $h): ?>
          <li class="px-4 py-3">
            <p class="text-sm font-medium text-slate-800"><?= e(fmt_time($h['date'], 'D j M Y')) ?></p>
            <?php if (!empty($h['name'])): ?>
              <p class="text-xs text-slate-500"><?= e($h['name']) ?></p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
