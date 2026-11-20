<?php
/**
 * Team tab — who can get into this clinic, and what they can do.
 *
 * Two lists, because "has an account" and "has been asked to get one" are
 * different states and a manager needs both to answer "are we short-staffed?".
 *
 * @var array<int,array<string,mixed>> $staff
 * @var array<int,array<string,mixed>> $invites
 * @var bool $canManage
 * @var array<string,mixed> $plan
 */
$roleLabel = static function (string $role): string {
    return match ($role) {
        'clinic_admin' => 'Admin',
        'doctor'       => 'Doctor',
        'receptionist' => 'Receptionist',
        'super_admin'  => 'Platform owner',
        default        => ucfirst(str_replace('_', ' ', $role)),
    };
};
?>
<div class="card overflow-hidden">
  <div class="section-head">
    <p class="section-title">Staff accounts</p>
    <p class="text-[11px] text-ink-400"><?= count($staff) ?> of <?= (int) ($plan['max_staff'] ?? 0) ?> allowed</p>
  </div>
  <div class="table-scroll">
    <table class="table">
      <thead>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th></tr>
      </thead>
      <tbody>
      <?php foreach ($staff as $s): ?>
        <tr>
          <td class="font-medium text-ink-900"><?= e((string) $s['name']) ?></td>
          <td class="text-xs text-ink-500"><?= e((string) $s['email']) ?></td>
          <td><?= e($roleLabel((string) $s['role'])) ?></td>
          <td>
            <?php if ((int) $s['is_active'] === 1): ?>
              <span class="badge-teal">Active</span>
            <?php else: ?>
              <span class="badge-slate">Disabled</span>
            <?php endif; ?>
          </td>
          <td class="text-xs text-ink-500"><?= e($s['last_login_at'] ? ago((string) $s['last_login_at']) : 'never') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="section-head">
    <p class="section-title">Pending invitations</p>
    <p class="text-[11px] text-ink-400"><?= count($invites) ?> outstanding</p>
  </div>
  <?php if ($invites === []): ?>
    <p class="px-4 py-6 text-center text-xs text-ink-500">No invitations are outstanding.</p>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table">
        <thead><tr><th>Email</th><th>Role</th><th>Invited</th><th>Expires</th></tr></thead>
        <tbody>
        <?php foreach ($invites as $inv): ?>
          <tr>
            <td class="text-ink-900"><?= e((string) $inv['email']) ?></td>
            <td><?= e($roleLabel((string) $inv['role'])) ?></td>
            <td class="text-xs text-ink-500"><?= e(ago((string) $inv['created_at'])) ?></td>
            <td class="text-xs text-ink-500"><?= e(fmt_time((string) $inv['expires_at'], 'M j, Y')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="border-t border-slate-100 px-4 py-2.5 text-[11px] leading-relaxed text-ink-500">
      No mail transport is configured on this server, so invitations are not emailed — the link is shown
      once to whoever created it.
    </p>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
  <div class="card card-pad">
    <h2 class="text-sm font-bold text-ink-900">Invite a team member</h2>
    <p class="mt-1 text-xs text-ink-500">They choose their own password from the link.</p>
    <form method="post" action="/settings/staff" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto]">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="invite-email">Email</label>
        <input class="input" id="invite-email" name="email" type="email" required placeholder="name@clinic.lk">
      </div>
      <div>
        <label class="label" for="invite-role">Role</label>
        <select class="select" id="invite-role" name="role">
          <option value="receptionist">Receptionist</option>
          <option value="doctor">Doctor</option>
          <option value="clinic_admin">Admin</option>
        </select>
      </div>
      <div class="flex items-end">
        <button class="btn-primary w-full sm:w-auto">Send invite</button>
      </div>
    </form>
  </div>
<?php endif; ?>
