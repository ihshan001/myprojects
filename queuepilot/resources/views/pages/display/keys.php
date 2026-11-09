<?php
/**
 * Manage the keys that open the public screens.
 *
 * Each screen gets its own key so one can be revoked — a TV that is moved,
 * retired or compromised — without every other screen in the clinic going
 * dark. That was impossible when the whole clinic shared one key.
 *
 * @var array      $displayKeys, $departments
 * @var array|null $issuedKey  shown once, straight after issuing
 * @var string     $legacyKey  the pre-migration shared key, if one exists
 */
?>

<div class="card card-pad mb-4">
  <h1 class="text-2xl font-bold tracking-tight text-slate-900">Public screens</h1>
  <p class="mt-1 max-w-prose text-sm text-slate-500">
    Keys that open the waiting-room board. Give each screen its own key so you can take one offline without
    affecting the others. A key is shown once when it is issued and cannot be read back afterwards.
  </p>
</div>

<?php if ($issuedKey !== null): ?>
  <div class="mb-4 rounded-xl border border-teal-200 bg-teal-50 p-4">
    <p class="text-sm font-semibold text-teal-900">Key for <?= e($issuedKey['name']) ?></p>
    <p class="mt-2 break-all font-mono text-sm text-teal-900"><?= e($issuedKey['key']) ?></p>
    <p class="mt-2 text-xs text-teal-800">
      Open <span class="font-mono">/board/<?= e($issuedKey['key']) ?></span> on the screen.
      Copy it now — this is the only time it is shown.
    </p>
  </div>
<?php endif; ?>

<?php if ($legacyKey !== ''): ?>
  <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
    <p class="text-sm font-semibold text-amber-900">This clinic still has one shared key</p>
    <p class="mt-1 text-xs text-amber-800">
      It was issued before screens could be named, so it cannot be revoked individually. Issue a named key
      below, point the screen at it, then rotate the shared key to retire it.
    </p>
  </div>
<?php endif; ?>

<!-- ── Issue ──────────────────────────────────────────────────────────── -->
<div class="card card-pad mb-4">
  <h2 class="text-sm font-bold text-slate-900">Issue a key</h2>
  <form method="post" action="/display/keys" class="mt-3 flex flex-wrap items-end gap-3">
    <?= csrf_field() ?>
    <div class="min-w-48 flex-1">
      <label class="label" for="key-name">Screen name</label>
      <input class="input" id="key-name" name="name" required maxlength="80"
             placeholder="Reception TV, Ward 2 landing…">
    </div>
    <div class="w-44">
      <label class="label" for="key-kind">Shows</label>
      <select class="select" id="key-kind" name="kind">
        <option value="waiting_room">Whole clinic</option>
        <?php if ($departments !== []): ?>
          <option value="department">One department</option>
        <?php endif; ?>
      </select>
    </div>
    <?php if ($departments !== []): ?>
      <div class="w-48">
        <label class="label" for="key-dept">Department</label>
        <select class="select" id="key-dept" name="department_id">
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <button class="btn-primary"><svg class="h-4 w-4"><use href="#i-plus"/></svg>Issue key</button>
  </form>
</div>

<!-- ── Existing screens ───────────────────────────────────────────────── -->
<div class="card overflow-hidden">
  <div class="border-b border-slate-100 px-4 py-3">
    <h2 class="text-sm font-bold text-slate-900">Screens</h2>
  </div>

  <?php if ($displayKeys === []): ?>
    <p class="px-4 py-10 text-center text-sm text-slate-500">
      No named screens yet. Issue one above to give a display its own key.
    </p>
  <?php else: ?>
    <ul class="divide-y divide-slate-100">
      <?php foreach ($displayKeys as $k): ?>
        <?php $revoked = $k['revoked_at'] !== null; ?>
        <li class="flex flex-wrap items-center gap-3 px-4 py-3 <?= $revoked ? 'opacity-60' : '' ?>">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-slate-800"><?= e($k['name']) ?></p>
            <p class="text-xs text-slate-500">
              <?= e($k['kind'] === 'department' ? ('Department · ' . ($k['department_name'] ?? '')) : 'Whole clinic') ?>
              · issued <?= e(fmt_time($k['created_at'], 'j M Y')) ?>
              <?php if ($k['last_used_at'] !== null): ?>
                · last seen <?= e(ago($k['last_used_at'])) ?>
              <?php endif; ?>
            </p>
          </div>

          <?php if ($revoked): ?>
            <span class="badge-slate">Revoked <?= e(fmt_time($k['revoked_at'], 'j M Y')) ?></span>
          <?php else: ?>
            <span class="badge-emerald">Live</span>
            <form method="post" action="/display/keys/<?= (int) $k['id'] ?>/revoke"
                  onsubmit="return confirm('Revoke this screen? Only this display stops updating.')">
              <?= csrf_field() ?>
              <button class="act-stop">Revoke</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
