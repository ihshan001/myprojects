<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-bell"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">Notifications</h1>
    <p class="page-sub">
      What the system told staff, and what it sent to patients. Two lists because they are two
      different questions: "did anyone see this" and "did the SMS actually leave".
    </p>
  </div>
  </div>
  <?php if ($tab === 'staff' && $unread > 0): ?>
    <form method="post" action="/notifications/read-all">
      <?= csrf_field() ?>
      <button class="btn-secondary btn-sm">Mark all read (<?= (int) $unread ?>)</button>
    </form>
  <?php endif; ?>
</div>

<div class="mt-4 flex gap-1 border-b border-hairline">
  <a href="/notifications?tab=staff"
     class="-mb-px border-b-2 px-3 py-2 text-xs font-semibold <?= $tab === 'staff' ? 'border-teal-600 text-brand-700' : 'border-transparent text-ink-500 hover:text-ink-700' ?>">
    Staff notices <?php if ($unread > 0): ?><span class="badge-amber ml-1"><?= (int) $unread ?></span><?php endif; ?>
  </a>
  <a href="/notifications?tab=patients"
     class="-mb-px border-b-2 px-3 py-2 text-xs font-semibold <?= $tab === 'patients' ? 'border-teal-600 text-brand-700' : 'border-transparent text-ink-500 hover:text-ink-700' ?>">
    Patient messages
  </a>
</div>

<?php if ($tab === 'staff'): ?>
  <div class="card mt-3 sm:mt-4 overflow-hidden">
    <?php if ($staffNotices === []): ?>
      <?php $title = 'Nothing yet'; $message = 'Operational notices appear here: a doctor running late, a queue paused, a session closed with patients unseen.'; $icon = 'bell'; ?>
      <div class="p-4"><?php include QP_ROOT . '/resources/views/components/empty.php'; ?></div>
    <?php else: ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($staffNotices as $n): ?>
          <li class="flex items-start justify-between gap-3 px-4 py-3 <?= $n['read_at'] === null ? 'bg-brand-50/30' : '' ?>">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-ink-900">
                <?php if ($n['read_at'] === null): ?><span class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-brand-600 align-middle"></span><?php endif; ?>
                <?= e($n['title']) ?>
              </p>
              <?php if (!empty($n['body'])): ?>
                <p class="mt-0.5 text-xs leading-relaxed text-ink-700"><?= e($n['body']) ?></p>
              <?php endif; ?>
              <p class="mt-1 text-[10px] text-ink-400">
                <?= e(ago($n['created_at'])) ?>
                <?php if ($n['user_id'] === null): ?> · whole clinic<?php endif; ?>
              </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <?php if (!empty($n['link'])): ?>
                <a class="btn-ghost btn-sm" href="<?= e($n['link']) ?>">Open</a>
              <?php endif; ?>
              <?php if ($n['read_at'] === null): ?>
                <form method="post" action="/notifications/<?= (int) $n['id'] ?>/read">
                  <?= csrf_field() ?>
                  <button class="btn-ghost btn-sm">Mark read</button>
                </form>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="mt-4 grid gap-3 sm:grid-cols-3">
    <?php foreach ([
        ['queued', 'Queued', 'slate'],
        ['sent', 'Sent', 'emerald'],
        ['logged', 'Recorded only', 'amber'],
        ['failed', 'Failed', 'rose'],
    ] as [$key, $label, $tone]): ?>
      <div class="card card-pad">
        <p class="stat-value text-<?= $tone ?>-700"><?= (int) ($stats[$key] ?? 0) ?></p>
        <p class="stat-label mt-1"><?= e($label) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card mt-3 sm:mt-4 overflow-hidden">
    <?php if ($patientNotices === []): ?>
      <?php $title = 'No patient messages'; $message = 'Nothing has been queued yet. A message is created only when a clinic enables SMS and an entry reaches the trigger.'; $icon = 'mail'; ?>
      <div class="p-4"><?php include QP_ROOT . '/resources/views/components/empty.php'; ?></div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>When</th><th>To</th><th class="hidden sm:table-cell">Template</th>
              <th>Token</th><th>Status</th><th class="hidden md:table-cell">Detail</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($patientNotices as $n): ?>
              <tr>
                <td class="whitespace-nowrap text-xs text-ink-500"><?= e(ago($n['created_at'])) ?></td>
                <td class="font-mono text-xs text-ink-700"><?= e($n['to_number'] ?? '—') ?></td>
                <td class="hidden sm:table-cell text-xs text-ink-700"><?= e($n['template']) ?></td>
                <td class="text-xs text-ink-700"><?= e($n['token_label'] ?? '—') ?></td>
                <td>
                  <span class="<?= match ((string) $n['status']) {
                      'sent' => 'badge-emerald',
                      'logged' => 'badge-amber',
                      'queued' => 'badge-slate',
                      default => 'badge-rose',
                  } ?>"><?= e($n['status'] === 'logged' ? 'recorded, not sent' : $n['status']) ?></span>
                  <?php if ((int) $n['attempts'] > 0): ?>
                    <span class="ml-1 text-[10px] text-ink-400">×<?= (int) $n['attempts'] ?></span>
                  <?php endif; ?>
                </td>
                <td class="hidden md:table-cell max-w-xs truncate text-[11px] text-ink-500" title="<?= e((string) ($n['last_error'] ?? '')) ?>">
                  <?= e((string) ($n['last_error'] ?? '—')) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="border-t border-slate-100 px-4 py-2 text-[10px] leading-relaxed text-ink-400">
        Phone numbers are masked to the last three digits. The full number is held only long
        enough to deliver the message.
      </p>
    <?php endif; ?>
  </div>
<?php endif; ?>
