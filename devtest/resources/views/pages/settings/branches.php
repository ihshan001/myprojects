<?php
/**
 * Who works in which branch.
 *
 * Organised by branch, not by user, because that is the question being asked
 * ("who covers Negombo?") and because a wrong grant is then visible as a name
 * sitting in the wrong column rather than a permission buried in a profile.
 *
 * @var array<string,mixed> $clinic
 * @var list<array<string,mixed>> $branches
 * @var list<array<string,mixed>> $grants
 * @var list<array<string,mixed>> $staff
 * @var bool $canManage
 */
$staffById = [];
foreach ($staff as $s) {
    $staffById[(int) $s['id']] = $s;
}
$grantsByBranch = [];
foreach ($grants as $g) {
    $grantsByBranch[(int) $g['clinic_id']][] = $g;
}
?>
<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Branches</h1>
    <p class="mt-1 text-sm text-slate-500">
      Staff work in their own branch. Grant another one here and it appears in their switcher.
    </p>
  </div>
  <a href="/settings" class="btn-secondary text-xs">Back to settings</a>
</div>

<div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3">
  <p class="text-xs leading-relaxed text-slate-600">
    <b>Nothing widens by accident.</b> A branch grant selects one of the branches a person is
    already allowed to use — it does not create a group-wide view. Every screen, report and queue
    in the product scopes to the branch that is currently active, so switching branches changes
    which clinic's data you see, never how much of it.
  </p>
</div>

<div class="mt-5 space-y-4">
  <?php foreach ($branches as $b): ?>
    <?php $branchGrants = $grantsByBranch[(int) $b['id']] ?? []; ?>
    <div class="card overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <div>
          <p class="text-sm font-semibold text-slate-800"><?= e((string) $b['name']) ?></p>
          <p class="text-[11px] text-slate-400">
            <?= e((string) ($b['city'] ?? '')) ?>
            <?php if ((int) $b['id'] === (int) $clinic['id']): ?> · this branch<?php endif; ?>
          </p>
        </div>
        <span class="badge-slate"><?= count($branchGrants) ?> granted</span>
      </div>

      <?php if ($branchGrants === [] && (int) $b['id'] !== (int) $clinic['id']): ?>
        <p class="px-4 py-3 text-xs text-slate-500">
          Nobody has been granted this branch yet.
        </p>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($branchGrants as $g): ?>
            <li class="flex items-center justify-between gap-3 px-4 py-2.5">
              <div class="min-w-0">
                <p class="truncate text-sm text-slate-800"><?= e((string) $g['user_name']) ?></p>
                <p class="truncate text-[11px] text-slate-400">
                  <?= e((string) $g['email']) ?> · granted <?= e(date('j M Y', strtotime((string) $g['created_at']))) ?>
                </p>
              </div>
              <?php if ($canManage): ?>
                <form method="post" action="/settings/branches/revoke">
                  <?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int) $g['user_id'] ?>">
                  <input type="hidden" name="clinic_id" value="<?= (int) $g['clinic_id'] ?>">
                  <button class="btn-ghost text-xs text-rose-600 hover:bg-rose-50">Remove</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <details class="border-t border-slate-200">
          <summary class="cursor-pointer px-4 py-2.5 text-xs font-semibold text-teal-700 hover:bg-slate-50">
            Grant this branch to someone
          </summary>
          <form method="post" action="/settings/branches" class="flex flex-wrap items-end gap-2 px-4 pb-4">
            <?= csrf_field() ?>
            <input type="hidden" name="clinic_id" value="<?= (int) $b['id'] ?>">
            <div class="min-w-0 flex-1">
              <label class="label" for="user-<?= (int) $b['id'] ?>">Staff member</label>
              <select class="select" id="user-<?= (int) $b['id'] ?>" name="user_id" required>
                <option value="">Choose…</option>
                <?php foreach ($staff as $s): ?>
                  <?php if ((string) $s['via'] === 'home' && (int) $s['id'] === 0) { continue; } ?>
                  <option value="<?= (int) $s['id'] ?>">
                    <?= e((string) $s['name']) ?> · <?= e(str_replace('_', ' ', (string) $s['role'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn-primary text-xs">Grant</button>
          </form>
          <p class="px-4 pb-4 text-[11px] leading-relaxed text-slate-400">
            The person keeps their own role — a receptionist granted a second branch is still a
            receptionist there. Their home branch cannot be removed this way.
          </p>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mt-5 overflow-hidden">
  <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
    <p class="text-xs font-semibold text-slate-600">Who can reach this branch</p>
  </div>
  <div class="table-scroll">
    <table class="table">
    <thead><tr><th>Name</th><th class="hidden sm:table-cell">Email</th><th>Role</th><th>Access via</th></tr></thead>
    <tbody>
    <?php foreach ($staff as $s): ?>
    <tr>
    <td class="font-medium"><?= e((string) $s['name']) ?></td>
    <td class="hidden sm:table-cell text-xs text-slate-500"><?= e((string) $s['email']) ?></td>
    <td><span class="badge-slate"><?= e(str_replace('_', ' ', (string) $s['role'])) ?></span></td>
    <td>
    <span class="<?= (string) $s['via'] === 'home' ? 'badge-teal' : 'badge-violet' ?>">
    <?= (string) $s['via'] === 'home' ? 'home branch' : 'granted' ?>
    </span>
    </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
  </div>
</div>
