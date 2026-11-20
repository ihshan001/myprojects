<?php
/**
 * The doctor's working screen.
 *
 * Deliberately narrower than the reception board. The PDF asks for one
 * primary action per screen and for doctor controls to be touch-friendly,
 * so the patient in the room gets a single large button and everything else
 * (transfer, hold, cancel) sits behind a disclosure — present, but not
 * competing with the action the doctor came to take.
 *
 * @var array|null $queue, $session, $nowServing, $nextWaiting, $estimate, $doctor
 * @var array      $board
 * @var int        $waiting
 */
use App\Domain\Queue\QueueStateMachine;

// The patient the big panel is about: whoever is in the room, or, if the room
// is empty, whoever the next press will bring in.
$subject = $nowServing ?? $nextWaiting;
$subjectStatus = $subject['status'] ?? null;
$inRoom = $nowServing !== null;

$sessionState = $session['state'] ?? 'not_started';
$canCall = ($session['started'] ?? false) === true && $sessionState !== 'closed';
?>

<?php if ($queue === null): ?>
  <div class="card card-pad">
    <?php if ($doctorId === null): ?>
      <h2 class="text-base font-semibold text-slate-800">This account is not linked to a doctor</h2>
      <p class="mt-1 max-w-prose text-sm text-slate-500">
        The doctor workspace works from your doctor profile. Ask a clinic owner to open
        <span class="font-medium text-slate-700">Doctors &amp; rooms</span>, edit your doctor, and attach this
        login to it. Until then there is no queue to show you.
      </p>
    <?php else: ?>
      <h2 class="text-base font-semibold text-slate-800">No queue today</h2>
      <p class="mt-1 max-w-prose text-sm text-slate-500">
        You have no session scheduled for today, so there is no queue to work. Reception can still see the
        clinic board; if this is wrong, ask an owner to check your schedule.
      </p>
    <?php endif; ?>
  </div>
<?php else: ?>

  <!-- ── Session bar ─────────────────────────────────────────────────── -->
  <div class="card card-pad mb-4 flex flex-wrap items-center gap-4">
    <div class="min-w-0 flex-1">
      <div class="flex flex-wrap items-center gap-2">
        <?php
          $pill = match ($sessionState) {
              'running'     => 'badge-emerald',
              'paused'      => 'badge-amber',
              'closed'      => 'badge-slate',
              default       => 'badge-sky',
          };
        ?>
        <span class="<?= $pill ?>"><?= e($session['label'] ?? 'Not started') ?></span>
        <span class="text-sm font-medium text-slate-500"><?= e($queue['name']) ?></span>
      </div>
      <p class="mt-1 text-sm text-slate-500">
        <span class="font-semibold text-slate-800"><?= (int) $waiting ?></span> waiting
        <?php if ($estimate !== null && $waiting > 0): ?>
          · about <?= e((string) $estimate['low']) ?>–<?= e((string) $estimate['high']) ?> min for the last in line
        <?php endif; ?>
      </p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <?php if ($sessionState === 'not_started'): ?>
        <form method="post" action="/queue/session/start">
          <?= csrf_field() ?>
          <input type="hidden" name="queue_id" value="<?= (int) $queue['id'] ?>">
          <button type="submit" class="btn-lg btn-primary">
            <svg class="h-5 w-5"><use href="#i-play"/></svg>Start session
          </button>
        </form>
      <?php elseif ($sessionState === 'running'): ?>
        <form method="post" action="/queue/session">
          <?= csrf_field() ?>
          <input type="hidden" name="queue_id" value="<?= (int) $queue['id'] ?>">
          <input type="hidden" name="status" value="paused">
          <button type="submit" class="btn-secondary"><svg class="h-4 w-4"><use href="#i-pause"/></svg>Pause</button>
        </form>
      <?php elseif ($sessionState === 'paused'): ?>
        <form method="post" action="/queue/session">
          <?= csrf_field() ?>
          <input type="hidden" name="queue_id" value="<?= (int) $queue['id'] ?>">
          <input type="hidden" name="status" value="open">
          <button type="submit" class="btn-secondary"><svg class="h-4 w-4"><use href="#i-play"/></svg>Resume</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($sessionState === 'not_started'): ?>
    <div class="mb-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
      Reception can already issue tokens, but you cannot call anyone until you start the session.
    </div>
  <?php endif; ?>

  <!-- ── The patient in the room (one primary action) ─────────────────── -->
  <div class="card card-pad mb-4" data-entry-row
       data-entry='<?= e(json_encode([
           'id'      => (int) ($subject['id'] ?? 0),
           'version' => (int) ($subject['version'] ?? 0),
           'status'  => (string) $subjectStatus,
           'token'   => (string) ($subject['token_label'] ?? ''),
       ])) ?>'>

    <?php if ($subject === null): ?>
      <p class="text-sm font-semibold uppercase tracking-wider text-slate-400">Now serving</p>
      <p class="mt-3 text-2xl font-semibold text-slate-800">No one in the room</p>
      <p class="mt-1 text-sm text-slate-500">
        <?= $waiting > 0 ? 'The waiting list is empty for now.' : 'Nobody is waiting for you right now.' ?>
      </p>
    <?php else: ?>
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
          <p class="text-sm font-semibold uppercase tracking-wider text-slate-400">
            <?= $inRoom ? 'Now serving' : 'Next up' ?>
          </p>
          <p class="token-hero mt-1"><?= e($subject['token_label']) ?></p>
          <p class="mt-1 text-lg font-medium text-slate-800"><?= e($subject['full_name'] ?? '') ?></p>
          <div class="mt-2">
            <?php $status = (string) $subjectStatus; include QP_ROOT . '/resources/views/components/badge.php'; ?>
          </div>
          <?php if ($subjectStatus === QueueStateMachine::IN_CONSULTATION && !empty($subject['started_at'])): ?>
            <p class="mt-2 text-sm text-slate-500 tabular">
              In the room <?= e((string) minutes_between($subject['started_at'])) ?> min
            </p>
          <?php endif; ?>
        </div>
      </div>

      <!-- The single primary action for the current state. -->
      <div class="mt-5 flex flex-wrap gap-2">
        <?php if ($subjectStatus === QueueStateMachine::WAITING): ?>
          <button type="button" class="btn-lg btn-primary" data-act="call" <?= $canCall ? '' : 'disabled' ?>>
            <svg class="h-5 w-5"><use href="#i-megaphone"/></svg>Call next patient
          </button>
        <?php elseif ($subjectStatus === QueueStateMachine::CALLED): ?>
          <button type="button" class="btn-lg btn-primary" data-act="start">
            <svg class="h-5 w-5"><use href="#i-play"/></svg>Start consultation
          </button>
          <button type="button" class="btn-lg btn-secondary" data-act="recall">Call again</button>
        <?php elseif ($subjectStatus === QueueStateMachine::IN_CONSULTATION): ?>
          <button type="button" class="btn-lg btn-primary" data-act="complete_next">
            <svg class="h-5 w-5"><use href="#i-check"/></svg>Complete &amp; call next
          </button>
          <button type="button" class="btn-lg btn-secondary" data-act="complete">
            <svg class="h-5 w-5"><use href="#i-check"/></svg>Complete only
          </button>
        <?php endif; ?>

        <?php if (!$canCall && in_array($subjectStatus, [QueueStateMachine::WAITING], true)): ?>
          <p class="w-full text-sm text-slate-500">Start the session before calling anyone.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Waiting list, with the rest of the actions disclosed ─────────── -->
  <div class="card">
    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
      <h2 class="text-sm font-semibold text-slate-700">Waiting list</h2>
      <span class="text-xs text-slate-400"><?= count($board) ?> on the board</span>
    </div>

    <?php if ($board === []): ?>
      <p class="px-4 py-10 text-center text-sm text-slate-500">Nobody on the board yet.</p>
    <?php else: ?>
      <?php $position = 0; ?>
      <?php foreach ($board as $entry): ?>
        <?php
          if ($entry['status'] === QueueStateMachine::WAITING) {
              $position++;
          }
          // Reuse the shared row so the queue's action rules live in one place
          // rather than being restated for this screen.
          $canOperate = true;
          include QP_ROOT . '/resources/views/components/queue-row.php';
        ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php endif; ?>
