<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Appointment policies</h1>
    <p class="mt-1 max-w-2xl text-sm text-slate-500">
      How late a patient may arrive, how far ahead this service may be booked,
      and whether it takes walk-ins. Every row here overrides the clinic
      default for one service; blank fields inherit it.
    </p>
  </div>
  <div class="flex gap-2">
    <a class="btn-secondary btn-sm" href="/queues/workflows">Service workflows</a>
    <a class="btn-secondary btn-sm" href="/settings">Clinic settings</a>
  </div>
</div>

<div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600">
  <p class="font-semibold text-slate-700">The clinic default, which everything inherits</p>
  <p class="mt-1">
    Late grace <span class="font-semibold text-slate-800"><?= (int) $defaults['late_grace_minutes'] ?> min</span>
    · Booking horizon <span class="font-semibold text-slate-800"><?= (int) $defaults['horizon_days'] ?> days</span>
    · Walk-ins <span class="font-semibold text-slate-800">accepted</span>
    — change these on <a class="underline" href="/settings">Settings</a>.
  </p>
</div>

<?php if (!empty($errors)): ?>
  <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
    <p class="font-semibold">That policy was not saved.</p>
    <ul class="mt-1 list-disc pl-5">
      <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.15fr_.85fr]">
  <div class="space-y-5">
    <div class="card">
      <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">What each service currently does</p>
      </div>
      <div class="divide-y divide-slate-100">
        <?php foreach ($kinds as $kindValue => $kindLabel): ?>
          <?php $r = $resolved[$kindValue]; ?>
          <div class="flex flex-wrap items-center gap-3 px-4 py-3">
            <div class="min-w-0 flex-1">
              <p class="font-medium text-slate-900"><?= e($kindLabel) ?></p>
              <p class="mt-0.5 text-xs text-slate-500">
                Grace <?= (int) $r['late_grace_minutes'] ?> min ·
                bookable <?= (int) $r['horizon_days'] ?> days ahead ·
                <?= $r['allow_walkins'] ? 'walk-ins accepted' : 'booked patients only' ?>
              </p>
            </div>
            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold
              <?= $r['source'] === 'clinic' ? 'bg-slate-100 text-slate-600' : 'bg-teal-100 text-teal-800' ?>">
              <?= $r['source'] === 'clinic' ? 'Clinic default' : 'Service policy' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Configured overrides</p>
        <span class="text-[11px] text-slate-500"><?= count($policies) ?></span>
      </div>
      <?php if ($policies === []): ?>
        <p class="px-4 py-6 text-sm text-slate-500">
          None. Every service uses the clinic default, which is exactly how this
          product behaved before this screen existed.
        </p>
      <?php else: ?>
        <div class="divide-y divide-slate-100">
          <?php foreach ($policies as $policy): ?>
            <div class="flex flex-wrap items-start gap-3 px-4 py-3 <?= (int) $policy['is_active'] === 1 ? '' : 'bg-slate-50/70' ?>">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-medium text-slate-900"><?= e($kinds[$policy['kind']] ?? $policy['kind']) ?></span>
                  <?php if ((int) $policy['doctor_id'] > 0): ?>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600"><?= e((string) ($policy['doctor_name'] ?? 'one doctor')) ?> only</span>
                  <?php else: ?>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">every doctor</span>
                  <?php endif; ?>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                  <?php
                    $bits = [];
                    if ($policy['late_grace_minutes'] !== null) { $bits[] = 'grace ' . (int) $policy['late_grace_minutes'] . ' min'; }
                    if ($policy['horizon_days'] !== null) { $bits[] = 'bookable ' . (int) $policy['horizon_days'] . ' days'; }
                    $bits[] = (int) $policy['allow_walkins'] === 1 ? 'walk-ins accepted' : 'booked patients only';
                    echo e(implode(' · ', $bits));
                  ?>
                </p>
              </div>
              <form method="post" action="/queues/appointment-policies/<?= (int) $policy['id'] ?>/delete"
                    onsubmit="return confirm('Remove this override? That service falls back to the clinic default.')">
                <?= csrf_field() ?>
                <button class="btn-secondary btn-sm text-red-700" type="submit">Remove</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card h-fit">
    <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
      <p class="text-xs font-semibold text-slate-600">Set a policy</p>
    </div>
    <form method="post" action="/queues/appointment-policies" class="space-y-3 p-4">
      <?= csrf_field() ?>

      <div>
        <label class="label" for="kind">Service</label>
        <select class="select" id="kind" name="kind" required>
          <?php foreach ($kinds as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= ($old['kind'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="label" for="doctor_id">Doctor</label>
        <select class="select" id="doctor_id" name="doctor_id">
          <option value="">Every doctor of this service</option>
          <?php foreach ($doctors as $doctor): ?>
            <option value="<?= (int) $doctor['id'] ?>" <?= (string) ($old['doctor_id'] ?? '') === (string) $doctor['id'] ? 'selected' : '' ?>>
              <?= e($doctor['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="mt-1 text-[11px] text-slate-500">A doctor row overrides the service row for that doctor only.</p>
      </div>

      <div class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
        <div>
          <label class="label" for="late_grace_minutes">Late grace</label>
          <input class="input" id="late_grace_minutes" name="late_grace_minutes" type="number" min="0" max="240"
                 value="<?= e((string) ($old['late_grace_minutes'] ?? '')) ?>" placeholder="inherit">
          <p class="mt-1 text-[10px] text-slate-400">minutes</p>
        </div>
        <div>
          <label class="label" for="horizon_days">Bookable ahead</label>
          <input class="input" id="horizon_days" name="horizon_days" type="number" min="1" max="365"
                 value="<?= e((string) ($old['horizon_days'] ?? '')) ?>" placeholder="inherit">
          <p class="mt-1 text-[10px] text-slate-400">days</p>
        </div>
      </div>

      <div>
        <label class="label" for="allow_walkins">Walk-ins</label>
        <select class="select" id="allow_walkins" name="allow_walkins">
          <option value="1" <?= ($old['allow_walkins'] ?? '1') === '1' ? 'selected' : '' ?>>Accepted alongside appointments</option>
          <option value="0" <?= ($old['allow_walkins'] ?? '') === '0' ? 'selected' : '' ?>>Booked patients only</option>
        </select>
        <p class="mt-1 text-[11px] text-slate-500">
          Enforced when a token is issued for a queue of this kind: a walk-in is
          refused with an explanation, not silently ignored.
        </p>
      </div>

      <button class="btn-primary w-full" type="submit">Save policy</button>
      <p class="text-[11px] leading-relaxed text-slate-500">
        Saving the same service again replaces its policy rather than adding a
        second one. Leaving both numbers blank and keeping walk-ins accepted
        saves nothing — a policy that changes no behaviour is not saved.
      </p>
    </form>
  </div>
</div>
