<?php
/**
 * A patient's own appointment, opened from the signed link.
 *
 * Deliberately no login: the link is the credential. What it can change is
 * deliberately narrow too — the time, or nothing. The doctor belongs to the
 * booking; choosing a different clinician is a conversation with reception,
 * because it changes which room, which roster and which fee applies.
 *
 * @var array<string,mixed> $clinicRecord
 * @var array<string,mixed> $appointment
 * @var list<string> $slots
 * @var string|null $slotReason
 */
$when = (string) $appointment['scheduled_for'];
$statusCopy = (string) $appointment['status'] === 'checked_in'
    ? 'You have checked in — your token is with reception.'
    : 'Your booking is confirmed.';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e($title) ?> · QueuePilot</title>
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="patient-view min-h-full bg-canvas">
  <main class="mx-auto max-w-lg px-5 py-8">
    <p class="text-center text-xs font-semibold uppercase tracking-wide text-ink-400">
      <?= e((string) $clinicRecord['name']) ?>
    </p>

    <div class="card mt-3 p-6">
      <?php if (!empty($moved)): ?>
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-xs leading-relaxed text-emerald-900">
          <b>Moved to <?= e((string) $moved) ?>.</b> The clinic's list has already been updated.
        </div>
      <?php endif; ?>

      <?php if (!empty($cancelled)): ?>
        <div class="mb-4 rounded-xl border border-hairline bg-canvas px-3.5 py-3 text-xs leading-relaxed text-ink-700">
          <b>This appointment is cancelled.</b> The time is now free for someone else. You can book
          again whenever you need to — no explanation required.
        </div>
      <?php endif; ?>

      <?php if (!empty($error)): ?>
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-xs leading-relaxed text-amber-900">
          <?= e((string) $error) ?>
        </div>
      <?php endif; ?>

      <h1 class="text-lg font-bold text-ink-900">Your appointment</h1>
      <p class="mt-0.5 text-xs text-ink-500"><?= e((string) $appointment['full_name']) ?></p>

      <dl class="mt-4 space-y-2 rounded-xl bg-canvas p-4 text-sm">
        <div class="flex justify-between gap-3">
          <dt class="text-ink-500">When</dt>
          <dd class="font-semibold text-ink-900">
            <?= e(date('D j M Y', strtotime($when))) ?> · <?= e(date('g:i A', strtotime($when))) ?>
          </dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-ink-500">Doctor</dt>
          <dd class="font-semibold text-ink-900"><?= e((string) $appointment['doctor_name']) ?></dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-ink-500">Status</dt>
          <dd class="text-ink-700"><?= e($statusCopy) ?></dd>
        </div>
      </dl>

      <?php if ((string) $appointment['status'] === 'booked'): ?>
        <details class="mt-4" <?= (empty($moved) && empty($error)) ? '' : 'open' ?>>
          <summary class="cursor-pointer text-sm font-medium text-brand-700">I need a different time</summary>

          <form method="get" action="/a/<?= e($token) ?>" class="mt-3 flex gap-2">
            <input class="input text-xs" type="date" name="date" value="<?= e($date) ?>"
                   min="<?= e(\App\Core\Db::today()) ?>"
                   max="<?= e(date('Y-m-d', strtotime('+' . (int) $horizon . ' days'))) ?>">
            <button class="btn-secondary btn-sm shrink-0 text-xs" type="submit">Show times</button>
          </form>

          <?php if ($slotReason !== null): ?>
            <p class="mt-2 text-[11px] leading-relaxed text-amber-800"><?= e((string) $slotReason) ?></p>
          <?php elseif ($slots === []): ?>
            <p class="mt-2 text-[11px] leading-relaxed text-ink-500">
              Nothing is open on that day. Try another date.
            </p>
          <?php else: ?>
            <form method="post" action="/a/<?= e($token) ?>/reschedule" class="mt-3 space-y-2">
              <?= csrf_field() ?>
              <input type="hidden" name="date" value="<?= e($date) ?>">
              <div class="grid grid-cols-3 gap-1.5">
                <?php foreach ($slots as $slot): ?>
                  <label class="cursor-pointer">
                    <input type="radio" name="time" value="<?= e($slot) ?>" class="peer sr-only" required>
                    <span class="block rounded-lg border border-hairline bg-white px-1 py-1.5 text-center text-[11px] text-ink-700
                                 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:font-semibold peer-checked:text-brand-800">
                      <?= e($slot) ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <button class="btn w-full justify-center bg-brand-700 text-xs text-white" type="submit">
                Move my appointment
              </button>
              <p class="text-[10px] leading-relaxed text-ink-400">
                You keep <?= e((string) $appointment['doctor_name']) ?>. Times shown are this doctor's real
                openings, so a slot you pick will not be refused.
              </p>
            </form>
          <?php endif; ?>
        </details>

        <form method="post" action="/a/<?= e($token) ?>/cancel" class="mt-3"
              data-confirm="Cancel this appointment? The time goes back to the clinic.">
          <?= csrf_field() ?>
          <button type="submit" class="btn w-full justify-center border border-hairline bg-white text-xs text-rose-700 hover:bg-rose-50">
            I cannot come — cancel it
          </button>
        </form>
      <?php else: ?>
        <p class="mt-4 text-xs leading-relaxed text-ink-500">
          This appointment has already started at the clinic. Speak to reception if you need
          another day.
        </p>
      <?php endif; ?>
    </div>

    <?php if (!empty($clinicRecord['phone'])): ?>
      <p class="mt-4 text-center text-xs text-ink-500">
        Rather speak to someone? Call
        <a class="font-semibold text-brand-700" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $clinicRecord['phone'])) ?>">
          <?= e((string) $clinicRecord['phone']) ?>
        </a>
      </p>
    <?php endif; ?>

    <p class="mt-6 text-center text-[10px] leading-relaxed text-ink-400">
      This link is personal to your booking. Anyone holding it can change this appointment,
      so please do not forward it.
    </p>
  </main>
</body>
</html>
