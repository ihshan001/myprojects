<?php
/**
 * Sign in — a complete document in the landing's own language: the paper form
 * on the right, the brand on the left. Rendered standalone (no console
 * layout): the door to the product should look like the product's brochure,
 * not like the back office. The demo-account fill lives in app.js — inline
 * handlers are impossible here, the page ships `script-src 'self'`.
 */
use App\Core\Session;

$errors = Session::takeFlash('errors') ?? [];
$old = Session::takeFlash('old') ?? [];
$val = static fn (string $k, string $d = ''): string => (string) ($old[$k] ?? $d);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#06322E">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="robots" content="noindex, nofollow">
  <title>Sign in · QueuePilot</title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="/assets/img/icon-192.png">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="lp auth-page h-full">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<?php if (($flash = notice()) !== null): ?>
  <div class="fixed left-1/2 top-4 z-50 w-[min(92vw,28rem)] -translate-x-1/2 rise rounded-xl border px-4 py-3 text-sm shadow-lg
    <?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
    <?= e($flash['message']) ?>
  </div>
<?php endif; ?>

<div class="auth-split">
  <!-- The brand side: the landing's deep teal, the visit in five beats. -->
  <aside class="auth-brand">
    <a class="auth-brand-mark" href="/" aria-label="QueuePilot — home">
      <span class="h-9 w-9 shrink-0 rounded-[0.625rem] p-0.5" style="background-image:linear-gradient(140deg,#0F766E,#06322E)">
        <svg viewBox="0 0 32 32" class="h-full w-full" aria-hidden="true">
          <circle cx="14" cy="14" r="6.4" fill="none" stroke="#fff" stroke-width="2.6"/>
          <path d="M18.2 18.2 L22.6 22.6" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
          <circle cx="23.4" cy="23.4" r="2.6" fill="#E0A63C"/>
        </svg>
      </span>
      <span class="lp-display text-[1.1875rem] font-semibold text-white">QueuePilot</span>
    </a>

    <div class="auth-brand-body">
      <h2 class="auth-brand-line">A calmer waiting room.</h2>
      <p class="auth-brand-sub">Tokens, wait ranges and room names &mdash; the moment they&rsquo;re needed.</p>

      <p class="auth-panel-head mt-9">One visit, in five beats</p>
      <ol class="auth-steps">
        <?php foreach ([['01', 'Arrive'], ['02', 'Wait'], ['03', 'Call'], ['04', 'Consult'], ['05', 'After']] as [$n, $beat]): ?>
          <li class="auth-step"><span class="auth-step-n"><?= e($n) ?></span><?= e($beat) ?></li>
        <?php endforeach; ?>
      </ol>
    </div>

    <p class="auth-brand-foot">Never a patient name on a public screen.</p>
  </aside>

  <!-- The form side: paper, ink, and nothing that does not sign you in. -->
  <main class="auth-form-col">
    <div class="auth-card">
      <p class="auth-kicker">
        New clinic?
        <a href="/register">Start a 14-day free trial</a>
      </p>
      <h1 class="lp-display auth-title">Sign in to your clinic</h1>
      <p class="auth-sub">Use the account your clinic administrator created.</p>

      <?php if ($errors !== []): ?>
        <div class="auth-errors" role="alert">
          <ul class="list-inside list-disc space-y-0.5">
            <?php foreach ($errors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" action="/login" class="auth-form" novalidate>
        <?= csrf_field() ?>
        <div>
          <label class="label" for="email">Email address</label>
          <input class="input" id="email" name="email" type="email" autocomplete="username"
                 required value="<?= e($val('email', $email ?? '')) ?>" placeholder="you@clinic.lk">
        </div>
        <div>
          <div class="flex items-center justify-between gap-3">
            <label class="label" for="password">Password</label>
            <a class="auth-link" href="/forgot-password">Forgot password?</a>
          </div>
          <input class="input" id="password" name="password" type="password" autocomplete="current-password"
                 required placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
        </div>
        <button class="lp-btn w-full" type="submit">Sign in</button>
      </form>

      <div class="auth-demo">
        <p class="auth-demo-head">
          <svg class="h-3.5 w-3.5"><use href="#i-sparkles"/></svg>
          Demo accounts
        </p>
        <p class="auth-demo-note">
          Click to fill &mdash; the password is included so you can explore every role.
        </p>
        <div class="mt-3 flex flex-col gap-1.5">
          <?php foreach ([
              ['Receptionist', 'reception@serenity.lk', 'Reception#2026'],
              ['Doctor', 'amara@serenity.lk', 'Doctor#2026'],
              ['Clinic owner', 'admin@serenity.lk', 'Serenity#2026'],
              ['Platform admin', 'owner@queuepilot.app', 'QueuePilot#2026'],
          ] as [$role, $mail, $pass]): ?>
            <button type="button" class="auth-demo-btn"
                    data-demo-email="<?= e($mail) ?>" data-demo-pass="<?= e($pass) ?>">
              <span class="min-w-0">
                <span class="auth-demo-name"><?= e($role) ?></span>
                <span class="auth-demo-mail"><?= e($mail) ?></span>
              </span>
              <svg class="h-3.5 w-3.5 shrink-0" style="color:#B9CEC8"><use href="#i-arrow-right"/></svg>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </main>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
