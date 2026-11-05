<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Error') ?> · QueuePilot</title>
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="flex min-h-full items-center justify-center bg-slate-50 px-5">
  <div class="w-full max-w-md text-center">
    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-teal-700 text-lg font-black text-white">Q</div>
    <p class="mt-6 text-6xl font-black tracking-tight text-slate-200"><?= (int) ($status ?? 500) ?></p>
    <h1 class="mt-2 text-xl font-bold text-slate-900"><?= e($title ?? 'Something went wrong') ?></h1>
    <p class="mt-2 text-sm leading-relaxed text-slate-600">
      <?= e($message ?? 'Please try again. If this keeps happening, contact your clinic administrator.') ?>
    </p>
    <div class="mt-6 flex flex-wrap justify-center gap-2">
      <a href="/" class="btn-secondary">Home</a>
      <a href="/app" class="btn-primary">Back to the board</a>
    </div>
    <?php if (!empty($requestId)): ?>
      <p class="mt-6 text-[11px] text-slate-400">Reference: <?= e($requestId) ?></p>
    <?php endif; ?>
  </div>
</body>
</html>
