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

    <div class="card overflow-hidden">
      <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Staff accounts</p>
        <p class="text-[11px] text-slate-400"><?= count($staff) ?> of <?= (int) ($plan['max_staff'] ?? 0) ?> allowed</p>
      </div>
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

      <?php if ($canManage): ?>
        <details class="border-t border-slate-200">
          <summary class="cursor-pointer px-4 py-3 text-xs font-semibold text-teal-700 hover:bg-slate-50">Invite a staff member</summary>
          <form method="post" action="/settings/invites" class="space-y-3 px-4 pb-4">
            <?= csrf_field() ?>
            <div class="grid gap-3 sm:grid-cols-2">
              <div><label class="label">Full name</label><input class="input" name="name" required maxlength="120"></div>
              <div><label class="label">Email</label><input class="input" name="email" type="email" required maxlength="160"></div>
              <div class="sm:col-span-2"><label class="label">Role</label>
                <select class="select" name="role">
                  <option value="receptionist">Receptionist</option>
                  <option value="doctor">Doctor</option>
                  <option value="clinic_admin">Clinic owner</option>
                </select></div>
            </div>
            <button class="btn-primary">Send invitation</button>
            <p class="help">
              You never choose their password: the invitation email contains a one-time link where
              they set their own. The link expires in 72 hours and can be revoked below.
            </p>
          </form>
        </details>

        <?php if (($invites ?? []) !== []): ?>
          <details class="border-t border-slate-200" open>
            <summary class="cursor-pointer px-4 py-3 text-xs font-semibold text-teal-700 hover:bg-slate-50">
              Pending invitations (<?= count($invites) ?>)
            </summary>
            <table class="table">
              <thead><tr><th>Email</th><th>Role</th><th class="hidden sm:table-cell">Expires</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($invites as $inv): ?>
                  <tr>
                    <td class="text-xs"><?= e($inv['email']) ?></td>
                    <td><span class="badge-slate"><?= e(str_replace('_', ' ', (string) $inv['role'])) ?></span></td>
                    <td class="hidden sm:table-cell text-xs text-slate-500"><?= e(ago($inv['expires_at'])) ?> left</td>
                    <td class="text-right">
                      <form method="post" action="/settings/invites/<?= (int) $inv['id'] ?>/revoke" class="inline">
                        <?= csrf_field() ?>
                        <button class="act-mute">Revoke</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        <?php endif; ?>
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
</div>
