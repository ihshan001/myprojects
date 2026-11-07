<?php
/**
 * "Where do I stand?" — one patient, one queue.
 *
 * For someone who has stepped outside the clinic. They want two answers:
 * is my queue moving, and how far down am I. They do not want every other
 * doctor's list, so this page never renders one.
 *
 * Read-only on purpose. The token label is printed on paper and called out
 * loud, so this page must not hand back the private /t/{track_token} link
 * that can cancel or reschedule the visit.
 *
 * @var array $clinicRecord, $rooms
 * @var array|null $result
 * @var bool $solo
 */
$brand = $clinicRecord['brand_color'] ?: '#0D7459';
$p = ($result['ok'] ?? false) ? $result['position'] : null;
$ahead = $p !== null ? max(0, (int) $p['position'] - 1) : 0;
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Where do I stand? · <?= e($clinicRecord['name']) ?></title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="patient-view min-h-full bg-canvas text-ink-900"
      <?= ($p !== null && in_array($p['status'], ['waiting', 'called'], true)) ? 'data-position-auto="30"' : '' ?>>
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div class="mx-auto w-full max-w-md px-4 py-6 sm:py-10">

  <div class="flex items-center gap-3">
    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white"
          style="background-image: linear-gradient(145deg, <?= e($brand) ?>, <?= e($brand) ?>CC)">
      <svg class="h-5 w-5" aria-hidden="true"><use href="#i-queue"/></svg>
    </span>
    <div class="min-w-0">
      <p class="truncate text-[15px] font-bold"><?= e($clinicRecord['name']) ?></p>
      <p class="truncate text-[12px] text-ink-500"><?= e($departmentName ?? 'Queue position') ?></p>
    </div>
  </div>

  <?php if ($p !== null): ?>
    <!-- ---------------------------------------------------------- answer -->
    <div class="card mt-5 overflow-hidden">
      <div class="px-5 py-6 text-center text-white"
           style="background-image: linear-gradient(135deg, <?= e($brand) ?>, color-mix(in srgb, <?= e($brand) ?> 62%, #000))">
        <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-white/75">Your token</p>
        <p class="mt-1 text-[56px] font-extrabold leading-none tracking-tight tabular"><?= e($p['token']) ?></p>
        <p class="mt-3 text-[13px] text-white/85"><?= e($p['status_label']) ?></p>
      </div>

      <div class="px-5 py-5">
        <?php if ($p['status'] === 'called'): ?>
          <p class="rounded-xl bg-brand-50 px-4 py-3 text-center text-[15px] font-bold text-brand-800">
            It's your turn. Please go to <?= e($p['room'] ?? 'the consultation room') ?> now.
          </p>
        <?php elseif ($p['status'] === 'waiting'): ?>
          <p class="text-center text-[22px] font-extrabold text-ink-900">
            <?= $ahead === 0 ? 'You are next' : $ahead . ' ' . ($ahead === 1 ? 'person' : 'people') . ' ahead of you' ?>
          </p>
          <p class="mt-1 text-center text-[13px] text-ink-500">
            <?= e($p['estimate']['label'] ?? '') ?><?= ($p['estimate']['confidence'] ?? '') === 'estimating' ? ' (rough guide)' : '' ?>
          </p>
        <?php elseif ($p['status'] === 'in_consultation'): ?>
          <p class="text-center text-[17px] font-bold text-ink-900">You're with the doctor now.</p>
        <?php elseif ($p['status'] === 'completed'): ?>
          <p class="text-center text-[17px] font-bold text-ink-900">Your visit is complete. Get well soon.</p>
        <?php elseif (in_array($p['status'], ['skipped', 'no_show'], true)): ?>
          <p class="text-center text-[15px] font-semibold text-ink-900">
            You were marked absent. Please speak to reception to rejoin the queue.
          </p>
        <?php endif; ?>

        <?php if (!empty($p['paused'])): ?>
          <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] font-medium text-amber-900">
            This queue is temporarily paused. The clinic will resume shortly.
          </p>
        <?php endif; ?>

        <!-- Is it moving? Only this queue, never another doctor's. -->
        <div class="mt-5 grid grid-cols-2 gap-3">
          <div class="rounded-xl bg-canvas px-3 py-3 text-center">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Now serving</p>
            <p class="mt-1 text-[24px] font-extrabold leading-none tabular text-ink-900"><?= e($p['now_serving'] ?? '—') ?></p>
          </div>
          <div class="rounded-xl bg-canvas px-3 py-3 text-center">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Your position</p>
            <p class="mt-1 text-[24px] font-extrabold leading-none tabular text-ink-900">
              <?= in_array($p['status'], ['waiting', 'called'], true) ? (int) $p['position'] : '—' ?>
            </p>
          </div>
        </div>

        <dl class="mt-5 space-y-2 border-t border-hairline pt-4 text-[13px]">
          <div class="flex justify-between gap-3">
            <dt class="text-ink-500">Doctor</dt>
            <dd class="truncate font-semibold text-ink-900"><?= e($p['doctor'] ?? $p['queue']) ?></dd>
          </div>
          <?php if (!empty($p['room'])): ?>
            <div class="flex justify-between gap-3">
              <dt class="text-ink-500">Room</dt>
              <dd class="truncate font-semibold text-ink-900"><?= e($p['room']) ?></dd>
            </div>
          <?php endif; ?>
        </dl>

        <?php if (in_array($p['status'], ['waiting', 'called'], true)): ?>
          <p class="mt-4 text-center text-[11px] text-ink-400">This page refreshes itself every 30 seconds.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="mt-4 flex gap-2">
      <a href="/board/<?= e($key) ?>/position" class="btn-secondary flex-1">Check another token</a>
      <?php if (!empty($p['clinic_phone'])): ?>
        <a href="tel:<?= e($p['clinic_phone']) ?>" class="btn-primary flex-1">Call the clinic</a>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- ------------------------------------------------------------ ask -->
    <h1 class="mt-6 text-[22px] font-extrabold leading-tight">Where do I stand?</h1>
    <p class="mt-1.5 text-[13px] leading-relaxed text-ink-500">
      Enter the token number on your slip. You'll see how far down the queue you are
      and which token is being seen now — just for your own doctor.
    </p>

    <?php if (!empty($result['error'])): ?>
      <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-[13px] font-medium text-rose-800">
        <?= e($result['error']) ?>
      </p>
    <?php endif; ?>

    <?php if ($rooms === []): ?>
      <p class="mt-5 rounded-xl border border-hairline bg-white px-4 py-5 text-center text-[13px] text-ink-500">
        No queue is open right now. Please ask reception when the next session starts.
      </p>
    <?php else: ?>
      <form method="get" action="/board/<?= e($key) ?>/position" class="card mt-5 px-4 py-5">
        <?php if ($solo): ?>
          <!-- One doctor: asking which one would be a question with one answer. -->
          <input type="hidden" name="queue" value="<?= (int) $rooms[0]['queue_id'] ?>">
          <p class="label">Doctor</p>
          <p class="text-[14px] font-semibold text-ink-900"><?= e($rooms[0]['doctor'] ?? $rooms[0]['queue']) ?></p>
          <?php if (!empty($rooms[0]['specialty'])): ?>
            <p class="text-[12px] text-ink-500"><?= e($rooms[0]['specialty']) ?></p>
          <?php endif; ?>
        <?php else: ?>
          <label class="label" for="queue">Which doctor are you seeing?</label>
          <select class="select" id="queue" name="queue" required>
            <option value="">Choose your doctor</option>
            <?php foreach ($rooms as $r): ?>
              <option value="<?= (int) $r['queue_id'] ?>" <?= $queueId === (int) $r['queue_id'] ? 'selected' : '' ?>>
                <?= e($r['doctor'] ?? $r['queue']) ?><?= !empty($r['room']) ? ' · ' . e($r['room']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <label class="label mt-4" for="token">Your token number</label>
        <input class="input text-[18px] font-bold uppercase tabular" id="token" name="token" required
               maxlength="16" autocomplete="off" autocapitalize="characters" spellcheck="false"
               placeholder="A005" value="<?= e($token) ?>">
        <p class="field-help">It's printed at the top of your slip.</p>

        <button class="btn-primary mt-4 w-full" type="submit">Show my position</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <a href="/board/<?= e($key) ?>" class="mt-5 inline-flex min-h-[36px] items-center gap-2 text-[13px] font-semibold text-ink-500 hover:text-ink-900">
    <svg class="h-4 w-4" aria-hidden="true"><use href="#i-monitor"/></svg>
    See the whole waiting-room board
  </a>

  <p class="mt-4 flex items-start gap-2 text-[11px] leading-relaxed text-ink-400">
    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true"><use href="#i-shield"/></svg>
    This page shows only your own queue. It never shows patient names, and it cannot
    change or cancel your visit.
  </p>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>

</body>
</html>
