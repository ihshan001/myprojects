<?php
/**
 * The waiting-room board, shared by every route that shows it:
 *   /display        staff preview
 *   /board/{key}    the screen on the wall
 *   /live/{key}     the older public link
 *
 * Two layouts, because a solo practice is not a scaled-down group practice:
 *   solo  — one room gets the whole wall: a huge token, then its own up-next
 *           list. A grid of one card beside a hero saying the same thing twice
 *           is wasted wall.
 *   multi — every room is an EQUAL card with an equally large token. Singling
 *           one doctor out as a hero left everyone else's patients squinting at
 *           a small print, which is the opposite of what a queue board is for.
 *
 * Privacy: a token, its room, the doctor and the doctor's own specialty. Never
 * a patient name. This is an unauthenticated URL anyone can photograph.
 *
 * @var array $clinicRecord, $boardRooms, $boardStats, $servingRooms
 * @var string|null $departmentName
 * @var string $key
 */
$brand = $clinicRecord['brand_color'] ?: '#0D7459';
$rooms = $boardRooms ?? [];
$stats = $boardStats ?? ['waiting' => 0, 'completed' => 0];
$solo = count($rooms) === 1;
$only = $solo ? $rooms[0] : null;
// Full literal class names: Tailwind drops anything it cannot find in source.
$accents = ['wr-green', 'wr-blue', 'wr-violet', 'wr-orange', 'wr-slate'];
$positionUrl = '/board/' . $key . '/position';
$positionLink = rtrim((string) \App\Core\Env::get('app.url', ''), '/') . $positionUrl;
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
  <div class="flex items-center gap-3 sm:gap-5">
    <a href="<?= e($positionUrl) ?>" class="wr-cta">
      <svg class="h-4 w-4 shrink-0" aria-hidden="true"><use href="#i-search"/></svg>
      Check my position
    </a>
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

<div data-tv-layout data-solo="<?= $solo ? '1' : '0' ?>">
<?php if ($rooms === []): ?>
  <div class="mt-6">
    <h1 class="wr-eyebrow">Now serving</h1>
    <div class="wr-empty mt-3">
      <svg class="h-9 w-9 text-ink-300" aria-hidden="true"><use href="#i-clock"/></svg>
      <p class="mt-4 text-lg font-bold text-ink-700 sm:text-xl">No session is open right now</p>
      <p class="mt-1.5 max-w-sm text-[13px] leading-relaxed text-ink-500">
        Please ask reception when the next session starts.
      </p>
    </div>
  </div>

<?php elseif ($solo): ?>
  <!-- ------------------------------------------------------ one doctor -->
  <div class="mt-6 grid gap-5 xl:grid-cols-[1.55fr_1fr] xl:gap-6">
    <section class="flex min-w-0 flex-col">
      <h1 class="wr-eyebrow">Now serving</h1>
      <div class="mt-3 flex-1" data-tv-hero data-brand="<?= e($brand) ?>">
        <?php if (!empty($only['token'])): ?>
          <div class="wr-hero" data-token="<?= e($only['token']) ?>"
               style="background-image: linear-gradient(135deg, <?= e($brand) ?>, color-mix(in srgb, <?= e($brand) ?> 62%, #000))">
            <p class="wr-hero-room"><?= e($only['room']) ?></p>
            <p class="wr-hero-token"><?= e($only['token']) ?></p>
            <p class="wr-hero-doc"><?= e($only['doctor'] ?? $only['queue']) ?></p>
            <?php if (!empty($only['specialty'])): ?>
              <p class="wr-hero-spec">
                <svg class="h-4 w-4 shrink-0 sm:h-5 sm:w-5" aria-hidden="true"><use href="#i-stethoscope"/></svg>
                <?= e($only['specialty']) ?>
              </p>
            <?php endif; ?>
            <p class="mt-3 text-[12px] font-semibold uppercase tracking-[0.14em] text-white/70 sm:text-[13px]">
              <?= ($only['status'] ?? '') === 'called' ? 'Please proceed to the room' : 'In consultation' ?>
            </p>
          </div>
        <?php else: ?>
          <div class="wr-empty">
            <svg class="h-9 w-9 text-ink-300" aria-hidden="true"><use href="#i-clock"/></svg>
            <p class="mt-4 text-lg font-bold text-ink-700 sm:text-xl">No token called yet</p>
            <p class="mt-1.5 max-w-sm text-[13px] leading-relaxed text-ink-500">
              Take a number at reception or on the tablet, then watch this screen.
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

  <!-- The single queue's own list, given the width the grid would have wasted. -->
  <section class="wr-card mt-5" data-tv-solo-next
           style="--wr: <?= e($brand) ?>; --wr-bg: color-mix(in srgb, <?= e($brand) ?> 9%, #fff)">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <p class="wr-card-label">Up next</p>
      <p class="text-[12px] text-ink-600 sm:text-[13px]" data-tv-solo-count><?= (int) $only['waiting'] ?> waiting</p>
    </div>
    <?php if (!empty($only['up_next'])): ?>
      <div class="wr-chips mt-2" data-tv-solo-chips>
        <?php foreach (array_slice($only['up_next'], 0, 6) as $u): ?>
          <span class="wr-chip wr-chip-lg"><?= e($u['token']) ?></span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="mt-2 text-[13px] text-ink-400">No one waiting.</p>
    <?php endif; ?>
  </section>

<?php else: ?>
  <!-- --------------------------------------------------- several doctors -->
  <!-- Every room the same size. A called room is ringed, not enlarged, so no
       doctor's patients get a smaller token than anyone else's. -->
  <section class="mt-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h1 class="wr-eyebrow">Now serving · all rooms</h1>
      <p class="text-[11px] text-ink-500 sm:text-[12px]">Find your doctor, then watch that column</p>
    </div>
    <div class="wr-cards mt-3" data-tv-cards>
      <?php foreach ($rooms as $i => $room): ?>
        <?php $called = ($room['status'] ?? '') === 'called'; ?>
        <div class="wr-card <?= e($accents[$i % count($accents)]) ?> <?= $called ? 'wr-card-called' : '' ?>"
             data-room="<?= e($room['room']) ?>">
          <div class="flex items-start justify-between gap-2">
            <p class="wr-card-room min-w-0 truncate"><?= e($room['room']) ?></p>
            <?php if (!empty($room['paused'])): ?>
              <span class="stale-pill shrink-0">Paused</span>
            <?php elseif ($called): ?>
              <span class="wr-call-pill shrink-0">CALLED</span>
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
            <p class="mt-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-400">
              <?= $called ? 'Please proceed' : 'In consultation' ?>
            </p>
          <?php else: ?>
            <p class="wr-card-idle">—</p>
            <p class="mt-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-400">Not yet called</p>
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
    </div>
  </section>
<?php endif; ?>
</div>

<!-- Stepped outside? Take your own queue with you. -->
<div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-hairline bg-canvas px-4 py-4 sm:px-5">
  <div class="flex min-w-0 items-center gap-4">
    <img class="h-20 w-20 shrink-0 rounded-lg bg-white p-1" width="80" height="80"
         src="<?= e(\App\Support\QrCode::dataUri($positionLink, 3, '#0f172a', '#ffffff')) ?>"
         alt="QR code to check your own queue position">
    <div class="min-w-0">
      <p class="text-[13px] font-bold text-ink-900 sm:text-[15px]">Stepping outside? Check your own queue.</p>
      <p class="mt-1 max-w-md text-[11.5px] leading-relaxed text-ink-500 sm:text-[12.5px]">
        Scan this, or tap the button, and enter the token on your slip. You'll see which token
        is being seen now and how many are ahead of you — for your doctor only.
      </p>
    </div>
  </div>
  <a href="<?= e($positionUrl) ?>" class="btn-primary shrink-0">
    <svg class="h-4 w-4" aria-hidden="true"><use href="#i-search"/></svg>
    Check my position
  </a>
</div>
