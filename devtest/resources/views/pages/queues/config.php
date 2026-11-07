<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-settings"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">Queue settings</h1>
    <p class="page-sub">
      How tomorrow's queues are created. A queue already open today keeps its labels —
      a patient is holding one.
    </p>
  </div>
  </div>
  <a class="btn-secondary btn-sm" href="/app">Today's board</a>
</div>

<div class="mt-3 grid gap-3 sm:mt-6 sm:gap-5 lg:grid-cols-[1.2fr_.9fr]">
  <div class="card">
    <div class="section-head">
      <p class="section-title">Defaults</p>
    </div>

    <form method="post" action="/queues/config" class="space-y-4 p-4">
      <?= csrf_field() ?>

      <div>
        <label class="label" for="name_pattern">Queue name pattern</label>
        <input class="input font-mono text-sm" id="name_pattern" name="name_pattern"
               value="<?= e($settings['name_pattern']) ?>" required maxlength="60"
               placeholder="OPD — {doctor}">
        <p class="mt-1 text-[11px] leading-relaxed text-ink-500">
          <code class="rounded bg-slate-100 px-1">{doctor}</code> becomes the doctor's name;
          <code class="rounded bg-slate-100 px-1">{n}</code> becomes a counter number.
          For today's first doctor this would read:
          <span class="font-medium text-ink-700">
            <?= e(str_replace(['{doctor}', '{n}'], [$sampleDoctor, '1'], (string) $settings['name_pattern'])) ?>
          </span>
        </p>
      </div>

      <div>
        <label class="label" for="kind">Queue type</label>
        <select class="select" id="kind" name="kind">
          <?php foreach ($kinds as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $settings['kind'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="mt-1 text-[10px] text-ink-400">
          The type is descriptive. It changes nothing about ordering or calling — a lab
          queue and an OPD queue run on the same engine.
        </p>
      </div>

      <div class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
        <div>
          <label class="label" for="prefix">Token prefix</label>
          <input class="input font-mono uppercase" id="prefix" name="prefix" maxlength="3"
                 value="<?= e($settings['prefix']) ?>" placeholder="A">
          <p class="mt-1 text-[10px] text-ink-400">1–3 letters. Read aloud, so keep it sayable. Blank uses the doctor's initial.</p>
        </div>
        <div>
          <label class="label" for="padding">Digits in a token</label>
          <input class="input" id="padding" name="padding" type="number" min="1" max="6"
                 value="<?= (int) $settings['padding'] ?>">
          <p class="mt-1 text-[10px] text-ink-400">3 gives A001. 4 gives A0001.</p>
        </div>
      </div>

      <div class="rounded-lg border border-hairline bg-slate-50 p-3">
        <p class="text-xs font-semibold text-ink-700">Appointment vs walk-in order</p>
        <p class="mt-0.5 text-[11px] leading-relaxed text-ink-500">
          A lower number sorts earlier. These decide the order between an
          appointment and a walk-in who are otherwise equal; a clinical
          priority still outranks both, and a priority rule can add its own.
        </p>
        <div class="mt-2 grid grid-cols-2 gap-3">
          <div>
            <label class="label" for="appointment_weight">Appointment</label>
            <input class="input" id="appointment_weight" name="appointment_weight" type="number" min="1" max="9"
                   value="<?= (int) $weights['appointment'] ?>">
          </div>
          <div>
            <label class="label" for="walkin_weight">Walk-in</label>
            <input class="input" id="walkin_weight" name="walkin_weight" type="number" min="1" max="9"
                   value="<?= (int) $weights['walkin'] ?>">
          </div>
        </div>
        <p class="mt-1.5 text-[11px] text-ink-500">
          Currently <?= (int) $weights['appointment'] ?> vs <?= (int) $weights['walkin'] ?> —
          <?php if ((int) $weights['appointment'] === (int) $weights['walkin']): ?>
            equal, so arrival order decides.
          <?php elseif ((int) $weights['appointment'] < (int) $weights['walkin']): ?>
            someone who booked comes before a walk-in arriving at the same moment.
          <?php else: ?>
            walk-ins are seen ahead of booked patients, which suits a first-come clinic.
          <?php endif; ?>
        </p>
      </div>

      <?php if ($canManage): ?>
        <button class="btn-primary">Save settings</button>
      <?php else: ?>
        <p class="text-xs text-ink-500">Only a clinic owner can change these.</p>
      <?php endif; ?>
    </form>
  </div>

  <div class="card overflow-hidden">
    <div class="section-head">
      <p class="section-title">Open today</p>
    </div>

    <?php if ($todayQueues === []): ?>
      <p class="p-4 text-xs text-ink-500">No queues open today.</p>
    <?php else: ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($todayQueues as $q): ?>
          <li class="px-4 py-2.5">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate text-xs font-semibold text-ink-900"><?= e($q['name']) ?></p>
                <p class="mt-0.5 text-[10px] text-ink-500">
                  <?= e($q['kind']) ?> ·
                  <?= e((string) $q['token_prefix']) ?><?= str_pad('1', (int) $q['token_padding'], '0', STR_PAD_LEFT) ?> series ·
                  issued <?= (int) $q['last_token_no'] ?>
                </p>
              </div>

              <?php if ($canManage && (int) $q['last_token_no'] === 0): ?>
                <details class="relative shrink-0">
                  <summary class="cursor-pointer list-none text-[10px] font-medium text-ink-500 underline">rename</summary>
                  <div class="absolute right-0 z-20 mt-1 w-56 rounded-lg border border-hairline bg-white p-2.5 shadow-lg">
                    <form method="post" action="/queues/<?= (int) $q['id'] ?>/rename" class="space-y-2">
                      <?= csrf_field() ?>
                      <input class="input !py-1 text-xs" name="name" value="<?= e($q['name']) ?>" required maxlength="60">
                      <button class="btn-primary btn-sm w-full">Save</button>
                    </form>
                  </div>
                </details>
              <?php elseif ($canManage): ?>
                <span class="shrink-0 text-[10px] text-ink-400" title="Renaming would make the slip in a patient's hand disagree with the screen">locked</span>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="border-t border-hairline px-4 py-3">
      <p class="text-[11px] leading-relaxed text-ink-500">
        A queue becomes locked once it issues its first token. Changing the label then
        would leave patients holding a slip the screen no longer matches.
      </p>
    </div>
  </div>
</div>
