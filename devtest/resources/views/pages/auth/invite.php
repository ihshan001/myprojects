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

    <?php if (!empty($invalid)): ?>
      <div class="mt-8 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
        <?= e($invalid) ?>
      </div>
      <a href="/login" class="btn-secondary mt-4 w-full">Go to sign in</a>
    <?php else: ?>
      <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-900">Join <?= e((string) $invite['clinic_name']) ?></h1>
      <p class="mt-1.5 text-sm text-slate-500">
        You were invited as <strong><?= e(str_replace('_', ' ', (string) $invite['role'])) ?></strong>.
        Set your own password — nobody else knows it.
      </p>

      <?php if (!empty($error)): ?>
        <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
          <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/invite/<?= e((string) $token) ?>" class="mt-6 space-y-4" novalidate>
        <?= csrf_field() ?>
        <div>
          <label class="label" for="name">Your full name</label>
          <input class="input" id="name" name="name" required maxlength="120"
                 value="<?= e($name ?? '') ?>" autocomplete="name">
        </div>
        <div>
          <label class="label" for="password">Choose a password</label>
          <input class="input" id="password" name="password" type="password" required minlength="10"
                 autocomplete="new-password" placeholder="At least 10 characters">
          <p class="mt-1 text-[11px] text-slate-500"><?= e($passwordRule) ?></p>
        </div>
        <div>
          <label class="label" for="password_confirm">Repeat password</label>
          <input class="input" id="password_confirm" name="password_confirm" type="password" required
                 autocomplete="new-password">
        </div>
        <button class="btn-primary w-full" type="submit">Create my account</button>
      </form>

      <p class="mt-4 text-center text-[11px] text-slate-400">
        This invitation expires <?= e(ago($invite['expires_at'])) ?> from now and can only be used once.
      </p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
