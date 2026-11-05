<?php
use App\Core\Session;
use App\Domain\Queue\QueueStateMachine;

$status = $summary['status'] ?? null;
$waitingTotal = 0;
$inRoomTotal = 0;
foreach ($queues as $q) {
    $waitingTotal += (int) ($q['waiting_count'] ?? 0);
    $inRoomTotal += (int) ($q['active_count'] ?? 0);
}
$completed = (int) ($summary['completed'] ?? 0);
$avgWait = $summary['avg_wait'] ?? null;
?>
<div class="flex flex-wrap items-start justify-between gap-4">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Live board</h1>
    <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
      <span><?= e(date('l, j F Y')) ?></span>
      <span class="text-slate-300">·</span>
      <?php $p = ['stale' => false]; include QP_ROOT . '/resources/views/components/live-pill.php'; ?>
      <span class="text-xs text-slate-400">auto-updates every 5s</span>
    </p>
  </div>
  <div class="flex flex-wrap gap-2">
    <a href="/display" target="_blank" class="btn-secondary">
      <svg class="h-4 w-4"><use href="#i-monitor"/></svg>
      Waiting room screen
    </a>
    <?php if (!empty($canOperate) && empty($isDoctor)): ?>
      <a href="/queue/new" class="btn-primary">
        <svg class="h-4 w-4"><use href="#i-plus"/></svg>
        New patient
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <?php
  $stats = [
      ['Waiting now', $waitingTotal, 'clock', 'amber', $waitingTotal > 0 ? 'in ' . count(array_filter($queues, fn ($q) => (int) $q['waiting_count'] > 0)) . ' queue(s)' : 'none waiting'],
      ['In consultation', $inRoomTotal, 'stethoscope', 'teal', 'with a doctor now'],
      ['Completed today', $completed, 'check-circle', 'emerald', 'out of ' . (int) ($summary['total'] ?? 0) . ' tokens'],
      ['Average wait', $avgWait !== null ? round((float) $avgWait) . ' min' : '—', 'chart', 'sky', 'recorded this day'],
  ];
  foreach ($stats as [$label, $value, $icon, $tone, $hint]) {
      include QP_ROOT . '/resources/views/components/stat.php';
  }
  ?>
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-3">
  <div class="space-y-5 xl:col-span-2">
    <?php if ($queues === []): ?>
      <div class="card">
        <?php $title = 'No sessions open today'; $message = 'Open a session for one of your doctors to start issuing tokens.'; $icon = 'calendar'; $action = '<a href="/doctors" class="btn-primary">Open a session</a>'; include QP_ROOT . '/resources/views/components/empty.php'; ?>
      </div>
    <?php endif; ?>

    <?php foreach ($queues as $queue): ?>
      <?php
      $qid = (int) $queue['id'];
      $nowServing = $serving[$qid] ?? null;
      $rows = $byQueue[$qid] ?? [];
      $isOpen = $queue['status'] === 'open';
      ?>
      <section class="card overflow-hidden" data-queue-board="<?= $qid ?>">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50/60 px-4 py-3">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="truncate text-sm font-bold text-slate-900"><?= e($queue['name']) ?></h2>
              <span class="<?= $isOpen ? 'badge-emerald' : ($queue['status'] === 'paused' ? 'badge-amber' : 'badge-slate') ?>">
                <?= e(ucfirst($queue['status'])) ?>
              </span>
            </div>
            <p class="mt-0.5 truncate text-xs text-slate-500">
              <?= e($queue['doctor_name'] ?? 'Unassigned') ?>
              <?php if (!empty($queue['specialty'])): ?> · <?= e($queue['specialty']) ?><?php endif; ?>
              <?php if (!empty($queue['room_name'])): ?> · <?= e($queue['room_name']) ?><?php endif; ?>
            </p>
          </div>

          <div class="flex items-center gap-3">
            <div class="text-right" data-now-serving-wrap>
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Now serving</p>
              <p class="font-mono text-lg font-black leading-tight text-teal-700" data-now-serving>
                <?= e($nowServing['token_label'] ?? '—') ?>
              </p>
              <p class="max-w-[9rem] truncate text-[11px] text-slate-400" data-now-serving-sub>
                <?= e($nowServing['full_name'] ?? 'No one in the room') ?>
              </p>
            </div>
            <form method="post" action="/queue/session" class="flex items-center gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="queue_id" value="<?= $qid ?>">
              <?php if ($isOpen): ?>
                <input type="hidden" name="status" value="paused">
                <button class="btn-sm btn-secondary" title="Pause this queue (keeps every waiting patient, stops new calls)">
                  <svg class="h-3.5 w-3.5"><use href="#i-pause"/></svg>
                </button>
              <?php else: ?>
                <input type="hidden" name="status" value="open">
                <button class="btn-sm btn-secondary" title="Reopen this queue">
                  <svg class="h-3.5 w-3.5"><use href="#i-play"/></svg>
                </button>
              <?php endif; ?>
            </form>
            <?php $pending = Session::takeFlash('pending_close'); ?>
            <?php if (is_array($pending) && (int) $pending['queue_id'] === $qid): ?>
              <form method="post" action="/queue/session" class="flex items-center gap-1">
                <?= csrf_field() ?>
                <input type="hidden" name="queue_id" value="<?= $qid ?>">
                <input type="hidden" name="status" value="closed">
                <input type="hidden" name="resolve" value="acknowledge">
                <button class="btn-sm btn-stop" title="Close the day and mark unseen patients as not seen">
                  Close day · <?= (int) $pending['waiting'] ?> unseen
                </button>
              </form>
            <?php else: ?>
              <form method="post" action="/queue/session" class="flex items-center gap-1">
                <?= csrf_field() ?>
                <input type="hidden" name="queue_id" value="<?= $qid ?>">
                <input type="hidden" name="status" value="closed">
                <button class="btn-sm btn-secondary" title="End today's session">
                  <svg class="h-3.5 w-3.5"><use href="#i-check"/></svg>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </header>

        <div data-queue-stats class="flex items-center gap-5 border-b border-slate-100 px-4 py-2 text-xs text-slate-500">
          <span>Waiting <b class="tabular text-slate-800" data-stat="waiting"><?= (int) ($queue['waiting_count'] ?? 0) ?></b></span>
          <span>In room <b class="tabular text-slate-800" data-stat="in_room"><?= (int) ($queue['active_count'] ?? 0) ?></b></span>
          <span class="ml-auto">Tokens issued <b class="tabular text-slate-800"><?= (int) $queue['last_token_no'] ?></b></span>
        </div>

        <div data-queue-list>
          <?php if ($rows === []): ?>
            <p class="px-4 py-10 text-center text-sm text-slate-500">
              No patients in this queue right now.
              <a href="/queue/new?queue=<?= $qid ?>" class="font-semibold text-teal-700 hover:underline">Issue the first token →</a>
            </p>
          <?php else: ?>
            <?php
            $position = 0;
            foreach ($rows as $entry) {
                if ($entry['status'] === 'waiting') {
                    $position++;
                }
                $canOperate = $canOperate;
                include QP_ROOT . '/resources/views/components/queue-row.php';
            }
            ?>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <!-- right rail -->
  <div class="space-y-5">
    <div class="card card-pad">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-bold text-slate-900">Patient tracking</h3>
        <span class="badge-teal">No app needed</span>
      </div>
      <p class="mt-2 text-xs leading-relaxed text-slate-500">
        Every token gets a private link. Print it on the slip or send it by SMS — the patient sees their
        position and an honest wait range, and nothing else.
      </p>
      <a href="/queue/new<?= isset($q) && is_array($q) ? '?queue=' . (int) $q['id'] : '' ?>" class="btn-secondary mt-4 w-full">
        <svg class="h-4 w-4"><use href="#i-plus"/></svg>
        Issue a token
      </a>
    </div>

    <div class="card card-pad">
      <h3 class="text-sm font-bold text-slate-900">Today at a glance</h3>
      <dl class="mt-3 space-y-2.5 text-sm">
        <?php foreach ([
            ['Tokens issued', (int) ($summary['total'] ?? 0)],
            ['Completed', $completed],
            ['No shows', (int) ($summary['no_show'] ?? 0)],
            ['Skipped', (int) ($summary['skipped'] ?? 0)],
            ['Clinical priority', (int) ($summary['prioritised'] ?? 0)],
            ['Longest wait', ($summary['max_wait'] ?? null) !== null ? round((float) $summary['max_wait']) . ' min' : '—'],
        ] as [$label, $value]): ?>
          <div class="flex items-center justify-between border-b border-slate-100 pb-2 last:border-0 last:pb-0">
            <dt class="text-slate-500"><?= e($label) ?></dt>
            <dd class="font-semibold tabular text-slate-900"><?= e((string) $value) ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
      <a href="/reports" class="btn-ghost mt-3 w-full text-xs">Open full reports →</a>
    </div>

    <?php if (!empty($displayToken)): ?>
      <div class="card card-pad">
        <h3 class="text-sm font-bold text-slate-900">Waiting room display</h3>
        <p class="mt-2 text-xs leading-relaxed text-slate-500">
          Open on a TV or spare tablet. Shows token numbers and room names only — no patient names.
        </p>
        <div class="mt-3 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2">
          <code class="flex-1 truncate text-[11px] text-slate-600">/api/display/<?= e(substr($displayToken, 0, 10)) ?>…</code>
          <button class="rounded p-1 text-slate-400 hover:bg-white hover:text-slate-700"
                  data-copy="<?= e($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/display">
            <svg class="h-3.5 w-3.5"><use href="#i-copy"/></svg>
          </button>
        </div>
        <a href="/display" target="_blank" class="btn-secondary mt-3 w-full">
          <svg class="h-4 w-4"><use href="#i-monitor"/></svg>
          Open display
        </a>
      </div>
    <?php endif; ?>

    <div class="card card-pad">
      <h3 class="text-sm font-bold text-slate-900">Doctors on duty</h3>
      <div class="mt-3 space-y-2">
        <?php foreach (array_slice($doctors, 0, 6) as $doc): ?>
          <div class="flex items-center gap-2.5">
            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-teal-50 text-[10px] font-bold text-teal-700">
              <?= e(App\Core\Str::initials((string) $doc['name'])) ?>
            </span>
            <div class="min-w-0 flex-1">
              <p class="truncate text-xs font-medium text-slate-800"><?= e($doc['name']) ?></p>
              <p class="truncate text-[10px] text-slate-400"><?= e($doc['specialty'] ?? 'General') ?></p>
            </div>
            <span class="text-[10px] text-slate-400 tabular"><?= round((float) $doc['avg_consult_minutes']) ?>m avg</span>
          </div>
        <?php endforeach; ?>
        <?php if ($doctors === []): ?>
          <p class="text-xs text-slate-500">No doctors yet. <a href="/doctors" class="font-semibold text-teal-700">Add one →</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
