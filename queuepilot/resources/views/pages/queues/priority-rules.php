<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Priority rules</h1>
    <p class="mt-1 max-w-2xl text-sm text-slate-500">
      Standing clinical decisions written once. A rule decides the order of a
      patient the desk has not flagged — children under five before adults,
      routine reviews behind acute cases, one doctor's list before another's.
      A member of staff flagging a patient by hand always overrides a rule.
    </p>
  </div>
  <div class="flex gap-2">
    <a class="btn-secondary btn-sm" href="/queues/config">Queue settings</a>
    <a class="btn-secondary btn-sm" href="/app">Today's board</a>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
    <p class="font-semibold">That rule was not saved.</p>
    <ul class="mt-1 list-disc pl-5">
      <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.1fr_.9fr]">
  <div class="space-y-5">
    <div class="card">
      <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Rules, strongest first</p>
        <span class="text-[11px] text-slate-500">
          <?= count(array_filter($rules, static fn ($r) => (int) $r['is_active'] === 1)) ?> of <?= count($rules) ?> active
        </span>
      </div>

      <?php if ($rules === []): ?>
        <p class="px-4 py-6 text-sm text-slate-500">
          No rules yet. Every arrival is ordered by its source weight (appointments
          and walk-ins) and by when it joined. Add one on the right if this clinic
          has a standing clinical priority.
        </p>
      <?php else: ?>
        <div class="divide-y divide-slate-100">
          <?php foreach ($rules as $rule): ?>
            <?php $isActive = (int) $rule['is_active'] === 1; ?>
            <div class="flex flex-wrap items-start gap-3 px-4 py-3 <?= $isActive ? '' : 'bg-slate-50/70' ?>">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-medium text-slate-900"><?= e($rule['name']) ?></span>
                  <?php if ((int) $rule['effect'] > 0): ?>
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">+<?= (int) $rule['effect'] ?> ahead</span>
                  <?php else: ?>
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-900"><?= (int) $rule['effect'] ?> behind</span>
                  <?php endif; ?>
                  <?php if ((int) $rule['queue_id'] > 0): ?>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">this queue only</span>
                  <?php endif; ?>
                  <?php if (!$isActive): ?>
                    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600">Off</span>
                  <?php endif; ?>
                </div>
                <p class="mt-1 text-xs text-slate-500">Matches: <?= e($rule['matches']) ?></p>
                <p class="mt-1 text-xs text-slate-600">
                  Reason copied onto the entry: <span class="italic">“<?= e($rule['reason']) ?>”</span>
                </p>
              </div>
              <div class="flex shrink-0 gap-1.5">
                <form method="post" action="/queues/priority-rules/<?= (int) $rule['id'] ?>/toggle">
                  <?= csrf_field() ?>
                  <button class="btn-secondary btn-sm" type="submit"><?= $isActive ? 'Turn off' : 'Turn on' ?></button>
                </form>
                <form method="post" action="/queues/priority-rules/<?= (int) $rule['id'] ?>/delete"
                      onsubmit="return confirm('Delete this rule? Patients it already reordered keep their recorded reason.')">
                  <?= csrf_field() ?>
                  <button class="btn-secondary btn-sm text-red-700" type="submit">Delete</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600">
      <p class="font-semibold text-slate-700">How a rule is applied</p>
      <ul class="mt-1 list-disc space-y-1 pl-5">
        <li>The strongest matching boost wins. Two rules that both fit the same patient do not add together.</li>
        <li>A rule decides the <span class="font-medium">next</span> token issued. Patients already waiting are not reordered underneath them.</li>
        <li>The reason is copied onto the entry, not looked up later — editing a rule cannot rewrite history.</li>
        <li>A hand-set priority at the desk beats any rule.</li>
        <li>Age comes from the patient's year of birth. A patient with no birth year recorded never matches an age rule.</li>
      </ul>
    </div>
  </div>

  <div class="card h-fit">
    <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
      <p class="text-xs font-semibold text-slate-600">New rule</p>
    </div>

    <form method="post" action="/queues/priority-rules" class="space-y-3 p-4">
      <?= csrf_field() ?>

      <div>
        <label class="label" for="name">Name</label>
        <input class="input" id="name" name="name" required maxlength="80"
               value="<?= e($old['name'] ?? '') ?>" placeholder="Under-fives first">
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="effect">Priority</label>
          <select class="select" id="effect" name="effect" required>
            <?php for ($i = 9; $i >= -5; $i--): if ($i === 0) { continue; } ?>
              <option value="<?= $i ?>" <?= (string) ($old['effect'] ?? '') === (string) $i ? 'selected' : '' ?>>
                <?= $i > 0 ? '+' . $i . ' ahead' : $i . ' behind' ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <div>
          <label class="label" for="source">Source</label>
          <select class="select" id="source" name="source">
            <option value="">Any</option>
            <?php foreach ($sources as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= ($old['source'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="kind">Queue type</label>
          <select class="select" id="kind" name="kind">
            <option value="">Any</option>
            <?php foreach ($kinds as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= ($old['kind'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="doctor_id">Doctor</label>
          <select class="select" id="doctor_id" name="doctor_id">
            <option value="">Any</option>
            <?php foreach ($doctors as $doctor): ?>
              <option value="<?= (int) $doctor['id'] ?>" <?= (string) ($old['doctor_id'] ?? '') === (string) $doctor['id'] ? 'selected' : '' ?>>
                <?= e($doctor['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div>
        <label class="label" for="queue_id">Queue</label>
        <select class="select" id="queue_id" name="queue_id">
          <option value="">Any queue of this clinic</option>
          <?php foreach ($queues as $queue): ?>
            <option value="<?= (int) $queue['id'] ?>" <?= (string) ($old['queue_id'] ?? '') === (string) $queue['id'] ? 'selected' : '' ?>>
              <?= e($queue['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="mt-1 text-[11px] text-slate-500">A rule for one queue is how a doctor's own preference stays theirs.</p>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="min_age">Youngest age</label>
          <input class="input" id="min_age" name="min_age" type="number" min="0" max="130"
                 value="<?= e((string) ($old['min_age'] ?? '')) ?>" placeholder="any">
        </div>
        <div>
          <label class="label" for="max_age">Oldest age</label>
          <input class="input" id="max_age" name="max_age" type="number" min="0" max="130"
                 value="<?= e((string) ($old['max_age'] ?? '')) ?>" placeholder="any">
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="from_hour">From hour</label>
          <input class="input" id="from_hour" name="from_hour" type="number" min="0" max="23"
                 value="<?= e((string) ($old['from_hour'] ?? '')) ?>" placeholder="any">
        </div>
        <div>
          <label class="label" for="to_hour">To hour</label>
          <input class="input" id="to_hour" name="to_hour" type="number" min="1" max="24"
                 value="<?= e((string) ($old['to_hour'] ?? '')) ?>" placeholder="any">
        </div>
      </div>
      <p class="-mt-1 text-[11px] text-slate-500">
        Hours use the clinic's clock, 0–23. A window that crosses midnight is two rules.
      </p>

      <div>
        <label class="label" for="reason">Clinical reason</label>
        <input class="input" id="reason" name="reason" required maxlength="160"
               value="<?= e($old['reason'] ?? '') ?>" placeholder="Child under five — see before adults">
        <p class="mt-1 text-[11px] text-slate-500">
          Copied onto every entry the rule decides. Write it for a colleague reading the day's log.
        </p>
      </div>

      <button class="btn-primary w-full" type="submit">Save rule</button>
    </form>
  </div>
</div>
