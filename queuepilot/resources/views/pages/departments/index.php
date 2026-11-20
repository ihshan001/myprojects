<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Departments</h1>
    <p class="mt-1 text-sm text-slate-500">
      A department is the part of the building a patient walks to. Queues, doctors and displays
      can belong to one, which is what makes a per-department board possible.
    </p>
  </div>
</div>

<?php if ($departments === []): ?>
  <div class="card mt-5">
    <?php $title = 'No departments yet'; $message = 'A single-room clinic does not need them. Add one when two services share a waiting area and patients need to be told where to go.'; $icon = 'grid'; include QP_ROOT . '/resources/views/components/empty.php'; ?>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.4fr_.8fr]">
  <div class="space-y-3">
    <?php foreach ($departments as $d): ?>
      <?php // Resolved once per card: the action row and the body both need it,
            // and defining it inside one branch left the other undefined. ?>
      <?php $dkey = $displayKeys[(int) $d['id']] ?? null; ?>
      <div class="card card-pad <?= (int) $d['is_active'] === 1 ? '' : 'opacity-60' ?>">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="flex items-center gap-2">
              <p class="text-sm font-bold text-slate-900"><?= e($d['name']) ?></p>
              <?php if (!empty($d['code'])): ?>
                <span class="badge-slate"><?= e($d['code']) ?></span>
              <?php endif; ?>
              <?php if ((int) $d['is_active'] !== 1): ?>
                <span class="badge-slate">inactive</span>
              <?php endif; ?>
            </div>
            <p class="mt-0.5 text-[11px] text-slate-500">
              <?= (int) $d['queue_count'] ?> queue<?= (int) $d['queue_count'] === 1 ? '' : 's' ?>
              · <?= (int) $d['doctor_count'] ?> doctor<?= (int) $d['doctor_count'] === 1 ? '' : 's' ?>
              <?php if (!empty($d['floor'])): ?> · <?= e($d['floor']) ?><?php endif; ?>
              <?php if (!empty($d['lead_name'])): ?> · led by <?= e($d['lead_name']) ?><?php endif; ?>
            </p>
            <?php if (!empty($d['description'])): ?>
              <p class="mt-1.5 max-w-prose text-xs leading-relaxed text-slate-500"><?= e($d['description']) ?></p>
            <?php endif; ?>

            <div class="mt-2 flex flex-wrap items-center gap-2">
              <?php if ($dkey !== null): ?>
                <span class="max-w-[24rem] truncate rounded bg-slate-50 px-2 py-1 font-mono text-[10px] text-slate-500" title="<?= e($dkey) ?>">
                  <?= e($dkey) ?>
                </span>
                <a class="text-[10px] font-medium text-teal-700 hover:underline" href="<?= e($dkey) ?>" target="_blank">open display</a>
              <?php else: ?>
                <span class="text-[10px] text-slate-400">No display link yet — this TV would show the whole clinic.</span>
              <?php endif; ?>
              <?php if ($canManage): ?>
                <form method="post" action="/departments/<?= (int) $d['id'] ?>/display-key"
                      data-confirm-submit="<?= $dkey !== null ? 'Replace the existing display link? Screens using the old one stop updating.' : 'Issue a display link for this department?' ?>" data-confirm-danger>
                  <?= csrf_field() ?>
                  <button class="text-[10px] font-medium text-slate-500 underline hover:text-slate-700">
                    <?= $dkey !== null ? 'rotate link' : 'issue link' ?>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <a class="btn-secondary btn-sm" href="/departments/<?= (int) $d['id'] ?>">Today's board</a>
            <?php if ($canManage): ?>
              <details class="relative">
                <summary class="btn-secondary btn-sm cursor-pointer list-none">Edit</summary>
                <form method="post" action="/departments/<?= (int) $d['id'] ?>"
                      class="absolute right-0 z-20 mt-2 w-72 space-y-2 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                  <?= csrf_field() ?>
                  <div><label class="label">Name</label><input class="input" name="name" value="<?= e($d['name']) ?>" required maxlength="120"></div>
                  <div class="grid grid-cols-2 gap-2">
                    <div><label class="label">Code</label><input class="input" name="code" value="<?= e($d['code'] ?? '') ?>" maxlength="20"></div>
                    <div><label class="label">Floor</label><input class="input" name="floor" value="<?= e($d['floor'] ?? '') ?>" maxlength="60"></div>
                  </div>
                  <div><label class="label">Description</label><input class="input" name="description" value="<?= e($d['description'] ?? '') ?>" maxlength="500"></div>
                  <div>
                    <label class="label">Lead doctor</label>
                    <select class="select" name="lead_doctor_id">
                      <option value="0">— none —</option>
                      <?php foreach ($doctors as $doc): ?>
                        <option value="<?= (int) $doc['id'] ?>" <?= (int) ($d['lead_doctor_id'] ?? 0) === (int) $doc['id'] ? 'selected' : '' ?>>
                          <?= e($doc['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <label class="flex items-center gap-2 text-xs text-slate-600">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $d['is_active'] === 1 ? 'checked' : '' ?>>
                    Active
                  </label>
                  <button class="btn-primary btn-sm w-full">Save department</button>
                </form>
              </details>
              <?php if ((int) $d['is_active'] === 1): ?>
                <form method="post" action="/departments/<?= (int) $d['id'] ?>/deactivate"
                      data-confirm-submit="Deactivate <?= e(addslashes((string) $d['name'])) ?>? Its queues and history stay intact." data-confirm-danger>
                  <?= csrf_field() ?>
                  <button class="btn-ghost btn-sm text-rose-600">Deactivate</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="space-y-5">
    <?php if ($canManage): ?>
      <div class="card">
        <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
          <p class="text-xs font-semibold text-slate-600">Add a department</p>
        </div>
        <form method="post" action="/departments" class="space-y-3 p-4">
          <?= csrf_field() ?>
          <div><label class="label" for="name">Name</label><input class="input" id="name" name="name" required maxlength="120" placeholder="Outpatient (OPD)"></div>
          <div class="grid grid-cols-2 gap-2">
            <div><label class="label" for="code">Code</label><input class="input" id="code" name="code" maxlength="20" placeholder="OPD"></div>
            <div><label class="label" for="floor">Floor</label><input class="input" id="floor" name="floor" maxlength="60" placeholder="Ground"></div>
          </div>
          <div>
            <label class="label" for="lead_doctor_id">Lead doctor</label>
            <select class="select" id="lead_doctor_id" name="lead_doctor_id">
              <option value="0">— none yet —</option>
              <?php foreach ($doctors as $doc): ?>
                <option value="<?= (int) $doc['id'] ?>"><?= e($doc['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn-primary w-full">Create department</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Today's assignments</p>
      </div>
      <div class="p-4">
        <p class="text-[11px] leading-relaxed text-slate-500">
          Assigning a queue or a doctor to a department is what makes the per-department display
          and the department report meaningful. An unassigned queue simply does not appear there.
        </p>
        <?php if ($canManage && $departments !== []): ?>
          <form method="post" action="/departments/assign" class="mt-3 space-y-2">
            <?= csrf_field() ?>
            <div>
              <label class="label" for="department_id">Department</label>
              <select class="select" id="department_id" name="department_id" required>
                <?php foreach ($departments as $d): ?>
                  <?php if ((int) $d['is_active'] === 1): ?>
                    <option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label" for="doctor_id">Doctor (optional)</label>
              <select class="select" id="doctor_id" name="doctor_id">
                <option value="0">— not this —</option>
                <?php foreach ($doctors as $doc): ?>
                  <option value="<?= (int) $doc['id'] ?>"><?= e($doc['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label" for="queue_id">Queue (optional)</label>
              <select class="select" id="queue_id" name="queue_id">
                <option value="0">— not this —</option>
                <?php foreach ($queuesToday as $q): ?>
                  <option value="<?= (int) $q['id'] ?>">
                    <?= e($q['name']) ?> · <?= e($q['status']) ?><?= $q['department_id'] ? ' · already assigned' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn-secondary w-full">Assign</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
