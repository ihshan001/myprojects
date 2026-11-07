<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-pause"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">Breaks</h1>
    <p class="page-sub">
      A pause keeps every waiting patient and their estimate grows by its length — which is
      why it needs a reason and a category.
    </p>
  </div>
  </div>
</div>

<div class="mt-3 grid grid-cols-2 gap-2 sm:mt-4 sm:gap-3 sm:grid-cols-3">
  <div class="card card-pad">
    <p class="stat-value"><?= (int) $week['total_minutes'] ?></p>
    <p class="stat-label mt-1">Minutes paused, last 7 days</p>
  </div>
  <div class="card card-pad">
    <p class="stat-value"><?= count($week['rows']) ?></p>
    <p class="stat-label mt-1">Break types used</p>
  </div>
  <div class="card card-pad">
    <p class="text-2xl font-bold tabular-nums <?= count(array_filter($openQueues, fn ($q) => $q['break_started_at'] !== null)) > 0 ? 'text-amber-600' : 'text-emerald-600' ?>">
      <?= count(array_filter($openQueues, fn ($q) => $q['break_started_at'] !== null)) ?>
    </p>
    <p class="stat-label mt-1">Queues paused right now</p>
  </div>
</div>

<div class="mt-3 grid gap-3 sm:mt-6 sm:gap-5 lg:grid-cols-[1.3fr_.9fr]">
  <div class="space-y-5">
    <div class="card overflow-hidden">
      <div class="section-head">
        <p class="section-title">Today's queues</p>
      </div>
      <?php if ($openQueues === []): ?>
        <p class="p-4 text-xs text-ink-500">No queues are open today.</p>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($openQueues as $q): ?>
            <?php $onBreak = $q['break_started_at'] !== null; ?>
            <li class="px-4 py-3">
              <form method="post" action="<?= $onBreak ? '/breaks/end' : '/breaks/start' ?>" class="flex flex-wrap items-end gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="queue_id" value="<?= (int) $q['id'] ?>">

                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-semibold text-ink-900"><?= e($q['name']) ?></p>
                  <p class="mt-0.5 text-[11px] text-ink-500">
                    <?php if ($onBreak): ?>
                      <span class="font-medium text-amber-700">On break since <?= e(substr((string) $q['break_started_at'], 11, 5)) ?></span>
                      · <?= e($kinds[$q['break_kind'] ?? 'unspecified'] ?? 'Unspecified') ?>
                    <?php else: ?>
                      <?= e($q['status']) ?>
                    <?php endif; ?>
                  </p>
                </div>

                <?php if (!$onBreak): ?>
                  <div class="w-40">
                    <label class="label !mb-0.5 text-[10px]" for="kind-<?= (int) $q['id'] ?>">Type</label>
                    <select class="select !py-2 text-xs" id="kind-<?= (int) $q['id'] ?>" name="kind">
                      <?php foreach ($kinds as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $value === 'lunch' ? 'selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="w-48">
                    <label class="label !mb-0.5 text-[10px]" for="reason-<?= (int) $q['id'] ?>">Reason</label>
                    <input class="input !py-1.5 text-xs" id="reason-<?= (int) $q['id'] ?>" name="reason"
                           maxlength="180" placeholder="Dr. Perera's lunch">
                  </div>
                  <button class="btn-secondary btn-sm" <?= $canOperate ? '' : 'disabled' ?>>Pause</button>
                <?php else: ?>
                  <button class="btn-primary btn-sm">End break</button>
                <?php endif; ?>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="card overflow-hidden">
      <div class="section-head">
        <p class="section-title">Today</p>
      </div>
      <?php if ($today === []): ?>
        <?php $title = 'No breaks today'; $message = 'A break appears here once one starts. Its length is measured by the clock, not typed in afterwards.'; $icon = 'clock'; ?>
        <div class="p-4"><?php include QP_ROOT . '/resources/views/components/empty.php'; ?></div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="table">
            <thead><tr><th>Started</th><th>Queue</th><th>Type</th><th>Length</th><th class="hidden sm:table-cell">Reason</th></tr></thead>
            <tbody>
              <?php foreach ($today as $b): ?>
                <tr>
                  <td class="whitespace-nowrap text-xs text-ink-700"><?= e(substr((string) $b['started_at'], 11, 5)) ?></td>
                  <td class="text-xs text-ink-700"><?= e($b['queue_name']) ?></td>
                  <td class="text-xs text-ink-700"><?= e($kinds[$b['kind']] ?? $b['kind']) ?></td>
                  <td class="whitespace-nowrap text-xs">
                    <?php if ($b['ended_at'] === null): ?>
                      <span class="badge-amber">ongoing</span>
                    <?php else: ?>
                      <?= (int) $b['minutes'] ?> min
                    <?php endif; ?>
                  </td>
                  <td class="hidden sm:table-cell max-w-xs truncate text-[11px] text-ink-500"><?= e((string) ($b['reason'] ?? '—')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="space-y-5">
    <?php if ($canManage): ?>
      <div class="card">
        <div class="section-head">
          <p class="section-title">Plan a weekly break</p>
        </div>
        <form method="post" action="/breaks/schedule" class="space-y-2.5 p-4">
          <?= csrf_field() ?>
          <div>
            <label class="label" for="weekday">Day</label>
            <select class="select" id="weekday" name="weekday">
              <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $i => $name): ?>
                <option value="<?= $i ?>"><?= e($name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="doctor_id">Who</label>
            <select class="select" id="doctor_id" name="doctor_id">
              <option value="0">Everyone</option>
              <?php foreach ($doctors as $d): ?>
                <option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="grid grid-cols-1 gap-2 min-[420px]:grid-cols-2">
            <div>
              <label class="label" for="starts_at">From</label>
              <input class="input !py-1.5" id="starts_at" name="starts_at" type="time" value="13:00" required>
            </div>
            <div>
              <label class="label" for="ends_at">To</label>
              <input class="input !py-1.5" id="ends_at" name="ends_at" type="time" value="13:30" required>
            </div>
          </div>
          <div>
            <label class="label" for="kind">Type</label>
            <select class="select" id="kind" name="kind">
              <?php foreach ($kinds as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $value === 'lunch' ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn-primary w-full">Add to plan</button>
        </form>
      </div>

      <div class="card overflow-hidden">
        <div class="section-head">
          <p class="section-title">Weekly plan</p>
        </div>
        <?php if ($scheduled === []): ?>
          <p class="p-4 text-xs text-ink-500">No planned breaks. An unplanned pause still works — it just is not planned.</p>
        <?php else: ?>
          <ul class="divide-y divide-slate-100">
            <?php foreach ($scheduled as $s): ?>
              <li class="flex items-center justify-between gap-2 px-4 py-2.5">
                <div class="min-w-0">
                  <p class="text-xs font-semibold text-ink-900">
                    <?= e(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][(int) $s['weekday']]) ?>
                    <?= e(substr((string) $s['starts_at'], 0, 5)) ?>–<?= e(substr((string) $s['ends_at'], 0, 5)) ?>
                  </p>
                  <p class="text-[10px] text-ink-500">
                    <?= e($kinds[$s['kind']] ?? $s['kind']) ?> ·
                    <?= e($s['doctor_name'] ?? 'everyone') ?>
                  </p>
                </div>
                <form method="post" action="/breaks/schedule/<?= (int) $s['id'] ?>/remove">
                  <?= csrf_field() ?>
                  <button class="text-[10px] text-ink-400 underline hover:text-rose-700">remove</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card card-pad">
      <p class="stat-label">Last 7 days by type</p>
      <?php if ($week['rows'] === []): ?>
        <p class="mt-2 text-xs text-ink-500">No completed breaks.</p>
      <?php else: ?>
        <ul class="mt-2 space-y-1.5">
          <?php foreach ($week['rows'] as $r): ?>
            <li class="flex items-center justify-between text-xs">
              <span class="text-ink-700"><?= e($kinds[$r['kind']] ?? $r['kind']) ?></span>
              <span class="tabular-nums text-ink-900"><?= (int) $r['minutes'] ?> min <span class="text-ink-400">/ <?= (int) $r['breaks'] ?>×</span></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
