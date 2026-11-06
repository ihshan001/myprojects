<?php
/**
 * One waiting patient, exactly as the reference board draws it:
 * token chip | name + age·gender | est. wait | actions | overflow.
 *
 * The markup here is mirrored by rowHtml() in public/assets/js/app.js —
 * the 5s poller replaces this list, so the two must stay identical or the
 * design silently reverts five seconds after load.
 *
 * @var array $row
 * @var array|null $est  ['label' => '12–22 min', ...]
 * @var array $actions   ability-filtered action verbs
 * @var bool $compact    mobile drops the secondary actions into the overflow
 */
$by = (int) ($row['birth_year'] ?? 0);
$age = $by > 1900 ? max(0, (int) date('Y') - $by) : null;
$gender = ucfirst((string) ($row['gender'] ?? ''));
$bits = array_values(array_filter([$age !== null ? $age . ' years' : null, $gender !== '' ? $gender : null]));
$estLabel = $est['label'] ?? null;
$joined = fmt_time($row['joined_at'], 'g:i A');
$eid = (int) $row['id'];
$payload = htmlspecialchars(json_encode([
    'id' => $eid, 'version' => (int) $row['version'],
    'status' => (string) $row['status'], 'token' => (string) $row['token_label'],
]), ENT_QUOTES, 'UTF-8');
?>
<div class="prow" data-entry-row data-prow data-entry='<?= $payload ?>'>
  <span class="token-chip shrink-0"><?= e($row['token_label']) ?></span>

  <div class="min-w-0 flex-1">
    <p class="prow-name" title="<?= e($row['full_name']) ?>"><?= e($row['full_name']) ?></p>
    <p class="prow-meta"><?= e($bits !== [] ? implode(' · ', $bits) : (string) ($row['patient_code'] ?? '')) ?></p>
  </div>

  <div class="hidden w-[64px] shrink-0 text-right min-[400px]:block sm:w-[92px]">
    <p class="text-[10px] leading-tight text-ink-500">Est. wait</p>
    <p class="text-[12.5px] font-bold leading-tight text-ink-900 tabular"><?= e($estLabel ?? '—') ?></p>
    <p class="text-[10px] leading-tight text-ink-500 tabular"><?= e($joined) ?></p>
  </div>

  <?php if ($actions !== []): ?>
    <div class="flex shrink-0 items-center gap-1">
      <?php
      $tone = ['call' => 'act-call', 'recall' => 'act-call', 'start' => 'act-start', 'complete' => 'act-done',
               'skip' => 'act-skip', 'cancel' => 'act-stop', 'priority' => 'act-stop'];
      $word = ['call' => 'Call', 'recall' => 'Call again', 'start' => 'Start', 'complete' => 'Complete',
               'skip' => 'Skip', 'no_show' => 'No show', 'cancel' => 'Cancel', 'transfer' => 'Transfer',
               'requeue' => 'Re-queue', 'priority' => 'Priority', 'hold' => 'Hold', 'release_hold' => 'Resume'];
      $shown = 0;
      foreach ($actions as $a):
          $isPrimary = $shown === 0;
          $shown++;
          // Phones keep the first two verbs on the row; the rest live in the
          // overflow so the name column never gets squeezed to nothing.
          $hide = $shown > 2 ? 'hidden sm:inline-flex' : ($shown > 1 ? 'hidden min-[380px]:inline-flex' : '');
          ?>
        <button type="button" class="<?= e($tone[$a] ?? 'act-mute') ?> <?= $hide ?>" data-act="<?= e($a) ?>">
          <?= e($word[$a] ?? ucfirst($a)) ?>
        </button>
      <?php endforeach; ?>
      <span class="relative">
        <button type="button" class="act-mute !px-1.5" data-row-more aria-haspopup="true" aria-expanded="false"
                aria-label="More actions for <?= e($row['token_label']) ?>">
          <svg class="h-3.5 w-3.5"><use href="#i-dots"/></svg>
        </button>
        <span class="absolute right-0 top-full z-40 mt-1 hidden min-w-[9.5rem] overflow-hidden rounded-lg border border-hairline bg-white p-1 shadow-pop" data-row-menu>
          <?php
          // Everything the row does not show inline stays reachable here —
          // on a phone this is where Cancel / Transfer / Hold actually live.
          $menuActions = array_values(array_unique(array_merge($extraActions ?? [], array_slice($actions, 2))));
          ?>
          <?php if ($menuActions === []): ?>
            <span class="block px-2 py-1.5 text-[11px] text-ink-400">No further actions</span>
          <?php else: ?>
            <?php foreach ($menuActions as $a): ?>
              <button type="button" data-act="<?= e($a) ?>"
                      class="block w-full rounded px-2 py-1.5 text-left text-[12px] font-medium <?= $a === 'cancel' ? 'text-rose-600 hover:bg-rose-50' : 'text-ink-700 hover:bg-slate-50' ?>">
                <?= e($word[$a] ?? ucfirst($a)) ?>
              </button>
            <?php endforeach; ?>
          <?php endif; ?>
          <a href="/queue/<?= $eid ?>" class="block rounded px-2 py-1.5 text-[12px] font-medium text-ink-700 hover:bg-slate-50">Open visit</a>
        </span>
      </span>
    </div>
  <?php endif; ?>
</div>
