<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Service workflows</h1>
    <p class="mt-1 max-w-2xl text-sm text-slate-500">
      The steps a visit must pass before it counts as finished. A doctor's visit
      is one act; a laboratory's is a sample, a verified result and a handed-over
      report. Name them here and the desk cannot complete the visit until each
      one is ticked.
    </p>
  </div>
  <div class="flex gap-2">
    <a class="btn-secondary btn-sm" href="/queues/priority-rules">Priority rules</a>
    <a class="btn-secondary btn-sm" href="/queues/config">Queue settings</a>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
    <p class="font-semibold">That step was not saved.</p>
    <ul class="mt-1 list-disc pl-5">
      <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.1fr_.9fr]">
  <div class="space-y-5">
    <div class="card">
      <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Configured steps</p>
        <span class="text-[11px] text-slate-500">
          <?= count(array_filter($steps, static fn ($s) => (int) $s['is_active'] === 1)) ?> active
        </span>
      </div>

      <?php if ($steps === []): ?>
        <div class="px-4 py-6 text-sm text-slate-500">
          <p>No steps configured, so every visit completes in one action — the behaviour
             this product has always had.</p>
          <p class="mt-2">Add steps only for a service that genuinely has them. A
             consultation that requires "vitals" will refuse to complete when the
             nurse forgets, which is the point and also the cost.</p>
        </div>
      <?php else: ?>
        <div class="divide-y divide-slate-100">
          <?php foreach ($steps as $step): ?>
            <?php $isActive = (int) $step['is_active'] === 1; ?>
            <div class="flex flex-wrap items-start gap-3 px-4 py-3 <?= $isActive ? '' : 'bg-slate-50/70' ?>">
              <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-600">
                <?= (int) $step['position'] ?>
              </span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-medium text-slate-900"><?= e($step['name']) ?></span>
                  <?php if ((int) $step['is_required'] !== 1): ?>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">Optional</span>
                  <?php endif; ?>
                  <?php if (!$isActive): ?>
                    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600">Retired</span>
                  <?php endif; ?>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                  Applies to:
                  <?php if ((int) $step['queue_id'] > 0): ?>
                    queue <?= e((string) ($step['queue_name'] ?? ('#' . $step['queue_id']))) ?> only
                  <?php elseif ($step['kind'] !== null): ?>
                    every <?= e($kinds[$step['kind']] ?? $step['kind']) ?> queue
                  <?php else: ?>
                    every queue in this clinic
                  <?php endif; ?>
                </p>
              </div>
              <form method="post" action="/queues/workflows/<?= (int) $step['id'] ?>/delete"
                    onsubmit="return confirm('Remove this step? Visits that already passed it keep their record.')">
                <?= csrf_field() ?>
                <button class="btn-secondary btn-sm text-red-700" type="submit">Remove</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600">
      <p class="font-semibold text-slate-700">How steps work</p>
      <ul class="mt-1 list-disc space-y-1 pl-5">
        <li>A step belongs to a queue kind, or to one queue. A queue-level step with the same name replaces the kind-wide one for that queue.</li>
        <li>The visit stays <span class="font-medium">in consultation</span> until it is completed. Steps are recorded beside the entry, not as new statuses, so the board, the display and the patient's tracking link keep working.</li>
        <li>Every tick records who ticked it and when.</li>
        <li>A step with no required flag is recorded but does not block completion.</li>
        <li>Removing a step does not erase the record of visits that already passed it.</li>
      </ul>
    </div>
  </div>

  <div class="card h-fit">
    <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
      <p class="text-xs font-semibold text-slate-600">New step</p>
    </div>

    <form method="post" action="/queues/workflows" class="space-y-3 p-4">
      <?= csrf_field() ?>

      <div>
        <label class="label" for="name">Step</label>
        <input class="input" id="name" name="name" required maxlength="60"
               value="<?= e($old['name'] ?? '') ?>" placeholder="Sample taken">
        <p class="mt-1 text-[11px] text-slate-500">Name it the way staff say it out loud.</p>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="kind">Queue type</label>
          <select class="select" id="kind" name="kind">
            <option value="">Any type</option>
            <?php foreach ($kinds as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= ($old['kind'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="position">Order</label>
          <input class="input" id="position" name="position" type="number" min="1" max="20"
                 value="<?= e((string) ($old['position'] ?? 1)) ?>">
        </div>
      </div>

      <div>
        <label class="label" for="queue_id">Queue</label>
        <select class="select" id="queue_id" name="queue_id">
          <option value="">Any queue of the chosen type</option>
          <?php foreach ($queues as $queue): ?>
            <option value="<?= (int) $queue['id'] ?>" <?= (string) ($old['queue_id'] ?? '') === (string) $queue['id'] ? 'selected' : '' ?>>
              <?= e($queue['name']) ?> (<?= e($kinds[$queue['kind']] ?? $queue['kind']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <p class="mt-1 text-[11px] text-slate-500">Leave blank to apply to every queue of that type.</p>
      </div>

      <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="hidden" name="is_required" value="1">
        <input type="checkbox" checked disabled>
        <span>Required before the visit can be completed</span>
      </label>

      <button class="btn-primary w-full" type="submit">Add step</button>
    </form>
  </div>
</div>
