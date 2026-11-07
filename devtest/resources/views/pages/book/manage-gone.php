<?php
/**
 * A booking link that used to work.
 *
 * Reaching this page means the signature was correct for the time it was
 * issued, and the booking has since moved on. That is not an attack and it is
 * not the patient's fault, so the page explains rather than accusing.
 *
 * @var array<string,mixed> $clinicRecord
 * @var array<string,mixed> $appointment
 */
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
<body class="flex min-h-full items-center justify-center bg-slate-100 px-5">
  <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-600">
      <svg class="h-6 w-6"><use href="#i-clock"/></svg>
    </div>
    <h1 class="mt-4 text-lg font-bold text-slate-900"><?= e($title) ?></h1>
    <p class="mt-2 text-sm leading-relaxed text-slate-600">
      <?php if ((string) $appointment['status'] === 'cancelled'): ?>
        This appointment was cancelled, so the link that pointed at it no longer opens anything.
      <?php else: ?>
        This appointment has already moved past booking — it may have been changed, or the
        visit has taken place.
      <?php endif; ?>
      The clinic still has your name on record, and reception can look it up.
    </p>

    <?php if ((string) $appointment['status'] === 'cancelled'): ?>
      <a class="btn mt-5 w-full justify-center bg-teal-700 text-sm text-white"
         href="/book/<?= e((string) $clinicRecord['slug']) ?>">Book a new appointment</a>
    <?php endif; ?>

    <?php if (!empty($clinicRecord['phone'])): ?>
      <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $clinicRecord['phone'])) ?>"
         class="btn-secondary mt-3 w-full justify-center text-sm">Call the clinic</a>
    <?php endif; ?>
  </div>
</body>
</html>
