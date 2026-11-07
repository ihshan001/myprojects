<?php
/**
 * A kiosk key that is not (or no longer) valid.
 *
 * The likeliest cause is not an attack: somebody rotated the key when they
 * replaced the tablet, and this one still has the old URL open. Saying so is
 * more useful than a 404.
 */
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e($title ?? 'Kiosk not set up') ?></title>
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="patient-view flex min-h-full items-center justify-center bg-canvas px-5">
  <div class="w-full max-w-md rounded-2xl border border-hairline bg-white p-8 text-center shadow-sm">
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-canvas text-ink-500">
      <svg class="h-6 w-6"><use href="#i-shield"/></svg>
    </div>
    <h1 class="mt-4 text-lg font-bold text-ink-900">This kiosk is not set up</h1>
    <p class="mt-2 text-sm leading-relaxed text-ink-600">
      The check-in code on this screen is not valid any more. Please use the reception desk —
      they can print a number in a few seconds.
    </p>
    <p class="mt-4 text-xs leading-relaxed text-ink-400">
      Staff: a new code is issued under <b>Settings → Waiting room</b>. Issuing a new one
      replaces the old code, so this screen will need the new address.
    </p>
  </div>
</body>
</html>
