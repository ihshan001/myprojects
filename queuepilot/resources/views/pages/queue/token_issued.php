<?php $clinic = App\Core\Auth::clinic(); ?>
<div class="mx-auto max-w-2xl">
  <div class="text-center">
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
      <svg class="h-6 w-6"><use href="#i-check"/></svg>
    </div>
    <h1 class="mt-4 text-2xl font-bold tracking-tight text-slate-900">Token issued</h1>
    <p class="mt-1 text-sm text-slate-500"><?= e($patient['full_name']) ?> · <?= e($patient['code']) ?></p>
  </div>

  <div class="card mt-6 overflow-hidden">
    <div class="grid gap-0 sm:grid-cols-[1fr_auto]">
      <div class="p-6">
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-400">Token</p>
        <p class="mt-1 font-mono text-5xl font-black tracking-tight text-teal-700"><?= e($result['token']) ?></p>
        <p class="mt-2 text-sm text-slate-600"><?= e($queue['name'] ?? '') ?></p>
        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
          <span class="badge-amber">Position <?= (int) $position ?></span>
          <span class="badge-slate">Issued <?= e(date('g:i A')) ?></span>
          <span class="badge-teal">Live tracking</span>
        </div>
      </div>
      <div class="flex items-center justify-center border-t border-slate-200 bg-slate-50 p-5 sm:border-l sm:border-t-0">
        <div class="text-center">
          <img src="<?= e(App\Support\QrCode::dataUri($fullLink, 4)) ?>" alt="QR code for the tracking link"
               class="h-32 w-32 rounded-lg border border-slate-200 bg-white p-1">
          <p class="mt-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Scan to track</p>
        </div>
      </div>
    </div>

    <div class="border-t border-slate-200 p-5">
      <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Private tracking link</p>
      <div class="mt-2 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2">
        <code class="flex-1 truncate text-[11px] text-slate-600" id="track-url"><?= e($fullLink) ?></code>
        <button class="rounded p-1.5 text-slate-400 hover:bg-white hover:text-slate-700" data-copy="<?= e($fullLink) ?>" title="Copy link">
          <svg class="h-4 w-4"><use href="#i-copy"/></svg>
        </button>
      </div>
      <p class="mt-2 text-[11px] leading-relaxed text-slate-500">
        Anyone with this link can see the token's position and status — nothing else. It expires at the end of the session.
        <?php if (!empty($patient['phone'])): ?>
          An SMS is queued for <?= e($patient['phone']) ?>.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <div class="mt-5 grid gap-2 sm:grid-cols-3">
    <a href="/app" class="btn-primary">Back to the board</a>
    <a href="/queue/new" class="btn-secondary">Next patient</a>
    <a class="btn-secondary" href="/queue/<?= (int) $result['entry_id'] ?>/slip" target="_blank">
      <svg class="h-4 w-4"><use href="#i-printer"/></svg>
      Thermal slip
    </a>
    <button data-print class="btn-secondary">
      <svg class="h-4 w-4"><use href="#i-print"/></svg>
      Print token
    </button>
  </div>

  <p class="mt-5 text-center text-[11px] text-slate-400">
    <?= e($clinic['name'] ?? '') ?> · Keep this token number — it is how we will call you.
  </p>
</div>

<style media="print">
  .sidebar, footer, .btn, .btn-primary, .btn-secondary { display: none !important; }
  .main-col { padding-left: 0 !important; }
</style>
