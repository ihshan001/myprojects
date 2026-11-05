<?php
$s = $snapshot;
$isCalled = $s['status'] === 'called';
$isDone = in_array($s['status'], ['completed', 'cancelled', 'no_show'], true);
$tone = match ($s['status']) {
    'called' => 'from-sky-600 to-sky-800',
    'in_consultation' => 'from-teal-600 to-teal-800',
    'completed' => 'from-emerald-600 to-emerald-800',
    'skipped', 'no_show', 'cancelled' => 'from-slate-500 to-slate-700',
    default => 'from-teal-700 to-teal-900',
};
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="<?= e($clinic['brand_color'] ?: '#0F766E') ?>">
  <meta name="robots" content="noindex, nofollow">
  <title>Token <?= e($s['token']) ?> · <?= e($clinic['name']) ?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="/assets/img/icon-192.png">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="min-h-full bg-slate-100" data-track-token="<?= e($token) ?>">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div class="mx-auto max-w-md px-4 pb-10 pt-5 safe-bottom">

  <!-- clinic header -->
  <div class="flex items-center justify-between">
    <div class="flex items-center gap-2">
      <span class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-black text-white"
            style="background: <?= e($clinic['brand_color'] ?: '#0F766E') ?>">
        <?= e(App\Core\Str::initials($clinic['name'])) ?>
      </span>
      <div class="leading-tight">
        <p class="text-sm font-bold text-slate-900"><?= e($clinic['name']) ?></p>
        <p class="text-[10px] text-slate-500"><?= e($s['queue']) ?></p>
      </div>
    </div>
    <?php $stale = false; include QP_ROOT . '/resources/views/components/live-pill.php'; ?>
  </div>

  <!-- token card -->
  <div class="mt-4 overflow-hidden rounded-2xl bg-gradient-to-br <?= $tone ?> p-6 text-white shadow-lg">
    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-white/60">Your token</p>
    <p class="mt-1 font-mono text-6xl font-black leading-none tracking-tight"><?= e($s['token']) ?></p>

    <div class="mt-4 flex flex-wrap items-center gap-2">
      <span data-track-pill class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-bold backdrop-blur">
        <?= e($s['status_label']) ?>
      </span>
      <span class="text-xs text-white/70"><?= e($s['doctor'] ?? '') ?></span>
      <?php if (!empty($s['room'])): ?>
        <span class="text-xs text-white/70">· <?= e($s['room']) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- call banner -->
  <div data-track-banner class="<?= $isCalled
      ? 'rise mt-3 rounded-xl border border-sky-300 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-900'
      : 'hidden' ?>">
    <?= $isCalled ? 'It is your turn — please go to ' . e($s['room'] ?? 'the room') . '.' : '' ?>
  </div>

  <!-- position + estimate -->
  <div class="mt-3 grid grid-cols-2 gap-3">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400" data-track-position-label>
        <?= $s['status'] === 'waiting' ? ($s['position'] === 1 ? 'You are next' : 'People ahead') : 'Status' ?>
      </p>
      <?php if ($s['status'] === 'waiting'): ?>
        <p class="mt-1 text-4xl font-black tabular text-slate-900" data-track-position><?= (int) $s['position'] ?></p>
      <?php else: ?>
        <p class="mt-1 text-2xl font-black text-slate-900" data-track-position>—</p>
      <?php endif; ?>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Estimated wait</p>
      <p class="mt-1 text-2xl font-black text-slate-900" data-track-estimate><?= e($s['estimate']['label']) ?></p>
      <p class="mt-0.5 text-[10px] leading-tight text-slate-400" data-track-confidence>
        <?= $s['estimate']['confidence'] === 'data' ? 'Based on recent visit times' : 'Estimating — we learn as the clinic runs' ?>
      </p>
    </div>
  </div>

  <p class="mt-2 flex items-center gap-1.5 text-[11px] text-slate-400">
    <svg class="h-3 w-3"><use href="#i-info"/></svg>
    A range, not a promise — emergencies can change the order.
    Updated <span data-track-updated><?= e(substr((string) $s['updated_at'], 11, 5)) ?></span>.
  </p>

  <!-- status message -->
  <div class="mt-4 rounded-2xl border border-slate-200 bg-white px-4 py-3.5 shadow-sm">
    <p class="flex items-start gap-2.5 text-[13px] leading-relaxed text-slate-700">
      <svg class="mt-0.5 h-4 w-4 shrink-0 text-teal-600"><use href="#i-megaphone"/></svg>
      <span><?= e($s['status_message']) ?></span>
    </p>
  </div>

  <!-- what happens next -->
  <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">What happens next</p>
    <ol class="mt-3 space-y-3">
      <?php foreach ([
          ['Wait until your token is called', 'Watch this page or the screen in the waiting room. Keep it open — it refreshes itself.'],
          ['Walk to the room shown', 'Your token and room appear at the top of this page when it is your turn.'],
          ['If you miss your turn', 'Speak to reception. You can be called again without losing your place in the records.'],
      ] as $i => [$title, $body]): ?>
        <li class="flex gap-3">
          <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= $i + 1 ?></span>
          <div>
            <p class="text-xs font-semibold text-slate-800"><?= e($title) ?></p>
            <p class="text-[11px] leading-relaxed text-slate-500"><?= e($body) ?></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <!-- actions -->
  <div class="mt-4 grid grid-cols-2 gap-2">
    <?php if (!empty($clinic['phone'])): ?>
      <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $clinic['phone'])) ?>" class="btn-secondary">
        <svg class="h-4 w-4"><use href="#i-phone"/></svg>
        Call clinic
      </a>
    <?php endif; ?>
    <button class="btn-secondary" data-copy="<?= e($fullLink) ?>">
      <svg class="h-4 w-4"><use href="#i-link"/></svg>
      Copy link
    </button>
  </div>

  <!-- add to home screen hint -->
  <div class="mt-4 rounded-xl bg-white/70 px-3.5 py-3 text-[11px] leading-relaxed text-slate-500 ring-1 ring-slate-200">
    <b class="text-slate-700">Tip:</b> add this page to your home screen to open it in one tap.
    No app store, no install — and it keeps working if you switch apps.
  </div>

  <div class="mt-6 flex items-center justify-center gap-2 text-[10px] text-slate-400">
    <svg class="h-3 w-3"><use href="#i-shield"/></svg>
    This page shows only your token and queue position. No medical details are stored here.
  </div>

  <div class="mt-2 text-center">
    <img src="<?= e($qr) ?>" alt="QR code for this tracking page" class="mx-auto h-20 w-20 rounded-lg bg-white p-1 ring-1 ring-slate-200">
  </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
