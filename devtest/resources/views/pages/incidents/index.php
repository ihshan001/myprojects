<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-alert"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">Incidents</h1>
    <p class="mt-1 max-w-2xl text-sm text-ink-500">
      What broke, when somebody took it, what was done and why. The timeline is
      append-only — a correction is a new entry that says it corrects one, so a
      post-mortem cannot quietly rewrite itself.
    </p>
  </div>
  </div>
  <a class="btn-secondary btn-sm" href="/admin/monitor">Platform health</a>
</div>

<?php if (!empty($errors)): ?>
  <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
    <p class="font-semibold">That incident was not opened.</p>
    <ul class="mt-1 list-disc pl-5">
      <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="mt-3 grid grid-cols-2 gap-2 sm:mt-6 sm:gap-4 sm:grid-cols-4">
  <div class="card p-4">
    <p class="stat-label">Last 90 days</p>
    <p class="stat-value mt-1"><?= (int) $stats['total'] ?></p>
    <p class="text-[11px] text-ink-500"><?= (int) $stats['open'] ?> still open</p>
  </div>
  <div class="card p-4">
    <p class="stat-label">Avg. to acknowledge</p>
    <p class="stat-value mt-1">
      <?= $stats['avg_ack'] === null ? '—' : (int) $stats['avg_ack'] ?><span class="text-sm font-normal text-ink-400"><?= $stats['avg_ack'] === null ? '' : ' min' ?></span>
    </p>
    <p class="text-[11px] text-ink-500"><?= $stats['avg_ack'] === null ? 'nothing acknowledged yet' : 'from detection' ?></p>
  </div>
  <div class="card p-4">
    <p class="stat-label">Avg. to resolve</p>
    <p class="stat-value mt-1">
      <?= $stats['avg_resolve'] === null ? '—' : (int) $stats['avg_resolve'] ?><span class="text-sm font-normal text-ink-400"><?= $stats['avg_resolve'] === null ? '' : ' min' ?></span>
    </p>
    <p class="text-[11px] text-ink-500"><?= $stats['avg_resolve'] === null ? 'nothing resolved yet' : 'computed from timestamps' ?></p>
  </div>
  <div class="card p-4">
    <p class="stat-label">By severity</p>
    <p class="mt-1 text-xs leading-relaxed text-ink-700">
      <?= (int) $stats['by_severity']['critical'] ?> critical ·
      <?= (int) $stats['by_severity']['major'] ?> major ·
      <?= (int) $stats['by_severity']['minor'] ?> minor
    </p>
  </div>
</div>

<div class="mt-3 grid gap-3 sm:mt-6 sm:gap-5 lg:grid-cols-[1.15fr_.85fr]">
  <div class="card">
    <div class="section-head">
      <p class="section-title">Open incidents first, then the newest</p>
    </div>

    <?php if ($incidents === []): ?>
      <p class="px-4 py-6 text-sm text-ink-500">
        No incidents recorded. That is either a good month or an unwritten one —
        the point of this page is that the second possibility is visible.
      </p>
    <?php else: ?>
      <div class="divide-y divide-slate-100">
        <?php foreach ($incidents as $incident): ?>
          <?php
            $sevTone = match ($incident['severity']) {
                'critical' => 'bg-red-100 text-red-800',
                'major'    => 'bg-amber-100 text-amber-900',
                default    => 'bg-slate-100 text-ink-700',
            };
            $stTone = match ($incident['status']) {
                'open'      => 'bg-red-50 text-red-700',
                'mitigated' => 'bg-amber-50 text-amber-800',
                default     => 'bg-emerald-50 text-emerald-700',
            };
          ?>
          <a class="flex flex-wrap items-start gap-3 px-4 py-3 hover:bg-slate-50" href="/incidents/<?= (int) $incident['id'] ?>">
            <span class="mt-0.5 font-mono text-[11px] text-ink-400"><?= e($incident['reference']) ?></span>
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-ink-900"><?= e($incident['title']) ?></span>
                <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase <?= $sevTone ?>"><?= e($incident['severity']) ?></span>
                <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold <?= $stTone ?>"><?= e($incident['status']) ?></span>
                <?php if ($incident['clinic_id'] === null): ?>
                  <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">platform-wide</span>
                <?php endif; ?>
              </div>
              <p class="mt-1 text-[11px] text-ink-500">
                Detected <?= e(fmt_time($incident['detected_at'], 'j M, g:i A')) ?>
                <?php if (!empty($incident['created_by_name'])): ?> · opened by <?= e($incident['created_by_name']) ?><?php endif; ?>
                · <?= (int) $incident['entry_count'] ?> timeline <?= (int) $incident['entry_count'] === 1 ? 'entry' : 'entries' ?>
              </p>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="space-y-5">
    <div class="card">
      <div class="section-head">
        <p class="section-title">Record an incident</p>
      </div>
      <form method="post" action="/incidents" class="space-y-3 p-4">
        <?= csrf_field() ?>
        <div>
          <label class="label" for="title">What is wrong</label>
          <input class="input" id="title" name="title" required maxlength="140"
                 value="<?= e($old['title'] ?? '') ?>"
                 placeholder="Display boards stopped updating">
          <p class="mt-1 text-[11px] text-ink-500">Write it for somebody woken at 03:00.</p>
        </div>
        <div class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
          <div>
            <label class="label" for="severity">Severity</label>
            <select class="select" id="severity" name="severity">
              <?php foreach ($severities as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= ($old['severity'] ?? '') === $value ? 'selected' : '' ?>><?= e(ucfirst($value)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="detected_at">Detected at</label>
            <input class="input" id="detected_at" name="detected_at" type="datetime-local"
                   value="<?= e($old['detected_at'] ?? date('Y-m-d\TH:i')) ?>">
          </div>
        </div>
        <div>
          <label class="label" for="summary">What you know so far</label>
          <textarea class="input" id="summary" name="summary" rows="2" maxlength="500"
                    placeholder="Boards show the last token called 20 minutes ago. API returns 500."><?= e($old['summary'] ?? '') ?></textarea>
        </div>
        <?php if ($alerts !== []): ?>
          <div>
            <label class="label" for="alert_id">Link an open platform alert</label>
            <select class="select" id="alert_id" name="alert_id">
              <option value="">None</option>
              <?php foreach ($alerts as $alert): ?>
                <option value="<?= (int) $alert['id'] ?>" <?= (string) ($old['alert_id'] ?? '') === (string) $alert['id'] ? 'selected' : '' ?>>
                  <?= e($alert['severity']) ?> · <?= e(mb_substr((string) $alert['message'], 0, 70)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <button class="btn-primary w-full" type="submit">Open incident</button>
      </form>
    </div>

    <div class="rounded-lg border border-hairline bg-slate-50 px-4 py-3 text-xs leading-relaxed text-ink-700">
      <p class="font-semibold text-ink-700">How this is meant to be used</p>
      <ul class="mt-1 list-disc space-y-1 pl-5">
        <li>Open it while it is happening, not afterwards. A reconstruction is not a record.</li>
        <li>Time to acknowledge starts at <span class="font-medium">detected</span>, not at "when we got round to it".</li>
        <li>Nothing here pages anybody. Somebody still has to notice — see <code>docs/OPERATIONS.md</code>.</li>
        <li>Entries cannot be edited. If one is wrong, add another saying so.</li>
      </ul>
    </div>
  </div>
</div>
