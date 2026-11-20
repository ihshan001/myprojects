<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Set your password · <?= e($appName ?? \App\Core\Env::get('app.name', 'QueuePilot')) ?></title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="min-h-full bg-slate-50">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>
<div class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-6 py-12">
  <a href="/" class="flex items-center gap-2.5">
    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-700 text-sm font-black text-white">Q</span>
    <span class="text-base font-bold tracking-tight text-slate-900"><?= e($appName ?? \App\Core\Env::get('app.name', 'QueuePilot')) ?></span>
  </a>

  <?php if ($error !== null): ?>
    <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-900">That link cannot be used</h1>
    <p class="mt-2 text-sm leading-relaxed text-slate-600"><?= e($error) ?></p>
    <a href="/login" class="btn mt-6 justify-center">Go to sign in</a>
  <?php else: ?>
    <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-900">Set your password</h1>
    <p class="mt-1.5 text-sm leading-relaxed text-slate-500">
      For <strong class="text-slate-700"><?= e((string) ($clinic['name'] ?? '')) ?></strong>.
      Choose a password you have not used elsewhere.
      <?= e(App\Services\StaffInviteService::passwordRule()) ?>
    </p>

    <form method="post" action="/welcome/<?= e(rawurlencode($token)) ?>" class="mt-7 space-y-4" novalidate>
      <?= csrf_field() ?>
      <div>
        <label class="label" for="password">Your password</label>
        <input class="input" id="password" name="password" type="password"
               autocomplete="new-password" required autofocus>
      </div>
      <div>
        <label class="label" for="password_confirmation">Repeat it</label>
        <input class="input" id="password_confirmation" name="password_confirmation" type="password"
               autocomplete="new-password" required>
      </div>
      <button class="btn-primary w-full justify-center" type="submit">Set password and sign in</button>
    </form>

    <div class="mt-8 rounded-lg bg-white p-4 ring-1 ring-slate-200">
      <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">After you sign in</p>
      <ul class="mt-2 space-y-1.5 text-xs leading-relaxed text-slate-600">
        <li>· <strong>Getting started</strong> lists what is left to configure — rooms, hours, your own name.</li>
        <li>· Your waiting-room screen has its own address. Open it on the TV in the corridor.</li>
        <li>· Add staff from <strong>Settings → Staff</strong>; they receive their own link, never a password.</li>
      </ul>
    </div>
  <?php endif; ?>

  <p class="mt-8 text-xs leading-relaxed text-slate-400">
    This page proves you can read the mailbox the clinic registered with. If you were
    not expecting it, ignore it — the link expires on its own.
  </p>
</div>
</body>
</html>
