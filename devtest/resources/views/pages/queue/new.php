<?php
use App\Core\Str;
$selectedQueue = (int) App\Core\Request::int('queue');
$selectedPatient = (int) ($preselect ?? 0);
?>
<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-user-plus"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">New patient</h1>
    <p class="page-sub">Search by phone or name, or register a walk-in. Four keystrokes to a token.</p>
  </div>
  </div>
  <a href="/app" class="btn-ghost">
    <svg class="h-4 w-4"><use href="#i-arrow-left"/></svg>
    Back to board
  </a>
</div>

<div class="mt-6 grid gap-5 lg:grid-cols-[1.05fr_.95fr]">
  <!-- existing patient -->
  <div class="space-y-5">
    <div class="card card-pad">
      <div class="flex items-baseline justify-between gap-3">
        <h2 class="text-sm font-bold text-ink-900">1 · Who is it?</h2>
        <span class="shrink-0 text-[11px] font-semibold uppercase tracking-wide text-ink-400">Step 1 of 2</span>
      </div>
      <p class="mt-1 text-xs text-ink-500">Search first, every time. It prevents duplicate records.</p>


      <div class="relative mt-3">
        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400"><use href="#i-search"/></svg>
        <input class="input pl-9 pr-9" id="patient-search" type="search" value="<?= e($search ?? '') ?>"
               autocomplete="off" spellcheck="false"
               placeholder="Start typing a phone number or name…" aria-label="Search patients"
               aria-describedby="search-help">
        <span id="search-spinner" class="pointer-events-none absolute right-3 top-1/2 hidden h-4 w-4 -translate-y-1/2">
          <svg class="h-4 w-4 animate-spin text-ink-300"><use href="#i-refresh"/></svg>
        </span>
      </div>
      <p class="help" id="search-help">
        Results appear as you type — from 2 characters. Phone or name or patient code.
      </p>
      <p class="sr-only" role="status" aria-live="polite" id="search-status"></p>

      <div id="search-results" class="mt-3 space-y-1.5">
        <?php /* Server-rendered results are LINKS, not buttons: selecting a
                 patient has to work with JavaScript disabled, and a link to
                 ?patient= is the only way to carry the choice across a page
                 load. The live search swaps in buttons once JS is running. */ ?>
        <?php foreach ($results as $p): ?>
          <a class="np-result flex w-full items-center gap-3 rounded-xl border border-hairline px-3.5 py-2.5 text-left transition hover:border-teal-400 hover:bg-brand-50/40"
             href="/queue/new?patient=<?= (int) $p['id'] ?>&amp;q=<?= e(urlencode((string) $search)) ?>"
             data-pick-patient="<?= (int) $p['id'] ?>"
             data-pick-name="<?= e($p['full_name']) ?>"
             data-pick-phone="<?= e((string) $p['phone']) ?>">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-ink-700">
              <?= e(Str::initials((string) $p['full_name'])) ?>
            </span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-semibold text-ink-900"><?= e($p['full_name']) ?></span>
              <span class="block truncate text-[11px] text-ink-400"><?= e((string) $p['phone']) ?> · <?= e((string) $p['code']) ?></span>
            </span>
            <svg class="h-4 w-4 shrink-0 text-slate-300"><use href="#i-arrow-right"/></svg>
          </a>
        <?php endforeach; ?>
      </div>

      <noscript>
        <form method="get" action="/queue/new" class="mt-3 flex gap-2">
          <input class="input" name="q" value="<?= e($search ?? '') ?>" placeholder="Phone, name or code" aria-label="Search patients">
          <button class="btn-secondary" type="submit">Search</button>
        </form>
      </noscript>

      <?php if (($search ?? '') !== '' && $results === []): ?>
        <div class="mt-3 rounded-lg border border-dashed border-hairline px-4 py-5 text-center">
          <p class="text-sm text-ink-700">No patient matches "<?= e($search) ?>".</p>
          <p class="mt-1 text-xs text-ink-400">Fill in the details below — the record is created once and reused next time.</p>
        </div>
      <?php endif; ?>

      <div id="picked-patient" class="mt-3 <?= $picked ? '' : 'hidden' ?> rounded-xl border border-brand-200 bg-brand-50/60 px-4 py-3">
        <div class="flex items-center gap-2.5">
          <svg class="h-4 w-4 shrink-0 text-brand-700"><use href="#i-check-circle"/></svg>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-brand-800" data-picked-name><?= $picked ? e((string) $picked['full_name']) : '' ?></p>
            <p class="truncate text-[11px] text-brand-700/70" data-picked-phone><?= $picked ? (e((string) ($picked['phone'] ?? '')) ?: 'no phone on file') : '' ?></p>
          </div>
          <button type="button" id="clear-picked" class="shrink-0 text-[11px] font-semibold text-brand-800 hover:underline">Change</button>
        </div>
      </div>

      <div id="new-patient-fields" class="mt-4 border-t border-slate-100 pt-4 <?= $picked ? 'hidden' : '' ?>">
        <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-ink-400">New walk-in</p>
        <div class="space-y-3">
          <div>
            <label class="label" for="full_name">Patient name</label>
            <input class="input" id="full_name" name="full_name" form="token-form" autocomplete="off"
                   placeholder="Nimali Wickramasinghe">
          </div>
          <div class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
            <div>
              <label class="label" for="phone">Phone</label>
              <input class="input" id="phone" name="phone" form="token-form" inputmode="tel"
                     placeholder="077 123 4567" autocomplete="off" data-phone>
              <p class="help" id="phone-hint">Optional, for the SMS tracking link</p>
            </div>
            <div>
              <label class="label" for="gender">Gender</label>
              <select class="select" id="gender" name="gender" form="token-form">
                <option value="">—</option>
                <option value="female">Female</option>
                <option value="male">Male</option>
                <option value="other">Other</option>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- appointment check-in -->
    <?php if ($appointments !== []): ?>
      <div class="card card-pad">
        <h2 class="text-sm font-bold text-ink-900">Or check in a booking</h2>
        <p class="mt-1 text-xs text-ink-500">Booked patients keep their slot priority (weight 1, not 3).</p>
        <div class="mt-3 max-h-64 space-y-1.5 overflow-y-auto scrollbar-thin">
          <?php foreach ($appointments as $a): ?>
            <?php $isBooked = $a['status'] === 'booked'; ?>
            <div class="flex items-center gap-2.5 rounded-lg border border-hairline px-3 py-2">
              <span class="w-14 shrink-0 text-center font-mono text-xs font-bold text-ink-700">
                <?= e(date('H:i', strtotime((string) $a['scheduled_for']))) ?>
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-xs font-semibold text-ink-900"><?= e($a['full_name']) ?></p>
                <p class="truncate text-[10px] text-ink-400"><?= e($a['doctor_name']) ?></p>
              </div>
              <?php if ($isBooked): ?>
                <form method="post" action="/appointments/<?= (int) $a['id'] ?>/checkin">
                  <?= csrf_field() ?>
                  <button class="act-start">Check in</button>
                </form>
              <?php else: ?>
                <span class="badge-teal"><?= e(str_replace('_', ' ', $a['status'])) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- issue token -->
  <div class="card card-pad lg:sticky lg:top-6 lg:self-start">
    <div class="flex items-baseline justify-between gap-3">
      <h2 class="text-sm font-bold text-ink-900">2 · Issue the token</h2>
      <span class="shrink-0 text-[11px] font-semibold uppercase tracking-wide text-ink-400">Step 2 of 2</span>
    </div>
    <p class="mt-1 text-xs text-ink-500" id="issue-sub">Choose a queue. The next number is allocated atomically.</p>

    <form method="post" action="/queue/new" class="mt-4 space-y-4" id="token-form">
      <?= csrf_field() ?>
      <input type="hidden" name="patient_id" id="patient_id" value="<?= $selectedPatient ?: '' ?>">

      <div>
        <label class="label" for="queue_id">Queue</label>
        <?php if ($queues === []): ?>
          <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900">
            No queue is open today. <a href="/doctors" class="font-semibold underline">Open a session →</a>
          </div>
        <?php else: ?>
          <div class="space-y-1.5">
            <?php foreach ($queues as $q): ?>
              <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-hairline px-3 py-2 transition
                hover:border-teal-300 has-[:checked]:border-teal-600 has-[:checked]:bg-brand-50/50">
                <?php $isOpen = ($q['status'] ?? 'open') === 'open'; ?>
                <input type="radio" name="queue_id" value="<?= (int) $q['id'] ?>" class="mt-0"
                       <?= !$isOpen ? 'disabled' : '' ?>
                       <?= $isOpen && ((int) $q['id'] === $selectedQueue || ($selectedQueue === 0 && $q === $queues[0])) ? 'checked' : '' ?>>
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-xs font-semibold text-ink-900"><?= e($q['name']) ?></span>
                  <span class="block text-[10px] text-ink-400">
                    <?= (int) $q['waiting_count'] ?> waiting · next <?= e($q['token_prefix']) ?><?= str_pad((string) ((int) $q['last_token_no'] + 1), 3, '0', STR_PAD_LEFT) ?>
                  </span>
                </span>
                <span class="<?= $q['status'] === 'open' ? 'badge-emerald' : 'badge-amber' ?>"><?= e($q['status']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div>
        <label class="label" for="temperature">Temperature °C <span class="normal-case text-ink-400">(optional)</span></label>
        <input class="input" id="temperature" name="temperature" type="number" step="0.1"
               min="30" max="45" inputmode="decimal" placeholder="e.g. 39.4">
        <p class="help">
          Leave blank if no reading was taken — blank is not the same as normal, so a fever
          rule will not match this patient. Used only by your clinic's temperature rules.
        </p>
      </div>

      <div>
        <label class="label" for="note">Note for the doctor <span class="normal-case text-ink-400">(optional)</span></label>
        <input class="input" id="note" name="note" maxlength="200" placeholder="e.g. recheck of blood report">
        <p class="help">Kept internal — never shown on the public tracking page.</p>
      </div>

      <button class="btn-primary btn-lg w-full" type="submit" <?= $queues === [] ? 'disabled' : '' ?>>
        <svg class="h-4 w-4"><use href="#i-plus"/></svg>
        Issue token
      </button>
      <p class="text-center text-[11px] text-ink-400">
        Keyboard: <kbd class="rounded border border-hairline px-1 font-mono text-[10px]">Alt</kbd> +
        <kbd class="rounded border border-hairline px-1 font-mono text-[10px]">Enter</kbd> to submit
      </p>
    </form>
  </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
