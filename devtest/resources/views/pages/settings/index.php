<?php $c = $clinicRecord; ?>
<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Settings</h1>
    <p class="mt-1 text-sm text-slate-500">Clinic identity, staff accounts and queue policy.</p>
  </div>
  <a href="/billing" class="btn-secondary"><svg class="h-4 w-4"><use href="#i-card"/></svg><?= e($plan['name'] ?? 'No plan') ?></a>
</div>

<?php if (!empty($inviteLink)): ?>
  <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
    <p class="text-xs font-bold text-amber-900">Invitation link — pass this on yourself</p>
    <p class="mt-0.5 text-[11px] text-amber-800">
      No mail transport is configured, so nothing was emailed. This link works once and expires in 72 hours.
    </p>
    <div class="mt-2 flex items-center gap-2 rounded-lg bg-white px-3 py-2">
      <code class="flex-1 truncate text-[11px] text-slate-700"><?= e(rtrim((string) App\Core\Env::get('app.url', ''), '/') . $inviteLink) ?></code>
      <button class="rounded p-1 text-slate-400 hover:bg-slate-50 hover:text-slate-700"
              data-copy="<?= e(rtrim((string) App\Core\Env::get('app.url', ''), '/') . $inviteLink) ?>">
        <svg class="h-3.5 w-3.5"><use href="#i-copy"/></svg>
      </button>
    </div>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.15fr_.85fr]">
  <div class="space-y-5">
    <div class="card card-pad">
      <h2 class="text-sm font-bold text-slate-900">Clinic profile</h2>
      <p class="mt-1 text-xs text-slate-500">Shown on patient tracking pages and printed tokens.</p>
      <form method="post" action="/settings" class="mt-4 space-y-3" <?= $canManage ? '' : 'inert' ?>>
        <?= csrf_field() ?>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="sm:col-span-2"><label class="label" for="name">Clinic name</label>
            <input class="input" id="name" name="name" value="<?= e((string) $c['name']) ?>" required></div>
          <div><label class="label" for="phone">Phone</label>
            <input class="input" id="phone" name="phone" value="<?= e((string) ($c['phone'] ?? '')) ?>"></div>
          <div><label class="label" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" value="<?= e((string) ($c['email'] ?? '')) ?>"></div>
          <div class="sm:col-span-2"><label class="label" for="address_line">Address</label>
            <input class="input" id="address_line" name="address_line" value="<?= e((string) ($c['address_line'] ?? '')) ?>"></div>
          <div><label class="label" for="city">City</label>
            <input class="input" id="city" name="city" value="<?= e((string) ($c['city'] ?? '')) ?>"></div>
          <div><label class="label" for="district">District</label>
            <input class="input" id="district" name="district" value="<?= e((string) ($c['district'] ?? '')) ?>"></div>
          <div><label class="label" for="brand_color">Brand colour</label>
            <div class="flex gap-2">
              <input class="h-10 w-12 cursor-pointer rounded-lg border border-slate-300" type="color"
                     id="brand_color" name="brand_color" value="<?= e((string) $c['brand_color']) ?>">
              <input class="input" value="<?= e((string) $c['brand_color']) ?>" readonly aria-label="Colour value">
            </div></div>
          <div><label class="label" for="locale">Default language</label>
            <select class="select" id="locale" name="locale">
              <?php foreach ($locales as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $c['locale'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>

        <div class="border-t border-slate-100 pt-3">
          <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Queue policy</h3>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div><label class="label" for="token_prefix">Token prefix</label>
              <input class="input" id="token_prefix" name="token_prefix" maxlength="2"
                     value="<?= e((string) $c['token_prefix']) ?>" placeholder="A">
              <p class="help">Letters before the number: A001, A002…</p></div>
            <div><label class="label" for="late_grace_minutes">Late grace (minutes)</label>
              <input class="input" id="late_grace_minutes" name="late_grace_minutes" inputmode="numeric"
                     value="<?= (int) $c['late_grace_minutes'] ?>">
              <p class="help">How long before a patient is marked absent.</p></div>
          </div>

          <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5">
            <label class="flex items-start gap-2">
              <input type="checkbox" class="checkbox mt-0.5" name="notify_approach" value="1"
                     <?= (int) ($c['notify_approach'] ?? 0) === 1 ? 'checked' : '' ?>>
              <span>
                <span class="block text-[11px] font-semibold text-slate-800">SMS "your turn is approaching"</span>
                <span class="block text-[10px] leading-relaxed text-slate-500">
                  One extra message per patient when they are near the front. Costs one SMS each — most
                  clinics leave this off and rely on the tracking link.
                </span>
              </span>
            </label>
            <div class="mt-2 flex items-center gap-2 pl-6">
              <label class="text-[10px] font-semibold text-slate-600" for="approach_threshold">Message the next</label>
              <input class="input !w-14 !px-2 !py-1 text-center text-[11px]" id="approach_threshold"
                     name="approach_threshold" inputmode="numeric" min="1" max="10"
                     value="<?= (int) ($c['approach_threshold'] ?? 2) ?>">
              <span class="text-[10px] text-slate-600">patient(s) in line</span>
            </div>
          </div>
        </div>

        <?php if ($canManage): ?>
          <button class="btn-primary w-full">Save settings</button>
        <?php else: ?>
          <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">Only a clinic owner can change these settings.</p>
        <?php endif; ?>
      </form>
    </div>

    <?php $dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday']; ?>
    <div class="card overflow-hidden">
      <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Opening hours and booking</p>
      </div>
      <form method="post" action="/settings/hours" class="space-y-3 px-4 py-4">
        <?= csrf_field() ?>
        <p class="text-[11px] leading-relaxed text-slate-500">
          The public booking page builds its times from this grid — the clinic's hours on each
          weekday, divided by each doctor's own consultation length, minus appointments already
          booked. Days left empty use the 8:30&nbsp;AM&ndash;12:30&nbsp;PM default and say so to the patient.
        </p>

        <?php foreach ($dayNames as $dow => $dayName): ?>
          <?php $row = $weekHours[$dow] ?? ['open' => null, 'close' => null, 'closed' => true, 'configured' => false]; ?>
          <div class="grid grid-cols-[7.5rem_1fr_1fr_auto] items-center gap-2">
            <label class="text-xs font-medium text-slate-600" for="open_<?= $dow ?>"><?= e($dayName) ?></label>
            <input class="input py-1.5 text-xs" type="time" id="open_<?= $dow ?>" name="open_<?= $dow ?>"
                   value="<?= e($row['open'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>>
            <input class="input py-1.5 text-xs" type="time" name="close_<?= $dow ?>"
                   value="<?= e($row['close'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>
                   aria-label="<?= e($dayName) ?> closing time">
            <label class="flex items-center gap-1.5 text-[11px] text-slate-500">
              <input type="checkbox" name="closed_<?= $dow ?>" value="1"
                     <?= $row['closed'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
              closed
            </label>
            <?php if (!$row['configured']): ?>
              <span class="col-start-2 col-span-3 text-[10px] text-slate-400">default — not yet set by this clinic</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div class="grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2">
          <div>
            <label class="label" for="horizon_days">Book up to (days ahead)</label>
            <input class="input" type="number" id="horizon_days" name="horizon_days" min="1" max="180"
                   value="<?= (int) ($horizon ?? 30) ?>" <?= $canManage ? '' : 'disabled' ?>>
          </div>
        </div>

        <?php if ($canManage): ?>
          <button class="btn-primary w-full">Save opening hours</button>
        <?php else: ?>
          <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">Only a clinic owner can change opening hours.</p>
        <?php endif; ?>
      </form>
    </div>

    <div class="card overflow-hidden">
      <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Staff accounts</p>
        <p class="text-[11px] text-slate-400"><?= count($staff) ?> of <?= (int) ($plan['max_staff'] ?? 0) ?> allowed</p>
      </div>
      <div class="table-scroll">
        <table class="table">
        <thead><tr><th>Name</th><th class="hidden sm:table-cell">Email</th><th>Role</th><th class="hidden md:table-cell">Last sign-in</th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
        <tr>
        <td class="font-medium"><?= e($s['name']) ?></td>
        <td class="hidden sm:table-cell text-xs text-slate-500"><?= e($s['email']) ?></td>
        <td><span class="<?= $s['role'] === 'clinic_admin' ? 'badge-violet' : ($s['role'] === 'doctor' ? 'badge-teal' : 'badge-slate') ?>">
        <?= e(str_replace('_', ' ', (string) $s['role'])) ?></span></td>
        <td class="hidden md:table-cell text-xs text-slate-500"><?= e($s['last_login_at'] ? ago($s['last_login_at']) : 'never') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
      </div>

      <?php if ($canManage): ?>

      <?php endif; ?>
    </div>
  </div>

  <div class="space-y-5">
    <div class="card card-pad">
      <h2 class="text-sm font-bold text-slate-900">Waiting-room display</h2>
      <p class="mt-1 text-xs leading-relaxed text-slate-500">
        A revocable key just for lobby screens. It shows tokens and rooms — never patient names.
      </p>
      <div class="mt-3 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2">
        <code class="flex-1 truncate text-[10px] text-slate-600"><?= e($displayToken ?: '—') ?></code>
        <button class="rounded p-1 text-slate-400 hover:bg-white hover:text-slate-700"
                data-copy="<?= e(($displayToken ?? '')) ?>"><svg class="h-3.5 w-3.5"><use href="#i-copy"/></svg></button>
      </div>
      <a href="/display" target="_blank" class="btn-secondary mt-3 w-full">
        <svg class="h-4 w-4"><use href="#i-monitor"/></svg> Open display mode
      </a>
    </div>

    <div class="card card-pad">
      <h2 class="text-sm font-bold text-slate-900">Notifications</h2>
      <div class="mt-3 grid grid-cols-3 gap-2 text-center">
        <?php foreach ([['Queued', $notifStats['queued'] ?? 0, 'text-amber-600'], ['Sent', $notifStats['sent'] ?? 0, 'text-emerald-600'], ['Failed', $notifStats['failed'] ?? 0, 'text-rose-600']] as [$l, $v, $tone]): ?>
          <div class="rounded-lg bg-slate-50 py-2.5">
            <p class="text-lg font-bold tabular <?= $tone ?>"><?= (int) $v ?></p>
            <p class="text-[10px] text-slate-500"><?= e($l) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="mt-3 text-[11px] leading-relaxed text-slate-500">
        Messages are queued inside the same transaction as the queue change, so one is never lost.
        SMS and WhatsApp usage is billed separately from your plan.
      </p>
      <?php if (($notifStats['total'] ?? 0) > 0): ?>
        <a href="/audit?action=notification" class="btn-ghost mt-2 w-full text-xs">View delivery log →</a>
      <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
      <div class="card card-pad">
        <h2 class="text-sm font-bold text-slate-900">Your data</h2>
        <p class="mt-1 text-xs leading-relaxed text-slate-500">
          Every patient record, token and history entry is yours. Take the whole clinic as one zip of CSV
          files whenever you want — no support ticket, no waiting.
        </p>
        <ul class="mt-3 space-y-1 text-[11px] leading-relaxed text-slate-500">
          <li>· Password hashes and two-factor secrets are never included.</li>
          <li>· The display token only on request, because it is a live credential.</li>
          <li>· Every export is recorded in the audit log.</li>
        </ul>
        <a href="/settings/export" class="btn-secondary mt-3 w-full text-xs">Export clinic data →</a>
        <a href="/settings/offline" class="btn-ghost mt-2 w-full text-xs">Offline desks →</a>
      </div>
    <?php endif; ?>

    <div class="card card-pad">
      <h2 class="text-sm font-bold text-slate-900">Language</h2>
      <p class="mt-1 text-xs text-slate-500">Interface language for your staff. Patients always see the clinic's default.</p>
      <form method="post" action="/locale" class="mt-3 flex gap-2">
        <?= csrf_field() ?>
        <select class="select" name="locale">
          <?php foreach ($locales as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= ($locale ?? 'en') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn-secondary shrink-0">Apply</button>
      </form>
    </div>
  </div>

  <?php if ($canManage): ?>
    <div class="card mt-5 overflow-hidden" id="account">
      <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Account closure</p>
      </div>
      <div class="px-4 py-4">
        <p class="text-[11px] leading-relaxed text-slate-500">
          Closing the clinic signs out every staff member immediately and hides the public
          booking page and display. <b class="text-slate-700">Nothing is deleted for 90 days</b>,
          which is the window in which support can restore access if this was a mistake. After
          that the patient and visit records are purged; invoices are kept for accounting.
        </p>
        <details class="mt-3">
          <summary class="cursor-pointer text-xs font-semibold text-rose-600 hover:underline">Close this clinic…</summary>
          <form method="post" action="/settings/closure" class="mt-3 space-y-2"
                onsubmit="return confirm('Sign out every staff member and close this clinic? There is a 90-day window to reverse it.')">
            <?= csrf_field() ?>
            <label class="label" for="confirm_name">Type <b><?= e($clinicRecord['name']) ?></b> to confirm</label>
            <input class="input" id="confirm_name" name="confirm_name" required autocomplete="off"
                   placeholder="<?= e($clinicRecord['name']) ?>">
            <button class="btn-stop btn-sm">Request closure</button>
          </form>
        </details>
      </div>
    </div>
  <?php endif; ?>
</div>
