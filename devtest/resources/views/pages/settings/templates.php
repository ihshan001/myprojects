<?php
/**
 * Message wording.
 *
 * One card per message, each showing the effective text, whether it is
 * customised, what placeholders it may use, and a live-ish preview. The point
 * of the layout is that a manager can see all six messages at once and notice
 * that one of them is still in the wrong language.
 *
 * @var list<array<string,mixed>> $templates
 * @var string|null $error
 * @var array<string,mixed> $old
 */
$errorTemplate = (string) ($old['template'] ?? '');
?>
<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-file"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">Message wording</h1>
    <p class="page-sub">
      The texts your patients receive. Change any of them — no deploy, no support ticket.
    </p>
  </div>
  </div>
  <a href="/settings" class="btn-secondary text-xs">Back to settings</a>
</div>

<?php if ($error !== null): ?>
  <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs leading-relaxed text-rose-900">
    <b>Not saved.</b> <?= e($error) ?>
  </div>
<?php endif; ?>

<div class="mt-4 rounded-xl border border-hairline bg-white px-4 py-3">
  <p class="text-xs leading-relaxed text-ink-700">
    <b>Any message you have not edited keeps the default wording</b>, including improvements we ship
    later. You are overriding one message at a time, not copying the whole set. Placeholders are
    filled in when the message is sent; anything not listed on a card is refused on save, because a
    typo would reach a patient as literal braces.
  </p>
</div>

<div class="mt-5 space-y-4">
  <?php foreach ($templates as $t): ?>
    <?php $isErrored = $errorTemplate === (string) $t['key']; ?>
    <div class="card overflow-hidden <?= $isErrored ? 'ring-2 ring-rose-200' : '' ?>" id="<?= e((string) $t['key']) ?>">
      <div class="section-head">
        <div class="flex items-center gap-2">
          <p class="text-sm font-semibold text-ink-900"><?= e((string) $t['label']) ?></p>
          <?php if ($t['customised']): ?>
            <span class="badge-teal">your wording</span>
          <?php else: ?>
            <span class="badge-slate">default</span>
          <?php endif; ?>
        </div>
        <?php if ($t['customised']): ?>
          <form method="post" action="/settings/templates/reset"
                onsubmit="return confirm('Go back to the default wording for this message?');">
            <?= csrf_field() ?>
            <input type="hidden" name="template" value="<?= e((string) $t['key']) ?>">
            <button class="btn-ghost text-xs">Use the default</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="p-4">
        <p class="rounded-lg bg-slate-50 px-3 py-2 font-mono text-[11px] leading-relaxed text-ink-700">
          <?= e((string) $t['body']) ?>
        </p>

        <details class="mt-3" <?= $isErrored ? 'open' : '' ?>>
          <summary class="cursor-pointer text-xs font-semibold text-brand-700">Edit this message</summary>

          <form method="post" action="/settings/templates" class="mt-3 space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="template" value="<?= e((string) $t['key']) ?>">
            <div>
              <label class="label" for="body-<?= e((string) $t['key']) ?>">Message</label>
              <textarea class="input font-mono text-xs" id="body-<?= e((string) $t['key']) ?>" name="body"
                        rows="3" maxlength="480" required><?= e($isErrored ? (string) ($old['body'] ?? '') : (string) $t['body']) ?></textarea>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
              <span class="text-[11px] text-ink-500">Insert:</span>
              <?php foreach ($t['allowed'] as $ph): ?>
                <button type="button" class="rounded border border-hairline bg-white px-1.5 py-0.5 font-mono text-[10px]
                                               text-ink-700 hover:border-teal-400 hover:text-brand-700"
                        onclick="(function(b){var f=b.closest('form'),t=f.querySelector('textarea');
                                 t.value += '{<?= e($ph) ?>}'; t.focus();})(this)">
                  {<?= e($ph) ?>}
                </button>
              <?php endforeach; ?>
            </div>

            <?php if ($t['required'] !== []): ?>
              <p class="text-[11px] leading-relaxed text-amber-800">
                Must include <?= e('{' . implode('} and {', $t['required']) . '}') ?> —
                without it the message does not tell the patient what they need to know.
              </p>
            <?php endif; ?>

            <p class="text-[11px] leading-relaxed text-ink-400">
              Under 160 characters is one text. Longer messages are split and the clinic pays per part.
            </p>

            <div class="flex gap-2">
              <button class="btn-primary text-xs">Save this message</button>
            </div>
          </form>
        </details>

        <?php if (!$t['customised']): ?>
          <p class="mt-3 text-[10px] leading-relaxed text-ink-400">
            Default: <span class="font-mono"><?= e((string) $t['default']) ?></span>
          </p>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
