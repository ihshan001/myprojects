<?php
use App\Core\Auth;
/**
 * Settings.
 *
 * One screen, five tabs. The Clinic settings tab is the one the design is
 * built around: a read-only summary of how the clinic is set up, with an
 * edit affordance on each card, and a right-hand rail carrying the things
 * you change often (notifications, language, timezone).
 *
 * The summary-first shape is deliberate. The old screen was a wall of inputs
 * that implied you had come there to type; most visits are "what are our
 * hours again?", which a form answers badly. Editing is still one click away
 * — it just is not the first thing you see.
 *
 * @var string $tab
 * @var array<string,mixed> $clinicRecord
 * @var array<int,array<string,mixed>> $staff
 * @var array<int,array<string,mixed>> $invites
 * @var array<int,array<string,mixed>> $notifications
 * @var array<string,string> $timezones
 * @var array<string,string> $locales
 * @var array<int,array<string,mixed>> $integrations
 * @var int $connectedCount
 * @var bool $canManage
 * @var array<string,mixed> $plan
 * @var string $purgePhrase
 */

$tabs = [
    'clinic'        => 'Clinic settings',
    'team'          => 'Team',
    'notifications' => 'Notifications',
    'integrations'  => 'Integrations',
    'billing'       => 'Billing',
];

$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$addr = trim(implode(', ', array_filter([
    (string) ($clinicRecord['address_line'] ?? ''),
    (string) ($clinicRecord['city'] ?? ''),
])));
?>
<div class="page-head">
  <div class="min-w-0">
    <h1 class="page-title">Settings</h1>
    <p class="page-sub">Manage clinic details, preferences, and system configuration.</p>
  </div>
</div>

<?php if (!empty($inviteLink)): ?>
  <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
    <p class="text-xs font-bold text-amber-900">Invitation link — pass this on yourself</p>
    <p class="mt-0.5 text-[11px] text-amber-800">
      No mail transport is configured, so nothing was emailed. This link works once and expires in 72 hours.
    </p>
    <div class="mt-2 flex items-center gap-2 rounded-lg bg-white px-3 py-2">
      <code class="flex-1 truncate text-[11px] text-ink-700"><?= e(rtrim((string) \App\Core\Env::get('app.url', ''), '/') . $inviteLink) ?></code>
      <button class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-ink-400 hover:bg-slate-100 hover:text-ink-700"
              data-copy="<?= e(rtrim((string) \App\Core\Env::get('app.url', ''), '/') . $inviteLink) ?>">
        <svg class="h-3.5 w-3.5"><use href="#i-copy"/></svg>
      </button>
    </div>
  </div>
<?php endif; ?>

<nav class="st-tabs mt-4" aria-label="Settings sections">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="st-tab <?= $tab === $key ? 'is-active' : '' ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>
       href="/settings?tab=<?= e((string) $key) ?>"><?= e((string) $label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'clinic'): ?>

<div class="mt-4 grid gap-x-6 gap-y-4 lg:grid-cols-[minmax(0,1fr)_14.875rem]">

  <!-- ============================================================ main -->
  <div class="space-y-5">

    <!-- ---------------------------------------------------- profile -->
    <section class="card overflow-hidden">
      <div class="st-card-head">
        <h2 class="st-card-title">
          <svg class="st-card-icon"><use href="#i-building"/></svg>Clinic profile
        </h2>
        <?php if ($canManage): ?>
          <details class="st-details">
            <summary class="st-link">Edit profile</summary>
            <div class="st-details-body">
              <form method="post" action="/settings" class="space-y-3">
                <?= csrf_field() ?>
                <div class="grid gap-3 sm:grid-cols-2">
                  <div class="sm:col-span-2"><label class="label" for="name">Clinic name</label>
                    <input class="input" id="name" name="name" value="<?= e((string) $clinicRecord['name']) ?>" required></div>
                  <div><label class="label" for="phone">Phone</label>
                    <input class="input" id="phone" name="phone" value="<?= e((string) ($clinicRecord['phone'] ?? '')) ?>"></div>
                  <div><label class="label" for="email">Email</label>
                    <input class="input" id="email" name="email" type="email" value="<?= e((string) ($clinicRecord['email'] ?? '')) ?>"></div>
                  <div class="sm:col-span-2"><label class="label" for="address_line">Address</label>
                    <input class="input" id="address_line" name="address_line" value="<?= e((string) ($clinicRecord['address_line'] ?? '')) ?>"></div>
                  <div><label class="label" for="city">City</label>
                    <input class="input" id="city" name="city" value="<?= e((string) ($clinicRecord['city'] ?? '')) ?>"></div>
                  <div><label class="label" for="district">District</label>
                    <input class="input" id="district" name="district" value="<?= e((string) ($clinicRecord['district'] ?? '')) ?>"></div>
                </div>
                <button class="btn-primary w-full">Save profile</button>
              </form>
            </div>
          </details>
        <?php endif; ?>
      </div>

      <div class="grid gap-x-6 gap-y-4 px-4 py-10 sm:grid-cols-2">
        <div class="min-w-0">
          <p class="text-[15px] font-bold leading-snug text-ink-900"><?= e((string) $clinicRecord['name']) ?></p>
          <p class="mt-1 text-[11px] text-ink-400"><?= e((string) ($clinicRecord['legal_name'] ?? '')) ?></p>
        </div>
        <div class="min-w-0">
          <p class="st-field-label">Phone</p>
          <p class="st-field-value"><?= e((string) ($clinicRecord['phone'] ?? '—')) ?></p>
        </div>
        <div class="min-w-0">
          <p class="st-field-label">Email</p>
          <p class="st-field-value break-all"><?= e((string) ($clinicRecord['email'] ?? '—')) ?></p>
        </div>
        <div class="min-w-0">
          <p class="st-field-label">Address</p>
          <p class="st-field-value"><?= $addr === '' ? '—' : e($addr) ?></p>
        </div>
      </div>

      <details class="st-more">
        <summary class="st-more-summary">
          <span>Show more</span>
          <svg class="h-3 w-3"><use href="#i-chevron-down"/></svg>
        </summary>
        <dl class="st-dl">
          <div><dt>Token prefix</dt><dd><?= e((string) ($clinicRecord['token_prefix'] ?? 'A')) ?></dd></div>
          <div><dt>Late grace</dt><dd><?= (int) ($clinicRecord['late_grace_minutes'] ?? 15) ?> minutes</dd></div>
          <div><dt>District</dt><dd><?= e((string) ($clinicRecord['district'] ?? '—')) ?></dd></div>
          <div><dt>Brand colour</dt><dd>
            <span class="inline-flex items-center gap-1.5">
              <span class="h-3 w-3 rounded-full border border-hairline" style="background:<?= e((string) ($clinicRecord['brand_color'] ?? '#0F766E')) ?>"></span>
              <?= e((string) ($clinicRecord['brand_color'] ?? '')) ?>
            </span></dd></div>
          <div><dt>Online booking</dt><dd><?= (int) ($clinicRecord['allow_online_booking'] ?? 0) === 1 ? 'Enabled' : 'Disabled' ?></dd></div>
          <div><dt>Approach SMS</dt><dd><?= (int) ($clinicRecord['notify_approach'] ?? 0) === 1
              ? 'On — next ' . (int) ($clinicRecord['approach_threshold'] ?? 2) . ' in line'
              : 'Off' ?></dd></div>
        </dl>
      </details>
    </section>

    <!-- ------------------------------------------------------ hours -->
    <section class="card overflow-hidden">
      <div class="st-card-head">
        <h2 class="st-card-title">
          <svg class="st-card-icon"><use href="#i-clock"/></svg>Operating hours
        </h2>
        <p class="st-card-sub">Set your clinic hours and public holidays.</p>
        <a class="st-link" href="/settings?tab=clinic#hours">Edit hours</a>
      </div>

      <div class="grid gap-x-6 gap-y-4 px-4 py-10 sm:grid-cols-2">
        <div>
          <p class="st-sub">Weekly schedule</p>
          <dl class="st-hours">
            <?php foreach ($dayNames as $dow => $dayName): ?>
              <?php $row = $weekHours[$dow] ?? ['open' => null, 'close' => null, 'closed' => true]; ?>
              <div class="st-hours-row">
                <dt><?= e($dayName) ?></dt>
                <dd class="<?= $row['closed'] ? 'text-ink-400' : '' ?>">
                  <?php if ($row['closed']): ?>
                    Closed
                  <?php else: ?>
                    <?= e(fmt_time(date('Y-m-d') . ' ' . (string) $row['open'], 'h:i A')) ?> –
                    <?= e(fmt_time(date('Y-m-d') . ' ' . (string) $row['close'], 'h:i A')) ?>
                  <?php endif; ?>
                </dd>
              </div>
            <?php endforeach; ?>
          </dl>
          <a class="st-link mt-2 inline-block" href="/settings?tab=clinic#hours">View full schedule</a>
        </div>

        <div>
          <p class="st-sub">Upcoming holidays</p>
          <?php if ($holidays === []): ?>
            <p class="text-[11px] text-ink-500">No holidays scheduled.</p>
          <?php else: ?>
            <ul class="st-holidays">
              <?php foreach ($holidays as $h): ?>
                <li>
                  <span class="truncate"><?= e((string) $h['name']) ?></span>
                  <span class="shrink-0 tabular text-ink-500"><?= e(fmt_time((string) $h['date'], 'M d, Y')) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <a class="st-link mt-2 inline-block" href="/calendar">View all holidays</a>
        </div>
      </div>
    </section>

    <!-- ------------------------------------------------------ staff -->
    <section class="card overflow-hidden">
      <div class="st-card-head">
        <h2 class="st-card-title">
          <svg class="st-card-icon"><use href="#i-users"/></svg>Staff &amp; access
        </h2>
        <p class="st-card-sub">Manage staff accounts and their access levels.</p>
        <a class="st-link" href="/settings?tab=team">Manage staff</a>
      </div>
      <div class="table-scroll px-4 pb-4 pt-3">
        <table class="table st-table">
          <thead><tr><th>Staff member</th><th>Role</th><th>Status</th><th>Last active</th></tr></thead>
          <tbody>
          <?php foreach ($staff as $s): ?>
            <tr>
              <td class="font-medium text-ink-900"><?= e((string) $s['name']) ?></td>
              <td><?= e(match ((string) $s['role']) {
                  'clinic_admin' => 'Admin', 'doctor' => 'Doctor',
                  'receptionist' => 'Receptionist', default => ucfirst(str_replace('_', ' ', (string) $s['role'])),
              }) ?></td>
              <td><span class="<?= (int) $s['is_active'] === 1 ? 'badge-teal' : 'badge-slate' ?>"><?= (int) $s['is_active'] === 1 ? 'Active' : 'Disabled' ?></span></td>
              <td class="text-xs text-ink-500"><?= e($s['last_login_at'] ? ago((string) $s['last_login_at']) : 'never') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php foreach ($invites as $inv): ?>
            <tr>
              <td class="text-ink-500"><?= e((string) $inv['email']) ?></td>
              <td><?= e(ucfirst(str_replace('_', ' ', (string) $inv['role']))) ?></td>
              <td><span class="badge-amber">Invited</span></td>
              <td class="text-xs text-ink-400">Pending</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ------------------------------------------------ system data -->
    <section class="card overflow-hidden">
      <div class="st-card-head">
        <h2 class="st-card-title">
          <svg class="st-card-icon"><use href="#i-database"/></svg>System &amp; data
        </h2>
        <p class="st-card-sub">Manage system data, backups, and data retention.</p>
      </div>
      <ul class="divide-y divide-slate-100">
        <?php if (Auth::can('clinic.manage')): ?>
        <li>
          <a class="st-row" href="/settings/export">
            <span class="st-row-icon"><svg class="h-4 w-4"><use href="#i-plus"/></svg></span>
            <span class="min-w-0">
              <span class="block text-[13px] font-semibold text-ink-900">Export clinic data</span>
              <span class="block text-[11px] text-ink-500">Download your clinic data as a single archive.</span>
            </span>
          </a>
        </li>
        <li>
          <a class="st-row" href="/audit">
            <span class="st-row-icon"><svg class="h-4 w-4"><use href="#i-file"/></svg></span>
            <span class="min-w-0">
              <span class="block text-[13px] font-semibold text-ink-900">Audit log</span>
              <span class="block text-[11px] text-ink-500">View system activity for this clinic.</span>
            </span>
          </a>
        </li>
        <?php endif; ?>
        <li>
          <a class="st-row" href="/settings?tab=clinic#retention">
            <span class="st-row-icon"><svg class="h-4 w-4"><use href="#i-shield"/></svg></span>
            <span class="min-w-0">
              <span class="block text-[13px] font-semibold text-ink-900">Data retention</span>
              <span class="block text-[11px] text-ink-500">
                <?= $clinicRecord['purge_after'] === null
                    ? 'Manage data retention policy — currently set to keep records indefinitely.'
                    : 'Records are purged after ' . (int) $clinicRecord['purge_after'] . ' days.' ?>
              </span>
            </span>
          </a>
        </li>
      </ul>
    </section>

    <!-- ------------------------------------------------ danger zone -->
    <?php if ($canManage): ?>
      <section class="card overflow-hidden border-rose-200">
        <div class="st-card-head !border-rose-100">
          <h2 class="st-card-title !text-rose-700">
            <svg class="st-card-icon"><use href="#i-alert"/></svg>Danger zone
          </h2>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3.5">
          <div class="min-w-0">
            <p class="text-[13px] font-semibold text-ink-900">Clear clinic data</p>
            <p class="mt-0.5 text-[11px] text-ink-500">
              Delete every patient, token and appointment. Staff accounts are kept.
            </p>
          </div>
          <details class="st-details shrink-0">
            <summary class="btn-danger">Clear data</summary>
            <div class="st-details-body !right-0 !left-auto !w-80">
              <form method="post" action="/settings/purge" class="space-y-2">
                <?= csrf_field() ?>
                <p class="text-[11px] leading-relaxed text-ink-600">
                  Type the clinic name to confirm:
                  <span class="block font-semibold text-ink-900"><?= e($purgePhrase) ?></span>
                </p>
                <input class="input" name="confirm_phrase" autocomplete="off" placeholder="Clinic name" required>
                <button class="btn-danger w-full">Delete everything</button>
              </form>
            </div>
          </details>
        </div>
      </section>
    <?php endif; ?>

  </div>

  <!-- ============================================================ rail -->
  <aside class="space-y-5">

    <!-- ------------------------------------------------------ guide -->
    <section class="card card-pad">
      <h2 class="text-[13px] font-bold text-ink-900">Settings guide</h2>
      <p class="mt-2 text-[11px] leading-relaxed text-ink-500">
        Learn how to configure your clinic, from opening hours to priority rules,
        and what each setting changes for patients.
      </p>
      <a class="st-link mt-2.5 inline-block" href="/settings?tab=clinic#guide">View guide</a>
    </section>

    <!-- ----------------------------------------------- notifications -->
    <section class="card overflow-hidden">
      <div class="st-card-head">
        <h2 class="text-[13px] font-bold text-ink-900">Notifications</h2>
      </div>
      <p class="px-4 pt-3 text-[11px] leading-relaxed text-ink-500">
        Configure what alerts you and your team receive.
      </p>
      <form method="post" action="/settings/notifications" class="px-4 pb-3 pt-3">
        <?= csrf_field() ?>
        <div class="space-y-2.5">
          <?php foreach ($notifications as $n): ?>
            <label class="flex items-start gap-2 <?= $canManage ? 'cursor-pointer' : '' ?>">
              <input type="checkbox" class="checkbox mt-0.5" name="<?= e((string) $n['key']) ?>" value="1"
                     <?= $n['on'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
              <span class="min-w-0">
                <span class="block text-[11px] font-semibold text-ink-900"><?= e((string) $n['name']) ?></span>
                <span class="block text-[10px] leading-snug text-ink-500"><?= e((string) $n['blurb']) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if ($canManage): ?>
          <button class="btn-secondary mt-3 w-full !py-1.5 text-xs">Save alerts</button>
        <?php endif; ?>
      </form>
      <a class="st-link block border-t border-slate-100 px-4 py-2.5" href="/settings?tab=notifications">Manage notifications</a>
    </section>

    <!-- --------------------------------------------- language/region -->
    <section class="card overflow-hidden">
      <div class="st-card-head">
        <h2 class="text-[13px] font-bold text-ink-900">Language &amp; region</h2>
      </div>
      <form method="post" action="/settings/preferences" class="space-y-2.5 px-4 py-4">
        <?= csrf_field() ?>
        <div>
          <label class="label" for="st-locale">Language</label>
          <select class="select" id="st-locale" name="locale" <?= $canManage ? '' : 'disabled' ?>>
            <?php foreach ($locales as $code => $label): ?>
              <option value="<?= e((string) $code) ?>" <?= ($clinicRecord['locale'] ?? 'en') === $code ? 'selected' : '' ?>><?= e((string) $label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="st-timezone">Timezone</label>
          <select class="select" id="st-timezone" name="timezone" <?= $canManage ? '' : 'disabled' ?>>
            <?php foreach ($timezones as $tz => $label): ?>
              <option value="<?= e((string) $tz) ?>" <?= ($clinicRecord['timezone'] ?? 'Asia/Colombo') === $tz ? 'selected' : '' ?>><?= e((string) $label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($canManage): ?>
          <button class="btn-primary w-full !py-1.5 text-xs">Save preferences</button>
        <?php endif; ?>
      </form>
    </section>

  </aside>
</div>

<?php else: ?>

<div class="mt-4 space-y-4">
  <?php include QP_ROOT . '/resources/views/pages/settings/_' . $tab . '.php'; ?>
</div>

<?php endif; ?>
