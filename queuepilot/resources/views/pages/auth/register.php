<?php
/**
 * Create your clinic — a complete document in the landing's language, paired
 * with sign-in: brand on the left (deep teal, what gets set up), the form on
 * the right (paper, ink). Plans, prices and validation are unchanged; only
 * the room they are presented in moved from the console's slate to the
 * brand's paper.
 */
$errors = App\Core\Session::takeFlash('errors') ?? [];
$old = App\Core\Session::takeFlash('old') ?? [];
$val = static fn (string $k, string $d = ''): string => (string) ($old[$k] ?? $d);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#06322E">
  <meta name="robots" content="noindex, nofollow">
  <title>Create your clinic · QueuePilot</title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="lp auth-page h-full">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div class="auth-split">
  <!-- The brand side: the offer, and exactly what it sets up. -->
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
      <h2 class="auth-brand-line">Open your first queue today.</h2>
      <p class="auth-brand-sub">Fourteen days, full access. No card, and nothing to install.</p>

      <p class="auth-panel-head">What gets set up for you</p>
      <ul class="auth-facts">
        <?php foreach ([
            'Three rooms and three specialties you can rename',
            'Your first doctor with today\'s queue already open',
            'Token prefix from your clinic name (A001, A002…)',
            'Patient tracking links enabled',
            'Waiting-room display key generated',
        ] as $item): ?>
          <li class="auth-fact">
            <svg aria-hidden="true"><use href="#i-check-circle"/></svg>
            <span><?= e($item) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <p class="auth-brand-foot">
      <strong class="mb-1.5 block text-[0.625rem] uppercase tracking-[0.18em]" style="color:rgba(246,231,200,.6)">Fair by design</strong>
      SMS and WhatsApp messages are metered separately at cost plus a small margin, so a quiet month
      costs less. Nothing is auto-charged, and suspending an account never deletes queue history.
    </p>
  </aside>

  <!-- The form side. -->
  <main class="auth-form-col">
    <div class="auth-card">
      <p class="auth-kicker text-right">
        Already have an account? <a href="/login">Sign in</a>
      </p>
      <h1 class="lp-display auth-title">Create your clinic</h1>
      <p class="auth-sub">14 days free. No card. Your first queue opens automatically.</p>

      <?php if ($errors !== []): ?>
        <div class="auth-errors" role="alert">
          <ul class="list-inside list-disc space-y-0.5">
            <?php foreach ($errors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" action="/register" class="auth-form" novalidate>
        <?= csrf_field() ?>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label class="label" for="name">Clinic name</label>
            <input class="input <?= isset($errors['name']) ? 'input-error' : '' ?>" id="name" name="name"
                   required value="<?= e($val('name')) ?>" placeholder="Serenity OPD &amp; Family Clinic">
            <p class="help">This appears on patient tracking pages and printed slips.</p>
          </div>
          <div>
            <label class="label" for="owner_name">Your full name</label>
            <input class="input <?= isset($errors['owner_name']) ? 'input-error' : '' ?>" id="owner_name"
                   name="owner_name" required value="<?= e($val('owner_name')) ?>" placeholder="Dr. Nuwan Perera">
          </div>
          <div>
            <label class="label" for="phone">Clinic phone</label>
            <input class="input" id="phone" name="phone" value="<?= e($val('phone')) ?>" placeholder="+94 11 269 4400">
          </div>
          <div>
            <label class="label" for="city">City</label>
            <input class="input" id="city" name="city" value="<?= e($val('city')) ?>" placeholder="Colombo">
          </div>
          <div>
            <label class="label" for="district">District</label>
            <select class="select" id="district" name="district">
              <?php foreach (['Colombo','Gampaha','Kalutara','Galle','Matara','Kandy','Jaffna','Kurunegala','Anuradhapura','Ratnapura','Batticaloa','Trincomalee','Other'] as $d): ?>
                <option value="<?= $d ?>" <?= $val('district') === $d ? 'selected' : '' ?>><?= $d ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="email">Owner email (your sign-in)</label>
            <input class="input <?= isset($errors['email']) ? 'input-error' : '' ?>" id="email" name="email"
                   type="email" required value="<?= e($val('email')) ?>" placeholder="you@clinic.lk">
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="password">Password</label>
            <input class="input <?= isset($errors['password']) ? 'input-error' : '' ?>" id="password"
                   name="password" type="password" required minlength="10" autocomplete="new-password"
                   placeholder="At least 10 characters">
            <p class="help">Minimum 10 characters. Use a passphrase you don't reuse elsewhere.</p>
          </div>
        </div>

        <fieldset class="rounded-2xl border p-4" style="border-color:var(--lp-line)">
          <legend class="label px-1">Choose a plan</legend>
          <div class="grid gap-2 sm:grid-cols-2">
            <?php foreach ($plans as $plan): ?>
              <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition
                <?= $selected === $plan['code']
                    ? 'border-teal-700 bg-teal-50/60 ring-2 ring-teal-700/25'
                    : 'border-slate-200 hover:border-slate-300' ?>">
                <input type="radio" name="plan" value="<?= e($plan['code']) ?>" class="mt-1 accent-teal-700"
                       <?= $selected === $plan['code'] ? 'checked' : '' ?>>
                <span class="min-w-0">
                  <span class="flex items-baseline gap-2">
                    <span class="text-sm font-bold text-slate-900"><?= e($plan['name']) ?></span>
                    <span class="text-xs text-slate-500">
                      <?= (float) $plan['price_monthly'] > 0 ? 'Rs ' . number_format((float) $plan['price_monthly']) . '/mo' : 'Talk to us' ?>
                    </span>
                  </span>
                  <span class="mt-0.5 block text-[11px] leading-snug text-slate-500">
                    <?= (int) $plan['max_doctors'] ?> doctors · <?= (int) $plan['max_staff'] ?> staff
                  </span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <button class="lp-btn w-full" type="submit">
          Create clinic &amp; start trial
          <svg class="h-4 w-4"><use href="#i-arrow-right"/></svg>
        </button>
        <p class="auth-note">
          By creating a clinic you agree that patient data in your clinic is yours, and that
          QueuePilot will not use it for anything except operating your queues.
        </p>
      </form>

      <p class="auth-works">
        <strong class="font-bold" style="color:var(--lp-ink)">Works on what you have</strong> &mdash;
        a 1366&times;768 reception desktop, an old Android tablet at the desk, and your patients&rsquo;
        existing phones. No app install, no special hardware.
      </p>
    </div>
  </main>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
