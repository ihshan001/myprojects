<div class="page-head">
  <div>
    <h1 class="page-title"><?= !empty($forced) ? 'Choose your own password' : 'Change password' ?></h1>
    <p class="mt-1 text-sm text-slate-500">
      <?php if (!empty($forced)): ?>
        Someone else chose the password you just signed in with. Choose one only you know before you carry on.
      <?php else: ?>
        Signed in as <?= e((string) (App\Core\Auth::user()['email'] ?? '')) ?>.
      <?php endif; ?>
    </p>
  </div>
  <?php if (empty($forced)): ?>
    <a href="/settings" class="btn-secondary"><svg class="h-4 w-4"><use href="#i-settings"/></svg>Back to settings</a>
  <?php endif; ?>
</div>

<?php if (!empty($forced)): ?>
  <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
    <p class="text-sm font-bold text-amber-900">This password was issued to you — it is not yours yet.</p>
    <p class="mt-1 text-xs leading-relaxed text-amber-800">
      Whoever set up your account knows the password you are using now. If they wrote it in a message,
      on a note, or read it down the phone, it is not a secret. Change it here and that copy stops working.
    </p>
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
    <?php foreach ($errors as $message): ?>
      <p class="text-sm font-semibold text-rose-800"><?= e((string) $message) ?></p>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.1fr_.9fr]">
  <div class="card card-pad">
    <form method="post" action="/settings/password" class="space-y-4" autocomplete="off">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="current_password">Current password</label>
        <input class="input" id="current_password" name="current_password" type="password"
               required autocomplete="current-password">
      </div>
      <div>
        <label class="label" for="password">New password</label>
        <input class="input" id="password" name="password" type="password"
               required minlength="<?= (int) App\Core\Env::get('security.password_min', 10) ?>"
               autocomplete="new-password">
        <p class="mt-1.5 text-xs text-slate-500">
          At least <?= (int) App\Core\Env::get('security.password_min', 10) ?> characters.
          A short sentence you will remember beats a short scramble you will write down.
        </p>
      </div>
      <div>
        <label class="label" for="password_confirmation">New password again</label>
        <input class="input" id="password_confirmation" name="password_confirmation" type="password"
               required autocomplete="new-password">
      </div>
      <button class="btn-primary w-full"><?= !empty($forced) ? 'Save and continue' : 'Change password' ?></button>
    </form>
  </div>

  <div class="card card-pad">
    <h2 class="text-sm font-bold text-slate-900">Why we ask for the old one</h2>
    <ul class="mt-3 space-y-2.5 text-[11px] leading-relaxed text-slate-600">
      <li><strong class="text-slate-800">A stolen session is not enough.</strong> Being signed in on a shared reception machine must not let the next person lock the owner out.</li>
      <li><strong class="text-slate-800">Other sessions keep working</strong> until you sign out. If you think someone else has your password, change it and then sign out everywhere by signing out here.</li>
      <li><strong class="text-slate-800">Nothing is emailed.</strong> Your new password is not sent anywhere — that is the point of choosing it here.</li>
      <li><strong class="text-slate-800">Forgotten it entirely?</strong> Sign out and use <a class="link" href="/forgot-password">forgot password</a> instead.</li>
    </ul>
  </div>
</div>
