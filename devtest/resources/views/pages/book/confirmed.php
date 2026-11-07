<?php
$brand = $clinicRecord['brand_color'] ?: '#0D7459';
?>
<!DOCTYPE html>
<html lang="<?= e($clinicRecord['locale'] ?? 'en') ?>" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?= e($brand) ?>">
  <meta name="robots" content="noindex">
  <title><?= e($title) ?></title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="patient-view min-h-full bg-canvas">
  <header class="px-5 py-5 text-white" style="background: <?= e($brand) ?>">
    <div class="mx-auto max-w-lg">
      <p class="text-sm font-bold"><?= e($clinicRecord['name']) ?></p>
      <p class="text-[11px] text-white/70">Appointment requested</p>
    </div>
  </header>

  <main class="mx-auto max-w-lg px-5 py-8">
    <div class="card p-6 text-center">
      <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
        <svg class="h-6 w-6"><use href="#i-check"/></svg>
      </span>
      <h1 class="mt-3 text-lg font-bold text-ink-900">You are booked</h1>
      <p class="mt-1 text-sm text-ink-600"><?= e($name) ?></p>

      <dl class="mt-5 space-y-2 rounded-xl bg-canvas p-4 text-left text-sm">
        <div class="flex justify-between gap-3">
          <dt class="text-ink-500">When</dt>
          <dd class="font-semibold text-ink-900">
            <?= e(date('D j M Y', strtotime($date))) ?> · <?= e(date('g:i A', strtotime($time))) ?>
          </dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-ink-500">Doctor</dt>
          <dd class="font-semibold text-ink-900"><?= e($doctor) ?></dd>
        </div>
      </dl>

      <p class="mt-5 text-xs leading-relaxed text-ink-600">
        No token is issued yet. When you arrive, check in at reception and you will
        receive your queue token. If you arrive more than
        <?= (int) ($clinicRecord['late_grace_minutes'] ?? 15) ?> minutes late,
        you will join the walk-in queue instead.
      </p>

      <div class="mt-5 rounded-xl border border-brand-200 bg-brand-50 p-4 text-left">
        <p class="text-xs font-semibold text-teal-900">Changed your mind?</p>
        <p class="mt-1 text-[11px] leading-relaxed text-brand-800">
          Open your appointment page to move it to another time or cancel it. Keep the
          link — it is your key, and you do not need a password.
        </p>
        <a class="btn mt-3 w-full justify-center bg-brand-700 text-xs text-white" href="<?= e($manageUrl) ?>">
          Manage this appointment
        </a>
      </div>

      <a class="btn-secondary mt-3 w-full" href="/book/<?= e((string) $clinicRecord['slug']) ?>">
        Book another appointment
      </a>

      <?php if (!empty($clinicRecord['phone'])): ?>
        <p class="mt-4 text-xs text-ink-500">
          Prefer to speak to someone? Call
          <a class="font-semibold text-brand-700" href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $clinicRecord['phone'])) ?>">
            <?= e((string) $clinicRecord['phone']) ?>
          </a>
        </p>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
