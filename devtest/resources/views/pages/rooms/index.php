<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Rooms</h1>
    <p class="mt-1 text-sm text-slate-500">
      What the waiting-room screen calls each consultation space. A patient is told
      &ldquo;please proceed to <span class="font-medium text-slate-700"><?= e($rooms[0]['name'] ?? 'Room 1') ?></span>&rdquo;,
      so the name needs to be something they will recognise.
    </p>
  </div>
  <?php if ($canManage): ?>
    <a class="btn-secondary btn-sm" href="/doctors">Assign to a doctor</a>
  <?php endif; ?>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.4fr_.8fr]">
  <div class="card overflow-hidden">
    <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
      <p class="text-xs font-semibold text-slate-600"><?= count($rooms) ?> room<?= count($rooms) === 1 ? '' : 's' ?></p>
    </div>

    <?php if ($rooms === []): ?>
      <?php $title = 'No rooms yet'; $message = 'Add one on the right. Without a room the display announces the queue name instead, which is less use to a patient looking for a door.'; $icon = 'building'; ?>
      <div class="p-4"><?php include QP_ROOT . '/resources/views/components/empty.php'; ?></div>
    <?php else: ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($rooms as $r): ?>
          <li class="px-4 py-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-900">
                  <?= e($r['name']) ?>
                  <?php if (!empty($r['floor'])): ?>
                    <span class="ml-1.5 text-[11px] font-normal text-slate-400"><?= e($r['floor']) ?></span>
                  <?php endif; ?>
                </p>
                <p class="mt-0.5 text-[11px] text-slate-500">
                  <?php if ((int) $r['doctors'] > 0): ?>
                    In use by <span class="font-medium text-slate-700"><?= e((string) $r['doctor_names']) ?></span>
                  <?php else: ?>
                    Not assigned to anyone
                  <?php endif; ?>
                </p>
              </div>

              <?php if ($canManage): ?>
                <div class="flex shrink-0 items-center gap-2">
                  <details class="relative">
                    <summary class="cursor-pointer list-none text-[11px] font-medium text-slate-500 underline hover:text-slate-700">rename</summary>
                    <div class="absolute right-0 z-20 mt-2 w-64 rounded-lg border border-slate-200 bg-white p-3 shadow-lg">
                      <form method="post" action="/rooms/<?= (int) $r['id'] ?>" class="space-y-2">
                        <?= csrf_field() ?>
                        <div>
                          <label class="label" for="name-<?= (int) $r['id'] ?>">Name</label>
                          <input class="input !py-1.5 text-sm" id="name-<?= (int) $r['id'] ?>" name="name"
                                 value="<?= e($r['name']) ?>" required maxlength="60">
                        </div>
                        <div>
                          <label class="label" for="floor-<?= (int) $r['id'] ?>">Floor</label>
                          <input class="input !py-1.5 text-sm" id="floor-<?= (int) $r['id'] ?>" name="floor"
                                 value="<?= e((string) ($r['floor'] ?? '')) ?>" maxlength="40">
                        </div>
                        <button class="btn-primary btn-sm w-full">Save</button>
                      </form>
                    </div>
                  </details>

                  <form method="post" action="/rooms/<?= (int) $r['id'] ?>/delete"
                        onsubmit="return confirm('Remove <?= e(addslashes((string) $r['name'])) ?>? A room still assigned to a doctor cannot be removed.')">
                    <?= csrf_field() ?>
                    <button class="text-[11px] font-medium text-slate-400 underline hover:text-rose-700">remove</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <?php if ($canManage): ?>
    <div class="card">
      <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Add a room</p>
      </div>
      <form method="post" action="/rooms" class="space-y-3 p-4">
        <?= csrf_field() ?>
        <div>
          <label class="label" for="name">Name</label>
          <input class="input" id="name" name="name" required maxlength="60" placeholder="Consulting 2">
          <p class="mt-1 text-[10px] text-slate-400">Short and sayable out loud. Patients hear this.</p>
        </div>
        <div>
          <label class="label" for="floor">Floor</label>
          <input class="input" id="floor" name="floor" maxlength="40" placeholder="Ground">
          <p class="mt-1 text-[10px] text-slate-400">Optional. Shown next to the name.</p>
        </div>
        <button class="btn-primary w-full">Add room</button>
      </form>
      <div class="border-t border-slate-200 px-4 py-3">
        <p class="text-[11px] leading-relaxed text-slate-500">
          Renaming updates every display on its next poll, about four seconds. Patients
          already told the old name are not notified, so rename between sessions rather
          than mid-clinic.
        </p>
      </div>
    </div>
  <?php endif; ?>
</div>
