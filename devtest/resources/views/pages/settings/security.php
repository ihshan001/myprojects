<div class="page-head">
  <div>
    <h1 class="page-title">Account security</h1>
    <p class="mt-1 text-sm text-slate-500">Two-factor authentication for <?= e((string) (App\Core\Auth::user()['email'] ?? '')) ?>.</p>
  </div>
  <a href="/settings" class="btn-secondary"><svg class="h-4 w-4"><use href="#i-settings"/></svg>Back to settings</a>
</div>

<?php if (!empty($error)): ?>
  <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"><?= e($error) ?></div>
<?php endif; ?>

<?php if (!empty($recovery)): ?>
  <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-4">
    <p class="text-sm font-bold text-amber-900">Save these recovery codes now</p>
    <p class="mt-1 text-xs text-amber-800">Each code works once. They will not be shown again.</p>
    <div class="mt-3 grid grid-cols-2 gap-1.5 font-mono text-xs text-amber-950 sm:grid-cols-4">
      <?php foreach ($recovery as $code): ?>
        <span class="rounded bg-white/70 px-2 py-1 text-center"><?= e($code) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.1fr_.9fr]">
  <div class="card card-pad">
    <?php if ($enabled): ?>
      <div class="flex items-center gap-2">
        <span class="badge-emerald">Enabled</span>
        <span class="text-xs text-slate-500">Every sign-in asks for a code.</span>
      </div>
      <p class="mt-3 text-xs leading-relaxed text-slate-600">
        Turning two-factor off requires your password — a stolen session is not enough.
      </p>
      <form method="post" action="/settings/security/2fa/disable" class="mt-4 max-w-xs space-y-2">
        <?= csrf_field() ?>
        <label class="label" for="password">Confirm your password</label>
        <input class="input" id="password" name="password" type="password" required autocomplete="current-password">
        <button class="btn-stop w-full">Turn off two-factor</button>
      </form>
    <?php elseif (!empty($pending)): ?>
      <h2 class="text-sm font-bold text-slate-900">Scan this with your authenticator app</h2>
      <p class="mt-1 text-xs text-slate-500">Google Authenticator, Authy, 1Password — anything that supports TOTP.</p>
      <div class="mt-3 break-all rounded-lg bg-slate-50 px-3 py-2 font-mono text-[11px] text-slate-700"><?= e($pending) ?></div>
      <p class="mt-2 text-[11px] text-slate-500">
        No camera? Enter this secret manually — it is also in the URI above after <code>secret=</code>.
      </p>
      <form method="post" action="/settings/security/2fa/confirm" class="mt-4 max-w-xs space-y-2">
        <?= csrf_field() ?>
        <label class="label" for="code">Enter the 6-digit code to confirm</label>
        <input class="input text-center tracking-[0.3em] tabular" id="code" name="code" inputmode="numeric"
               maxlength="6" required autocomplete="one-time-code" placeholder="000000">
        <button class="btn-primary w-full">Enable two-factor</button>
      </form>
    <?php else: ?>
      <div class="flex items-center gap-2">
        <span class="badge-slate">Off</span>
        <span class="text-xs text-slate-500">Your account is protected by a password only.</span>
      </div>
      <p class="mt-3 text-xs leading-relaxed text-slate-600">
        Clinic owners and platform administrators can see every patient in the clinic and change
        plan and billing settings. A single password is a thin defence for that. Two-factor adds a
        code from your phone to every sign-in.
      </p>
      <form method="post" action="/settings/security/2fa/begin" class="mt-4">
        <?= csrf_field() ?>
        <button class="btn-primary">Set up two-factor</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card card-pad">
    <h2 class="text-sm font-bold text-slate-900">How this works</h2>
    <ul class="mt-3 space-y-2.5 text-[11px] leading-relaxed text-slate-600">
      <li><strong class="text-slate-800">TOTP, RFC 6238.</strong> Codes are computed from a shared secret and the clock — no SMS, so no SIM-swap attack and no per-message cost.</li>
      <li><strong class="text-slate-800">Secret encrypted at rest.</strong> The stored value is AES-256-GCM encrypted with a key derived from APP_KEY. A database dump alone cannot mint codes.</li>
      <li><strong class="text-slate-800">Clock drift tolerated ±30s.</strong> A phone that is slightly off still works; more than that is rejected.</li>
      <li><strong class="text-slate-800">Attempts are limited.</strong> Five wrong codes per five minutes, then the account waits.</li>
      <li><strong class="text-slate-800">Recovery codes are hashed.</strong> They are shown once and consumed on use.</li>
    </ul>
  </div>
</div>
