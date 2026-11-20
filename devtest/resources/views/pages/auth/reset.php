<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Choose a new password · QueuePilot</title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="min-h-full bg-slate-50">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>
<?php if (($flash = notice()) !== null): ?>
  <div class="fixed left-1/2 top-4 z-50 w-[min(92vw,30rem)] -translate-x-1/2">
    <div class="rise rounded-xl border px-4 py-3 text-sm shadow-lg
      <?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
      <?= e($flash['message']) ?>
    </div>
  </div>
<?php endif; ?>

<div class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-6 py-12">
  <a href="/" class="flex items-center gap-2.5">
    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-700 text-sm font-black text-white">Q</span>
    <span class="text-base font-bold tracking-tight text-slate-900">QueuePilot</span>
  </a>

  <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-900">Choose a new password</h1>
  <p class="mt-1.5 text-sm text-slate-500">
    For <span class="font-medium text-slate-700"><?= e($email) ?></span>.
    <?= e(\App\Services\StaffInviteService::passwordRule()) ?>
  </p>

  <form method="post" action="/reset-password/<?= e(rawurlencode($token)) ?>" class="mt-7 space-y-4" novalidate>
    <?= csrf_field() ?>
    <div>
      <label class="label" for="password">New password</label>
      <input class="input" id="password" name="password" type="password"
             autocomplete="new-password" required autofocus>
    </div>
    <div>
      <label class="label" for="password_confirmation">Repeat the new password</label>
      <input class="input" id="password_confirmation" name="password_confirmation" type="password"
             autocomplete="new-password" required>
    </div>
    <button class="btn-primary w-full justify-center" type="submit">Change my password</button>
  </form>

  <p class="mt-6 text-xs leading-relaxed text-slate-400">
    Changing your password signs you out everywhere. Anything else signed in with the old
    password is cut off at the next request.
  </p>
</div>
</body>
</html>
