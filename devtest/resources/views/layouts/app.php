<?php
use App\Core\Auth;
$u = $user ?? null;
$cl = $clinic ?? null;
$current = $path ?? '/';
$isActive = static function (string $prefix) use ($current): string {
    if ($prefix === '/app') {
        return $current === '/app' ? 'nav-item-active' : '';
    }
    return str_starts_with($current, $prefix) ? 'nav-item-active' : '';
};
$roleLabel = $u ? [
    'super_admin' => 'Platform admin', 'clinic_admin' => 'Clinic owner',
    'receptionist' => 'Reception', 'doctor' => 'Doctor',
][$u['role']] ?? $u['role'] : '';
$navSections = [];
$navFooter = [];
if ($u) {
    if ($u['role'] === 'super_admin') {
        $navSections = [
            'Main' => [
                ['/admin', 'grid', 'Overview', $isActive('/admin')],
            ],
            'Tenants' => [
                ['/admin/clinics', 'building', 'Clinics', $isActive('/admin/clinics')],
                ['/admin/organisations', 'users', 'Groups', $isActive('/admin/organisations')],
                ['/admin/features', 'switch', 'Feature gates', $isActive('/admin/features')],
            ],
            'Revenue' => [
                ['/admin/plans', 'card', 'Plans & billing', $isActive('/admin/plans')],
                ['/admin/billing', 'check-circle', 'Payment verification', $isActive('/admin/billing')],
            ],
        ];
        $navFooter = [
            ['/admin/support', 'lifebuoy', 'Support queue', $isActive('/admin/support')],
        ];
    } else {
        $isDoctor = $u['role'] === 'doctor';

        // Only show destinations the current role can actually open. A doctor
        // who sees a "Patients" link that 403s learns to distrust the UI.
        $navSections = [
            'Main' => array_values(array_filter([
                ['/app', 'grid', t('nav.live_board', 'Live board'), $isActive('/app')],
                $isDoctor ? null : ['/queue/new', 'user-plus', t('nav.new_patient', 'New patient'), $isActive('/queue/new')],
            ])),
            'Operations' => array_values(array_filter([
                $isDoctor ? null : ['/appointments', 'calendar', t('nav.appointments', 'Appointments'), $isActive('/appointments')],
                $isDoctor ? null : ['/patients', 'users', t('nav.patients', 'Patients'), $isActive('/patients')],
                ['/doctors', 'stethoscope', t('nav.doctors', 'Doctors & rooms'), $isActive('/doctors') || $isActive('/rooms')],
                ['/queues/config', 'settings', 'Queue settings', $isActive('/queues/config')],
                ['/queues/priority-rules', 'sparkles', 'Priority rules', $isActive('/queues/priority-rules')],
                ['/queues/workflows', 'switch', 'Service workflows', $isActive('/queues/workflows')],
                ['/queues/appointment-policies', 'info', 'Policies', $isActive('/queues/appointment-policies')],
                ['/incidents', 'alert', 'Incidents', $isActive('/incidents')],
                ['/breaks', 'pause', 'Breaks', $isActive('/breaks')],
                ['/calendar', 'clock', 'Schedules & holidays', $isActive('/calendar')],
                $isDoctor ? null : ['/departments', 'grid', 'Departments', $isActive('/departments')],
            ])),
            'Insights' => array_values(array_filter([
                Auth::can('reports.view') ? ['/reports', 'chart', t('nav.reports', 'Reports'), $isActive('/reports')] : null,
                Auth::can('clinic.manage') ? ['/audit', 'file', 'Audit log', $isActive('/audit')] : null,
                Auth::can('billing.view') ? ['/billing', 'activity', 'Subscription', $isActive('/billing')] : null,
                (Auth::can('clinic.manage') && (Auth::clinic()['organisation_id'] ?? null) !== null)
                    ? ['/organisation', 'building', 'Group', $isActive('/organisation')] : null,
            ])),
        ];
        $navFooter = array_values(array_filter([
            ['/notifications', 'bell', 'Notifications', $isActive('/notifications')],
            ['/settings', 'settings', t('nav.settings', 'Settings'), $isActive('/settings')],
            ['/support', 'lifebuoy', 'Support', $isActive('/support')],
            ['/getting-started', 'sparkles', 'Getting started', $isActive('/getting-started')],
        ]));
    }
}
// Bottom tab bar (mobile): the four destinations a phone user reaches for.
$tabBar = [];
if ($u) {
    $tabBar = $u['role'] === 'super_admin'
        ? [['/admin', 'grid', 'Overview'], ['/admin/clinics', 'building', 'Clinics'],
           ['/admin/billing', 'card', 'Billing']]
        : array_values(array_filter([
            ['/app', 'grid', 'Live board'],
            $u['role'] === 'doctor' ? null : ['/appointments', 'calendar', 'Appointments'],
            $u['role'] === 'doctor' ? ['/doctors', 'stethoscope', 'Rooms'] : ['/patients', 'users', 'Patients'],
        ]));
}
$searchAction = $u ? ($u['role'] === 'super_admin' ? '/admin/clinics' : ($u['role'] === 'doctor' ? '/app' : '/patients')) : '/patients';
?>
<!DOCTYPE html>
<html lang="<?= e($locale ?? 'en') ?>" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0d7459">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title ?? 'ClinicQueue') ?> · <?= e($appName) ?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="/assets/img/icon-192.png">
  <link rel="preload" href="/assets/fonts/inter-400.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/assets/fonts/inter-600.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="h-full">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<?php if ($u): ?>
<?php
  $brandName = 'ClinicQueue';
  $brandTag  = 'Smart Queue. Better Care.';
  $initials  = App\Core\Str::initials((string) $u['name']);
  // "Dr. Malini" style short greeting: first two words of the display name.
  $nameParts = preg_split('/\s+/', trim((string) $u['name'])) ?: [];
  $shortName = implode(' ', array_slice($nameParts, 0, 2));
?>
<div class="shell">
  <!-- ===================================================== sidebar ===== -->
  <aside class="sidebar no-print">
    <div class="flex h-[68px] shrink-0 items-center gap-2.5 px-4">
      <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-700 shadow-sm">
        <svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>
        </svg>
      </span>
      <div class="min-w-0 leading-tight">
        <p class="truncate text-[15px] font-bold tracking-tight text-ink-900"><?= e($brandName) ?></p>
        <p class="truncate text-[10.5px] text-ink-500"><?= e($brandTag) ?></p>
      </div>
    </div>

    <?php $choices = $branchChoices ?? []; ?>
    <?php if (count($choices) > 1): ?>
      <form method="post" action="/branch/switch" class="px-4 pb-2">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($path ?? '/app') ?>">
        <label class="sr-only" for="branch-switch">Working in branch</label>
        <select id="branch-switch" name="clinic_id" onchange="this.form.submit()"
                class="w-full rounded-lg border border-hairline bg-white px-2 py-1.5 text-[11px] text-ink-700">
          <?php foreach ($choices as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) ($activeBranchId ?? 0) ? 'selected' : '' ?>>
              <?= e((string) $b['name']) ?><?= $b['is_home'] ? ' (home)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>

    <nav class="scrollbar-thin flex-1 overflow-y-auto px-3 pb-2 pt-1">
      <?php foreach ($navSections as $section => $items): ?>
        <?php if ($items === []) continue; ?>
        <p class="nav-section-label"><?= e($section) ?></p>
        <?php foreach ($items as [$href, $icon, $label, $active]): ?>
          <a href="<?= e($href) ?>" class="nav-item <?= $active ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <svg class="h-[17px] w-[17px] shrink-0"><use href="#i-<?= e($icon) ?>"/></svg>
            <span class="truncate"><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <?php if ($navFooter !== []): ?>
        <div class="mt-4 border-t border-hairline pt-2">
          <?php foreach ($navFooter as [$href, $icon, $label, $active]): ?>
            <a href="<?= e($href) ?>" class="nav-item <?= $active ?>"<?= $active ? ' aria-current="page"' : '' ?>>
              <svg class="h-[17px] w-[17px] shrink-0"><use href="#i-<?= e($icon) ?>"/></svg>
              <span class="truncate"><?= e($label) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </nav>

    <!-- User card. In devtest the chevron opens the role switcher: each port
         is a different signed-in role, so this is how you move between them. -->
    <div class="relative shrink-0 p-3" data-role-menu>
      <?php $switcherStyle = 'pop'; include QP_ROOT . '/resources/views/components/role-switcher.php'; ?>
      <button type="button" data-role-toggle aria-expanded="false" aria-controls="role-pop"
              class="flex w-full items-center gap-2.5 rounded-xl border border-hairline bg-white px-2.5 py-2.5 text-left shadow-card transition hover:bg-slate-50">
        <span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-700 text-[12px] font-bold text-white">
          <?= e($initials) ?>
          <?php if (!empty($devRole)): ?>
            <span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-amber-400" title="devtest build"></span>
          <?php endif; ?>
        </span>
        <span class="min-w-0 flex-1 leading-tight">
          <span class="block truncate text-[12.5px] font-bold text-ink-900"><?= e($u['name']) ?></span>
          <span class="block truncate text-[11px] text-ink-500"><?= e($roleLabel) ?></span>
        </span>
        <svg class="h-4 w-4 shrink-0 text-ink-400"><use href="#i-chevron-down"/></svg>
      </button>
    </div>
  </aside>

  <!-- ============================================ mobile off-canvas ==== -->
  <div id="nav-overlay" class="nav-overlay no-print" hidden></div>
  <aside id="nav-drawer" class="nav-drawer no-print" aria-label="Navigation" aria-hidden="true">
    <div class="flex h-14 shrink-0 items-center gap-2 border-b border-hairline px-3">
      <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-brand-400 to-brand-700">
        <svg class="h-4 w-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
      </span>
      <div class="min-w-0 flex-1 leading-tight">
        <p class="truncate text-sm font-bold text-ink-900"><?= e($brandName) ?></p>
        <p class="truncate text-[10px] text-ink-500"><?= e($cl['name'] ?? 'Platform console') ?></p>
      </div>
      <button type="button" id="nav-close" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-ink-500 hover:bg-slate-100" aria-label="Close navigation">
        <svg class="h-5 w-5"><use href="#i-x"/></svg>
      </button>
    </div>

    <nav class="scrollbar-thin flex-1 overflow-y-auto overscroll-contain px-3 py-3">
      <?php foreach ($navSections as $section => $items): ?>
        <?php if ($items === []) continue; ?>
        <p class="nav-section-label"><?= e($section) ?></p>
        <?php foreach ($items as [$href, $icon, $label, $active]): ?>
          <a href="<?= e($href) ?>" class="nav-item <?= $active ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <svg class="h-4 w-4 shrink-0"><use href="#i-<?= e($icon) ?>"/></svg>
            <span class="truncate"><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if ($navFooter !== []): ?>
        <div class="mt-4 border-t border-hairline pt-2">
          <?php foreach ($navFooter as [$href, $icon, $label, $active]): ?>
            <a href="<?= e($href) ?>" class="nav-item <?= $active ?>"><svg class="h-4 w-4 shrink-0"><use href="#i-<?= e($icon) ?>"/></svg><span class="truncate"><?= e($label) ?></span></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($cl && $u['role'] !== 'super_admin'): ?>
        <p class="nav-section-label">Display</p>
        <a href="/display" target="_blank" class="nav-item">
          <svg class="h-4 w-4 shrink-0"><use href="#i-monitor"/></svg><span>Waiting room TV</span>
          <svg class="ml-auto h-3 w-3 opacity-60"><use href="#i-arrow-right"/></svg>
        </a>
      <?php endif; ?>
    </nav>

    <?php $switcherStyle = 'drawer'; include QP_ROOT . '/resources/views/components/role-switcher.php'; ?>

    <div class="shrink-0 border-t border-hairline p-3">
      <div class="flex items-center gap-2.5">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-700 text-xs font-bold text-white"><?= e($initials) ?></span>
        <div class="min-w-0 flex-1 leading-tight">
          <p class="truncate text-xs font-bold text-ink-900"><?= e($u['name']) ?></p>
          <p class="truncate text-[10px] text-ink-500"><?= e($roleLabel) ?></p>
        </div>
      </div>
    </div>
  </aside>

  <!-- ================================================== main column ==== -->
  <div class="main-col">
    <!-- Desktop search bar -->
    <div class="hidden px-6 pt-4 lg:block xl:px-12 no-print">
      <div class="topbar-card">
        <svg class="h-4 w-4 shrink-0 text-slate-400"><use href="#i-search"/></svg>
        <form method="get" action="<?= e($searchAction) ?>" class="min-w-0 flex-1" data-board-search>
          <label class="sr-only" for="q-desk">Search</label>
          <input id="q-desk" name="q" type="search" class="topbar-search" autocomplete="off"
                 placeholder="Search patients, tokens, appointments...">
        </form>
        <a href="/notifications" class="relative shrink-0 rounded-lg p-1.5 text-ink-500 transition hover:bg-slate-100" aria-label="Notifications">
          <svg class="h-[18px] w-[18px]"><use href="#i-bell"/></svg>
          <?php if ((int) ($bellCount ?? 0) > 0): ?>
            <span class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[9px] font-bold text-white"><?= (int) $bellCount ?></span>
          <?php endif; ?>
        </a>
        <?php if ($u['role'] === 'clinic_admin' || $u['role'] === 'receptionist'): ?>
          <a href="/queue/new" class="btn-primary shrink-0 !px-3.5 !py-2 !text-[13px]">
            <svg class="h-4 w-4"><use href="#i-plus"/></svg>New patient
          </a>
        <?php elseif ($u['role'] === 'super_admin'): ?>
          <a href="/admin/clinics" class="btn-primary shrink-0 !px-3.5 !py-2 !text-[13px]">
            <svg class="h-4 w-4"><use href="#i-building"/></svg>Clinics
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Mobile header -->
    <div class="sticky top-0 z-30 bg-canvas/95 backdrop-blur lg:hidden no-print"
         style="padding-top: env(safe-area-inset-top)">
      <div class="flex min-h-[60px] items-center gap-2.5 px-4">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-700 shadow-sm">
          <svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
        </span>
        <div class="min-w-0 flex-1 leading-tight">
          <p class="truncate text-[15px] font-bold tracking-tight text-ink-900"><?= e($brandName) ?></p>
          <p class="truncate text-[10.5px] text-ink-500"><?= e($brandTag) ?></p>
        </div>
        <a href="/notifications" class="relative rounded-lg p-2 text-ink-700" aria-label="Notifications">
          <svg class="h-5 w-5"><use href="#i-bell"/></svg>
          <?php if ((int) ($bellCount ?? 0) > 0): ?>
            <span class="absolute right-0 top-0 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[9px] font-bold text-white"><?= (int) $bellCount ?></span>
          <?php endif; ?>
        </a>
        <button type="button" id="mobile-menu-btn" class="rounded-lg p-2 text-ink-700" aria-label="Open navigation"
                aria-controls="nav-drawer" aria-expanded="false">
          <svg class="h-5 w-5"><use href="#i-menu"/></svg>
        </button>
      </div>
      <div class="flex items-center gap-2 px-4 pb-3">
        <form method="get" action="<?= e($searchAction) ?>" class="flex h-10 min-w-0 flex-1 items-center gap-2 rounded-xl border border-hairline bg-white px-3 shadow-card" data-board-search>
          <svg class="h-4 w-4 shrink-0 text-slate-400"><use href="#i-search"/></svg>
          <label class="sr-only" for="q-mob">Quick search</label>
          <input id="q-mob" name="q" type="search" autocomplete="off" placeholder="Quick search..."
                 class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[13px] placeholder:text-slate-400 focus:outline-none focus:ring-0">
        </form>
        <button type="button" data-filter-toggle class="flex h-10 w-11 shrink-0 items-center justify-center rounded-xl border border-hairline bg-white text-ink-700 shadow-card" aria-label="Filters">
          <svg class="h-4 w-4"><use href="#i-filter"/></svg>
        </button>
      </div>
    </div>

    <main class="page !px-4 !pt-1 lg:!px-6 lg:!pt-5 xl:!px-12">
      <!-- One panel for every page, so the whole app shares the board's
           framing instead of each view inventing its own. -->
      <div class="lg:board-panel">
        <?= $content ?>
      </div>
    </main>

    <footer class="mt-auto px-4 py-5 text-center text-[11px] text-ink-400 no-print">
      <?= e($brandName) ?>&trade; <?= e(date('Y')) ?> &middot; v2.0.0 &middot; Secure &middot; Reliable &middot; Built for modern clinics
      <span class="text-brand-600">&#9829;</span>
    </footer>

    <?php if ($tabBar !== []): ?>
      <nav class="bottom-tabs no-print" aria-label="Primary">
        <?php foreach ($tabBar as [$href, $icon, $label]): $on = $href === '/app' ? $current === '/app' : str_starts_with($current, $href); ?>
          <a href="<?= e($href) ?>" class="btab <?= $on ? 'btab-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
            <svg class="h-[18px] w-[18px]"><use href="#i-<?= e($icon) ?>"/></svg>
            <span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
        <button type="button" class="btab" data-more-toggle aria-label="More">
          <svg class="h-[18px] w-[18px]"><use href="#i-dots"/></svg>
          <span>More</span>
        </button>
      </nav>
      <div class="h-[52px] lg:hidden" style="margin-bottom: env(safe-area-inset-bottom)"></div>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
  <?= $content ?>
<?php endif; ?>

<?php include QP_ROOT . '/resources/views/partials/toast.php'; ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
