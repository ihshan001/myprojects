<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · QueuePilot</title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
  <meta name="robots" content="noindex,nofollow">
</head>
<body class="min-h-full bg-slate-50">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div class="flex min-h-screen items-center justify-center px-6 py-12">
  <div class="w-full max-w-sm">
    <a href="/" class="flex items-center gap-2.5">
      <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-700 text-sm font-black text-white">Q</span>
      <span class="text-base font-bold tracking-tight text-slate-900">QueuePilot</span>
    </a>

    <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-900">One more step</h1>
    <p class="mt-1.5 text-sm text-slate-500">
      Your password was accepted. Enter the 6-digit code from your authenticator app — or one of your recovery codes.
    </p>

    <?php if (!empty($error)): ?>
      <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/login/2fa" class="mt-6 space-y-4" novalidate>
      <?= csrf_field() ?>
      <div>
        <label class="label" for="code">Authentication code</label>
        <input class="input text-center text-lg tracking-[0.4em] tabular" id="code" name="code"
               inputmode="text" autocomplete="one-time-code" autofocus required
               placeholder="000000" maxlength="12">
      </div>
      <button class="btn-primary w-full" type="submit">Verify and sign in</button>
    </form>

    <form method="post" action="/login/2fa/cancel" class="mt-3">
      <?= csrf_field() ?>
      <button class="w-full text-center text-xs font-semibold text-slate-500 hover:text-slate-800">Cancel and go back</button>
    </form>

    <p class="mt-6 rounded-lg bg-slate-100 px-3 py-2 text-[11px] leading-relaxed text-slate-500">
      Lost your phone? Use a recovery code. Each one works once. If you have none left, an administrator
      must turn two-factor off for your account.
    </p>
  </div>
</div>
</body>
</html>
