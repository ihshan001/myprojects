<?php
/** Digital token with a QR code + copy link (patient surface). */
$token = $token ?? '';
$trackUrl = $trackUrl ?? '';
$label = $label ?? 'Your token';
?>
<div class="patient-token">
  <p class="text-xs font-semibold uppercase tracking-widest text-teal-200"><?= e($label) ?></p>
  <div class="mt-2 flex items-end justify-between gap-4">
    <div>
      <p class="token-hero"><?= e($token) ?></p>
      <p class="mt-1 text-sm text-teal-100/80"><?= e($subtitle ?? '') ?></p>
    </div>
    <img src="<?= e($qr ?? '') ?>" alt="QR code for your token" class="h-20 w-20 rounded-lg bg-white p-1">
  </div>
</div>
