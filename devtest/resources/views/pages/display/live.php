<?php
/**
 * The older public queue link, /live/{key}.
 *
 * This used to be a separate, older-looking page with an inline script that
 * the Content-Security-Policy silently blocked — so it opened, then froze.
 * It now renders the same board as /display and /board/{key} and loads the
 * same external poller, so all three cannot drift apart again.
 *
 * No staff controls: anyone holding this URL can reach it.
 */
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Waiting room · <?= e($clinicRecord['name']) ?></title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="wr min-h-full" data-display-board="<?= e($key) ?>">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div data-tv-offline class="hidden fixed inset-x-0 top-0 z-50 bg-amber-500 px-4 py-2 text-center text-xs font-bold text-amber-950">
  Connection lost — showing the last known state. This screen is NOT live.
</div>

<div class="wr-shell">
  <?php include QP_ROOT . '/resources/views/partials/waiting-board.php'; ?>

  <footer class="wr-foot">
    <p class="wr-foot-note">
      <svg class="h-4 w-4 shrink-0" aria-hidden="true"><use href="#i-info"/></svg>
      Thank you for your patience.
    </p>
    <p class="flex items-center gap-2">
      <svg class="h-4 w-4 shrink-0 text-ink-400" aria-hidden="true"><use href="#i-clock"/></svg>
      Estimated wait times may change based on consultation duration.
    </p>
    <p class="flex items-center gap-2">
      <svg class="h-4 w-4 shrink-0 text-ink-400" aria-hidden="true"><use href="#i-phone"/></svg>
      In case of emergency, please contact the staff.
    </p>
  </footer>

  <p class="mt-4 flex items-center gap-2 border-t border-hairline pt-3 text-[11px] text-ink-400">
    <svg class="h-3.5 w-3.5 shrink-0" aria-hidden="true"><use href="#i-shield"/></svg>
    Tokens and rooms only · patient names are never shown on this screen
  </p>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>

</body>
</html>
