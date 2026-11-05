<?php use App\Domain\Queue\QueueStateMachine; ?>
<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <div class="flex items-center gap-2">
      <a href="/app" class="text-slate-400 hover:text-slate-700"><svg class="h-4 w-4"><use href="#i-arrow-left"/></svg></a>
      <h1 class="text-xl font-bold tracking-tight text-slate-900"><?= e($queue['name']) ?></h1>
      <span class="<?= $queue['status'] === 'open' ? 'badge-emerald' : 'badge-amber' ?>"><?= e($queue['status']) ?></span>
    </div>
    <p class="mt-1 text-sm text-slate-500">
      <?= e($queue['doctor_name'] ?? 'Unassigned') ?>
      <?php if (!empty($queue['specialty'])): ?> · <?= e($queue['specialty']) ?><?php endif; ?>
      <?php if (!empty($queue['room_name'])): ?> · <?= e($queue['room_name']) ?><?php endif; ?>
      · <?= e((string) $queue['session_date']) ?>
    </p>
  </div>
  <div class="flex flex-wrap gap-2">
    <?php if (count($queues) > 1): ?>
      <form method="get" action="/queue">
        <select class="select !py-2 text-xs" name="queue_id" onchange="this.form.submit()">
          <?php foreach ($queues as $q): ?>
            <option value="<?= (int) $q['id'] ?>" <?= (int) $q['id'] === (int) $queue['id'] ? 'selected' : '' ?>>
              <?= e($q['name']) ?> (<?= (int) $q['waiting_count'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <!--
      The scan box. A barcode reader is a keyboard: it types the code into
      whatever has focus and presses Enter. So this is a real GET form with
      autofocus, and the field is wide enough to hold a whole tracking URL —
      which is what the slip's QR actually contains.
    -->
    <form method="get" action="/queue/scan" class="flex items-center gap-1.5">
      <input type="hidden" name="queue_id" value="<?= (int) $queue['id'] ?>">
      <div class="relative">
        <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"><use href="#i-qr"/></svg>
        <input class="input !py-2 pl-8 text-xs" type="text" name="code" id="scan-code"
               placeholder="Scan a slip or type A012" autocomplete="off" autocapitalize="characters"
               aria-label="Scan a token or tracking code">
      </div>
      <button class="btn-secondary !py-2 text-xs">Find</button>
    </form>
    <a href="/queue/new?queue=<?= (int) $queue['id'] ?>" class="btn-primary">
      <svg class="h-4 w-4"><use href="#i-plus"/></svg>
      New patient
    </a>
  </div>
</div>

<div class="mt-5 grid gap-4 sm:grid-cols-3">
  <div class="card card-pad">
    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Now serving</p>
    <p class="mt-1 font-mono text-3xl font-black text-teal-700" data-now-serving><?= e($serving['token_label'] ?? '—') ?></p>
    <p class="truncate text-xs text-slate-400" data-now-serving-sub><?= e($serving['full_name'] ?? 'No one in the room') ?></p>
  </div>
  <div class="card card-pad" data-queue-stats>
    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Waiting</p>
    <p class="mt-1 text-3xl font-black tabular text-slate-900" data-stat="waiting">
      <?= count(array_filter($rows, fn ($r) => $r['status'] === 'waiting')) ?>
    </p>
    <p class="text-xs text-slate-400">in this queue</p>
  </div>
  <div class="card card-pad">
    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Tokens issued</p>
    <p class="mt-1 text-3xl font-black tabular text-slate-900"><?= (int) $queue['last_token_no'] ?></p>
    <p class="text-xs text-slate-400">prefix <?= e($queue['token_prefix'] ?: '—') ?></p>
  </div>
</div>

<div class="card mt-5 overflow-hidden" data-queue-board="<?= (int) $queue['id'] ?>">
  <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
    <p class="text-xs font-semibold text-slate-600">Live queue</p>
    <span class="text-[11px] text-slate-400">priority first, then arrival order</span>
  </div>
  <div data-queue-list>
    <?php if ($rows === []): ?>
      <p class="px-4 py-12 text-center text-sm text-slate-500">This queue is empty.</p>
    <?php else: ?>
      <?php $position = 0; foreach ($rows as $entry) { if ($entry['status'] === 'waiting') { $position++; } include QP_ROOT . '/resources/views/components/queue-row.php'; } ?>
    <?php endif; ?>
  </div>
</div>
