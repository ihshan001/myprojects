<?php
/**
 * Waiting-room TV. Dark, huge, legible from 6 metres.
 * Privacy: token + room + doctor only. Never a patient name, specialty,
 * phone number or reason for visit.
 *
 * Serves two audiences from one template:
 *   /display        staff preview, $canRotate true  -> setup controls shown
 *   /board/{key}    the screen on the wall, $publicScreen true -> no controls,
 *                   because anyone in the room can reach that URL.
 */
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isPublic = !empty($publicScreen);
$brand = $clinicRecord['brand_color'] ?: '#0D7459';
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
<body class="tv min-h-full" data-display-board="<?= e($key) ?>">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div id="tv-offline" data-tv-offline class="hidden fixed inset-x-0 top-0 z-50 bg-amber-500 px-4 py-2 text-center text-xs font-bold text-amber-950">
  Connection lost — showing the last known state. This screen is NOT live.
</div>

<div class="mx-auto flex min-h-screen max-w-[110rem] flex-col p-5 sm:p-8 lg:p-10 xl:h-screen xl:min-h-0 xl:overflow-hidden">

  <header class="tv-head">
    <div class="flex min-w-0 items-center gap-3 sm:gap-4">
      <span class="tv-mark" style="background: linear-gradient(145deg, <?= e($brand) ?>, <?= e($brand) ?>DD)">
        <?= e(App\Core\Str::initials((string) $clinicRecord['name'])) ?>
      </span>
      <div class="min-w-0">
        <p class="truncate text-lg font-bold leading-tight sm:text-2xl"><?= e($clinicRecord['name']) ?></p>
        <p class="tv-eyebrow mt-1"><?= e($departmentName ?? 'Waiting room') ?></p>
      </div>
    </div>
    <div class="flex items-center gap-4 sm:gap-6">
      <span class="live-pill" data-tv-live><span class="pulse-dot"><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span></span><span data-tv-live-text>Live</span></span>
      <div class="text-right">
        <p class="tv-token text-2xl leading-none sm:text-4xl" data-tv-clock><?= e(date('h:i A')) ?></p>
        <p class="mt-1.5 text-[11px] text-white/45 sm:text-[13px]" data-tv-counts>
          <?= count($queues) ?> session(s) open today
        </p>
      </div>
    </div>
  </header>

  <!-- delay / pause notices: the clinic talking to the room -->
  <div class="mt-5 hidden rounded-2xl border border-amber-400/40 bg-amber-400/10 px-5 py-3.5" data-tv-notices>
    <div class="flex items-start gap-3">
      <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-300" aria-hidden="true"><use href="#i-alert"/></svg>
      <p class="text-sm font-semibold leading-relaxed text-amber-100 sm:text-base" data-tv-notices-text></p>
    </div>
  </div>

  <div class="mt-6 grid flex-1 gap-6 xl:min-h-0 xl:grid-cols-[1.5fr_1fr] xl:gap-8">

    <!-- now serving: one tile per room, because a clinic calls several at once -->
    <section class="flex flex-col">
      <div class="flex items-baseline justify-between gap-3">
        <h1 class="tv-eyebrow">Now serving</h1>
        <p class="text-[11px] text-white/35">Please watch for your token</p>
      </div>

      <div class="mt-4 flex-1" data-tv-rooms-wrap>
        <div class="tv-rooms" data-tv-rooms>
          <!-- Server-rendered so the wall is never blank before the first poll.
               app.js replaces this with the same markup on every refresh. -->
          <?php if (!empty($servingRooms)): ?>
            <?php foreach ($servingRooms as $room): ?>
              <?php $isCall = ($room['status'] ?? '') === 'called'; ?>
              <div class="tv-room <?= $isCall ? 'tv-room-call' : 'tv-room-busy' ?>" data-token="<?= e($room['token']) ?>">
                <div>
                  <p class="tv-eyebrow"><?= e($room['room']) ?></p>
                  <p class="tv-room-token mt-2"><?= e($room['token']) ?></p>
                </div>
                <div>
                  <p class="tv-room-meta"><?= e($room['doctor'] ?? $room['queue']) ?></p>
                  <p class="tv-room-sub"><?= $isCall ? 'Please proceed to the room' : 'In consultation' ?></p>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="tv-empty" data-tv-empty>
              <svg class="h-9 w-9 text-white/25" aria-hidden="true"><use href="#i-clock"/></svg>
              <p class="mt-4 text-lg font-bold text-white/70 sm:text-xl">No token called yet</p>
              <p class="mt-1.5 max-w-sm text-[13px] leading-relaxed text-white/40">
                <?= $queues === []
                    ? 'No session is open right now. Please ask reception when the next session starts.'
                    : 'Take a number at reception or on the tablet, then watch this screen for your token.' ?>
              </p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- coming up -->
    <section class="tv-panel flex flex-col xl:min-h-0">
      <div class="flex items-baseline justify-between gap-3">
        <h2 class="tv-eyebrow">Coming up</h2>
        <p class="text-[11px] text-white/30">tokens only — no names</p>
      </div>

      <div class="mt-4 grid flex-1 content-start gap-2.5 sm:grid-cols-2 xl:min-h-0 xl:grid-cols-1 xl:overflow-hidden" data-tv-next>
        <?php if (!empty($upcomingTokens)): ?>
          <?php foreach (array_slice($upcomingTokens, 0, 6) as $u): ?>
            <div class="tv-chip">
              <span class="tv-chip-token"><?= e($u['token']) ?></span>
              <span class="min-w-0">
                <span class="block truncate text-[13px] font-semibold text-white/75"><?= e($u['room']) ?></span>
                <span class="block truncate text-[11px] text-white/40"><?= e($u['doctor'] ?? $u['queue']) ?></span>
              </span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="py-8 text-center text-[13px] text-white/35" data-tv-next-empty>No one waiting.</p>
        <?php endif; ?>
      </div>

      <div class="mt-5 border-t border-white/10 pt-4">
        <p class="text-[11px] leading-relaxed text-white/40 sm:text-[12px]">
          Missed your call? Speak to reception — you can be called again without losing your place.
        </p>
      </div>
    </section>
  </div>

  <?php if ($canRotate): ?>
    <!-- The kiosk lives here rather than under Settings because it is the same
         job as the board: a screen in the waiting room. Staff-only; a public
         board must never offer to mint tokens. -->
    <div class="mt-6 rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-3.5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-xs font-semibold text-white/85">Self check-in kiosk</p>
          <p class="mt-1 max-w-xl text-[11px] leading-relaxed text-white/40">
            A tablet in the waiting room that issues walk-in numbers by itself. It never shows
            another patient's details, never sets a priority, and only offers sessions that are
            open today. Its code is separate from the display's — leaking the board must not let
            anyone mint tokens.
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <form method="post" action="/display/kiosk">
            <?= csrf_field() ?>
            <button class="rounded-lg border border-brand-400/40 bg-brand-500/15 px-3 py-1.5 text-[11px] font-semibold text-brand-100 hover:bg-brand-500/25">
              <?= $kioskKey === null ? 'Set up kiosk' : 'New kiosk code' ?>
            </button>
          </form>
          <?php if ($kioskKey !== null): ?>
            <form method="post" action="/display/kiosk/off" onsubmit="return confirm('Switch the kiosk off? Any tablet using it stops issuing tokens.');">
              <?= csrf_field() ?>
              <button class="tv-btn">Switch off</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($kioskKey !== null): ?>
        <div class="mt-3 rounded-xl bg-black/30 px-3 py-2.5">
          <p class="text-[10px] uppercase tracking-wide text-white/35">Open this on the tablet</p>
          <p class="mt-1 break-all font-mono text-[11px] text-brand-200"><?= e(rtrim((string) \App\Core\Env::get('app.url', ''), '/')) ?>/kiosk/<?= e($kioskKey) ?></p>
        </div>
        <?php if (!empty($kioskNewKey)): ?>
          <p class="mt-2 text-[10px] text-amber-300">
            Newly issued — any older kiosk address has stopped working.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <footer class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-white/10 pt-4 text-[11px] text-white/35">
    <p class="flex items-center gap-2">
      <svg class="h-3.5 w-3.5 shrink-0" aria-hidden="true"><use href="#i-shield"/></svg>
      Token and room only · patient details are never shown on this screen
    </p>
    <div class="flex items-center gap-2">
      <button type="button" id="tv-speech" class="tv-btn">Voice announcements: on</button>
      <button type="button" id="tv-full" class="tv-btn">Full screen</button>
      <?php if ($canRotate): ?>
        <form method="post" action="/display/rotate" onsubmit="return confirm('Rotate the display key? Every screen using the old link stops updating.')">
          <?= csrf_field() ?>
          <button class="tv-btn">Rotate key</button>
        </form>
      <?php endif; ?>
      <?php if (!$isPublic): ?>
        <a href="/app" class="tv-btn">Exit display</a>
      <?php endif; ?>
    </div>
  </footer>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script>
  // Voice toggle and full screen are local to this browser, so they are safe
  // on a public board: they change nothing about the clinic's data.
  (function () {
    var btn = document.getElementById('tv-speech');
    var on = localStorage.getItem('qp_speech') !== 'off';
    function render() { btn.textContent = 'Voice announcements: ' + (on ? 'on' : 'off'); }
    btn.addEventListener('click', function () {
      on = !on;
      localStorage.setItem('qp_speech', on ? 'on' : 'off');
      render();
    });
    render();

    var full = document.getElementById('tv-full');
    if (!document.documentElement.requestFullscreen) {
      full.remove();
    } else {
      full.addEventListener('click', function () {
        if (document.fullscreenElement) { document.exitFullscreen(); }
        else { document.documentElement.requestFullscreen().catch(function () {}); }
      });
      document.addEventListener('fullscreenchange', function () {
        full.textContent = document.fullscreenElement ? 'Exit full screen' : 'Full screen';
      });
    }
  })();
</script>
</body>
</html>
