<?php
/**
 * The kiosk screen: one question, one button, then a receipt that clears.
 *
 * Sized for a tablet on a stand in a waiting room: 16px minimum text,
 * finger-height buttons, no hover-only affordances. The patient may never
 * have used a computer.
 *
 * @var array<string,mixed> $clinic
 * @var string $key
 * @var list<array<string,mixed>> $queues
 * @var array<string,mixed>|null $receipt
 * @var int $receiptSeconds
 * @var string|null $error
 * @var array<string,mixed> $old
 */
$first = $queues[0] ?? null;
$chosenQueue = (int) ($old['queue_id'] ?? 0) ?: ($first !== null ? (int) $first['id'] : 0);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
  <?php if ($receipt !== null): ?>
    <!-- A receipt is a patient's visit on a public screen. It clears itself. -->
    <meta http-equiv="refresh" content="<?= (int) $receiptSeconds ?>;url=/kiosk/<?= e($key) ?>?done=1">
  <?php endif; ?>
</head>
<body class="patient-view min-h-full bg-canvas">
  <main class="mx-auto w-full max-w-xl px-5 py-8">
    <header class="text-center">
      <p class="text-sm font-semibold uppercase tracking-widest text-brand-700"><?= e((string) $clinic['name']) ?></p>
      <h1 class="mt-1 text-2xl font-bold text-ink-900">Self check-in</h1>
    </header>

    <?php if ($receipt !== null): ?>
      <!-- ---------------- the receipt ---------------- -->
      <div class="card mt-6 p-8 text-center">
        <p class="text-sm text-ink-500">Your number</p>
        <p class="mt-2 font-mono text-7xl font-bold tracking-tight text-brand-700"><?= e((string) $receipt['token']) ?></p>

        <dl class="mx-auto mt-6 max-w-xs space-y-2 text-left text-sm">
          <div class="flex justify-between gap-3">
            <dt class="text-ink-500">Service</dt>
            <dd class="font-semibold text-ink-900"><?= e((string) $receipt['queue']) ?></dd>
          </div>
          <?php if ((string) $receipt['doctor'] !== ''): ?>
            <div class="flex justify-between gap-3">
              <dt class="text-ink-500">Doctor</dt>
              <dd class="font-semibold text-ink-900"><?= e((string) $receipt['doctor']) ?></dd>
            </div>
          <?php endif; ?>
          <div class="flex justify-between gap-3">
            <dt class="text-ink-500">Ahead of you</dt>
            <dd class="font-semibold text-ink-900"><?= (int) $receipt['ahead'] ?> patient(s)</dd>
          </div>
        </dl>

        <div class="mt-6 rounded-xl bg-brand-50 px-4 py-3 text-sm leading-relaxed text-teal-900">
          <?= e((string) $receipt['message']) ?>
          <?php if ((string) ($receipt['track_url'] ?? '') !== ''): ?>
            <span class="mt-1 block text-xs text-brand-800">
              Watch your place on your phone: scan the slip at the desk, or ask reception for the link.
            </span>
          <?php endif; ?>
        </div>

        <p class="mt-4 text-xs text-ink-400">
          This screen clears itself in <?= (int) $receiptSeconds ?> seconds.
        </p>
      </div>

    <?php else: ?>
      <!-- ---------------- the form ---------------- -->
      <?php if ($error !== null): ?>
        <div class="mt-5 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-relaxed text-amber-900">
          <?= e((string) $error) ?>
        </div>
      <?php endif; ?>

      <?php if ($queues === []): ?>
        <div class="card mt-6 p-8 text-center">
          <p class="text-lg font-semibold text-ink-900">No session is open right now</p>
          <p class="mt-2 text-sm leading-relaxed text-ink-600">
            The clinic has not opened a queue for today. Please speak to the reception desk.
          </p>
          <?php if (!empty($clinic['phone'])): ?>
            <a class="btn-secondary mt-5 inline-flex" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $clinic['phone'])) ?>">
              Call <?= e((string) $clinic['phone']) ?>
            </a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <form method="post" action="/kiosk/<?= e($key) ?>" class="card mt-6 space-y-5 p-6" autocomplete="off">
          <?= csrf_field() ?>

          <div>
            <label class="label text-base" for="full_name">Your name</label>
            <input class="input !py-4 text-lg" id="full_name" name="full_name" required maxlength="120"
                   value="<?= e((string) ($old['full_name'] ?? '')) ?>" autofocus
                   placeholder="As the doctor should call you">
          </div>

          <div>
            <label class="label text-base" for="phone">Mobile number <span class="font-normal text-ink-400">(optional)</span></label>
            <input class="input !py-4 text-lg" id="phone" name="phone" inputmode="tel" maxlength="10"
                   value="<?= e((string) ($old['phone'] ?? '')) ?>" placeholder="0771234567">
            <p class="help text-xs">So the clinic can text you if the doctor is delayed.</p>
          </div>

          <?php if (count($queues) > 1): ?>
            <fieldset>
              <legend class="label text-base">Which service?</legend>
              <div class="mt-2 space-y-2">
                <?php foreach ($queues as $q): ?>
                  <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-hairline bg-white px-4 py-3
                                has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                    <input type="radio" name="queue_id" value="<?= (int) $q['id'] ?>" class="h-5 w-5 shrink-0 accent-brand-600"
                           <?= (int) $q['id'] === $chosenQueue ? 'checked' : '' ?> required>
                    <span class="min-w-0 flex-1">
                      <span class="block text-base font-semibold text-ink-900"><?= e((string) $q['name']) ?></span>
                      <span class="block text-xs text-ink-500">
                        <?= e((string) ($q['doctor_name'] ?? 'Any available doctor')) ?>
                        · <?= (int) $q['waiting_count'] ?> waiting
                        <?php if ((string) $q['status'] === 'paused'): ?>
                          · <b class="text-amber-700">paused, longer wait</b>
                        <?php endif; ?>
                      </span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
          <?php else: ?>
            <input type="hidden" name="queue_id" value="<?= (int) $first['id'] ?>">
            <div class="rounded-xl bg-canvas px-4 py-3 text-sm">
              <p class="font-semibold text-ink-900"><?= e((string) $first['name']) ?></p>
              <p class="text-xs text-ink-500">
                <?= e((string) ($first['doctor_name'] ?? 'Any available doctor')) ?>
                · <?= (int) $first['waiting_count'] ?> waiting
                <?php if ((string) $first['status'] === 'paused'): ?>
                  · <b class="text-amber-700">paused, longer wait</b>
                <?php endif; ?>
              </p>
            </div>
          <?php endif; ?>

          <button class="btn w-full justify-center bg-brand-700 !py-4 text-lg text-white" type="submit">
            Get my number
          </button>

          <p class="text-center text-xs leading-relaxed text-ink-400">
            Not sure, or here for something urgent? Please speak to the reception desk.
          </p>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <p class="mt-8 text-center text-[10px] text-ink-400">
      Powered by QueuePilot · this screen shows no other patient's details
    </p>
  </main>
</body>
</html>
