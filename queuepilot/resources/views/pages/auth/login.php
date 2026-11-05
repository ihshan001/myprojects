<?php
/**
 * Sign-in form. Rendered inside layouts/app.php, so this template supplies
 * only the body content — no <html>/<head>/<body> wrapper. A previous version
 * was a complete HTML document nested inside the layout, which produced
 * nested <html>/<body> tags and broke the demo-account autofill script.
 */
?>
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
          <a class="text-xs font-medium text-teal-700 hover:underline" href="/forgot-password">Forgot password?</a>
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

<script>
  document.querySelectorAll('[data-demo-email]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('email').value = btn.dataset.demoEmail;
      document.getElementById('password').value = btn.dataset.demoPass;
      document.getElementById('email').focus();
    });
  });
</script>
