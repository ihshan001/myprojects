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

<div id="tv-offline" class="hidden fixed inset-x-0 top-0 z-50 bg-amber-500 px-4 py-2 text-center text-xs font-bold text-amber-950">
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
    <div class="flex flex-col items-center justify-center rounded-3xl bg-white/[0.04] px-6 py-10 text-center">
      <p class="text-xs font-bold uppercase tracking-[0.35em] text-teal-400">Now serving</p>
      <p class="tv-token mt-4 text-[7rem] leading-none text-white lg:text-[10rem]" data-tv-serving data-token="">—</p>
      <div class="mt-6 flex items-center gap-3">
        <span class="live-pill"><span class="pulse-dot"><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span></span>Live</span>
        <p class="text-sm text-slate-400">Please have your token ready</p>
      </div>
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
