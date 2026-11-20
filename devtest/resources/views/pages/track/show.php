<?php
$s = $snapshot;
$isCalled = $s['status'] === 'called';
$isDone = in_array($s['status'], ['completed', 'cancelled', 'no_show'], true);
// Same palette as the console, so the patient's phone and the wall screen
// read as one product.
$tone = match ($s['status']) {
    'called' => 'from-sky-600 to-sky-800',
    'in_consultation' => 'from-brand-600 to-brand-800',
    'completed' => 'from-emerald-600 to-emerald-800',
    'skipped', 'no_show', 'cancelled' => 'from-ink-500 to-ink-700',
    default => 'from-brand-700 to-brand-900',
};
$isWaiting = $s['status'] === 'waiting';
// Once the token is called an estimate is meaningless, and showing "Next up"
// to someone who has already been called reads as a bug.
$estimateLabel = match ($s['status']) {
    'called' => 'Now',
    'in_consultation' => 'In progress',
    'completed' => 'Finished',
    'cancelled', 'no_show' => '—',
    default => (string) $s['estimate']['label'],
};
// Never show a bare em-dash where a status belongs: a patient staring at "—"
// has no idea whether they have been seen, skipped or forgotten.
$positionValue = $isWaiting ? (string) (int) $s['position'] : (string) $s['status_label'];
// Identical to the classes app.js applies on refresh, so the pill does not
// change colour four seconds after the page loads.
$pillTone = match ($s['status']) {
    'called' => 'bg-sky-100 text-sky-800',
    'in_consultation' => 'bg-brand-100 text-brand-800',
    'completed' => 'bg-emerald-100 text-emerald-800',
    default => 'bg-amber-100 text-amber-800',
};
$positionLabel = $isWaiting
    ? ((int) $s['position'] === 1 ? 'You are next' : 'People ahead of you')
    : 'Your status';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="<?= e($clinic['brand_color'] ?: '#0D7459') ?>">
  <meta name="robots" content="noindex, nofollow">
  <title>Token <?= e($s['token']) ?> · <?= e($clinic['name']) ?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="/assets/img/icon-192.png">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="patient-view min-h-full bg-canvas" data-track-token="<?= e($token) ?>">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div class="mx-auto max-w-md px-4 pb-10 pt-5 safe-bottom">

  <!-- clinic header -->
  <div class="flex items-center justify-between">
    <div class="flex items-center gap-2">
      <span class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-black text-white"
            style="background: <?= e($clinic['brand_color'] ?: '#0D7459') ?>">
        <?= e(App\Core\Str::initials($clinic['name'])) ?>
      </span>
      <div class="leading-tight">
        <p class="text-sm font-bold text-ink-900"><?= e($clinic['name']) ?></p>
        <p class="text-[10px] text-ink-500"><?= e($s['queue']) ?></p>
      </div>
    </div>
    <?php $stale = false; include QP_ROOT . '/resources/views/components/live-pill.php'; ?>
  </div>

  <!-- token card -->
  <div class="mt-4 overflow-hidden rounded-2xl bg-gradient-to-br <?= $tone ?> p-6 text-white shadow-lg">
    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-white/60">Your token</p>
    <p class="tv-token mt-1 text-6xl leading-none sm:text-7xl"><?= e($s['token']) ?></p>

    <div class="mt-4 flex flex-wrap items-center gap-2">
      <span data-track-pill class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold <?= $pillTone ?>">
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
    <div class="card p-4">
      <p class="stat-label" data-track-position-label><?= e($positionLabel) ?></p>
      <p class="mt-1 <?= $isWaiting ? 'stat-value text-4xl' : 'text-xl font-bold leading-tight text-ink-900' ?>"
         data-track-position><?= e($positionValue) ?></p>
      <?php if ($isWaiting): ?>
        <p class="stat-hint"><?= (int) $s['position'] === 1 ? 'Keep an eye on the screen' : 'in this queue' ?></p>
      <?php endif; ?>
    </div>
    <div class="card p-4">
      <p class="stat-label">Estimated wait</p>
      <p class="mt-1 text-2xl font-bold text-ink-900" data-track-estimate><?= e($estimateLabel) ?></p>
      <p class="stat-hint" data-track-confidence>
        <?= !$isWaiting ? '' : ($s['estimate']['confidence'] === 'data' ? 'Based on recent visit times' : 'Estimating — we learn as the clinic runs') ?>
      </p>
    </div>
  </div>

  <div class="mt-2 flex items-start gap-1.5 text-[11px] leading-relaxed text-ink-400">
    <svg class="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true"><use href="#i-info"/></svg>
    <p class="min-w-0">
      <span data-track-caveat class="<?= $isWaiting ? '' : 'hidden' ?>">A range, not a promise — emergencies can change the order.</span>
      Updated <span data-track-updated><?= e(substr((string) $s['updated_at'], 11, 5)) ?></span>.
    </p>
  </div>

  <!-- status message -->
  <div class="mt-4 card px-4 py-3.5">
    <p class="flex items-start gap-2.5 text-[13px] leading-relaxed text-ink-700">
      <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand-600"><use href="#i-megaphone"/></svg>
      <span><?= e($s['status_message']) ?></span>
    </p>
  </div>

  <!-- what happens next -->
  <div class="mt-4 card p-4">
    <p class="stat-label"><?= $isWaiting ? 'What happens next' : 'What to do now' ?></p>
    <ol class="mt-3 space-y-3">
      <?php
      // Guidance has to follow the visit. Telling a patient who is already
      // standing at the door to "wait until your token is called" is worse
      // than saying nothing.
      $room = $s['room'] ?: 'the room shown above';
      $steps = match ($s['status']) {
          'called' => [
              ['Go to ' . $room . ' now', 'Your token is being called. Please head straight there.'],
              ['If you cannot reach it in time', 'Tell reception. You can be called again without losing your place.'],
              ['Keep this page open', 'It updates itself, so you will see when the consultation ends.'],
          ],
          'in_consultation' => [
              ['You are with the doctor', 'Nothing else is needed from you right now.'],
              ['After the consultation', 'Reception will handle any follow-up, prescription or booking.'],
              ['This page stays available', 'You can reopen it from the same link after your visit.'],
          ],
          'completed' => [
              ['Your visit is complete', 'Thank you for waiting.'],
              ['Anything unclear?', 'Call the clinic using the button below and quote your token.'],
              ['Keep the link', 'It remains a record of today’s visit.'],
          ],
          'skipped', 'no_show' => [
              ['Speak to reception', 'Your token was missed. Reception can put you back in the queue.'],
              ['You will not lose your place in the records', 'Re-queuing keeps the original arrival time.'],
              ['Keep this page open', 'It refreshes itself once you are back in the queue.'],
          ],
          'cancelled' => [
              ['This visit was cancelled', 'Your place has been given to the next patient.'],
              ['Need to be seen?', 'Call the clinic or take a new number at reception.'],
              ['Nothing further is required', 'You can close this page.'],
          ],
          'on_hold' => [
              ['You are temporarily out of the queue', 'Reception paused your token and will resume it.'],
              ['Come back to the waiting area', 'You will be called again without losing your place.'],
              ['Keep this page open', 'It updates itself when you are resumed.'],
          ],
          default => [
              ['Wait until your token is called', 'Watch this page or the screen in the waiting room. Keep it open — it refreshes itself.'],
              ['Walk to the room shown', 'Your token and room appear at the top of this page when it is your turn.'],
              ['If you miss your turn', 'Speak to reception. You can be called again without losing your place in the records.'],
          ],
      };
      ?>
      <?php foreach ($steps as $i => [$title, $body]): ?>
        <li class="flex gap-3">
          <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-canvas text-[10px] font-bold text-ink-600"><?= $i + 1 ?></span>
          <div>
            <p class="text-xs font-semibold text-ink-800"><?= e($title) ?></p>
            <p class="text-[11px] leading-relaxed text-ink-500"><?= e($body) ?></p>
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

  <!-- patient-initiated cancellation -->
  <?php if (($cancelled = \App\Core\Session::takeFlash('cancel_ok')) !== null): ?>
    <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-xs leading-relaxed text-emerald-900">
      <b>Your visit is cancelled.</b> You have given up your place in the queue. If that was a
      mistake, call the clinic — reception can put you back in line.
    </div>
  <?php elseif (($refused = \App\Core\Session::takeFlash('cancel_refused')) !== null): ?>
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-xs leading-relaxed text-amber-900">
      <b>It is too late to cancel from here.</b>
      <?php if ($refused === 'called'): ?>
        Your token has been called — please go to the consultation room, or tell reception you
        are leaving so they can mark you down correctly.
      <?php else: ?>
        This visit has already moved past the waiting stage. Please speak to reception.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (($moved = \App\Core\Session::takeFlash('reschedule_ok')) !== null): ?>
    <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-xs leading-relaxed text-emerald-900">
      <b>Your appointment has been moved to <?= e($moved) ?>.</b>
      Your place in today's queue is unchanged. The clinic has been told.
    </div>
  <?php elseif (($why = \App\Core\Session::takeFlash('reschedule_refused')) !== null): ?>
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-xs leading-relaxed text-amber-900">
      <b>It is too late to change this booking from here.</b>
      <?php if ($why === 'no_appointment'): ?>
        This visit was a walk-in, not a booking, so there is nothing to reschedule.
      <?php elseif ($why === 'called'): ?>
        Your token has been called — please speak to reception if you need another day.
      <?php else: ?>
        This visit has moved past the waiting stage. Please speak to reception.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($appointment)): ?>
    <div class="mt-4 rounded-xl bg-white/80 px-3.5 py-3 ring-1 ring-slate-200">
      <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-400">Your booking</p>
      <p class="mt-1 text-sm text-ink-800">
        <?= e(fmt_time((string) $appointment['scheduled_for'], 'l j M')) ?> at
        <span class="font-semibold"><?= e(substr((string) $appointment['scheduled_for'], 11, 5)) ?></span>
      </p>

      <details class="mt-2">
        <summary class="cursor-pointer text-xs font-medium text-brand-700">I need a different day or time</summary>
        <form method="get" action="/t/<?= e($token) ?>" class="mt-2 flex gap-2">
          <input class="input text-xs" type="date" name="date" value="<?= e($rescheduleDate) ?>"
                 min="<?= e(\App\Core\Db::today()) ?>">
          <button class="btn-secondary btn-sm shrink-0 text-xs" type="submit">Show times</button>
        </form>

        <?php if ($rescheduleSlots['reason'] !== null): ?>
          <p class="mt-2 text-[11px] leading-relaxed text-amber-800"><?= e((string) $rescheduleSlots['reason']) ?></p>
        <?php elseif ($rescheduleSlots['slots'] === []): ?>
          <p class="mt-2 text-[11px] leading-relaxed text-ink-500">
            No times are open on that day. Try another date, or call the clinic.
          </p>
        <?php else: ?>
          <form method="post" action="/t/<?= e($token) ?>/reschedule" class="mt-2 space-y-2">
            <?= csrf_field() ?>
            <input type="hidden" name="date" value="<?= e($rescheduleDate) ?>">
            <div class="grid grid-cols-3 gap-1.5">
              <?php foreach ($rescheduleSlots['slots'] as $slot): ?>
                <label class="cursor-pointer">
                  <input type="radio" name="time" value="<?= e($slot) ?>" class="peer sr-only" required>
                  <span class="block rounded-lg border border-hairline bg-white px-1 py-1.5 text-center text-[11px] text-ink-700
                               peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:font-semibold peer-checked:text-brand-800">
                    <?= e($slot) ?>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
            <button class="btn w-full justify-center bg-brand-700 text-xs text-white" type="submit">
              Move my appointment to this time
            </button>
            <p class="text-[10px] leading-relaxed text-ink-400">
              You stay with <?= e((string) $s['doctor'] ?? 'the same doctor') ?>. To see someone else, call the clinic.
            </p>
          </form>
        <?php endif; ?>
      </details>
    </div>
  <?php endif; ?>

  <?php if ($s['status'] === 'waiting'): ?>
    <form method="post" action="/t/<?= e($token) ?>/cancel" class="mt-4"
          data-confirm="Cancel your visit and give up your place in the queue?">
      <?= csrf_field() ?>
      <button type="submit" class="btn w-full justify-center border border-hairline bg-white text-sm text-rose-700 hover:bg-rose-50">
        I can no longer come — cancel my visit
      </button>
    </form>
    <p class="mt-1.5 text-center text-[10px] leading-relaxed text-ink-400">
      Cancelling frees your place for the next patient. You cannot undo it here.
    </p>
  <?php endif; ?>

  <!-- satisfaction: asked once, at the only honest moment -->
  <?php if (($rated = \App\Core\Session::takeFlash('rated_ok')) !== null): ?>
    <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-xs leading-relaxed text-emerald-900">
      <b>Thank you — that helps.</b> Your answer goes to the clinic, not into a marketing list.
    </div>
  <?php elseif (!empty($feedback['can'])): ?>
    <div class="mt-4 rounded-xl bg-white/80 px-3.5 py-3 ring-1 ring-slate-200">
      <p class="text-sm font-semibold text-ink-800">How was your visit?</p>
      <p class="mt-0.5 text-[11px] leading-relaxed text-ink-500">
        One tap. It goes straight to the clinic and stays anonymous — this page does not say who you are.
      </p>

      <form method="post" action="/t/<?= e($token) ?>/rate" class="mt-3">
        <?= csrf_field() ?>
        <fieldset>
          <legend class="sr-only">Your rating of the visit</legend>
          <div class="grid grid-cols-5 gap-1.5">
            <?php foreach ([5 => 'Very good', 4 => 'Good', 3 => 'Okay', 2 => 'Poor', 1 => 'Very poor'] as $value => $label): ?>
              <label class="cursor-pointer text-center">
                <input type="radio" name="rating" value="<?= (int) $value ?>" class="peer sr-only" required>
                <span class="flex h-9 items-center justify-center rounded-lg border border-hairline bg-white
                             text-sm font-semibold text-ink-600 peer-checked:border-brand-600
                             peer-checked:bg-brand-50 peer-checked:text-brand-800">
                  <?= (int) $value ?>
                </span>
                <span class="mt-1 block text-[9px] leading-tight text-ink-400"><?= e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <details class="mt-3">
          <summary class="cursor-pointer text-[11px] font-medium text-brand-700">Add a note (optional)</summary>
          <div class="mt-2 space-y-2">
            <div>
              <label class="label text-[11px]" for="wait_rating">The wait</label>
              <select class="select !py-1.5 text-xs" id="wait_rating" name="wait_rating">
                <option value="">No opinion</option>
                <?php foreach ([5 => 'Very good', 4 => 'Good', 3 => 'Okay', 2 => 'Poor', 1 => 'Very poor'] as $value => $label): ?>
                  <option value="<?= (int) $value ?>"><?= (int) $value ?> · <?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label text-[11px]" for="comment">Anything the clinic should know</label>
              <textarea class="input text-xs" id="comment" name="comment" rows="2" maxlength="1000"
                        placeholder="Please do not include medical details"></textarea>
            </div>
          </div>
        </details>

        <button class="btn mt-3 w-full justify-center bg-brand-700 text-xs text-white" type="submit">
          Send my answer
        </button>
      </form>
    </div>
  <?php elseif (($feedback['reason'] ?? null) === 'already_rated'): ?>
    <div class="mt-4 rounded-xl bg-white/70 px-3.5 py-3 text-[11px] leading-relaxed text-ink-500 ring-1 ring-slate-200">
      You rated this visit <b class="text-ink-700"><?= (int) ($feedback['existing']['rating'] ?? 0) ?>/5</b>. Thank you —
      one answer per visit keeps the clinic's numbers honest.
    </div>
  <?php endif; ?>

  <!-- add to home screen hint -->
  <div class="mt-4 rounded-xl bg-white/70 px-3.5 py-3 text-[11px] leading-relaxed text-ink-500 ring-1 ring-slate-200">
    <b class="text-ink-700">Tip:</b> add this page to your home screen to open it in one tap.
    No app store, no install — and it keeps working if you switch apps.
  </div>

  <div class="mt-6 flex items-center justify-center gap-2 text-[10px] text-ink-400">
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
