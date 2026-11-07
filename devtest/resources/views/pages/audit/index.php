<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-list"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">Audit log</h1>
    <p class="page-sub">Append-only. Clinic owners cannot edit or delete these entries.</p>
  </div>
  </div>
  <form method="get" action="/audit" class="flex gap-2">
    <input class="input !py-2 text-xs" name="action" value="<?= e($filter ?? '') ?>" placeholder="Filter by action e.g. queue.call">
    <button class="btn-secondary">Filter</button>
  </form>
</div>

<div class="card mt-3 sm:mt-5 overflow-hidden">
  <?php if ($entries === []): ?>
    <?php $title = 'No audit entries'; $message = 'Actions such as calling tokens, granting priority and changing settings will appear here.'; $icon = 'shield'; include QP_ROOT . '/resources/views/components/empty.php'; ?>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table">
      <thead>
      <tr><th>When</th><th>Actor</th><th>Action</th><th class="hidden md:table-cell">Entity</th><th class="hidden lg:table-cell">Detail</th><th class="hidden lg:table-cell">IP</th></tr>
      </thead>
      <tbody>
      <?php foreach ($entries as $e): ?>
      <tr>
      <td class="whitespace-nowrap text-xs text-ink-500"><?= e(fmt_time($e['created_at'], 'j M, g:i:s A')) ?></td>
      <td class="text-xs"><?= e($e['actor_name'] ?? 'system') ?></td>
      <td>
      <code class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-ink-700"><?= e($e['action']) ?></code>
      </td>
      <td class="hidden md:table-cell text-[11px] text-ink-500">
      <?= e((string) ($e['entity'] ?? '—')) ?><?= !empty($e['entity_id']) ? ' #' . (int) $e['entity_id'] : '' ?>
      </td>
      <td class="hidden lg:table-cell max-w-[18rem] truncate text-[11px] text-ink-400" title="<?= e((string) ($e['meta_json'] ?? '')) ?>">
      <?= e((string) ($e['meta_json'] ?? '')) ?>
      </td>
      <td class="hidden lg:table-cell font-mono text-[10px] text-ink-400"><?= e((string) ($e['ip'] ?? '')) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
