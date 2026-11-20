<?php
/**
 * Waiting-room board — the light board on the wall.
 *
 * Serves two audiences from one template:
 *   /display        staff preview, $canRotate true  -> setup controls shown
 *   /board/{key}    the screen on the wall, $publicScreen true -> no controls,
 *                   because anyone in the room can reach that URL.
 *
 * The board itself lives in partials/waiting-board.php so /live/{key} renders
 * exactly the same thing.
 */
$isPublic = !empty($publicScreen);
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

  <?php if ($canRotate): ?>
    <!-- The kiosk lives here rather than under Settings because it is the same
         job as the board: a screen in the waiting room. Staff-only; a public
         board must never offer to mint tokens. -->
    <div class="mt-6 rounded-2xl border border-hairline bg-canvas px-4 py-3.5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-xs font-semibold text-ink-800">Self check-in kiosk</p>
          <p class="mt-1 max-w-xl text-[11px] leading-relaxed text-ink-500">
            A tablet in the waiting room that issues walk-in numbers by itself. It never shows
            another patient's details, never sets a priority, and only offers sessions that are
            open today. Its code is separate from the display's — leaking the board must not let
            anyone mint tokens.
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <form method="post" action="/display/kiosk">
            <?= csrf_field() ?>
            <button class="rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-[11px] font-semibold text-brand-700 hover:bg-brand-100">
              <?= $kioskKey === null ? 'Set up kiosk' : 'New kiosk code' ?>
            </button>
          </form>
          <?php if ($kioskKey !== null): ?>
            <form method="post" action="/display/kiosk/off" data-confirm="Switch the kiosk off? Any tablet using it stops issuing tokens.">
              <?= csrf_field() ?>
              <button class="wr-btn">Switch off</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($kioskKey !== null): ?>
        <div class="mt-3 rounded-xl border border-hairline bg-white px-3 py-2.5">
          <p class="text-[10px] uppercase tracking-wide text-ink-400">Open this on the tablet</p>
          <p class="mt-1 break-all font-mono text-[11px] text-brand-700"><?= e(rtrim((string) \App\Core\Env::get('app.url', ''), '/')) ?>/kiosk/<?= e($kioskKey) ?></p>
        </div>
        <?php if (!empty($kioskNewKey)): ?>
          <p class="mt-2 text-[10px] text-amber-700">
            Newly issued — any older kiosk address has stopped working.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

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

  <!-- Staff controls sit below the patient-facing footer and are never sent
       to the public board at all. -->
  <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-hairline pt-3 text-[11px] text-ink-400">
    <p class="flex items-center gap-2">
      <svg class="h-3.5 w-3.5 shrink-0" aria-hidden="true"><use href="#i-shield"/></svg>
      Tokens and rooms only · patient names are never shown on this screen
    </p>
    <div class="flex items-center gap-2">
      <button type="button" id="tv-speech" class="wr-btn">Voice announcements: on</button>
      <button type="button" id="tv-full" class="wr-btn">Full screen</button>
      <?php if ($canRotate): ?>
        <form method="post" action="/display/rotate" data-confirm="Rotate the display key? Every screen using the old link stops updating.">
          <?= csrf_field() ?>
          <button class="wr-btn">Rotate key</button>
        </form>
      <?php endif; ?>
      <?php if (!$isPublic): ?>
        <a href="/app" class="wr-btn">Exit display</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>

</body>
</html>
