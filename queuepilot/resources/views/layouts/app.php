<?php
use App\Core\Auth;
$u = $user ?? null;
$cl = $clinic ?? null;
$current = $path ?? '/';
$isActive = static function (string $prefix) use ($current): string {
    // An exact match for a bare section root. Prefix matching would keep
    // 'Overview' lit on every page beneath it, so the nav could never tell
    // you where you actually were.
    if ($prefix === '/app' || $prefix === '/admin') {
        return $current === $prefix ? 'nav-item-active' : '';
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
                ['/admin/monitor', 'heart', 'Platform health', $isActive('/admin/monitor')],
                ['/admin/clinics', 'building', 'Clinics', $isActive('/admin/clinics')],
                ['/admin/plans', 'card', 'Plans & billing', $isActive('/admin/plans')],
                ['/admin/billing', 'check-circle', 'Payment verification', $isActive('/admin/billing')],
                ['/admin/support', 'lifebuoy', 'Support queue', $isActive('/admin/support')],
                ['/admin/organisations', 'users', 'Groups', $isActive('/admin/organisations')],
            ],
        ];
    } elseif ($u['role'] === 'doctor') {
        // The PDF is specific about the doctor's navigation: today's queue, my
        // schedule, my patients, my profile — and "no clinic-wide
        // configuration or platform administration should be visible". A
        // doctor who can see a link to the clinic's queue settings has been
        // handed a reason to change them for everybody.
        $navSections = [
            'My work' => [
                ['/doctor', 'stethoscope', 'Today’s queue', $isActive('/doctor')],
                ['/doctor/schedule', 'calendar', 'My schedule', $isActive('/doctor/schedule')],
                ['/doctor/patients', 'users', 'My patients', $isActive('/doctor/patients')],
                ['/doctor/profile', 'user', 'My profile', $isActive('/doctor/profile')],
            ],
        ];
    } else {
        // Only show destinations the current role can actually open. A doctor
        // who sees a "Patients" link that 403s learns to distrust the UI.
        $navSections = [
            'Queue' => [
                ['/app', 'grid', t('nav.live_board', 'Live board'), $isActive('/app')],
                ['/queue/new', 'plus', t('nav.new_patient', 'New patient'), $isActive('/queue/new')],
            ],
            'Clinic' => [
                ['/appointments', 'calendar', t('nav.appointments', 'Appointments'), $isActive('/appointments')],
                ['/patients', 'users', t('nav.patients', 'Patients'), $isActive('/patients')],
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
                ['/departments', 'grid', 'Departments', $isActive('/departments')],
                ['/calendar', 'calendar', 'Schedules & holidays', $isActive('/calendar')],
            ],
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

/* Desktop header nav.
   A 256px rail of twenty links is right for a phone drawer but wrong for a
   wide screen: it spends the width the queue board needs on labels nobody
   scrolls. So the header carries only the destinations a clinic reaches for
   all day and groups the rest under "More", keeping the drawer's section
   names so it stays the same map either way. */
$flatNav = [];
foreach ($navSections as $section => $items) {
    foreach ($items as $it) {
        $flatNav[] = [$section, $it[0], $it[1], $it[2], $it[3]];
    }
}
// Role-aware. A shared list left the platform header with nothing but
// "More", because none of a clinic's destinations are the admin's.
$primaryHrefs = !$u ? [] : ($u['role'] === 'super_admin'
    ? ['/admin', '/admin/clinics', '/admin/organisations', '/admin/plans', '/admin/billing', '/admin/monitor']
    : ($u['role'] === 'doctor'
        ? ['/doctor', '/doctor/schedule', '/doctor/patients']
        : ['/app', '/queue/new', '/patients', '/appointments', '/reports']));

$headerPrimary = array_values(array_filter($flatNav, static fn ($n) => in_array($n[1], $primaryHrefs, true)));
$headerMore = [];
foreach ($flatNav as $n) {
    if (in_array($n[1], $primaryHrefs, true)) {
        continue;
    }
    $headerMore[$n[0]][] = $n;
}
// Kept one click away in the header rather than buried in the drawer.
$headerQuick = array_values(array_filter($flatNav, static fn ($n) => in_array($n[1], ['/settings', '/support'], true)));

// The five things a phone screen can show without scrolling. Everything
// else lives behind the drawer that the "More" tab opens.
$tabBar = array_values(array_filter($flatNav, static function ($n) use ($primaryHrefs) {
    return in_array($n[1], array_slice($primaryHrefs, 0, 4), true);
}));

// Search goes where the signed-in role can act on what it finds.
$searchAction = !$u ? '/patients' : ($u['role'] === 'super_admin' ? '/admin/clinics'
    : ($u['role'] === 'doctor' ? '/doctor/patients' : '/patients'));
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
            <select id="branch-switch" name="clinic_id" data-submit-on-change
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
    <!-- Desktop header: the handful of places a clinic goes all day, with
         everything else one click away under "More". -->
    <header class="topnav no-print">
      <div class="topnav-inner">
        <a href="<?= e($u && $u['role'] === 'super_admin' ? '/admin' : '/app') ?>" class="topnav-brand">
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-teal-700 text-sm font-black text-white">Q</span>
          <span class="truncate text-[14px] font-bold tracking-tight text-slate-900"><?= e($appName) ?></span>
        </a>

        <?php if ($headerPrimary !== []): ?>
          <nav class="topnav-links" aria-label="Main">
            <?php foreach ($headerPrimary as [, $href, $icon, $label, $active]): ?>
              <a href="<?= e($href) ?>" class="topnav-link <?= $active ? 'is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                <?= e($label) ?>
              </a>
            <?php endforeach; ?>

            <?php if ($headerMore !== []): ?>
              <div class="topnav-more" data-nav-more>
                <button type="button" class="topnav-link" data-nav-more-toggle aria-expanded="false" aria-controls="nav-more-menu">
                  More
                  <svg class="h-3.5 w-3.5"><use href="#i-chevron-down"/></svg>
                </button>
                <div class="topnav-menu" id="nav-more-menu" hidden>
                  <?php foreach ($headerMore as $section => $items): ?>
                    <p class="topnav-menu-label"><?= e($section) ?></p>
                    <?php foreach ($items as [, $href, $icon, $label, $active]): ?>
                      <a href="<?= e($href) ?>" class="topnav-menu-item <?= $active ? 'is-active' : '' ?>">
                        <svg class="h-4 w-4 shrink-0 text-slate-400"><use href="#i-<?= e($icon) ?>"/></svg>
                        <span class="truncate"><?= e($label) ?></span>
                      </a>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          </nav>
        <?php endif; ?>

        <div class="topnav-right">
          <form method="get" action="<?= e($searchAction) ?>" class="topnav-search">
            <svg class="h-4 w-4 shrink-0 text-slate-400"><use href="#i-search"/></svg>
            <label class="sr-only" for="q-desk">Search</label>
            <input id="q-desk" name="q" type="search" autocomplete="off"
                   placeholder="<?= e($u && $u['role'] === 'super_admin' ? 'Search clinics...' : 'Search patients, tokens...') ?>">
          </form>

          <?php $choices = $branchChoices ?? []; ?>
          <?php if (count($choices) > 1): ?>
            <form method="post" action="/branch/switch" class="shrink-0">
              <?= csrf_field() ?>
              <input type="hidden" name="back" value="<?= e($path ?? '/app') ?>">
              <label class="sr-only" for="branch-switch-head">Working in branch</label>
              <select id="branch-switch-head" name="clinic_id" data-submit-on-change class="topnav-branch">
                <?php foreach ($choices as $b): ?>
                  <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) ($activeBranchId ?? 0) ? 'selected' : '' ?>>
                    <?= e((string) $b['name']) ?><?= $b['is_home'] ? ' (home)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>

          <?php foreach ($headerQuick as [, $href, $icon, $label]): ?>
            <a href="<?= e($href) ?>" class="topnav-icon" aria-label="<?= e($label) ?>" title="<?= e($label) ?>">
              <svg class="h-[18px] w-[18px]"><use href="#i-<?= e($icon) ?>"/></svg>
            </a>
          <?php endforeach; ?>

          <?php if ($u && $u['role'] !== 'super_admin' && Auth::can('queue.issue')): ?>
            <a href="/queue/new" class="btn-primary shrink-0 !w-auto !px-3 !py-1.5 !text-[13px]">
              <svg class="h-4 w-4"><use href="#i-plus"/></svg>New patient
            </a>
          <?php endif; ?>

          <div class="topnav-user" title="<?= e($u['name'] ?? '') ?> · <?= e($roleLabel) ?>">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-700 text-[11px] font-bold text-white">
              <?= e(App\Core\Str::initials((string) $u['name'])) ?>
            </span>
            <span class="hidden min-w-0 leading-tight xl:block">
              <span class="block truncate text-[12px] font-bold text-slate-900"><?= e($u['name']) ?></span>
              <span class="block truncate text-[10px] text-slate-500"><?= e($roleLabel) ?></span>
            </span>
          </div>

          <form method="post" action="/logout" class="shrink-0">
            <?= csrf_field() ?>
            <button type="submit" class="topnav-icon" title="Sign out" aria-label="Sign out">
              <svg class="h-[18px] w-[18px]"><use href="#i-logout"/></svg>
            </button>
          </form>
        </div>
      </div>
    </header>

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

    <?php if ($tabBar !== []): ?>
      <!-- Thumb-reachable on a phone. The rail is a drawer you have to open;
           these are the four things a clinic does all day, always visible. -->
      <nav class="bottom-tabs no-print" aria-label="Primary">
        <?php foreach ($tabBar as [, $href, $icon, $label, $active]): ?>
          <a href="<?= e($href) ?>" class="btab <?= $active ? 'btab-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <svg class="h-[18px] w-[18px]"><use href="#i-<?= e($icon) ?>"/></svg>
            <span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
        <button type="button" class="btab" data-open-nav aria-label="More" aria-controls="mobile-menu">
          <svg class="h-[18px] w-[18px]"><use href="#i-menu"/></svg>
          <span>More</span>
        </button>
      </nav>
      <div class="h-[52px] lg:hidden" style="margin-bottom: env(safe-area-inset-bottom)"></div>
    <?php endif; ?>

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
