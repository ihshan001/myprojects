<?php
/**
 * Where a clinic waits for a decision.
 *
 * Four states, four different things to say, and none of them is an error. The
 * clinic is signed in — a redirect loop back to /login here would make a
 * perfectly healthy signup look broken.
 */

$isPending  = $status === 'pending';
$isDeclined = $status === 'declined';
$isTrial    = $status === 'trial';
$isSuspended = $status === 'suspended';

$tone = match (true) {
    $isDeclined  => ['bg' => 'bg-rose-50', 'ring' => 'ring-rose-200', 'ink' => 'text-rose-900', 'dot' => 'bg-rose-500'],
    $isPending   => ['bg' => 'bg-amber-50', 'ring' => 'ring-amber-200', 'ink' => 'text-amber-900', 'dot' => 'bg-amber-500'],
    $isSuspended => ['bg' => 'bg-rose-50', 'ring' => 'ring-rose-200', 'ink' => 'text-rose-900', 'dot' => 'bg-rose-500'],
    $isTrial     => ['bg' => 'bg-teal-50', 'ring' => 'ring-teal-200', 'ink' => 'text-teal-900', 'dot' => 'bg-teal-500'],
    default      => ['bg' => 'bg-slate-50', 'ring' => 'ring-slate-200', 'ink' => 'text-slate-900', 'dot' => 'bg-slate-400'],
};
?>

<div class="mx-auto max-w-2xl">
  <div class="card card-pad">
    <div class="flex items-start gap-4">
      <span class="mt-1 flex h-10 w-10 shrink-0 items-center justify-center rounded-full <?= e($tone['bg']) ?>">
        <span class="h-3 w-3 rounded-full <?= e($tone['dot']) ?>"></span>
      </span>
      <div class="min-w-0">
        <h1 class="text-xl font-bold tracking-tight text-slate-900">
          <?php if ($isPending): ?>
            Your clinic is awaiting approval
          <?php elseif ($isDeclined): ?>
            Your clinic application was not approved
          <?php elseif ($isSuspended): ?>
            Your clinic is suspended
          <?php elseif ($isTrial): ?>
            Your clinic is on a trial
          <?php else: ?>
            <?= e($clinic['name']) ?>
          <?php endif; ?>
        </h1>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          <?php if ($isPending): ?>
            We have your application for <strong><?= e($clinic['name']) ?></strong>.
            A member of the <?= e($appName ?? \App\Core\Env::get('app.name', 'QueuePilot')) ?> team reviews every clinic by hand — we do not
            switch a clinic on automatically, because a queue system handles real
            patients from the first minute it is live.
          <?php elseif ($isDeclined): ?>
            We were not able to approve <strong><?= e($clinic['name']) ?></strong> at this time.
            If you think this is a mistake, reply to the email we sent and a person will look again.
          <?php elseif ($isSuspended): ?>
            Access to <strong><?= e($clinic['name']) ?></strong> is paused. Your data is safe and
            untouched — nothing is deleted while an account is suspended.
          <?php elseif ($isTrial): ?>
            <strong><?= e($clinic['name']) ?></strong> is running on a trial
            <?php if ($trialEndsOn): ?>until <strong><?= e(date('j F Y', strtotime((string) $trialEndsOn))) ?></strong><?php endif; ?>.
          <?php else: ?>
            This application is active.
          <?php endif; ?>
        </p>
      </div>
    </div>

    <?php if ($isDeclined && $reason): ?>
      <div class="mt-5 rounded-lg <?= e($tone['bg']) ?> p-4 ring-1 <?= e($tone['ring']) ?>">
        <p class="text-[11px] font-semibold uppercase tracking-wide <?= e($tone['ink']) ?>">Reason given</p>
        <p class="mt-1 text-sm <?= e($tone['ink']) ?>"><?= e($reason) ?></p>
      </div>
    <?php endif; ?>

    <?php if ($isPending): ?>
      <ol class="mt-6 space-y-3">
        <li class="flex gap-3">
          <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-teal-600 text-[11px] font-bold text-white">1</span>
          <div>
            <p class="text-sm font-medium text-slate-900">Application received</p>
            <p class="text-xs text-slate-500">We have the clinic details and the owner account.</p>
          </div>
        </li>
        <li class="flex gap-3">
          <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-500 text-[11px] font-bold text-white">2</span>
          <div>
            <p class="text-sm font-medium text-slate-900">Review — usually within one working day</p>
            <p class="text-xs text-slate-500">We confirm the clinic is real and that the contact details reach someone.</p>
          </div>
        </li>
        <li class="flex gap-3">
          <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-200 text-[11px] font-bold text-slate-500">3</span>
          <div>
            <p class="text-sm font-medium text-slate-400">Your queue system goes live</p>
            <p class="text-xs text-slate-400">You will be emailed, and this page will change on its own.</p>
          </div>
        </li>
      </ol>
    <?php endif; ?>

    <div class="mt-6 border-t border-slate-100 pt-4">
      <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Clinic</dt>
          <dd class="mt-0.5 text-slate-900"><?= e($clinic['name']) ?></dd>
        </div>
        <div>
          <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Owner</dt>
          <dd class="mt-0.5 text-slate-900"><?= e($owner['name'] ?? '—') ?></dd>
        </div>
        <div>
          <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Applied</dt>
          <dd class="mt-0.5 text-slate-900"><?= e(date('j F Y', strtotime((string) $clinic['created_at']))) ?></dd>
        </div>
        <div>
          <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Contact</dt>
          <dd class="mt-0.5 text-slate-900"><?= e($clinic['email'] ?? $owner['email'] ?? '—') ?></dd>
        </div>
      </dl>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
      <?php if ($isSuspended || $isTrial): ?>
        <a href="/billing" class="btn btn-primary">Go to billing</a>
      <?php endif; ?>
      <a href="/onboarding-status" class="btn">Refresh status</a>
      <form method="post" action="/logout" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn">Sign out</button>
      </form>
    </div>

    <?php if ($isPending): ?>
      <p class="mt-4 text-xs text-slate-400">
        Nothing to do right now. Your staff accounts and any setup you do will be waiting
        exactly as you left them once the clinic is approved.
      </p>
    <?php endif; ?>
  </div>
</div>
