<?php
/**
 * Dev role chooser: pick a role and enter that dashboard directly, no
 * password. Temporary — login will be re-added once all roles and features
 * are tested. Not for production.
 */
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · QueuePilot</title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="min-h-full bg-slate-50 text-slate-900">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div class="max-w-xl mx-auto px-6 py-12">
  <div class="text-center mb-10">
    <a href="/" class="flex items-center gap-2.5 justify-center">
      <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-700 text-sm font-black text-white">Q</span>
      <span class="text-base font-bold tracking-tight text-slate-900">QueuePilot</span>
    </a>
    <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900"><?= e($title) ?></h1>
    <p class="mt-2 text-sm text-slate-500">Pick a role to enter that dashboard directly. No password — dev access only.</p>
    <p class="mt-1 text-xs text-rose-500">Not for production. Login and real auth will be restored after testing.</p>
  </div>

  <div class="grid gap-3">
    <?php foreach ($users as $u): ?>
      <?php
      $roleLabel = [
        'super_admin' => 'Platform Admin',
        'clinic_admin' => 'Clinic Owner',
        'receptionist' => 'Receptionist',
        'doctor' => 'Doctor',
      ][$u['role']] ?? $u['role'];
      $clinicName = $u['clinic_id'] ? '(clinic #' . (int) $u['clinic_id'] . ')' : '(platform, no clinic)';
      $target = $u['role'] === 'super_admin' ? '/admin' : '/app';
      ?>
      <a href="/dev/role/sign-in-as/<?= (int) $u['id'] ?>"
         class="group flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-4 hover:bg-slate-50 hover:border-teal-200 transition">
        <div>
          <p class="text-sm font-semibold text-slate-900 group-hover:text-teal-700"><?= e($roleLabel) ?></p>
          <p class="text-[11px] text-slate-500 truncate"><?= e((string) $u['email']) ?> <?= e($clinicName) ?></p>
        </div>
        <span class="text-teal-600 group-hover:text-teal-800">Enter →</span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="mt-10 text-center">
    <p class="text-xs text-slate-400">QueuePilot · dev access bypass · multi-tenant queue management</p>
  </div>
</div>
</body>
</html>
