<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset your password · QueuePilot</title>
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

  <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-900">Forgot your password?</h1>
  <p class="mt-1.5 text-sm leading-relaxed text-slate-500">
    Enter the email address your clinic administrator set up for you. We will send a link
    that lets you choose a new password. The link is good for 60 minutes and works once.
  </p>

  <form method="post" action="/forgot-password" class="mt-7 space-y-4" novalidate>
    <?= csrf_field() ?>
    <div>
      <label class="label" for="email">Email address</label>
      <input class="input" id="email" name="email" type="email" autocomplete="username"
             required value="<?= e(old('email', '')) ?>" placeholder="you@clinic.lk" autofocus>
    </div>
    <button class="btn-primary w-full justify-center" type="submit">Send the reset link</button>
  </form>

  <?php if (($preview = \App\Core\Session::takeFlash('reset_preview')) !== null): ?>
    <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
      <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Development mode</p>
      <p class="mt-1 text-sm leading-relaxed text-amber-900">
        Mail is being written to the log rather than delivered, so here is the link that would
        have been emailed. This panel never appears when a real mail transport is configured.
      </p>
      <a class="mt-2 block break-all text-sm font-medium text-amber-800 underline" href="<?= e($preview) ?>"><?= e($preview) ?></a>
    </div>
  <?php endif; ?>

  <p class="mt-7 text-sm text-slate-500">
    Remembered it? <a class="font-medium text-teal-700 hover:underline" href="/login">Sign in</a>.
  </p>
  <p class="mt-2 text-xs leading-relaxed text-slate-400">
    If the address is not on file nothing is sent — but this page says the same thing either
    way, so it cannot be used to find out who has an account here.
  </p>
</div>
</body>
</html>
