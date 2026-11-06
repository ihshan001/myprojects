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
if ($u) {
    if ($u['role'] === 'super_admin') {
        $navSections = [
            'Platform' => [
                ['/admin', 'grid', 'Overview', $isActive('/admin')],
                ['/admin/clinics', 'building', 'Clinics', $isActive('/admin/clinics')],
                ['/admin/plans', 'card', 'Plans & billing', $isActive('/admin/plans')],
                ['/admin/billing', 'check-circle', 'Payment verification', $isActive('/admin/billing')],
                ['/admin/support', 'lifebuoy', 'Support queue', $isActive('/admin/support')],
                ['/admin/organisations', 'users', 'Groups', $isActive('/admin/organisations')],
            ],
        ];
    } else {
        $isDoctor = $u['role'] === 'doctor';

        // Only show destinations the current role can actually open. A doctor
        // who sees a "Patients" link that 403s learns to distrust the UI.
        $navSections = [
            'Queue' => array_values(array_filter([
                ['/app', 'grid', t('nav.live_board', 'Live board'), $isActive('/app')],
                $isDoctor ? null : ['/queue/new', 'plus', t('nav.new_patient', 'New patient'), $isActive('/queue/new')],
            ])),
            'Clinic' => array_values(array_filter([
                $isDoctor ? null : ['/appointments', 'calendar', t('nav.appointments', 'Appointments'), $isActive('/appointments')],
                $isDoctor ? null : ['/patients', 'users', t('nav.patients', 'Patients'), $isActive('/patients')],
                ['/doctors', 'stethoscope', t('nav.doctors', 'Doctors & rooms'), $isActive('/doctors')],
                ['/rooms', 'building', 'Rooms', $isActive('/rooms')],
                ['/queues/config', 'switch', 'Queue settings', $isActive('/queues/config')],
                ['/queues/priority-rules', 'alert', 'Priority rules', $isActive('/queues/priority-rules')],
                ['/queues/workflows', 'check-circle', 'Service workflows', $isActive('/queues/workflows')],
                ['/queues/appointment-policies', 'clock', 'Appointment policies', $isActive('/queues/appointment-policies')],
                ['/incidents', 'shield', 'Incidents', $isActive('/incidents')],
                ['/getting-started', 'sparkles', 'Getting started', $isActive('/getting-started')],
                ['/breaks', 'pause', 'Breaks', $isActive('/breaks')],
                // Departments and the operating calendar are clinic structure;
                // a doctor does not need to see them, reception does not edit
                // them, and the page itself hides the write controls by role.
                $isDoctor ? null : ['/departments', 'grid', 'Departments', $isActive('/departments')],
                ['/calendar', 'calendar', 'Schedules & holidays', $isActive('/calendar')],
            ])),
            'Insight' => array_values(array_filter([
                Auth::can('reports.view') ? ['/reports', 'chart', t('nav.reports', 'Reports'), $isActive('/reports')] : null,
                // Only shown to a clinic owner whose clinic is actually in a
                // group — a link to an empty page trains people to ignore links.
                (Auth::can('clinic.manage') && (Auth::clinic()['organisation_id'] ?? null) !== null)
                    ? ['/organisation', 'building', 'Group', $isActive('/organisation')] : null,
                Auth::can('clinic.manage') ? ['/audit', 'shield', 'Audit log', $isActive('/audit')] : null,
                ['/settings', 'settings', t('nav.settings', 'Settings'), $isActive('/settings')],
                Auth::can('billing.view') ? ['/billing', 'card', 'Subscription', $isActive('/billing')] : null,
                ['/notifications', 'bell', 'Notifications', $isActive('/notifications')],
                ['/support', 'lifebuoy', 'Support', $isActive('/support')],
            ])),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e($locale ?? 'en') ?>" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="<?= e($cl['brand_color'] ?? '#0F766E') ?>">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title ?? 'QueuePilot') ?> · <?= e($appName) ?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="/assets/img/icon-192.png">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="h-full">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<?php if ($u): ?>
<div class="shell">
  <!-- Sidebar (desktop) -->
  <aside class="sidebar no-print">
    <div class="flex h-16 items-center gap-2.5 border-b border-white/10 px-5">
      <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-500 text-sm font-black text-slate-900">Q</span>
      <div class="leading-tight">
        <p class="text-sm font-bold text-white"><?= e($appName) ?></p>
        <p class="text-[10px] uppercase tracking-widest text-slate-400">Clinic Edition</p>
      </div>
    </div>

    <div class="border-b border-white/10 px-3 py-3">
      <div class="rounded-lg bg-white/5 px-3 py-2">
        <p class="truncate text-xs font-semibold text-white"><?= e($cl['name'] ?? 'Platform console') ?></p>
        <p class="mt-0.5 flex items-center gap-1.5 text-[10px] text-slate-400">
          <?php if ($cl): ?>
            <span class="h-1.5 w-1.5 rounded-full <?= $cl['billing_status'] === 'active' ? 'bg-emerald-400' : 'bg-amber-400' ?>"></span>
            <?= e(ucfirst(str_replace('_', ' ', $cl['billing_status']))) ?> plan
          <?php else: ?>
            <span class="h-1.5 w-1.5 rounded-full bg-violet-400"></span> All tenants
          <?php endif; ?>
        </p>

        <?php
        // Only shown when there is a real choice. A one-branch clinic never sees
        // a switcher, because a control that cannot do anything is noise.
        $choices = $branchChoices ?? [];
        ?>
        <?php if (count($choices) > 1): ?>
          <form method="post" action="/branch/switch" class="mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="<?= e($path ?? '/app') ?>">
            <label class="sr-only" for="branch-switch">Working in branch</label>
            <select id="branch-switch" name="clinic_id" onchange="this.form.submit()"
                    class="w-full rounded-md border border-white/15 bg-white/5 px-2 py-1 text-[11px] text-white
                           focus:border-teal-400 focus:outline-none">
              <?php foreach ($choices as $b): ?>
                <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) ($activeBranchId ?? 0) ? 'selected' : '' ?>>
                  <?= e((string) $b['name']) ?><?= $b['is_home'] ? ' (home)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
          <p class="mt-1 text-[10px] leading-snug text-slate-500">
            You are seeing only this branch.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <nav class="scrollbar-thin flex-1 overflow-y-auto px-2 py-3">
      <?php foreach ($navSections as $section => $items): ?>
        <p class="mb-1 mt-3 px-3 text-[10px] font-bold uppercase tracking-widest text-slate-500 first:mt-0"><?= e($section) ?></p>
        <?php foreach ($items as [$href, $icon, $label, $active]): ?>
          <a href="<?= e($href) ?>" class="nav-item <?= $active ?>">
            <svg class="h-4 w-4"><use href="#i-<?= e($icon) ?>"/></svg>
            <span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>

    <?php if ($cl && $u['role'] !== 'super_admin'): ?>
      <div class="px-3 pb-2">
        <a href="/display" target="_blank" class="nav-item">
          <svg class="h-4 w-4"><use href="#i-monitor"/></svg>
          <span>Waiting room TV</span>
          <svg class="ml-auto h-3 w-3 opacity-60"><use href="#i-arrow-right"/></svg>
        </a>
      </div>
    <?php endif; ?>

    <div class="border-t border-white/10 p-3">
      <div class="flex items-center gap-2.5 rounded-lg px-2 py-2">
        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-teal-600/30 text-xs font-bold text-teal-100">
          <?= e(App\Core\Str::initials((string) $u['name'])) ?>
        </span>
        <div class="min-w-0 flex-1 leading-tight">
          <p class="truncate text-xs font-semibold text-white"><?= e($u['name']) ?></p>
          <p class="truncate text-[10px] text-slate-400"><?= e($roleLabel) ?></p>
        </div>
      </div>
    </div>
  </aside>

  <!-- Mobile off-canvas navigation. Same grouped sections and active state as
       the desktop sidebar, rather than a flat list of every link. -->
  <div id="nav-overlay" class="nav-overlay no-print" hidden></div>
  <aside id="nav-drawer" class="nav-drawer no-print" aria-label="Navigation" aria-hidden="true">
    <div class="flex h-14 shrink-0 items-center gap-2 border-b border-white/10 px-3">
      <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-500 text-sm font-black text-slate-900">Q</span>
      <div class="min-w-0 flex-1 leading-tight">
        <p class="truncate text-sm font-bold text-white"><?= e($appName) ?></p>
        <p class="truncate text-[10px] text-slate-400"><?= e($cl['name'] ?? 'Platform console') ?></p>
      </div>
      <button type="button" id="nav-close" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-white/10 hover:text-white active:bg-white/20" aria-label="Close navigation">
        <svg class="h-5 w-5"><use href="#i-x"/></svg>
      </button>
    </div>

    <?php if (!empty($devRole)): ?>
      <div class="shrink-0 border-b border-white/10 bg-amber-500/10 px-3 py-2">
        <p class="flex items-center gap-1.5 text-[11px] font-bold text-amber-200">
          <span class="rounded bg-amber-500 px-1.5 py-0.5 text-[9px] font-black uppercase text-white">devtest</span>
          port <?= (int) ($devRole['port'] ?? 0) ?>
        </p>
        <p class="mt-0.5 truncate text-xs font-semibold text-white"><?= e($devRole['label'] ?? '') ?></p>
        <p class="truncate text-[10px] text-slate-400"><?= e($devRole['email'] ?? '') ?></p>
      </div>
    <?php endif; ?>

    <nav class="scrollbar-thin flex-1 overflow-y-auto overscroll-contain px-2 py-3">
      <?php foreach ($navSections as $section => $items): ?>
        <p class="nav-section-label"><?= e($section) ?></p>
        <?php foreach ($items as [$href, $icon, $label, $active]): ?>
          <a href="<?= e($href) ?>" class="nav-item <?= $active ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <svg class="h-4 w-4 shrink-0"><use href="#i-<?= e($icon) ?>"/></svg>
            <span class="truncate"><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <?php if ($cl && $u['role'] !== 'super_admin'): ?>
        <p class="nav-section-label">Display</p>
        <a href="/display" target="_blank" class="nav-item">
          <svg class="h-4 w-4 shrink-0"><use href="#i-monitor"/></svg>
          <span>Waiting room TV</span>
          <svg class="ml-auto h-3 w-3 opacity-60"><use href="#i-arrow-right"/></svg>
        </a>
      <?php endif; ?>
    </nav>

    <?php $switcherStyle = 'drawer'; include QP_ROOT . '/resources/views/components/role-switcher.php'; ?>

    <div class="shrink-0 border-t border-white/10 p-3">
      <div class="flex items-center gap-2.5">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-600/30 text-xs font-bold text-teal-100">
          <?= e(App\Core\Str::initials((string) $u['name'])) ?>
        </span>
        <div class="min-w-0 flex-1 leading-tight">
          <p class="truncate text-xs font-semibold text-white"><?= e($u['name']) ?></p>
          <p class="truncate text-[10px] text-slate-400"><?= e($roleLabel) ?></p>
        </div>
      </div>
    </div>
  </aside>

  <!-- Main column -->
  <div class="main-col">
    <!-- Mobile top bar. Sticky, safe-area aware, and the title truncates
         instead of pushing the menu button off the screen. -->
    <div class="sticky top-0 z-30 flex min-h-[3.5rem] items-center gap-2 border-b border-slate-200 bg-white/95 px-2 backdrop-blur lg:hidden no-print"
         style="padding-top: env(safe-area-inset-top)">
      <button type="button" id="mobile-menu-btn" class="icon-btn" aria-label="Open navigation"
              aria-controls="nav-drawer" aria-expanded="false">
        <svg class="h-5 w-5"><use href="#i-menu"/></svg>
      </button>
      <div class="flex min-w-0 flex-1 items-center gap-2">
        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-teal-700 text-xs font-black text-white">Q</span>
        <span class="truncate text-sm font-bold text-slate-900"><?= e($cl['name'] ?? $appName) ?></span>
      </div>
      <?php if (!empty($devRole)): ?>
        <span class="shrink-0 rounded bg-amber-500 px-1.5 py-0.5 text-[10px] font-black text-white"
              title="<?= e($devRole['label'] ?? '') ?>"><?= (int) ($devRole['port'] ?? 0) ?></span>
      <?php endif; ?>
    </div>

    <?php if (($flash = notice()) !== null): ?>
      <div class="px-4 pt-4 sm:px-6 lg:px-8">
        <div class="rise flex items-start gap-3 rounded-xl border px-4 py-3 text-sm
          <?= $flash['type'] === 'error'
              ? 'border-rose-200 bg-rose-50 text-rose-800'
              : ($flash['type'] === 'warn'
                ? 'border-amber-200 bg-amber-50 text-amber-900'
                : 'border-emerald-200 bg-emerald-50 text-emerald-800') ?>">
          <svg class="mt-0.5 h-4 w-4 shrink-0">
            <use href="#i-<?= $flash['type'] === 'error' ? 'alert' : ($flash['type'] === 'warn' ? 'alert' : 'check-circle') ?>"/>
          </svg>
          <p><?= e($flash['message']) ?></p>
        </div>
      </div>
    <?php endif; ?>


    <?php if (!empty($devRole)): ?>
      <div class="no-print border-b border-amber-300 bg-amber-50 px-3 py-2 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px]">
          <span class="rounded bg-amber-500 px-1.5 py-0.5 font-black uppercase tracking-wide text-white">devtest</span>
          <span class="font-bold text-amber-900"><?= e($devRole['label'] ?? '') ?></span>
          <span class="hidden font-mono text-amber-800 sm:inline"><?= e($devRole['email'] ?? '') ?></span>
          <?php if (!empty($devRole['clinic'])): ?>
            <span class="hidden text-amber-800 md:inline">&middot; <?= e($devRole['clinic']) ?></span>
          <?php endif; ?>
          <span class="ml-auto hidden text-amber-700 xl:inline">no sign-in &middot; role fixed by port</span>
        </div>
        <?php if (!empty($devRole['sees'])): ?>
          <p class="mt-0.5 hidden text-[10px] leading-snug text-amber-700 sm:block"><?= e($devRole['sees']) ?></p>
        <?php endif; ?>
        <!-- Every role is one tap away. Each port is its own preview, and these
             links rewrite the preview hostname rather than pointing at localhost. -->
        <div class="mt-1.5">
          <?php $switcherStyle = 'bar'; include QP_ROOT . '/resources/views/components/role-switcher.php'; ?>
        </div>
      </div>
    <?php endif; ?>

    <main class="page">
      <?= $content ?>
    </main>

    <footer class="mt-auto border-t border-slate-200 px-4 py-4 text-center text-[11px] text-slate-400 sm:px-6 lg:px-8 no-print">
      <?= e($appName) ?> · multi-tenant queue management · <?= e(date('Y')) ?>
      · clinic data isolated per tenant · request <?= e($requestId ?? '') ?>
    </footer>
  </div>
</div>
<?php else: ?>
  <?php if (($flash = notice()) !== null): ?>
    <div class="fixed left-1/2 top-4 z-50 w-[min(92vw,28rem)] -translate-x-1/2">
      <div class="rise rounded-xl border px-4 py-3 text-sm shadow-lg
        <?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
        <?= e($flash['message']) ?>
      </div>
    </div>
  <?php endif; ?>
  <?= $content ?>
<?php endif; ?>

<?php include QP_ROOT . '/resources/views/partials/toast.php'; ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
