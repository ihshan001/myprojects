<?php
/**
 * The live-queue card. Rendered identically on first load and on every 5s
 * refresh (GET /app/panel), so there is no second JS template to drift out
 * of sync with the design.
 *
 * Expects: activeQueue, activeId, queues, inRoom, nextUp, waitingRows,
 *          estimates, canOperate, isDoctor
 */
use App\Core\Auth;
use App\Domain\Queue\QueueStateMachine;

$canPriority = Auth::can('queue.priority');
$verbsFor = static function (string $status) use ($canPriority): array {
    if (!Auth::can('queue.operate')) return [];
    $a = QueueStateMachine::actions($status);
    if (!$canPriority) $a = array_values(array_diff($a, ['priority']));
    return $a;
};
?>
    <section class="card min-w-0 p-3 sm:p-4" data-queue-board="<?= (int) $activeId ?>">
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <h2 class="text-[17px] font-bold tracking-tight text-ink-900">Live queue</h2>
          <?php $open = ($activeQueue['status'] ?? '') === 'open'; ?>
          <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold <?= $open ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-ink-500' ?>">
            <?= $open ? 'Open' : e(ucfirst((string) ($activeQueue['status'] ?? '—'))) ?>
          </span>
        </div>
        <div class="flex items-center gap-1.5">
          <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg border border-hairline bg-white text-ink-700 shadow-card" data-view-list aria-label="List view">
            <svg class="h-4 w-4"><use href="#i-list"/></svg>
          </button>
          <a href="/queue" class="hidden h-8 w-8 items-center justify-center rounded-lg border border-hairline bg-white text-ink-700 shadow-card sm:flex" aria-label="Open queue">
            <svg class="h-4 w-4"><use href="#i-external"/></svg>
          </a>
          <?php if ($activeQueue && Auth::can('queue.operate')): ?>
            <form method="post" action="/queue/session">
              <?= csrf_field() ?>
              <input type="hidden" name="queue_id" value="<?= (int) $activeId ?>">
              <input type="hidden" name="status" value="<?= $open ? 'paused' : 'open' ?>">
              <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-hairline bg-white text-ink-700 shadow-card"
                      title="<?= $open ? 'Pause this queue' : 'Reopen this queue' ?>">
                <svg class="h-4 w-4"><use href="#i-<?= $open ? 'pause' : 'play' ?>"/></svg>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($queues !== []): ?>
        <!-- Doctor tabs. Real navigation, so the board works without JS. -->
        <div class="mt-2 flex items-center gap-4 overflow-x-auto border-b border-hairline pb-px sm:gap-5">
          <?php foreach (array_slice($queues, 0, 3) as $q): $on = (int) $q['id'] === (int) $activeId; ?>
            <a href="/app?queue=<?= (int) $q['id'] ?>" class="qtab <?= $on ? 'qtab-active' : '' ?>"<?= $on ? ' aria-current="true"' : '' ?>>
              <?= e($q['doctor_name'] ?? $q['name']) ?>
            </a>
          <?php endforeach; ?>
          <?php if (count($queues) > 3): ?>
            <form method="get" action="/app" class="shrink-0 pb-1">
              <label class="sr-only" for="queue-pick">Jump to another doctor</label>
              <select id="queue-pick" name="queue" onchange="this.form.submit()"
                      class="qselect h-8 cursor-pointer truncate rounded border-0 bg-transparent py-0 pl-0 pr-5 text-[12px] font-medium text-ink-500 focus:ring-0">
                <option value="" selected>All doctors</option>
                <?php foreach (array_slice($queues, 3) as $q): ?>
                  <option value="<?= (int) $q['id'] ?>"><?= e($q['doctor_name'] ?? $q['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php else: ?>
            <span class="whitespace-nowrap pb-2.5 text-[11.5px] text-ink-400 sm:text-[13px]">All doctors</span>
          <?php endif; ?>
        </div>

        <p class="mt-2 flex items-center gap-1.5 text-[11px] text-ink-500">
          <span class="h-1.5 w-1.5 rounded-full <?= $open ? 'bg-brand-500' : 'bg-slate-300' ?>"></span>
          Token auto-call is <?= $open ? 'ON' : 'OFF' ?>
        </p>
      <?php endif; ?>

      <?php if (!$activeQueue): ?>
        <?php $title = 'No sessions open today'; $message = 'Open a session for one of your doctors to start issuing tokens.'; $icon = 'calendar'; $action = '<a href="/doctors" class="btn-primary">Open a session</a>'; include QP_ROOT . '/resources/views/components/empty.php'; ?>
      <?php else: ?>

        <!-- In consultation -->
        <?php if ($inRoom): ?>
          <?php
            $by = (int) ($inRoom['birth_year'] ?? 0);
            $age = $by > 1900 ? (int) date('Y') - $by : null;
            $bits = array_values(array_filter([$age ? $age . ' years' : null, ucfirst((string) ($inRoom['gender'] ?? '')) ?: null]));
            $mins = !empty($inRoom['started_at']) ? (int) minutes_between($inRoom['started_at']) : 0;
            $acts = $verbsFor((string) $inRoom['status']);
            $payload = htmlspecialchars(json_encode(['id' => (int) $inRoom['id'], 'version' => (int) $inRoom['version'],
                'status' => (string) $inRoom['status'], 'token' => (string) $inRoom['token_label']]), ENT_QUOTES, 'UTF-8');
          ?>
          <div class="spot spot-now mt-3 flex flex-wrap items-center gap-x-3 gap-y-2" data-entry-row data-entry='<?= $payload ?>'>
            <div class="shrink-0">
              <span class="spot-tag bg-sky-100 text-sky-700"><?= e(QueueStateMachine::meta((string) $inRoom['status'])['label']) ?></span>
              <div class="mt-1.5"><span class="spot-token"><?= e($inRoom['token_label']) ?></span></div>
            </div>
            <div class="min-w-0 flex-1">
              <p class="truncate text-[15px] font-bold text-ink-900 sm:text-[17px]"><?= e($inRoom['full_name']) ?></p>
              <p class="truncate text-[12px] text-ink-500"><?= e(implode(' · ', $bits)) ?></p>
            </div>
            <div class="shrink-0 text-right">
              <p class="text-[13px] font-bold text-ink-900 tabular"><?= $mins ?> min</p>
              <p class="text-[11px] text-ink-500 tabular"><?= e(fmt_time($inRoom['started_at'] ?? $inRoom['joined_at'], 'g:i A')) ?></p>
            </div>
            <?php if ($acts !== []): ?>
              <div class="flex w-full shrink-0 flex-wrap justify-end gap-1.5 sm:w-auto">
                <?php foreach ($acts as $a):
                  $tone = $a === 'complete' ? 'act-done' : ($a === 'cancel' ? 'act-stop' : 'act-mute');
                  $word = ['complete' => 'Completed', 'transfer' => 'Transfer', 'cancel' => 'Cancel'][$a] ?? ucfirst($a); ?>
                  <button type="button" class="<?= $tone ?> !bg-white !px-3 !py-1.5 !text-[12px]" data-act="<?= e($a) ?>"><?= e($word) ?></button>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Next in line -->
        <?php if ($nextUp): ?>
          <?php
            $by = (int) ($nextUp['birth_year'] ?? 0);
            $age = $by > 1900 ? (int) date('Y') - $by : null;
            $bits = array_values(array_filter([$age ? $age . ' years' : null, ucfirst((string) ($nextUp['gender'] ?? '')) ?: null]));
            $est = $estimates[(int) $nextUp['id']] ?? null;
            $acts = $verbsFor('waiting');
            $primary = in_array('call', $acts, true) ? 'call' : ($acts[0] ?? null);
            $rest = array_values(array_filter($acts, static fn ($a) => $a !== $primary && $a !== 'no_show'));
            $payload = htmlspecialchars(json_encode(['id' => (int) $nextUp['id'], 'version' => (int) $nextUp['version'],
                'status' => 'waiting', 'token' => (string) $nextUp['token_label']]), ENT_QUOTES, 'UTF-8');
          ?>
          <div class="spot spot-next mt-3 flex flex-wrap items-center gap-x-3 gap-y-2" data-entry-row data-entry='<?= $payload ?>'>
            <div class="shrink-0">
              <span class="spot-tag bg-amber-100 text-amber-700">Next in line</span>
              <div class="mt-1.5"><span class="spot-token"><?= e($nextUp['token_label']) ?></span></div>
            </div>
            <div class="min-w-0 flex-1">
              <p class="truncate text-[15px] font-bold text-ink-900 sm:text-[17px]"><?= e($nextUp['full_name']) ?></p>
              <p class="truncate text-[12px] text-ink-500"><?= e(implode(' · ', $bits)) ?></p>
            </div>
            <div class="flex w-full shrink-0 flex-col items-end gap-2 sm:w-auto">
              <div class="flex w-full items-center justify-end gap-3">
                <div class="text-center">
                  <p class="text-[10px] leading-tight text-ink-500">Est. wait</p>
                  <p class="text-[13px] font-bold leading-tight text-ink-900 tabular"><?= e($est['label'] ?? '—') ?></p>
                  <p class="text-[10px] leading-tight text-ink-500 tabular"><?= e(fmt_time($nextUp['joined_at'], 'g:i A')) ?></p>
                </div>
                <?php if ($primary): ?>
                  <button type="button" class="btn-primary shrink-0 !px-3 !py-2 !text-[12px]" data-act="<?= e($primary) ?>">
                    <svg class="h-4 w-4"><use href="#i-<?= $primary === 'call' ? 'megaphone' : 'play' ?>"/></svg>
                    Call next
                  </button>
                <?php endif; ?>
              </div>
              <?php if ($rest !== []): ?>
                <div class="flex flex-wrap justify-end gap-1.5">
                  <?php foreach ($rest as $a):
                    $tone = in_array($a, ['cancel', 'priority'], true) ? 'act-stop' : 'act-mute';
                    $word = ['skip' => 'Skip', 'cancel' => 'Cancel', 'priority' => 'Priority', 'transfer' => 'Transfer', 'hold' => 'Hold'][$a] ?? ucfirst($a); ?>
                    <button type="button" class="<?= $tone ?> !bg-white !px-3 !py-1.5 !text-[12px]" data-act="<?= e($a) ?>"><?= e($word) ?></button>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Waiting list -->
        <div class="mt-4">
          <h3 class="px-1 text-[13px] font-bold text-ink-900">
            Waiting in queue <span class="ml-1 font-semibold text-ink-500">(<span data-stat="waiting"><?= count($waitingRows) ?></span>)</span>
          </h3>
          <div class="mt-1.5 rounded-xl border border-hairline" data-queue-list>
            <?php if ($waitingRows === []): ?>
              <p class="px-4 py-8 text-center text-[13px] text-ink-500">No patients waiting right now.</p>
            <?php else: ?>
              <?php foreach (array_slice($waitingRows, 0, 5) as $row): ?>
                <?php
                  $est = $estimates[(int) $row['id']] ?? null;
                  $actions = array_values(array_diff($verbsFor('waiting'), ['no_show', 'priority']));
                  $extraActions = array_values(array_intersect($verbsFor('waiting'), ['no_show', 'priority']));
                  include QP_ROOT . '/resources/views/components/board/patient-row.php';
                ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php if (count($waitingRows) > 5): ?>
            <a href="/queue?queue_id=<?= (int) $activeId ?>" class="mt-2 flex items-center justify-center gap-1.5 py-1 text-[12px] font-semibold text-ink-700 hover:text-brand-700">
              View all in queue (<?= count($waitingRows) ?>)
              <svg class="h-3.5 w-3.5"><use href="#i-chevron-down"/></svg>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>

