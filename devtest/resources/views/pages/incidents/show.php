<div class="page-head">
  <div class="min-w-0">
    <a class="text-xs text-ink-500 hover:underline" href="/incidents">&larr; All incidents</a>
    <div class="mt-1 flex flex-wrap items-center gap-2">
      <span class="font-mono text-xs text-ink-400"><?= e($incident['reference']) ?></span>
      <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase
        <?= $incident['severity'] === 'critical' ? 'bg-red-100 text-red-800' : ($incident['severity'] === 'major' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-ink-700') ?>">
        <?= e($incident['severity']) ?>
      </span>
      <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold
        <?= $incident['status'] === 'open' ? 'bg-red-50 text-red-700' : ($incident['status'] === 'mitigated' ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-700') ?>">
        <?= e($statuses[$incident['status']] ?? $incident['status']) ?>
      </span>
    </div>
    <h1 class="page-title mt-2"><?= e($incident['title']) ?></h1>
    <?php if (!empty($incident['summary'])): ?>
      <p class="mt-1 max-w-2xl text-sm text-ink-700"><?= e($incident['summary']) ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="mt-3 grid grid-cols-2 gap-2 sm:mt-4 sm:gap-4 sm:grid-cols-4">
  <div class="card p-3">
    <p class="stat-label">Detected</p>
    <p class="mt-1 text-sm font-medium text-ink-900"><?= e(fmt_time($timings['detected'], 'j M, g:i A')) ?></p>
  </div>
  <div class="card p-3">
    <p class="stat-label">Acknowledged</p>
    <p class="mt-1 text-sm font-medium text-ink-900">
      <?= $timings['acknowledge_minutes'] === null ? '—' : (int) $timings['acknowledge_minutes'] . ' min after' ?>
    </p>
  </div>
  <div class="card p-3">
    <p class="stat-label">Resolved</p>
    <p class="mt-1 text-sm font-medium text-ink-900">
      <?= $timings['resolve_minutes'] === null ? '—' : (int) $timings['resolve_minutes'] . ' min after' ?>
    </p>
  </div>
  <div class="card p-3">
    <p class="stat-label">Timeline</p>
    <p class="mt-1 text-sm font-medium text-ink-900"><?= count($entries) ?> entries</p>
  </div>
</div>

<div class="mt-3 grid gap-3 sm:mt-6 sm:gap-5 lg:grid-cols-[1.15fr_.85fr]">
  <div class="card">
    <div class="section-head">
      <p class="section-title">Timeline — append-only</p>
    </div>
    <ol class="divide-y divide-slate-100">
      <?php foreach ($entries as $entry): ?>
        <li class="flex gap-3 px-4 py-3">
          <span class="mt-1 inline-flex h-5 shrink-0 items-center rounded bg-slate-100 px-1.5 text-[10px] font-semibold uppercase text-ink-700">
            <?= e($kinds[$entry['kind']] ?? $entry['kind']) ?>
          </span>
          <div class="min-w-0 flex-1">
            <p class="text-sm text-ink-900"><?= e($entry['body']) ?></p>
            <p class="mt-0.5 text-[10px] text-ink-400">
              <?= e(fmt_time($entry['created_at'], 'j M, g:i:s A')) ?>
              <?php if (!empty($entry['actor'])): ?> · <?= e($entry['actor']) ?><?php endif; ?>
            </p>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>

    <?php if ($incident['status'] !== 'resolved'): ?>
      <form method="post" action="/incidents/<?= (int) $incident['id'] ?>/entries" class="flex flex-wrap gap-2 border-t border-hairline bg-white p-3">
        <?= csrf_field() ?>
        <select class="select w-32 shrink-0 text-xs" name="kind">
          <?php foreach ($kinds as $value => $label): ?>
            <option value="<?= e($value) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <input class="input min-w-0 flex-1 text-sm" name="body" required maxlength="2000"
               placeholder="What did you just do, or learn?">
        <button class="btn-primary btn-sm shrink-0" type="submit">Add</button>
      </form>
    <?php else: ?>
      <p class="border-t border-hairline bg-white px-4 py-3 text-xs text-ink-500">
        This incident is resolved. Its timeline is closed — a mistake in it is
        corrected by opening a follow-up, not by editing history.
      </p>
    <?php endif; ?>
  </div>

  <div class="space-y-4">
    <?php if ($incident['status'] !== 'resolved'): ?>
      <div class="card p-4">
        <h2 class="text-xs font-bold uppercase tracking-wider text-ink-400">Progress it</h2>

        <?php if ($incident['acknowledged_at'] === null): ?>
          <form method="post" action="/incidents/<?= (int) $incident['id'] ?>/acknowledge" class="mt-3">
            <?= csrf_field() ?>
            <button class="btn-primary w-full" type="submit">I am on it</button>
            <p class="mt-1.5 text-[11px] leading-relaxed text-ink-500">
              Records who took it and when. The response clock still starts when
              the incident was detected, so acknowledging late does not flatter us.
            </p>
          </form>
        <?php else: ?>
          <p class="mt-2 text-xs text-ink-500">Acknowledged already.</p>
        <?php endif; ?>

        <?php if ($incident['status'] === 'open'): ?>
          <form method="post" action="/incidents/<?= (int) $incident['id'] ?>/mitigate" class="mt-3 space-y-2">
            <?= csrf_field() ?>
            <input class="input text-sm" name="body" required placeholder="What stopped the bleeding?">
            <button class="btn-secondary w-full text-sm" type="submit">Mark mitigated</button>
          </form>
        <?php endif; ?>

        <form method="post" action="/incidents/<?= (int) $incident['id'] ?>/resolve" class="mt-3 space-y-2 border-t border-slate-100 pt-3">
          <?= csrf_field() ?>
          <input class="input text-sm" name="body" required placeholder="How was it fixed?">
          <button class="btn-secondary w-full text-sm" type="submit">Resolve</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="rounded-lg border border-hairline bg-slate-50 px-4 py-3 text-xs leading-relaxed text-ink-700">
      <p class="font-semibold text-ink-700">The five questions a post-mortem asks</p>
      <ul class="mt-1 list-disc space-y-1 pl-5">
        <li>When did we know?</li>
        <li>When did somebody take it?</li>
        <li>What did we actually do?</li>
        <li>Why did that work?</li>
        <li>What would have made it shorter?</li>
      </ul>
      <p class="mt-2">The first three are on this page. The last two are what you write before closing it.</p>
    </div>

    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-900">
      <p class="font-semibold">No pager</p>
      <p class="mt-1">
        Nothing here wakes anybody up. That is a provider contract (PagerDuty,
        Opsgenie, an on-call phone) and not something this codebase can supply;
        the runbook in <code>docs/OPERATIONS.md</code> says who to call.
      </p>
    </div>
  </div>
</div>
