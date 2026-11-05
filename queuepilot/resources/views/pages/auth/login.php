<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in · QueuePilot</title>
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

<div class="grid min-h-screen lg:grid-cols-[1fr_1.1fr]">
  <!-- form -->
  <div class="flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-sm">
      <a href="/" class="flex items-center gap-2.5">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-700 text-sm font-black text-white">Q</span>
        <span class="text-base font-bold tracking-tight text-slate-900">QueuePilot</span>
      </a>

      <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-900">Sign in to your clinic</h1>
      <p class="mt-1.5 text-sm text-slate-500">Use the account your clinic administrator created.</p>

      <form method="post" action="/login" class="mt-7 space-y-4" novalidate>
        <?= csrf_field() ?>
        <div>
          <label class="label" for="email">Email address</label>
          <input class="input" id="email" name="email" type="email" autocomplete="username"
                 required value="<?= e(old('email', $email ?? '')) ?>" placeholder="you@clinic.lk">
        </div>
        <div>
          <div class="flex items-center justify-between">
            <label class="label" for="password">Password</label>
          </div>
          <input class="input" id="password" name="password" type="password" autocomplete="current-password"
                 required placeholder="••••••••••">
        </div>
        <button class="btn-primary w-full" type="submit">Sign in</button>
      </form>

      <p class="mt-5 text-center text-xs text-slate-500">
        New clinic?
        <a href="/register" class="font-semibold text-teal-700 hover:underline">Start a 14-day trial</a>
      </p>

      <div class="mt-8 rounded-xl border border-slate-200 bg-white p-4">
        <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">
          <svg class="h-3.5 w-3.5"><use href="#i-sparkles"/></svg>
          Demo accounts
        </p>
        <p class="mt-1.5 text-[11px] leading-relaxed text-slate-500">
          Click to fill — the password is included so you can explore every role.
        </p>
        <div class="mt-3 space-y-1.5">
          <?php foreach ([
              ['Receptionist', 'reception@serenity.lk', 'Reception#2026'],
              ['Doctor', 'amara@serenity.lk', 'Doctor#2026'],
              ['Clinic owner', 'admin@serenity.lk', 'Serenity#2026'],
              ['Platform admin', 'owner@queuepilot.app', 'QueuePilot#2026'],
          ] as [$role, $mail, $pass]): ?>
            <button type="button"
                    class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 text-left transition hover:border-teal-300 hover:bg-teal-50/50"
                    data-demo-email="<?= e($mail) ?>" data-demo-pass="<?= e($pass) ?>">
              <span class="min-w-0">
                <span class="block text-[11px] font-semibold text-slate-800"><?= e($role) ?></span>
                <span class="block truncate text-[10px] text-slate-400"><?= e($mail) ?></span>
              </span>
              <svg class="h-3.5 w-3.5 shrink-0 text-slate-300"><use href="#i-arrow-right"/></svg>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- marketing panel -->
  <div class="relative hidden overflow-hidden bg-slate-900 lg:block">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(20,184,166,.25),transparent_55%),radial-gradient(circle_at_80%_80%,rgba(56,189,248,.18),transparent_50%)]"></div>
    <div class="relative flex h-full flex-col justify-between p-12 text-white">
      <div>
        <p class="text-xs font-bold uppercase tracking-widest text-teal-400">Clinic Edition</p>
        <h2 class="mt-4 max-w-md text-balance text-3xl font-black leading-tight">
          The board your receptionist trusts at 9am on a Monday.
        </h2>
        <p class="mt-4 max-w-md text-sm leading-relaxed text-slate-400">
          Tokens, recalls and wait ranges that come from your own consultation times — not from a guess.
          Every action is versioned, so two receptionists can work the same queue without stepping on each other.
        </p>
      </div>

      <div class="space-y-3">
        <?php foreach ([
            ['megaphone', 'Token-based calling', 'Names stay off the public screen.'],
            ['wifi-off', 'Honest when offline', 'Last-known state is labelled, never faked as live.'],
            ['shield', 'Tenant isolation', 'Every query is scoped to your clinic, server-side.'],
        ] as [$icon, $title, $body]): ?>
          <div class="flex items-start gap-3 rounded-xl border border-white/10 bg-white/[0.04] p-3.5">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-teal-500/15 text-teal-300">
              <svg class="h-4 w-4"><use href="#i-<?= $icon ?>"/></svg>
            </span>
            <div>
              <p class="text-xs font-bold"><?= e($title) ?></p>
              <p class="text-[11px] text-slate-400"><?= e($body) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script>
  document.querySelectorAll('[data-demo-email]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('email').value = btn.dataset.demoEmail;
      document.getElementById('password').value = btn.dataset.demoPass;
      document.getElementById('email').focus();
    });
  });
</script>
</body>
</html>
