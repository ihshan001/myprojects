<div class="page-head">
  <div>
    <h1 class="page-title">Your details</h1>
    <p class="mt-1 text-sm text-slate-500">
      How your name appears on the queue board, on tokens and in the audit log.
    </p>
  </div>
  <a href="/settings" class="btn-secondary"><svg class="h-4 w-4"><use href="#i-settings"/></svg>Back to settings</a>
</div>

<?php if (!empty($errors)): ?>
  <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
    <?php foreach ($errors as $message): ?>
      <p class="text-sm font-semibold text-rose-800"><?= e((string) $message) ?></p>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.1fr_.9fr]">
  <div class="card card-pad">
    <form method="post" action="/settings/profile" class="space-y-4">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="name">Your name</label>
        <input class="input" id="name" name="name" value="<?= e((string) ($user['name'] ?? '')) ?>"
               required maxlength="120" autocomplete="name">
        <p class="mt-1.5 text-xs text-slate-500">
          This is the name patients see when you call them, so write it as you would say it.
        </p>
      </div>
      <div>
        <label class="label" for="phone">Phone</label>
        <input class="input" id="phone" name="phone" value="<?= e((string) ($user['phone'] ?? '')) ?>"
               maxlength="32" autocomplete="tel" placeholder="+94 77 123 4567">
        <p class="mt-1.5 text-xs text-slate-500">
          Optional. Used to reach you about your account — never shown to patients.
        </p>
      </div>
      <div>
        <label class="label" for="locale">Language</label>
        <select class="select" id="locale" name="locale">
          <?php foreach (($locales ?? []) as $code => $label): ?>
            <option value="<?= e((string) $code) ?>" <?= ($user['locale'] ?? 'en') === $code ? 'selected' : '' ?>>
              <?= e((string) $label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn-primary w-full">Save your details</button>
    </form>
  </div>

  <div class="card card-pad">
    <h2 class="text-sm font-bold text-slate-900">Sign-in and role</h2>
    <dl class="mt-3 space-y-3 text-xs">
      <div>
        <dt class="font-semibold text-slate-500">Email</dt>
        <dd class="mt-0.5 text-slate-800"><?= e((string) ($user['email'] ?? '')) ?></dd>
      </div>
      <div>
        <dt class="font-semibold text-slate-500">Role</dt>
        <dd class="mt-0.5 text-slate-800"><?= e(ucfirst(str_replace('_', ' ', (string) ($user['role'] ?? '')))) ?></dd>
      </div>
    </dl>
    <p class="mt-4 text-[11px] leading-relaxed text-slate-600">
      Your email is your sign-in name, so it is changed by an administrator rather than here —
      changing it would move your account without proving you own the new address.
    </p>
    <a href="/settings/password" class="btn-secondary mt-4 w-full">
      <svg class="h-4 w-4"><use href="#i-lock"/></svg>Change password
    </a>
  </div>
</div>
