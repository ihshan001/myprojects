<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Support</h1>
    <p class="mt-1 text-sm text-slate-500">
      Raise a problem with the platform team. Tickets are private to this clinic.
    </p>
  </div>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-[1.4fr_.8fr]">
  <div class="card overflow-hidden">
    <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
      <p class="text-xs font-semibold text-slate-600">Your tickets</p>
    </div>
    <?php if ($tickets === []): ?>
      <?php $title = 'No tickets'; $message = 'When something breaks or a patient gets the wrong message, open a ticket here rather than emailing — it carries the clinic context support needs.'; $icon = 'lifebuoy'; ?>
      <div class="p-4"><?php include QP_ROOT . '/resources/views/components/empty.php'; ?></div>
    <?php else: ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($tickets as $t): ?>
          <li class="px-4 py-3 hover:bg-slate-50">
            <a href="/support/<?= (int) $t['id'] ?>" class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-slate-900">
                  #<?= (int) $t['id'] ?> · <?= e($t['subject']) ?>
                </p>
                <p class="mt-0.5 text-[11px] text-slate-500">
                  <?= e($t['opened_by'] ?? 'unknown') ?> ·
                  <?= e(ago($t['created_at'])) ?> ·
                  <?= (int) $t['reply_count'] ?> repl<?= (int) $t['reply_count'] === 1 ? 'y' : 'ies' ?>
                </p>
              </div>
              <span class="<?= match ((string) $t['status']) {
                  'open' => 'badge-amber',
                  'waiting' => 'badge-teal',
                  'resolved' => 'badge-emerald',
                  default => 'badge-slate',
              } ?>"><?= e($t['status']) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
      <p class="text-xs font-semibold text-slate-600">Open a ticket</p>
    </div>
    <form method="post" action="/support" class="space-y-3 p-4">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="subject">Subject</label>
        <input class="input" id="subject" name="subject" required maxlength="200"
               placeholder="SMS not arriving for one patient">
      </div>
      <div>
        <label class="label" for="body">What happened?</label>
        <textarea class="input min-h-24" id="body" name="body" required maxlength="5000"
                  placeholder="Token A014, called at 10:12. The patient says no SMS arrived."></textarea>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="label" for="category">Category</label>
          <select class="select" id="category" name="category">
            <option value="general">General</option>
            <option value="queue">Queue</option>
            <option value="billing">Billing</option>
            <option value="technical">Technical</option>
            <option value="account">Account</option>
          </select>
        </div>
        <div>
          <label class="label" for="priority">Priority</label>
          <select class="select" id="priority" name="priority">
            <option value="normal">Normal</option>
            <option value="low">Low</option>
            <option value="high">High</option>
            <option value="urgent">Urgent — clinic stopped</option>
          </select>
        </div>
      </div>
      <button class="btn-primary w-full">Open ticket</button>
    </form>
  </div>
</div>
