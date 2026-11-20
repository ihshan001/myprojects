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
<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Message wording</h1>
    <p class="mt-1 text-sm text-slate-500">
      The texts your patients receive. Change any of them — no deploy, no support ticket.
    </p>
  </div>
  <a href="/settings" class="btn-secondary text-xs">Back to settings</a>
</div>

<?php if ($error !== null): ?>
  <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs leading-relaxed text-rose-900">
    <b>Not saved.</b> <?= e($error) ?>
  </div>
<?php endif; ?>

<div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3">
  <p class="text-xs leading-relaxed text-slate-600">
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
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <div class="flex items-center gap-2">
          <p class="text-sm font-semibold text-slate-800"><?= e((string) $t['label']) ?></p>
          <?php if ($t['customised']): ?>
            <span class="badge-teal">your wording</span>
          <?php else: ?>
            <span class="badge-slate">default</span>
          <?php endif; ?>
        </div>
        <?php if ($t['customised']): ?>
          <form method="post" action="/settings/templates/reset"
                data-confirm-submit="Go back to the default wording for this message?">
            <?= csrf_field() ?>
            <input type="hidden" name="template" value="<?= e((string) $t['key']) ?>">
            <button class="btn-ghost text-xs">Use the default</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="p-4">
        <p class="rounded-lg bg-slate-50 px-3 py-2 font-mono text-[11px] leading-relaxed text-slate-700">
          <?= e((string) $t['body']) ?>
        </p>

        <details class="mt-3" <?= $isErrored ? 'open' : '' ?>>
          <summary class="cursor-pointer text-xs font-semibold text-teal-700">Edit this message</summary>

          <form method="post" action="/settings/templates" class="mt-3 space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="template" value="<?= e((string) $t['key']) ?>">
            <div>
              <label class="label" for="body-<?= e((string) $t['key']) ?>">Message</label>
              <textarea class="input font-mono text-xs" id="body-<?= e((string) $t['key']) ?>" name="body"
                        rows="3" maxlength="480" required><?= e($isErrored ? (string) ($old['body'] ?? '') : (string) $t['body']) ?></textarea>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
              <span class="text-[11px] text-slate-500">Insert:</span>
              <?php foreach ($t['allowed'] as $ph): ?>
                <button type="button" class="rounded border border-slate-200 bg-white px-1.5 py-0.5 font-mono text-[10px]
                                               text-slate-600 hover:border-teal-400 hover:text-teal-700"
                        data-insert-placeholder="{<?= e($ph) ?>}">
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

            <p class="text-[11px] leading-relaxed text-slate-400">
              Under 160 characters is one text. Longer messages are split and the clinic pays per part.
            </p>

            <div class="flex gap-2">
              <button class="btn-primary text-xs">Save this message</button>
            </div>
          </form>
        </details>

        <?php if (!$t['customised']): ?>
          <p class="mt-3 text-[10px] leading-relaxed text-slate-400">
            Default: <span class="font-mono"><?= e((string) $t['default']) ?></span>
          </p>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
