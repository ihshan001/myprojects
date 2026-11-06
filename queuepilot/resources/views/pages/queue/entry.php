<?php use App\Domain\Queue\QueueStateMachine; $meta = QueueStateMachine::meta((string) $entry['status']); ?>
<div class="mx-auto max-w-3xl">
  <a href="/app" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-800">
    <svg class="h-3.5 w-3.5"><use href="#i-arrow-left"/></svg> Back to board
  </a>

  <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
    <div>
      <div class="flex items-center gap-2.5">
        <h1 class="font-mono text-3xl font-black tracking-tight text-slate-900"><?= e($entry['token_label']) ?></h1>
        <?php $b = ['status' => $entry['status']]; include QP_ROOT . '/resources/views/components/badge.php'; ?>
        <?php if ((int) $entry['priority'] === 1): ?><span class="badge-priority">Priority</span><?php endif; ?>
      </div>
      <p class="mt-1 text-sm text-slate-600">
        <?= e($entry['full_name']) ?> · <?= e($entry['queue_name']) ?>
      </p>
    </div>
    <div class="text-right">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Position</p>
      <p class="text-2xl font-black tabular text-slate-900"><?= $position > 0 ? (int) $position : '—' ?></p>
    </div>
  </div>

  <div class="mt-5 grid gap-4 sm:grid-cols-2">
    <div class="card card-pad">
      <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400">Visit</h2>
      <dl class="mt-3 space-y-2 text-sm">
        <?php foreach ([
            ['Doctor', $entry['doctor_name'] ?? '—'],
            ['Room', $entry['room_name'] ?? '—'],
            ['Source', ucfirst((string) $entry['source'])],
            ['Joined', fmt_time($entry['joined_at'], 'j M, g:i A')],
            ['Called', $entry['called_at'] ? fmt_time($entry['called_at']) : '—'],
            ['Started', $entry['started_at'] ? fmt_time($entry['started_at']) : '—'],
            ['Finished', $entry['finished_at'] ? fmt_time($entry['finished_at']) : '—'],
            ['Wait', $entry['wait_minutes'] !== null ? (int) $entry['wait_minutes'] . ' min' : '—'],
            ['Consult', $entry['consult_minutes'] !== null ? (int) $entry['consult_minutes'] . ' min' : '—'],
        ] as [$k, $v]): ?>
          <div class="flex items-center justify-between border-b border-slate-100 pb-2 last:border-0 last:pb-0">
            <dt class="text-slate-500"><?= e($k) ?></dt>
            <dd class="font-medium text-slate-800"><?= e((string) $v) ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
      <?php if (!empty($entry['priority_reason'])): ?>
        <?php if (!empty($entry['priority_rule_id'])): ?>
          <!-- A standing rule decided this, not a person. Say so; staff who
               see a red alert on a routine patient will look for an emergency
               that does not exist. -->
          <div class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-[11px] text-indigo-900">
            <b>Priority set by rule:</b> <?= e($entry['priority_reason']) ?>
            <div class="mt-0.5 text-indigo-700">A standing rule at this clinic. Staff can still override it on this patient.</div>
          </div>
        <?php else: ?>
          <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] text-red-800">
            <b>Priority reason:</b> <?= e($entry['priority_reason']) ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($entry['note'])): ?>
        <div class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-[11px] text-slate-600">
          <b>Note:</b> <?= e($entry['note']) ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="space-y-4">
      <?php if ($canOperate): ?>
        <div class="card card-pad">
          <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400">Actions</h2>
          <form method="post" action="/queue/action" class="mt-3 grid grid-cols-2 gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
            <input type="hidden" name="version" value="<?= (int) $entry['version'] ?>">
            <?php foreach (QueueStateMachine::actions((string) $entry['status']) as $action): ?>
              <?php if ($action === 'priority') continue; ?>
              <button class="btn-secondary justify-start text-xs" name="action" value="<?= e($action) ?>">
                <?= e(ucfirst(str_replace('_', ' ', $action))) ?>
              </button>
            <?php endforeach; ?>
          </form>
          <?php if (in_array('priority', QueueStateMachine::actions((string) $entry['status']), true) && App\Core\Auth::can('queue.priority')): ?>
            <form method="post" action="/queue/action" class="mt-2 flex gap-2">
              <?= csrf_field() ?>
              <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
              <input type="hidden" name="version" value="<?= (int) $entry['version'] ?>">
              <input type="hidden" name="action" value="priority">
              <input class="input text-xs" name="reason" required placeholder="Clinical reason (required, audited)">
              <button class="btn-danger shrink-0 text-xs">Prioritise</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($steps)): ?>
        <div class="card card-pad">
          <div class="flex items-baseline justify-between">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400">Service steps</h2>
            <span class="text-[10px] text-slate-400">
              <?= count(array_filter($steps, static fn ($st) => $st['done'])) ?>/<?= count($steps) ?> done
            </span>
          </div>
          <p class="mt-1 text-[11px] leading-relaxed text-slate-500">
            This visit cannot be completed until every required step is ticked.
          </p>
          <ul class="mt-3 space-y-2">
            <?php foreach ($steps as $step): ?>
              <li class="flex items-start gap-2.5">
                <?php if ($canOperate && $step['done']): ?>
                  <form method="post" action="/queue/step" class="shrink-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                    <input type="hidden" name="step_id" value="<?= (int) $step['id'] ?>">
                    <input type="hidden" name="undo" value="1">
                    <button class="btn-secondary btn-sm text-[10px]" type="submit" title="Reopen this step">Undo</button>
                  </form>
                <?php elseif ($canOperate): ?>
                  <form method="post" action="/queue/step" class="shrink-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                    <input type="hidden" name="step_id" value="<?= (int) $step['id'] ?>">
                    <button class="btn-primary btn-sm text-[10px]" type="submit">Tick</button>
                  </form>
                <?php endif; ?>
                <div class="min-w-0 flex-1">
                  <p class="text-xs <?= $step['done'] ? 'font-medium text-slate-800' : 'text-slate-700' ?>">
                    <?php if ($step['done']): ?>
                      <span class="mr-1 text-emerald-600">✓</span>
                    <?php else: ?>
                      <span class="mr-1 text-slate-300">○</span>
                    <?php endif; ?>
                    <?= e($step['name']) ?>
                    <?php if ($step['is_required'] !== 1): ?>
                      <span class="text-[10px] text-slate-400">(optional)</span>
                    <?php endif; ?>
                  </p>
                  <?php if ($step['done'] && $step['done_at']): ?>
                    <p class="text-[10px] text-slate-400">
                      <?= e(fmt_time($step['done_at'], 'j M, g:i A')) ?><?php if (!empty($step['done_by'])): ?> · <?= e($step['done_by']) ?><?php endif; ?>
                    </p>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="card card-pad">
        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400">History</h2>
        <ol class="mt-3 space-y-3">
          <?php foreach ($events as $ev): ?>
            <li class="flex gap-2.5">
              <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-slate-300"></span>
              <div class="min-w-0">
                <p class="text-xs font-medium text-slate-800"><?= e(str_replace(['entry.', 'token_'], '', (string) $ev['action'])) ?>
                  <?php if ($ev['from_status'] || $ev['to_status']): ?>
                    <span class="text-slate-400"><?= e((string) $ev['from_status']) ?> → <?= e((string) $ev['to_status']) ?></span>
                  <?php endif; ?>
                </p>
                <p class="text-[10px] text-slate-400">
                  <?= e(fmt_time($ev['created_at'], 'j M, g:i:s A')) ?>
                  <?php if (!empty($ev['actor'])): ?> · <?= e($ev['actor']) ?><?php endif; ?>
                  <?php if (!empty($ev['reason'])): ?> · <?= e($ev['reason']) ?><?php endif; ?>
                </p>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if ($events === []): ?>
            <li class="text-xs text-slate-400">No events recorded yet.</li>
          <?php endif; ?>
        </ol>
      </div>
    </div>
  </div>
</div>
