<?php
$errors = App\Core\Session::takeFlash('errors') ?? [];
$old = App\Core\Session::takeFlash('old') ?? [];
$val = static fn (string $k, string $d = ''): string => (string) ($old[$k] ?? $d);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create your clinic · QueuePilot</title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="min-h-full bg-slate-50">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<div class="mx-auto max-w-5xl px-5 py-10">
  <div class="flex items-center justify-between">
    <a href="/" class="flex items-center gap-2.5">
      <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-700 text-sm font-black text-white">Q</span>
      <span class="text-base font-bold tracking-tight text-slate-900">QueuePilot</span>
    </a>
    <a href="/login" class="text-xs font-semibold text-slate-600 hover:text-slate-900">Already have an account? Sign in</a>
  </div>

  <div class="mt-8 grid gap-8 lg:grid-cols-[1.15fr_.85fr]">
    <div class="card card-pad">
      <h1 class="text-xl font-bold tracking-tight text-slate-900">Create your clinic</h1>
      <p class="mt-1 text-sm text-slate-500">14 days free. No card. Your first queue opens automatically.</p>

      <?php if ($errors !== []): ?>
        <div class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
          <ul class="list-inside list-disc space-y-0.5">
            <?php foreach ($errors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" action="/register" class="mt-6 space-y-4" novalidate>
        <?= csrf_field() ?>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label class="label" for="name">Clinic name</label>
            <input class="input <?= isset($errors['name']) ? 'input-error' : '' ?>" id="name" name="name"
                   required value="<?= e($val('name')) ?>" placeholder="Serenity OPD & Family Clinic">
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

        <fieldset class="pt-2">
          <legend class="label">Choose a plan</legend>
          <div class="grid gap-2 sm:grid-cols-2">
            <?php foreach ($plans as $plan): ?>
              <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition
                <?= $selected === $plan['code'] ? 'border-teal-600 bg-teal-50/50 ring-1 ring-teal-600/20' : 'border-slate-200 hover:border-slate-300' ?>">
                <input type="radio" name="plan" value="<?= e($plan['code']) ?>" class="mt-1"
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

        <button class="btn-primary w-full btn-lg" type="submit">
          Create clinic & start trial
          <svg class="h-4 w-4"><use href="#i-arrow-right"/></svg>
        </button>
        <p class="text-center text-[11px] text-slate-400">
          By creating a clinic you agree that patient data in your clinic is yours, and that
          QueuePilot will not use it for anything except operating your queues.
        </p>
      </form>
    </div>

    <div class="space-y-4">
      <div class="card card-pad">
        <h2 class="text-sm font-bold text-slate-900">What gets set up for you</h2>
        <ul class="mt-3 space-y-2.5 text-[13px] text-slate-600">
          <?php foreach ([
              'Three rooms and three specialties you can rename',
              'Your first doctor with today\'s queue already open',
              'Token prefix from your clinic name (A001, A002…)',
              'Patient tracking links enabled',
              'Waiting-room display key generated',
          ] as $item): ?>
            <li class="flex gap-2">
              <svg class="mt-0.5 h-4 w-4 shrink-0 text-teal-600"><use href="#i-check-circle"/></svg>
              <span><?= e($item) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="card card-pad bg-slate-900 text-white">
        <p class="text-xs font-bold uppercase tracking-widest text-teal-400">Fair by design</p>
        <p class="mt-2 text-[13px] leading-relaxed text-slate-300">
          SMS and WhatsApp messages are metered separately at cost plus a small margin, so a quiet month
          costs less. Nothing is auto-charged, and suspending an account never deletes queue history.
        </p>
      </div>

      <div class="card card-pad">
        <p class="text-xs font-bold uppercase tracking-widest text-slate-500">Works on what you have</p>
        <p class="mt-2 text-[13px] leading-relaxed text-slate-600">
          A 1366×768 reception desktop, an old Android tablet at the desk, and your patients' existing phones.
          No app install, no special hardware.
        </p>
      </div>
    </div>
  </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
