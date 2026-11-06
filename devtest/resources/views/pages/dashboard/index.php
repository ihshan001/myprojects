<?php
/**
 * The live board. Layout, spacing and colour follow the approved reference
 * (clinicodesk.png / clinicomob.png); the numbers are the clinic's real data.
 *
 * Desktop: one white panel wrapping the greeting, five stat tiles and a
 * 1fr/352px split. Mobile: the same blocks as separate cards, stat tiles in
 * a swipeable strip, and the rail cards two-up.
 */
use App\Core\Auth;
use App\Core\Session;

$waitingTotal = 0; $inRoomTotal = 0;
foreach ($queues as $q) {
    $waitingTotal += (int) ($q['waiting_count'] ?? 0);
    $inRoomTotal += (int) ($q['active_count'] ?? 0);
}
$completed = (int) ($summary['completed'] ?? 0);
$avgWait = $summary['avg_wait'] ?? null;
$longest = (int) ($longestWait ?? 0);
$hm = static function (int $m): string {
    return $m >= 60 ? intdiv($m, 60) . 'h ' . ($m % 60) . 'm' : $m . ' min';
};
$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$parts = preg_split('/\s+/', trim((string) ($user['name'] ?? ''))) ?: [];
$shortName = implode(' ', array_slice($parts, 0, 2));

$stats = [
    ['Waiting now', (string) $waitingTotal, 'patients', 'users', 'bg-brand-50 text-brand-600'],
    ['In consultation', (string) $inRoomTotal, 'with doctor', 'users', 'bg-sky-50 text-sky-500'],
    ['Completed today', (string) $completed, 'patients', 'check-circle', 'bg-emerald-50 text-emerald-500'],
    ['Average wait', $avgWait !== null ? round((float) $avgWait) . ' min' : '—', 'today', 'clock', 'bg-violet-50 text-violet-500'],
    ['Longest wait', $longest > 0 ? $hm($longest) : '—', 'today', 'hourglass', 'bg-amber-50 text-amber-500'],
];
$glance = [
    ['Tokens issued', (int) ($summary['total'] ?? 0)],
    ['Completed', $completed],
    ['No shows', (int) ($summary['no_show'] ?? 0)],
    ['Skipped', (int) ($summary['skipped'] ?? 0)],
    ['Clinical priority', (int) ($summary['prioritised'] ?? 0)],
    ['Longest wait', $longest > 0 ? $hm($longest) : '—'],
];
?>
<div class="lg:board-panel" data-board-fragment="/app/panel?queue=<?= (int) $activeId ?>">

  <!-- ---------------------------------------------------- greeting ---- -->
  <div class="card p-4 lg:rounded-none lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-[17px] font-bold leading-snug tracking-tight text-ink-900 sm:text-[22px]">
          <?= e($greet) ?>, <?= e($shortName) ?> <span class="emoji align-middle">&#128075;</span>
        </h1>
        <div class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[10.5px] text-ink-500 sm:gap-x-2 sm:text-[12px]">
          <span class="tabular"><?= e(date('l, j F Y')) ?> &middot; <?= e(date('g:i A')) ?></span>
          <?php $p = ['stale' => false]; include QP_ROOT . '/resources/views/components/live-pill.php'; ?>
          <span class="hidden text-[11px] text-ink-400 sm:inline">Auto-updates every 5s</span>
        </div>
        <p class="text-[11px] text-ink-400 sm:hidden">Auto-updates every 5s</p>
      </div>
      <?php if ($clinic): ?>
        <a href="/display" target="_blank"
           class="flex shrink-0 items-center gap-2 rounded-xl border border-hairline bg-white px-3 py-2.5 text-[12px] font-semibold text-ink-700 shadow-card transition hover:bg-slate-50 sm:px-4">
          <svg class="h-[18px] w-[18px] shrink-0 text-ink-500"><use href="#i-monitor"/></svg>
          <span class="max-w-[72px] text-left text-[11px] leading-tight sm:max-w-none sm:text-[12px]">Waiting room screen</span>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ------------------------------------------------------- stats ---- -->
  <div class="mt-3 flex gap-1.5 overflow-x-auto pb-1 sm:mt-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:overflow-visible sm:pb-0 lg:grid-cols-5">
    <?php foreach ($stats as [$label, $value, $hint, $icon, $tone]): ?>
      <div class="stat-tile w-[104px] shrink-0 sm:w-auto">
        <span class="stat-ico <?= e($tone) ?>">
          <svg class="h-5 w-5"><use href="#i-<?= e($icon) ?>"/></svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="stat-label"><?= e($label) ?></p>
          <p class="stat-value"><?= e($value) ?></p>
          <p class="stat-hint"><?= e($hint) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- --------------------------------------------------- two columns -- -->
  <div class="mt-3 grid min-w-0 gap-3 sm:mt-7 lg:grid-cols-[minmax(0,1fr)_352px] lg:gap-7">

    <?php include QP_ROOT . '/resources/views/partials/board-panel.php'; ?>

    <!-- ================================================= right rail === -->
    <div class="grid min-w-0 grid-cols-2 gap-3 lg:block lg:space-y-5">

      <!-- Patient tracking -->
      <section class="card p-3 sm:p-4">
        <div class="flex items-start justify-between gap-2">
          <h2 class="text-[12px] font-bold tracking-tight text-ink-900 sm:text-[15px]">Patient tracking</h2>
          <span class="shrink-0 rounded-full bg-brand-50 px-1.5 py-0.5 text-[8.5px] font-semibold text-brand-700 sm:px-2 sm:text-[10px]">No app needed</span>
        </div>
        <p class="mt-1.5 text-[11.5px] leading-snug text-ink-500">
          Share this QR or link with patients to check their position in queue.
        </p>
        <?php if ($trackUrl): ?>
          <div class="mt-2.5 flex items-center gap-2.5 rounded-xl bg-slate-50 p-2.5">
            <div class="h-[68px] w-[68px] shrink-0 overflow-hidden rounded bg-white [&>svg]:h-full [&>svg]:w-full">
              <?= $trackQr ?>
            </div>
            <div class="min-w-0">
              <p class="truncate text-[11.5px] font-bold text-ink-900"><?= e(preg_replace('#^https?://#', '', $trackUrl)) ?></p>
              <p class="mt-0.5 truncate text-[10.5px] text-ink-500">
                <?= e($activeQueue['room_name'] ?? 'Waiting room') ?> &middot; <?= e($activeQueue['specialty'] ?? $clinic['name'] ?? '') ?>
              </p>
              <button type="button" data-copy="<?= e($trackUrl) ?>"
                      class="mt-1.5 inline-flex items-center gap-1 whitespace-nowrap rounded-lg border border-hairline bg-white px-1.5 py-1 text-[10.5px] font-semibold text-ink-700 shadow-card hover:bg-slate-50 sm:gap-1.5 sm:px-2 sm:text-[11px]">
                <svg class="h-3 w-3"><use href="#i-link"/></svg>Copy link
              </button>
            </div>
          </div>
          <?php if (!empty($canOperate) && empty($isDoctor)): ?>
            <a href="/queue/new" class="btn-primary mt-2.5 w-full !py-2.5 !text-[13px]">
              <svg class="h-4 w-4"><use href="#i-ticket"/></svg>Issue a token
            </a>
          <?php endif; ?>
        <?php else: ?>
          <p class="mt-3 rounded-lg bg-slate-50 p-3 text-[11.5px] text-ink-500">
            No public screen key yet. Create one to let patients follow the queue from their own phone.
          </p>
          <?php if (Auth::can('clinic.manage')): ?>
            <a href="/settings" class="btn-secondary mt-2.5 w-full !py-2 !text-[12px]">Set up waiting room screen</a>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <!-- Today at a glance -->
      <section class="card p-3 sm:p-4">
        <h2 class="text-[12.5px] font-bold tracking-tight text-ink-900 sm:text-[15px]">Today at a glance</h2>
        <div class="mt-1.5">
          <?php foreach ($glance as [$label, $val]): ?>
            <div class="glance-row">
              <span class="text-ink-500"><?= e($label) ?></span>
              <span class="font-bold text-ink-900 tabular"><?= e((string) $val) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (Auth::can('reports.view')): ?>
          <a href="/reports" class="mt-2 flex items-center justify-center gap-1.5 py-1 text-[12px] font-semibold text-ink-700 hover:text-brand-700">
            <svg class="h-3.5 w-3.5"><use href="#i-external"/></svg>Open full reports
          </a>
        <?php endif; ?>
      </section>

      <!-- System status -->
      <section class="card col-span-2 p-3 sm:p-4">
        <h2 class="text-[14px] font-bold tracking-tight text-ink-900 sm:text-[15px]">System status</h2>
        <div class="mt-2 flex flex-wrap items-center gap-x-6 gap-y-1.5">
          <p class="flex items-center gap-2 text-[12px] font-semibold text-brand-700" data-live-text>
            <svg class="h-[18px] w-[18px]"><use href="#i-shield-check"/></svg>
            All systems operational
          </p>
          <p class="flex items-center gap-2 text-[11px] text-ink-500">
            Last updated: <span class="tabular" data-last-updated><?= e(date('g:i:s A')) ?></span>
            <button type="button" data-refresh-now class="text-ink-500 hover:text-brand-700" aria-label="Refresh now">
              <svg class="h-3.5 w-3.5"><use href="#i-refresh"/></svg>
            </button>
          </p>
        </div>
      </section>
    </div>
  </div>
</div>
