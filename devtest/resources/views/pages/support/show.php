<div class="page-head">
  <div>
    <div class="flex items-center gap-2 text-xs text-ink-500">
      <a href="/support" class="hover:underline">Support</a>
      <span>/</span>
      <span class="font-medium text-ink-700">#<?= (int) $ticket['id'] ?></span>
    </div>
    <h1 class="page-title mt-1"><?= e($ticket['subject']) ?></h1>
    <p class="page-sub">
      Opened by <?= e($ticket['opened_by'] ?? 'unknown') ?> <?= e(ago($ticket['created_at'])) ?>
      · <?= e($ticket['category']) ?> · <?= e($ticket['priority']) ?>
    </p>
  </div>
  <span class="<?= match ((string) $ticket['status']) {
      'open' => 'badge-amber', 'waiting' => 'badge-teal',
      'resolved' => 'badge-emerald', default => 'badge-slate',
  } ?>"><?= e($ticket['status']) ?></span>
</div>

<div class="mt-5 space-y-3">
  <div class="card card-pad">
    <p class="stat-label">Original request</p>
    <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-ink-900"><?= e($ticket['body']) ?></p>
  </div>

  <?php foreach ($replies as $r): ?>
    <div class="card card-pad <?= (int) $r['is_staff'] === 1 ? 'border-brand-200 bg-brand-50/40' : '' ?>">
      <div class="flex items-center justify-between">
        <p class="text-xs font-semibold text-ink-700">
          <?= e($r['author_name'] ?? 'Support') ?>
          <?php if ((int) $r['is_staff'] === 1): ?><span class="badge-teal ml-1">support</span><?php endif; ?>
        </p>
        <p class="text-[11px] text-ink-400"><?= e(ago($r['created_at'])) ?></p>
      </div>
      <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-ink-900"><?= e($r['body']) ?></p>
    </div>
  <?php endforeach; ?>

  <?php if (!in_array((string) $ticket['status'], ['resolved', 'closed'], true)): ?>
    <div class="card">
      <form method="post" action="/support/<?= (int) $ticket['id'] ?>/reply" class="space-y-2 p-4">
        <?= csrf_field() ?>
        <label class="label" for="body">Add detail</label>
        <textarea class="input min-h-20" id="body" name="body" required maxlength="5000"
                  placeholder="Anything that would help support reproduce it."></textarea>
        <button class="btn-primary btn-sm">Send reply</button>
      </form>
    </div>
  <?php else: ?>
    <p class="text-xs text-ink-500">
      This ticket is <?= e($ticket['status']) ?>. If the problem came back, open a new ticket — a
      closed thread is not read.
    </p>
  <?php endif; ?>
</div>
