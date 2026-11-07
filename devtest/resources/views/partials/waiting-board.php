<?php
/**
 * The waiting-room board itself, shared by every route that shows it:
 *   /display        staff preview
 *   /board/{key}    the screen on the wall
 *   /live/{key}     the older public link
 *
 * One template because these three drifted before: two were rebuilt and the
 * third stayed on the old markup with a poller CSP had already disabled.
 *
 * Privacy: a token, its room, the doctor and the doctor's own specialty.
 * Never a patient name. "Up next" is tokens only — this screen is on an
 * unauthenticated URL that anyone in the room can photograph.
 *
 * @var array $clinicRecord, $boardRooms, $boardStats, $servingRooms
 * @var string|null $departmentName
 */
$brand = $clinicRecord['brand_color'] ?: '#0D7459';
$rooms = $boardRooms ?? [];
$stats = $boardStats ?? ['waiting' => 0, 'completed' => 0];
// The hero promotes a call the patient must act on over a consultation
// already behind the door. Every room still has its own card below.
$hero = null;
foreach (($servingRooms ?? []) as $r) {
    if (($r['status'] ?? '') === 'called') { $hero = $r; break; }
    if ($hero === null) { $hero = $r; }
}
// Full literal class names: Tailwind drops anything it cannot find in source.
$accents = ['wr-green', 'wr-blue', 'wr-violet', 'wr-orange', 'wr-slate'];
?>

<header class="wr-head">
  <div class="flex min-w-0 items-center gap-3 sm:gap-4">
    <span class="wr-mark" style="background-image: linear-gradient(145deg, <?= e($brand) ?>, <?= e($brand) ?>CC)">
      <svg class="h-6 w-6" aria-hidden="true"><use href="#i-queue"/></svg>
    </span>
    <div class="min-w-0">
      <p class="wr-title"><?= e($clinicRecord['name']) ?></p>
      <p class="wr-tagline"><?= e($departmentName ?? 'Smart Queue. Better Care.') ?></p>
    </div>
  </div>
  <div class="flex items-center gap-4 sm:gap-6">
    <span class="live-pill" data-tv-live>
      <span class="pulse-dot"><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span></span>
      <span data-tv-live-text>LIVE</span>
    </span>
    <div class="text-right">
      <p class="wr-clock" data-tv-clock><?= e(date('h:i A')) ?></p>
      <p class="mt-1 text-[12px] text-ink-500 sm:text-[14px]" data-tv-counts><?= (int) $stats['waiting'] ?> waiting</p>
    </div>
  </div>
</header>

<!-- Announced delays and pauses: the clinic talking to the room. -->
<div class="mt-5 hidden rounded-2xl border border-amber-200 bg-amber-50 px-5 py-3.5" data-tv-notices>
  <div class="flex items-start gap-3">
    <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" aria-hidden="true"><use href="#i-alert"/></svg>
    <p class="text-sm font-semibold leading-relaxed text-amber-900 sm:text-base" data-tv-notices-text></p>
  </div>
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-[1.55fr_1fr] xl:gap-6">
  <section class="flex min-w-0 flex-col">
    <h1 class="wr-eyebrow">Now serving</h1>
    <div class="mt-3 flex-1" data-tv-hero data-brand="<?= e($brand) ?>">
      <?php if ($hero !== null): ?>
        <div class="wr-hero" data-token="<?= e($hero['token']) ?>"
             style="background-image: linear-gradient(135deg, <?= e($brand) ?>, color-mix(in srgb, <?= e($brand) ?> 62%, #000))">
          <p class="wr-hero-room"><?= e($hero['room']) ?></p>
          <p class="wr-hero-token"><?= e($hero['token']) ?></p>
          <p class="wr-hero-doc"><?= e($hero['doctor'] ?? $hero['queue']) ?></p>
          <?php if (!empty($hero['specialty'])): ?>
            <p class="wr-hero-spec">
              <svg class="h-4 w-4 shrink-0 sm:h-5 sm:w-5" aria-hidden="true"><use href="#i-stethoscope"/></svg>
              <?= e($hero['specialty']) ?>
            </p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="wr-empty">
          <svg class="h-9 w-9 text-ink-300" aria-hidden="true"><use href="#i-clock"/></svg>
          <p class="mt-4 text-lg font-bold text-ink-700 sm:text-xl">No token called yet</p>
          <p class="mt-1.5 max-w-sm text-[13px] leading-relaxed text-ink-500">
            Take a number at reception or on the tablet, then watch this screen for your token.
          </p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="flex min-w-0 flex-col">
    <h2 class="wr-eyebrow">Please watch for your token</h2>
    <div class="wr-aside mt-3 flex-1">
      <img src="<?= e(asset('img/waiting-room.png')) ?>" alt="" width="760" height="507" loading="lazy">
      <p class="wr-aside-text">Please be seated. We'll call your token when it's your turn.</p>
    </div>
  </section>
</div>

<section class="mt-7">
  <h2 class="wr-eyebrow">All doctors</h2>
  <div class="wr-cards mt-3" data-tv-cards>
    <?php if ($rooms === []): ?>
      <p class="col-span-full rounded-2xl border border-dashed border-hairline bg-canvas px-5 py-8 text-center text-[13px] text-ink-500">
        No session is open right now. Please ask reception when the next one starts.
      </p>
    <?php else: ?>
      <?php foreach ($rooms as $i => $room): ?>
        <div class="wr-card <?= e($accents[$i % count($accents)]) ?>" data-room="<?= e($room['room']) ?>">
          <div class="flex items-start justify-between gap-2">
            <p class="wr-card-room min-w-0 truncate"><?= e($room['room']) ?></p>
            <?php if (!empty($room['paused'])): ?>
              <span class="stale-pill shrink-0">Paused</span>
            <?php else: ?>
              <span class="live-pill shrink-0"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>LIVE</span>
            <?php endif; ?>
          </div>
          <p class="wr-card-doc"><?= e($room['doctor'] ?? $room['queue']) ?></p>
          <?php if (!empty($room['specialty'])): ?>
            <p class="wr-card-spec"><?= e($room['specialty']) ?></p>
          <?php endif; ?>

          <div class="wr-rule"></div>

          <p class="wr-card-label">Now serving</p>
          <?php if (!empty($room['token'])): ?>
            <p class="wr-card-token" data-token="<?= e($room['token']) ?>"><?= e($room['token']) ?></p>
          <?php else: ?>
            <p class="wr-card-idle">—</p>
          <?php endif; ?>

          <p class="wr-card-label mt-4">Up next</p>
          <?php if (!empty($room['up_next'])): ?>
            <div class="wr-chips">
              <?php foreach (array_slice($room['up_next'], 0, 6) as $u): ?>
                <span class="wr-chip"><?= e($u['token']) ?></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="mt-1.5 text-[12px] text-ink-400">No one waiting</p>
          <?php endif; ?>

          <p class="wr-card-foot mt-4"><?= (int) $room['waiting'] ?> waiting</p>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
