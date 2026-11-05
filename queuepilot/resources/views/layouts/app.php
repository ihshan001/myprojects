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
        <form method="post" action="/logout">
          <?= csrf_field() ?>
          <button type="submit" class="rounded p-1.5 text-slate-400 hover:bg-white/10 hover:text-white" title="Sign out">
            <svg class="h-4 w-4"><use href="#i-logout"/></svg>
          </button>
        </form>
      </div>
    </div>
  </aside>

  <!-- Main column -->
  <div class="main-col">
    <!-- Mobile top bar -->
    <div class="sticky top-0 z-20 flex h-14 items-center justify-between border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:hidden no-print">
      <div class="flex items-center gap-2">
        <span class="flex h-7 w-7 items-center justify-center rounded-md bg-teal-700 text-xs font-black text-white">Q</span>
        <span class="text-sm font-bold text-slate-900"><?= e($cl['name'] ?? $appName) ?></span>
      </div>
      <button type="button" id="mobile-menu-btn" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100" aria-label="Open menu">
        <svg class="h-5 w-5"><use href="#i-menu"/></svg>
      </button>
    </div>

    <div id="mobile-menu" class="hidden border-b border-slate-200 bg-slate-900 px-3 py-2 lg:hidden no-print">
      <?php foreach ($navSections as $items): foreach ($items as [$href, $icon, $label]): ?>
        <a href="<?= e($href) ?>" class="nav-item"><svg class="h-4 w-4"><use href="#i-<?= e($icon) ?>"/></svg><?= e($label) ?></a>
      <?php endforeach; endforeach; ?>
      <form method="post" action="/logout" class="mt-1 border-t border-white/10 pt-2">
        <?= csrf_field() ?>
        <button class="nav-item w-full text-left"><svg class="h-4 w-4"><use href="#i-logout"/></svg>Sign out</button>
      </form>
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

    <?php
    // A clinic whose owner has not confirmed their address can look around but
    // cannot issue tokens. Saying so once, at the top of every page, is fairer
    // than letting a receptionist discover it at the desk with a patient in
    // front of them.
    $qpClinicId = \App\Core\Auth::clinicId();
    $qpVerifyBanner = $qpClinicId !== null
        && \App\Core\Auth::can('queue.operate')
        && !(new \App\Services\EmailVerificationService())->clinicMayIssueTokens($qpClinicId);
    ?>
    <?php if ($qpVerifyBanner): ?>
      <div class="px-4 pt-4 sm:px-6 lg:px-8">
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <p class="flex flex-wrap items-center gap-2">
            <svg class="h-4 w-4 shrink-0"><use href="#i-alert"/></svg>
            <span class="font-semibold">Confirm the owner's email to start issuing tokens.</span>
            <span class="text-amber-800">Patients cannot be queued until the address on this account is confirmed.</span>
          </p>
          <div class="mt-2 flex flex-wrap items-center gap-3 pl-6">
            <form method="post" action="/verify-email/resend">
              <?= csrf_field() ?>
              <button class="rounded border border-amber-300 bg-white px-2.5 py-1 text-xs font-medium text-amber-900 hover:bg-amber-100">
                Resend confirmation
              </button>
            </form>
            <a href="/billing" class="text-xs font-medium text-amber-900 underline">Billing &amp; account</a>
          </div>
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
