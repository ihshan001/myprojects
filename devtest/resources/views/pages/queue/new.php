<?php
use App\Core\Str;
$selectedQueue = (int) App\Core\Request::int('queue');
$selectedPatient = (int) ($preselect ?? 0);
?>
<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">New patient</h1>
    <p class="mt-1 text-sm text-slate-500">Search by phone or name, or register a walk-in. Four keystrokes to a token.</p>
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
      <h2 class="text-sm font-bold text-slate-900">1 · Find an existing patient</h2>
      <p class="mt-1 text-xs text-slate-500">Phone number works best — it prevents duplicate records.</p>

      <form method="get" action="/queue/new" class="mt-3 flex gap-2">
        <div class="relative flex-1">
          <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"><use href="#i-search"/></svg>
          <input class="input pl-9" name="q" value="<?= e($search ?? '') ?>" autocomplete="off"
                 placeholder="077 123 4567 · Nimali · P-00001" aria-label="Search patients">
        </div>
        <button class="btn-secondary" type="submit">Search</button>
      </form>

      <?php if (($search ?? '') !== ''): ?>
        <div class="mt-4 space-y-2">
          <?php if ($results === []): ?>
            <div class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center">
              <p class="text-sm text-slate-600">No patient matches "<?= e($search) ?>".</p>
              <p class="mt-1 text-xs text-slate-400">Register them below — the record will be created once and reused next time.</p>
            </div>
          <?php endif; ?>
          <?php foreach ($results as $p): ?>
            <button type="button"
                    class="flex w-full items-center gap-3 rounded-xl border border-slate-200 px-3.5 py-2.5 text-left transition hover:border-teal-400 hover:bg-teal-50/40"
                    data-pick-patient="<?= (int) $p['id'] ?>"
                    data-pick-name="<?= e($p['full_name']) ?>"
                    data-pick-phone="<?= e((string) $p['phone']) ?>">
              <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-600">
                <?= e(Str::initials((string) $p['full_name'])) ?>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-slate-800"><?= e($p['full_name']) ?></span>
                <span class="block text-[11px] text-slate-400"><?= e((string) $p['phone']) ?> · <?= e((string) $p['code']) ?></span>
              </span>
              <svg class="h-4 w-4 text-slate-300"><use href="#i-arrow-right"/></svg>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div id="picked-patient" class="mt-4 hidden rounded-xl border border-teal-200 bg-teal-50/60 px-4 py-3">
        <div class="flex items-center gap-2.5">
          <svg class="h-4 w-4 text-teal-700"><use href="#i-check-circle"/></svg>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-teal-900" data-picked-name></p>
            <p class="text-[11px] text-teal-700/70" data-picked-phone></p>
          </div>
          <button type="button" id="clear-picked" class="text-[11px] font-semibold text-teal-800 hover:underline">Change</button>
        </div>
      </div>
    </div>

    <!-- appointment check-in -->
    <?php if ($appointments !== []): ?>
      <div class="card card-pad">
        <h2 class="text-sm font-bold text-slate-900">Or check in a booking</h2>
        <p class="mt-1 text-xs text-slate-500">Booked patients keep their slot priority (weight 1, not 3).</p>
        <div class="mt-3 max-h-64 space-y-1.5 overflow-y-auto scrollbar-thin">
          <?php foreach ($appointments as $a): ?>
            <?php $isBooked = $a['status'] === 'booked'; ?>
            <div class="flex items-center gap-2.5 rounded-lg border border-slate-200 px-3 py-2">
              <span class="w-14 shrink-0 text-center font-mono text-xs font-bold text-slate-700">
                <?= e(date('H:i', strtotime((string) $a['scheduled_for']))) ?>
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-xs font-semibold text-slate-800"><?= e($a['full_name']) ?></p>
                <p class="truncate text-[10px] text-slate-400"><?= e($a['doctor_name']) ?></p>
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
    <h2 class="text-sm font-bold text-slate-900">2 · Issue the token</h2>
    <p class="mt-1 text-xs text-slate-500">Choose a queue. The next number is allocated atomically.</p>

    <form method="post" action="/queue/new" class="mt-4 space-y-4" id="token-form">
      <?= csrf_field() ?>
      <input type="hidden" name="patient_id" id="patient_id" value="<?= $selectedPatient ?: '' ?>">

      <div id="new-patient-fields">
        <div class="space-y-3">
          <div>
            <label class="label" for="full_name">Patient name</label>
            <input class="input" id="full_name" name="full_name" placeholder="Nimali Wickramasinghe" autocomplete="off">
          </div>
          <div class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
            <div>
              <label class="label" for="phone">Phone</label>
              <input class="input" id="phone" name="phone" inputmode="tel" placeholder="077 123 4567"
                     autocomplete="off" data-phone>
              <p class="help" id="phone-hint">Optional, for SMS tracking link</p>
            </div>
            <div>
              <label class="label" for="gender">Gender</label>
              <select class="select" id="gender" name="gender">
                <option value="">—</option>
                <option value="female">Female</option>
                <option value="male">Male</option>
                <option value="other">Other</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div>
        <label class="label" for="queue_id">Queue</label>
        <?php if ($queues === []): ?>
          <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900">
            No queue is open today. <a href="/doctors" class="font-semibold underline">Open a session →</a>
          </div>
        <?php else: ?>
          <div class="space-y-1.5">
            <?php foreach ($queues as $q): ?>
              <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 transition
                hover:border-teal-300 has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50/50">
                <?php $isOpen = ($q['status'] ?? 'open') === 'open'; ?>
                <input type="radio" name="queue_id" value="<?= (int) $q['id'] ?>" class="mt-0"
                       <?= !$isOpen ? 'disabled' : '' ?>
                       <?= $isOpen && ((int) $q['id'] === $selectedQueue || ($selectedQueue === 0 && $q === $queues[0])) ? 'checked' : '' ?>>
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-xs font-semibold text-slate-800"><?= e($q['name']) ?></span>
                  <span class="block text-[10px] text-slate-400">
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
        <label class="label" for="note">Note for the doctor <span class="normal-case text-slate-400">(optional)</span></label>
        <input class="input" id="note" name="note" maxlength="200" placeholder="e.g. recheck of blood report">
        <p class="help">Kept internal — never shown on the public tracking page.</p>
      </div>

      <button class="btn-primary btn-lg w-full" type="submit" <?= $queues === [] ? 'disabled' : '' ?>>
        <svg class="h-4 w-4"><use href="#i-plus"/></svg>
        Issue token
      </button>
      <p class="text-center text-[11px] text-slate-400">
        Keyboard: <kbd class="rounded border border-slate-300 px-1 font-mono text-[10px]">Alt</kbd> +
        <kbd class="rounded border border-slate-300 px-1 font-mono text-[10px]">Enter</kbd> to submit
      </p>
    </form>
  </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script>
(function () {
  const patientId = document.getElementById('patient_id');
  const fields = document.getElementById('new-patient-fields');
  const picked = document.getElementById('picked-patient');
  const nameEl = document.querySelector('[data-picked-name]');
  const phoneEl = document.querySelector('[data-picked-phone]');

  function pick(btn) {
    patientId.value = btn.dataset.pickPatient;
    nameEl.textContent = btn.dataset.pickName;
    phoneEl.textContent = btn.dataset.pickPhone || 'no phone on file';
    picked.classList.remove('hidden');
    fields.classList.add('hidden');
    document.querySelectorAll('[data-pick-patient]').forEach((b) => b.classList.remove('border-teal-400', 'bg-teal-50/40'));
    btn.classList.add('border-teal-400', 'bg-teal-50/40');
    picked.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  document.querySelectorAll('[data-pick-patient]').forEach((btn) => {
    btn.addEventListener('click', () => pick(btn));
  });

  document.getElementById('clear-picked').addEventListener('click', () => {
    patientId.value = '';
    picked.classList.add('hidden');
    fields.classList.remove('hidden');
    document.getElementById('full_name').focus();
  });

  // Alt+Enter submits from anywhere in the form (receptionist speed)
  document.addEventListener('keydown', (e) => {
    if (e.altKey && e.key === 'Enter') {
      document.getElementById('token-form').requestSubmit();
    }
  });

  // Phone: show what will be used for the link, and validate length inline.
  const phone = document.querySelector('[data-phone]');
  const hint = document.getElementById('phone-hint');
  if (phone) {
    phone.addEventListener('blur', () => {
      const digits = phone.value.replace(/\D/g, '');
      if (digits === '') {
        hint.textContent = 'Optional, for SMS tracking link';
        hint.className = 'help';
      } else if (digits.length < 9) {
        hint.textContent = 'That looks short — use 07X XXX XXXX';
        hint.className = 'help text-rose-600';
      } else {
        hint.textContent = 'Tracking link will be sent to this number';
        hint.className = 'help text-teal-700';
      }
    });
  }
})();
</script>
