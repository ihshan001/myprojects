<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <div class="flex items-center gap-2 text-xs text-slate-500">
      <a href="/departments" class="hover:underline">Departments</a>
      <span>/</span>
      <span class="font-medium text-slate-700"><?= e($department['name']) ?></span>
    </div>
    <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900"><?= e($department['name']) ?> — today</h1>
    <p class="mt-1 text-sm text-slate-500">
      <?= e(date('l, j F Y')) ?>
      <?php if (!empty($department['floor'])): ?> · <?= e($department['floor']) ?><?php endif; ?>
    </p>
  </div>
  <a class="btn-secondary btn-sm" href="/app">Full board</a>
</div>

<?php if ($queues === []): ?>
  <div class="card mt-5">
    <?php $title = 'No queues in this department today'; $message = 'Either no session is scheduled, or the queues have not been assigned to this department yet.'; $icon = 'queue'; include QP_ROOT . '/resources/views/components/empty.php'; ?>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-4 md:grid-cols-2">
  <?php foreach ($queues as $q): ?>
    <div class="card card-pad">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-sm font-bold text-slate-900"><?= e($q['name']) ?></p>
          <p class="text-[11px] text-slate-500">
            <?= e($q['doctor_name'] ?? 'No doctor assigned') ?>
            <?php if (!empty($q['room_name'])): ?> · <?= e($q['room_name']) ?><?php endif; ?>
          </p>
        </div>
        <span class="<?= $q['status'] === 'open' ? 'badge-teal' : ($q['status'] === 'paused' ? 'badge-amber' : 'badge-slate') ?>">
          <?= e($q['status']) ?>
        </span>
      </div>
      <div class="mt-3 grid grid-cols-3 gap-2 text-center">
        <?php
        $waiting = (int) App\Core\Db::scalar(
            "SELECT COUNT(*) FROM queue_entries WHERE queue_id = ? AND status = 'waiting'", [(int) $q['id']]
        );
        $called = (int) App\Core\Db::scalar(
            "SELECT COUNT(*) FROM queue_entries WHERE queue_id = ? AND status = 'called'", [(int) $q['id']]
        );
        $done = (int) App\Core\Db::scalar(
            "SELECT COUNT(*) FROM queue_entries WHERE queue_id = ? AND status = 'completed'", [(int) $q['id']]
        );
        ?>
        <div class="rounded-lg bg-slate-50 py-2">
          <p class="text-lg font-bold text-slate-900"><?= $waiting ?></p>
          <p class="text-[10px] uppercase tracking-wide text-slate-500">waiting</p>
        </div>
        <div class="rounded-lg bg-sky-50 py-2">
          <p class="text-lg font-bold text-sky-900"><?= $called ?></p>
          <p class="text-[10px] uppercase tracking-wide text-sky-700">called</p>
        </div>
        <div class="rounded-lg bg-emerald-50 py-2">
          <p class="text-lg font-bold text-emerald-900"><?= $done ?></p>
          <p class="text-[10px] uppercase tracking-wide text-emerald-700">done</p>
        </div>
      </div>
      <a class="btn-secondary btn-sm mt-3 w-full justify-center" href="/queue?queue_id=<?= (int) $q['id'] ?>">Open queue</a>
    </div>
  <?php endforeach; ?>
</div>
