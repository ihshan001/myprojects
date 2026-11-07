<?php use App\Domain\Queue\QueueStateMachine; ?>
<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-queue"/></svg></span>
    <div class="min-w-0">
    <div class="flex items-center gap-2">
      <a href="/app" class="text-ink-400 hover:text-ink-700"><svg class="h-4 w-4"><use href="#i-arrow-left"/></svg></a>
      <h1 class="text-xl font-bold tracking-tight text-ink-900"><?= e($queue['name']) ?></h1>
      <span class="<?= $queue['status'] === 'open' ? 'badge-emerald' : 'badge-amber' ?>"><?= e($queue['status']) ?></span>
    </div>
    <p class="page-sub">
      <?= e($queue['doctor_name'] ?? 'Unassigned') ?>
      <?php if (!empty($queue['specialty'])): ?> · <?= e($queue['specialty']) ?><?php endif; ?>
      <?php if (!empty($queue['room_name'])): ?> · <?= e($queue['room_name']) ?><?php endif; ?>
      · <?= e((string) $queue['session_date']) ?>
    </p>
  </div>
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
        <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400"><use href="#i-qr"/></svg>
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

<?php
// "Open" and "the doctor is in the room" are different facts. Saying which
// one is true, in words, is the whole point of this banner — a board that
// silently allows calls is how a patient is called into an empty room.
$session = $session ?? ['started' => true, 'state' => 'running', 'label' => 'Running'];
$tone = match ($session['state']) {
    'not_started' => 'border-amber-300 bg-amber-50 text-amber-900',
    'paused' => 'border-hairline bg-slate-100 text-ink-700',
    'closed' => 'border-hairline bg-slate-100 text-ink-700',
    default => 'border-emerald-200 bg-emerald-50 text-emerald-900',
};
?>
<div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border <?= $tone ?> px-4 py-3">
  <div>
    <p class="text-sm font-semibold">
      <?php if ($session['state'] === 'not_started'): ?>
        The doctor has not started this session yet
      <?php elseif ($session['state'] === 'running'): ?>
        Session running — patients can be called
      <?php else: ?>
        <?= e($session['label']) ?>
      <?php endif; ?>
    </p>
    <p class="mt-0.5 text-xs leading-relaxed opacity-80">
      <?php if ($session['state'] === 'not_started'): ?>
        Tokens are still being issued and queued. Press start when the doctor is actually
        ready to see patients — calling is blocked until then, so nobody is called into an empty room.
      <?php elseif ($session['state'] === 'running'): ?>
        Started <?= e(date('g:i A', strtotime((string) $queue['started_at']))) ?>.
        <?= (int) \App\Core\Db::scalar('SELECT COUNT(*) FROM queue_entries WHERE queue_id = ? AND status = ?', [(int) $queue['id'], 'waiting']) ?> waiting.
      <?php else: ?>
        Patients keep their place. Resume the session to continue calling.
      <?php endif; ?>
    </p>
  </div>

  <?php if ($session['state'] === 'not_started'): ?>
    <form method="post" action="/queue/session/start">
      <?= csrf_field() ?>
      <input type="hidden" name="queue_id" value="<?= (int) $queue['id'] ?>">
      <button class="btn w-full justify-center bg-brand-700 text-sm text-white sm:w-auto">
        Start session
      </button>
    </form>
  <?php endif; ?>
</div>

<div class="mt-3 grid gap-3 sm:mt-6 sm:gap-4 sm:grid-cols-3">
  <div class="card card-pad">
    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-400">Now serving</p>
    <p class="mt-1 font-mono text-3xl font-black text-brand-700" data-now-serving><?= e($serving['token_label'] ?? '—') ?></p>
    <p class="truncate text-xs text-ink-400" data-now-serving-sub><?= e($serving['full_name'] ?? 'No one in the room') ?></p>
  </div>
  <div class="card card-pad" data-queue-stats>
    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-400">Waiting</p>
    <p class="mt-1 text-3xl font-black tabular text-ink-900" data-stat="waiting">
      <?= count(array_filter($rows, fn ($r) => $r['status'] === 'waiting')) ?>
    </p>
    <p class="text-xs text-ink-400">in this queue</p>
  </div>
  <div class="card card-pad">
    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-400">Tokens issued</p>
    <p class="mt-1 text-3xl font-black tabular text-ink-900"><?= (int) $queue['last_token_no'] ?></p>
    <p class="text-xs text-ink-400">prefix <?= e($queue['token_prefix'] ?: '—') ?></p>
  </div>
</div>

<div class="card mt-3 sm:mt-5 overflow-hidden" data-queue-board="<?= (int) $queue['id'] ?>">
  <div class="section-head">
    <p class="section-title">Live queue</p>
    <span class="text-[11px] text-ink-400">priority first, then arrival order</span>
  </div>
  <div data-queue-list>
    <?php if ($rows === []): ?>
      <p class="px-4 py-12 text-center text-sm text-ink-500">This queue is empty.</p>
    <?php else: ?>
      <?php $position = 0; foreach ($rows as $entry) { if ($entry['status'] === 'waiting') { $position++; } include QP_ROOT . '/resources/views/components/queue-row.php'; } ?>
    <?php endif; ?>
  </div>
</div>
