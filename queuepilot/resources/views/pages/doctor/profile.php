<?php
/**
 * "My profile" — the doctor's own details and the room they are in today.
 * Deliberately not a settings page: clinic-wide configuration belongs to the
 * owner, and a doctor who can change clinic defaults from here would be
 * changing them for everybody.
 *
 * @var array      $user
 * @var array|null $doctor
 */
?>

<div class="card card-pad mb-4">
  <h2 class="text-base font-semibold text-slate-800">My profile</h2>
  <p class="mt-1 max-w-prose text-sm text-slate-500">
    Your name, room and consultation settings are managed by the clinic owner. Password and two-factor
    settings are yours to change.
  </p>
</div>

<div class="card mb-4">
  <div class="border-b border-slate-100 px-4 py-3">
    <h2 class="text-sm font-semibold text-slate-700">Details</h2>
  </div>
  <dl class="divide-y divide-slate-100">
    <div class="flex gap-4 px-4 py-3">
      <dt class="w-40 shrink-0 text-sm text-slate-500">Name</dt>
      <dd class="text-sm font-medium text-slate-800"><?= e($user['name'] ?? '') ?></dd>
    </div>
    <div class="flex gap-4 px-4 py-3">
      <dt class="w-40 shrink-0 text-sm text-slate-500">Email</dt>
      <dd class="text-sm font-medium text-slate-800"><?= e($user['email'] ?? '') ?></dd>
    </div>
    <?php if ($doctor !== null): ?>
      <div class="flex gap-4 px-4 py-3">
        <dt class="w-40 shrink-0 text-sm text-slate-500">Room</dt>
        <dd class="text-sm font-medium text-slate-800"><?= e($doctor['room_name'] ?? 'Not assigned') ?></dd>
      </div>
      <div class="flex gap-4 px-4 py-3">
        <dt class="w-40 shrink-0 text-sm text-slate-500">Specialty</dt>
        <dd class="text-sm font-medium text-slate-800"><?= e($doctor['specialty'] ?? '—') ?></dd>
      </div>
      <div class="flex gap-4 px-4 py-3">
        <dt class="w-40 shrink-0 text-sm text-slate-500">Queue prefix</dt>
        <dd class="text-sm font-medium text-slate-800"><?= e($doctor['queue_prefix'] ?? '—') ?></dd>
      </div>
      <div class="flex gap-4 px-4 py-3">
        <dt class="w-40 shrink-0 text-sm text-slate-500">Average consultation</dt>
        <dd class="text-sm font-medium text-slate-800"><?= e((string) ($doctor['avg_consult_minutes'] ?? 10)) ?> min</dd>
      </div>
    <?php endif; ?>
  </dl>
</div>

<div class="card card-pad">
  <h2 class="text-base font-semibold text-slate-800">Security</h2>
  <p class="mt-1 max-w-prose text-sm text-slate-500">
    Change your password or set up two-factor authentication.
  </p>
  <div class="mt-4 flex flex-wrap gap-2">
    <a class="btn-secondary" href="/settings/security">
      <svg class="h-4 w-4"><use href="#i-lock"/></svg>Password &amp; two-factor
    </a>
  </div>
</div>
