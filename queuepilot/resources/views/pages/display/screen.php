<?php
/**
 * Waiting-room TV. Dark, huge, legible from 6 metres.
 * Privacy: token + room only. No names, no specialties, no phone numbers.
 */
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
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
<body class="tv min-h-full overflow-hidden" data-display-board="<?= e($key) ?>">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div id="tv-offline" data-tv-offline class="hidden fixed inset-x-0 top-0 z-50 bg-amber-500 px-4 py-2 text-center text-xs font-bold text-amber-950">
  Connection lost — showing the last known state. This screen is NOT live.
</div>

<div class="flex min-h-screen flex-col p-6 lg:p-10">

  <header class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 pb-5">
    <div class="flex items-center gap-3">
      <span class="flex h-11 w-11 items-center justify-center rounded-xl text-base font-black text-slate-900"
            style="background: <?= e($clinicRecord['brand_color'] ?: '#14b8a6') ?>">
        <?= e(App\Core\Str::initials((string) $clinicRecord['name'])) ?>
      </span>
      <div>
        <p class="text-lg font-bold leading-tight text-white"><?= e($clinicRecord['name']) ?></p>
        <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Now serving</p>
      </div>
    </div>
    <div class="text-right">
      <p class="text-2xl font-bold tabular text-white" data-tv-clock><?= e(date('h:i A')) ?></p>
      <p class="text-[11px] text-slate-500" data-tv-counts>
        <?= count($queues) ?> queue(s) today
      </p>
    </div>
  </header>

  <div class="grid flex-1 gap-6 pt-6 lg:grid-cols-[1.35fr_1fr]">

    <!-- now serving -->
    <div class="flex flex-col rounded-3xl bg-white/[0.04] px-6 py-6">
      <p class="text-xs font-bold uppercase tracking-[0.35em] text-teal-400">Now serving</p>

      <!-- One doctor: the whole wall is theirs. -->
      <div data-tv-solo class="flex flex-1 flex-col items-center justify-center text-center">
        <p class="tv-token mt-4 text-[7rem] leading-none text-white lg:text-[10rem]" data-tv-serving data-token="">—</p>
        <p class="mt-3 text-xl font-semibold text-slate-300" data-tv-serving-room></p>
      </div>

      <!-- Several doctors: one card each. Collapsing a five-room clinic into a
           single token means four rooms of patients never see their number. -->
      <div data-tv-rooms class="mt-4 hidden flex-1 content-start gap-3 sm:grid-cols-2"></div>

      <div class="mt-6 flex items-center justify-center gap-3">
        <span class="live-pill" data-tv-live><span class="pulse-dot"><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span></span><span data-tv-live-text>Live</span></span>
        <p class="text-sm text-slate-400">Please have your token ready</p>
      </div>
    </div>

    <!-- delay / pause notices: public information, no patient detail -->
    <div class="mb-4 hidden rounded-2xl border border-amber-400/40 bg-amber-400/10 px-5 py-3" data-tv-notices>
      <p class="text-sm font-bold text-amber-200" data-tv-notices-text></p>
    </div>

    <!-- queued -->
    <div class="flex flex-col rounded-3xl bg-white/[0.03] p-5">
      <div class="flex items-center justify-between">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Coming up</p>
        <p class="text-[11px] text-slate-600">tokens only — no names shown</p>
      </div>
      <div class="mt-4 flex-1 space-y-2.5" data-tv-next>
        <?php if ($queues === []): ?>
          <p class="py-10 text-center text-sm text-slate-600">No sessions open yet.</p>
        <?php endif; ?>
      </div>

      <div class="mt-4 border-t border-white/10 pt-4">
        <p class="text-[11px] leading-relaxed text-slate-500">
          If your token is called and you are not present, please speak to reception.
          We will call you again.
        </p>
      </div>
    </div>
  </div>

  <?php if ($canRotate): ?>
    <!-- The kiosk lives here rather than under Settings because it is the same
         job as the board: a screen in the waiting room. One place to set up
         what the room sees. -->
    <div class="mt-4 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-xs font-semibold text-slate-200">Self check-in kiosk</p>
          <p class="mt-0.5 max-w-xl text-[11px] leading-relaxed text-slate-500">
            A tablet in the waiting room that issues walk-in numbers by itself. It never shows
            another patient's details, never sets a priority, and only offers sessions that are
            open today. Its code is separate from the display's — leaking the board must not let
            anyone mint tokens.
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <form method="post" action="/display/kiosk">
            <?= csrf_field() ?>
            <button class="rounded border border-teal-500/40 bg-teal-500/10 px-2.5 py-1 text-[11px] font-medium text-teal-200 hover:bg-teal-500/20">
              <?= $kioskKey === null ? 'Set up kiosk' : 'New kiosk code' ?>
            </button>
          </form>
          <?php if ($kioskKey !== null): ?>
            <form method="post" action="/display/kiosk/off" onsubmit="return confirm('Switch the kiosk off? Any tablet using it stops issuing tokens.');">
              <?= csrf_field() ?>
              <button class="rounded border border-white/15 px-2.5 py-1 text-[11px] text-slate-400 hover:text-white">Switch off</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($kioskKey !== null): ?>
        <div class="mt-3 rounded-lg bg-black/30 px-3 py-2">
          <p class="text-[10px] uppercase tracking-wide text-slate-500">Open this on the tablet</p>
          <p class="mt-0.5 break-all font-mono text-[11px] text-teal-200"><?= e(rtrim((string) \App\Core\Env::get('app.url', ''), '/')) ?>/kiosk/<?= e($kioskKey) ?></p>
        </div>
        <?php if (!empty($kioskNewKey)): ?>
          <p class="mt-1.5 text-[10px] text-amber-300">
            Newly issued — any older kiosk address has stopped working.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <footer class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-white/10 pt-4 text-[11px] text-slate-600">
    <p>Token and room only · patient details are never shown on this screen</p>
    <div class="flex items-center gap-3">
      <button type="button" id="tv-speech" class="rounded border border-white/15 px-2 py-1 text-slate-400 hover:text-white">
        Voice announcements: on
      </button>
      <?php if ($canRotate): ?>
        <form method="post" action="/display/rotate" onsubmit="return confirm('Rotate the display key? Every screen using the old link stops updating.')">
          <?= csrf_field() ?>
          <button class="rounded border border-white/15 px-2 py-1 text-slate-400 hover:text-white">Rotate key</button>
        </form>
      <?php endif; ?>
      <a href="/app" class="text-slate-500 hover:text-white">Exit display</a>
    </div>
  </footer>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script>
  // Voice announcements toggle (persisted; some clinics prefer silence).
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
  })();
</script>
</body>
</html>
