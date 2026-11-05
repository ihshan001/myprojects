<?php
/**
 * Public booking form.
 *
 * Constraints that drive this page:
 *  - works on a cheap Android phone on a 3G connection (no JS required)
 *  - one screen, no account, no password
 *  - the clinic's own colour, so it does not look like a third-party site
 */
$brand = $clinicRecord['brand_color'] ?: '#0F766E';
$slots = ['08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
          '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'];
?>
<!DOCTYPE html>
<html lang="<?= e($clinicRecord['locale'] ?? 'en') ?>" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?= e($brand) ?>">
  <meta name="robots" content="noindex">
  <title><?= e($title) ?></title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="min-h-full bg-slate-50">
  <header class="px-5 py-5 text-white" style="background: <?= e($brand) ?>">
    <div class="mx-auto flex max-w-lg items-center gap-3">
      <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/15 text-sm font-black">
        <?= e(App\Core\Str::initials((string) $clinicRecord['name'])) ?>
      </span>
      <div class="min-w-0">
        <p class="truncate text-sm font-bold"><?= e($clinicRecord['name']) ?></p>
        <p class="text-[11px] text-white/70">Book an appointment</p>
      </div>
    </div>
  </header>

  <main class="mx-auto max-w-lg px-5 py-6">
    <?php if (!empty($error)): ?>
      <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/book/<?= e((string) $clinicRecord['slug']) ?>" class="card p-5">
      <?= csrf_field() ?>

      <label class="label" for="full_name">Patient name</label>
      <input class="input" id="full_name" name="full_name" required maxlength="120"
             autocomplete="name" placeholder="Nimal Perera">

      <label class="label mt-4" for="phone">Mobile number</label>
      <input class="input" id="phone" name="phone" required inputmode="tel"
             autocomplete="tel" placeholder="077 123 4567">
      <p class="mt-1 text-[11px] text-slate-500">We use this only to contact you about this appointment.</p>

      <label class="label mt-4" for="doctor_id">Doctor</label>
      <select class="select" id="doctor_id" name="doctor_id" required>
        <?php foreach ($doctors as $d): ?>
          <option value="<?= (int) $d['id'] ?>">
            <?= e($d['name']) ?><?= !empty($d['specialty']) ? ' — ' . e($d['specialty']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div class="mt-4 grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="date">Date</label>
          <input class="input" type="date" id="date" name="date" required
                 value="<?= e($date) ?>" min="<?= e(App\Core\Db::today()) ?>">
        </div>
        <div>
          <label class="label" for="time">Time</label>
          <select class="select" id="time" name="time">
            <?php foreach ($slots as $slot): ?>
              <option value="<?= e($slot) ?>"><?= e(date('g:i A', strtotime($slot))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <button class="btn-primary mt-5 w-full" type="submit">Request appointment</button>

      <p class="mt-3 text-center text-[11px] leading-relaxed text-slate-500">
        This holds your slot. Please arrive 10 minutes early and check in at reception.
      </p>
    </form>

    <?php if (!empty($clinicRecord['phone'])): ?>
      <p class="mt-4 text-center text-xs text-slate-500">
        Prefer to call?
        <a class="font-semibold text-teal-700" href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $clinicRecord['phone'])) ?>">
          <?= e((string) $clinicRecord['phone']) ?>
        </a>
      </p>
    <?php endif; ?>
  </main>
</body>
</html>
