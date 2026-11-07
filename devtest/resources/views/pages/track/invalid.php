<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e($title ?? 'Link not valid') ?> · QueuePilot</title>
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="flex min-h-full items-center justify-center bg-slate-100 px-5">
  <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-amber-700">
      <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
           stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
    </div>
    <h1 class="mt-4 text-lg font-bold text-slate-900"><?= e($title ?? 'Link not valid') ?></h1>
    <p class="mt-2 text-sm leading-relaxed text-slate-600">
      <?php if (!empty($expired)): ?>
        This tracking link has expired because the clinic session ended.
        Ask the reception desk to issue a new token.
      <?php else: ?>
        We could not find a queue entry for this link. Check the address, or ask the reception desk
        to show you the token again.
      <?php endif; ?>
    </p>
    <?php if (!empty($clinicPhone)): ?>
      <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $clinicPhone)) ?>" class="btn-primary mt-5 w-full">
        Call the clinic
      </a>
    <?php endif; ?>
    <a href="/" class="btn-ghost mt-2 w-full text-xs">What is QueuePilot?</a>
  </div>
</body>
</html>
