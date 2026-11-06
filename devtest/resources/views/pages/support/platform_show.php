<?php $isClosed = in_array((string) $ticket['status'], ['resolved', 'closed'], true); ?>
<div class="page-head">
  <div>
    <div class="flex items-center gap-2 text-xs text-ink-500">
      <a href="/admin/support" class="hover:underline">Support queue</a>
      <span>/</span>
      <span class="font-medium text-ink-700">#<?= (int) $ticket['id'] ?></span>
    </div>
    <h1 class="page-title mt-1"><?= e($ticket['subject']) ?></h1>
    <p class="page-sub">
      <a href="/admin/clinics" class="font-medium text-ink-700 hover:underline"><?= e($ticket['clinic_name']) ?></a>
      · opened by <?= e($ticket['opened_by'] ?? 'unknown') ?> <?= e(ago($ticket['created_at'])) ?>
      · <?= e($ticket['category']) ?> · <?= e($ticket['priority']) ?>
    </p>
  </div>
  <div class="flex items-center gap-2">
    <span class="<?= match ((string) $ticket['status']) {
        'open' => 'badge-amber', 'waiting' => 'badge-teal',
        'resolved' => 'badge-emerald', default => 'badge-slate',
    } ?>"><?= e($ticket['status']) ?></span>
  </div>
</div>

<?php if (!$isClosed): ?>
  <div class="card mt-3 sm:mt-4">
    <form method="post" action="/admin/support/<?= (int) $ticket['id'] ?>/status" class="flex flex-wrap items-end gap-2 p-3">
      <?= csrf_field() ?>
      <div class="min-w-40">
        <label class="label" for="status">Set status</label>
        <select class="select" id="status" name="status">
          <?php foreach (['open' => 'Open — waiting on the clinic', 'waiting' => 'Waiting on us', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= (string) $ticket['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn-secondary btn-sm">Update</button>
      <p class="ml-auto max-w-sm text-[11px] leading-relaxed text-ink-500">
        Replying moves the ticket to <span class="font-medium">open</span> so it is clear the
        clinic owes an answer next.
      </p>
    </form>
  </div>
<?php endif; ?>

<div class="mt-4 space-y-3">
  <div class="card card-pad">
    <p class="stat-label">Original request</p>
    <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-ink-900"><?= e($ticket['body']) ?></p>
  </div>

  <?php foreach ($replies as $r): ?>
    <div class="card card-pad <?= (int) $r['is_staff'] === 1 ? 'border-brand-200 bg-brand-50/40' : '' ?>">
      <div class="flex items-center justify-between">
        <p class="text-xs font-semibold text-ink-700">
          <?= e($r['author_name'] ?? 'Support') ?>
          <?php if ((int) $r['is_staff'] === 1): ?><span class="badge-teal ml-1">platform</span>
          <?php else: ?><span class="badge-slate ml-1">clinic</span><?php endif; ?>
        </p>
        <p class="text-[11px] text-ink-400"><?= e(ago($r['created_at'])) ?></p>
      </div>
      <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-ink-900"><?= e($r['body']) ?></p>
    </div>
  <?php endforeach; ?>

  <?php if ($isClosed): ?>
    <p class="text-xs text-ink-500">
      This ticket is <?= e($ticket['status']) ?>. Reopen it above if the clinic comes back.
    </p>
  <?php else: ?>
    <div class="card">
      <form method="post" action="/admin/support/<?= (int) $ticket['id'] ?>/reply" class="space-y-2 p-4">
        <?= csrf_field() ?>
        <label class="label" for="body">Reply to <?= e($ticket['clinic_name']) ?></label>
        <textarea class="input min-h-24" id="body" name="body" required maxlength="5000"
                  placeholder="What you found, and what the clinic should do next."></textarea>
        <button class="btn-primary btn-sm">Send reply</button>
      </form>
    </div>
  <?php endif; ?>
</div>
