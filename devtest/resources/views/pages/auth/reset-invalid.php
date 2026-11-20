<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset link expired · QueuePilot</title>
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="min-h-full bg-slate-50">
<div class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-6 py-12">
  <a href="/" class="flex items-center gap-2.5">
    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-700 text-sm font-black text-white">Q</span>
    <span class="text-base font-bold tracking-tight text-slate-900">QueuePilot</span>
  </a>

  <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-900">That link no longer works</h1>
  <p class="mt-1.5 text-sm leading-relaxed text-slate-500">
    Reset links expire after an hour and can only be used once. If you have already changed
    your password, sign in with the new one. Otherwise ask for a fresh link.
  </p>

  <div class="mt-7 flex flex-col gap-2 sm:flex-row">
    <a class="btn-primary justify-center" href="/forgot-password">Send a new link</a>
    <a class="btn-secondary justify-center" href="/login">Back to sign in</a>
  </div>
</div>
</body>
</html>
