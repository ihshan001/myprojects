<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Patients</h1>
    <p class="mt-1 text-sm text-slate-500"><?= number_format($total) ?> records in this clinic · private to you</p>
  </div>
  <a href="/queue/new" class="btn-primary"><svg class="h-4 w-4"><use href="#i-plus"/></svg>New patient</a>
</div>

<form method="get" action="/patients" class="mt-5 flex gap-2">
  <div class="relative flex-1 sm:max-w-sm">
    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"><use href="#i-search"/></svg>
    <input class="input pl-9" name="q" value="<?= e($q) ?>" placeholder="Name, phone or patient code">
  </div>
  <button class="btn-secondary">Search</button>
  <?php if ($q !== ''): ?><a href="/patients" class="btn-ghost">Clear</a><?php endif; ?>
</form>

<div class="card mt-4 overflow-hidden">
  <?php if ($patients === []): ?>
    <?php $title = $q !== '' ? 'No matches' : 'No patients yet'; $message = $q !== '' ? 'Try a phone number or the first few letters of the name.' : 'Register the first patient from the token desk.'; $icon = 'users'; include QP_ROOT . '/resources/views/components/empty.php'; ?>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Patient</th>
          <th class="hidden sm:table-cell">Phone</th>
          <th class="hidden md:table-cell">Code</th>
          <th class="numeric hidden lg:table-cell">Visits</th>
          <th class="hidden lg:table-cell">Last seen</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($patients as $p): ?>
          <tr>
            <td>
              <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-bold text-slate-600">
                  <?= e(App\Core\Str::initials((string) $p['full_name'])) ?>
                </span>
                <div class="min-w-0">
                  <a href="/patients/<?= (int) $p['id'] ?>" class="block truncate font-medium text-slate-900 hover:text-teal-700"><?= e($p['full_name']) ?></a>
                  <p class="text-[11px] text-slate-400"><?= e((string) ($p['gender'] ?? '')) ?><?= !empty($p['birth_year']) ? ' · ' . (int) (date('Y') - (int) $p['birth_year']) . ' yrs' : '' ?></p>
                </div>
              </div>
            </td>
            <td class="hidden sm:table-cell text-xs"><?= e((string) ($p['phone'] ?? '—')) ?></td>
            <td class="hidden md:table-cell font-mono text-[11px] text-slate-500"><?= e((string) $p['code']) ?></td>
            <td class="numeric hidden lg:table-cell text-xs"><?= (int) ($p['visits'] ?? 0) ?></td>
            <td class="hidden lg:table-cell text-xs text-slate-500"><?= e(!empty($p['last_seen']) ? ago($p['last_seen']) : '—') ?></td>
            <td class="text-right">
              <a href="/patients/<?= (int) $p['id'] ?>" class="act-mute">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
