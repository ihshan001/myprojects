<?php
/**
 * Patient-facing live queue — the same board the lobby TV shows, optimised
 * for a phone. No login: the receptionist tells patients to open this link
 * and watch their turn online. The JS polls /api/display/{key}, the exact
 * same endpoint the TV uses, so phone and screen stay in sync.
 *
 * Privacy: token numbers and rooms only. No names, no phone numbers.
 */
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta name="robots" content="noindex">
  <meta name="theme-color" content="#0f172a">
  <title><?= e($title) ?> · <?= e($clinicRecord['name']) ?></title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
  <style>
    /* Phone-specific tweaks on top of the shared design system. */
    .phone-token { font-size: clamp(3rem, 14vw, 9rem); line-height: 1; }
    .phone-chip { font-size: 0.85rem; padding: 0.35rem 0.75rem; border-radius: 999px; }
    .phone-upcoming { font-size: 1.1rem; }
    .phone-nudge { font-size: 0.85rem; color: #64748b; margin-top: 0.25rem; }
  </style>
</head>
<body class="min-h-full bg-slate-50 text-slate-900" data-phone-board="true" data-board-key="<?= e($key) ?>">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div class="max-w-xl mx-auto px-4 py-6">

  <!-- header -->
  <header class="mb-6 flex items-center justify-between gap-3">
    <div class="flex items-center gap-2.5">
      <span class="flex h-10 w-10 items-center justify-center rounded-xl text-sm font-black text-slate-900"
            style="background: <?= e($clinicRecord['brand_color'] ?: '#14b8a6') ?>"><?= e(App\Core\Str::initials((string) $clinicRecord['name'])) ?></span>
      <div>
        <p class="text-sm font-bold leading-tight text-slate-900"><?= e($clinicRecord['name']) ?></p>
        <p class="text-[10px] uppercase tracking-[0.2em] text-slate-500"><?= $departmentName !== null ? e($departmentName) : 'Queue' ?></p>
      </div>
    </div>
    <div class="text-right">
      <p class="text-xl font-bold tabular text-slate-900" id="phone-clock"><?= e(date('h:i A')) ?></p>
      <p class="text-[10px] text-slate-500" id="phone-status">Loading&hellip;</p>
    </div>
  </header>

  <!-- now serving -->
  <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400 mb-3">Now serving</p>

    <!-- One doctor: the whole card is theirs. -->
    <div id="phone-serving-solo" class="flex flex-col items-center justify-center">
      <p id="phone-serving-token" class="phone-token font-mono font-bold text-slate-900">—</p>
      <p id="phone-serving-room" class="mt-2 font-medium text-slate-600">—</p>
      <p id="phone-serving-doctor" class="mt-1 text-sm text-slate-500"></p>
    </div>

    <!-- Several doctors: one row each. A single token on a five-room clinic
         means four rooms of patients are watching a number that is not
         theirs, which on a phone is the very thing they opened it for. -->
    <ul id="phone-serving-rooms" class="hidden divide-y divide-slate-100"></ul>
  </section>

  <!-- coming up -->
  <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex items-center justify-between mb-4">
      <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Coming up</p>
      <p class="text-[10px] text-slate-400" id="phone-waiting-count">— waiting</p>
    </div>
    <ul id="phone-upcoming-list" class="space-y-2.5">
      <!-- populated by JS -->
    </ul>
    <p id="phone-empty" class="hidden py-6 text-center text-sm text-slate-500">No one is waiting right now.</p>
  </section>

  <!-- notices: delays / pauses -->
  <section id="phone-notices" class="hidden mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
    <p class="text-sm font-bold text-amber-900" id="phone-notices-text"></p>
  </section>

  <!-- Check my position: the board says where the queue is; this says where
       YOU are in it. Same key, no sign-in — type the number on your slip. -->
  <section class="mb-6">
    <a href="/board/<?= e($key) ?>/position"
       class="flex items-center justify-center gap-2 rounded-xl bg-teal-600 px-4 py-3.5 text-[15px] font-semibold text-white shadow-sm transition hover:bg-teal-700">
      <svg class="h-4 w-4" aria-hidden="true"><use href="#i-search"/></svg>
      Check my position
    </a>
    <p class="mt-1.5 text-center phone-nudge">Know your token number? See exactly how many people are ahead of you.</p>
  </section>

  <!-- nudge -->
  <section class="rounded-xl border border-teal-200 bg-teal-50 p-4">
    <p class="text-sm text-teal-800">Your place is saved — watch this page. When your token appears under “Now serving,” head to the room shown.</p>
    <p class="mt-2 text-xs text-teal-600">If your token is called and you are not present, please speak to reception. We will call you again.</p>
  </section>

</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
