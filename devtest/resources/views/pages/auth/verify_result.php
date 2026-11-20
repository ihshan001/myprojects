<div class="mx-auto max-w-md">
  <div class="card card-pad text-center">
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full <?= $ok ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' ?>">
      <?php if ($ok): ?>
        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
      <?php else: ?>
        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
      <?php endif; ?>
    </div>
    <h1 class="mt-4 text-xl font-bold tracking-tight text-slate-900"><?= e($title) ?></h1>
    <p class="mt-2 text-sm leading-relaxed text-slate-600"><?= e($message) ?></p>

    <div class="mt-6 flex justify-center gap-2">
      <?php if (!empty($signedIn) || ($ok && ($signedIn ?? false))): ?>
        <a class="btn-primary btn-sm" href="/app">Go to the workspace</a>
      <?php else: ?>
        <a class="btn-primary btn-sm" href="/login">Sign in</a>
      <?php endif; ?>
    </div>
  </div>

  <p class="mt-4 text-center text-[11px] leading-relaxed text-slate-400">
    Links expire after 48 hours and work once. If this one is old, sign in and
    press “Resend confirmation” on the billing page.
  </p>
</div>
