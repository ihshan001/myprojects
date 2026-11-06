<div>
  <a href="/patients" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-800">
    <svg class="h-3.5 w-3.5"><use href="#i-arrow-left"/></svg> All patients
  </a>

  <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
    <div class="flex items-center gap-3">
      <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-50 text-sm font-bold text-teal-700">
        <?= e(App\Core\Str::initials((string) $patient['full_name'])) ?>
      </span>
      <div>
        <h1 class="text-xl font-bold tracking-tight text-slate-900"><?= e($patient['full_name']) ?></h1>
        <p class="text-xs text-slate-500">
          <span class="font-mono"><?= e((string) $patient['code']) ?></span>
          <?php if (!empty($patient['phone'])): ?> · <?= e($patient['phone']) ?><?php endif; ?>
          <?php if (!empty($patient['gender'])): ?> · <?= e($patient['gender']) ?><?php endif; ?>
          <?php if (!empty($patient['birth_year'])): ?> · <?= 2026 - (int) $patient['birth_year'] ?> yrs<?php endif; ?>
        </p>
      </div>
    </div>
    <a href="/queue/new?patient=<?= (int) $patient['id'] ?>" class="btn-primary">
      <svg class="h-4 w-4"><use href="#i-plus"/></svg> Issue token
    </a>
  </div>

  <div class="mt-5 grid gap-3 sm:grid-cols-3">
    <?php
    $stats2 = [
        ['Total visits', $stats['visits'], 'queue', 'teal', ''],
        ['Completed', $stats['completed'], 'check-circle', 'emerald', ''],
        ['Average wait', $stats['avg_wait'] !== null ? $stats['avg_wait'] . ' min' : '—', 'clock', 'sky', 'when completed'],
    ];
    foreach ($stats2 as [$label, $value, $icon, $tone, $hint]) {
        include QP_ROOT . '/resources/views/components/stat.php';
    }
    ?>
  </div>

  <div class="mt-5 grid gap-5 lg:grid-cols-[1fr_.8fr]">
    <div class="card overflow-hidden">
      <div class="border-b border-slate-200 bg-slate-50/60 px-4 py-2.5">
        <p class="text-xs font-semibold text-slate-600">Visit history</p>
      </div>
      <?php if ($history === []): ?>
        <?php $title = 'No visits yet'; $message = 'This patient has not been added to a queue.'; $icon = 'queue'; include QP_ROOT . '/resources/views/components/empty.php'; ?>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Token</th><th>When</th><th class="hidden sm:table-cell">Doctor</th><th>Status</th><th class="numeric hidden sm:table-cell">Wait</th></tr></thead>
          <tbody>
            <?php foreach ($history as $h): ?>
              <tr>
                <td><a href="/queue/entry?id=<?= (int) $h['id'] ?>" class="token-chip"><?= e((string) $h['token_label']) ?></a></td>
                <td class="text-xs text-slate-600"><?= e(fmt_time($h['joined_at'], 'j M, g:i A')) ?></td>
                <td class="hidden sm:table-cell text-xs"><?= e((string) ($h['doctor_name'] ?? '—')) ?></td>
                <td><?php $b = ['status' => $h['status']]; include QP_ROOT . '/resources/views/components/badge.php'; ?></td>
                <td class="numeric hidden sm:table-cell text-xs"><?= $h['wait_minutes'] !== null ? (int) $h['wait_minutes'] . 'm' : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card card-pad">
      <h2 class="text-sm font-bold text-slate-900">Edit record</h2>
      <form method="post" action="/patients/<?= (int) $patient['id'] ?>" class="mt-3 space-y-3">
        <?= csrf_field() ?>
        <div><label class="label" for="full_name">Full name</label>
          <input class="input" id="full_name" name="full_name" value="<?= e((string) $patient['full_name']) ?>" required></div>
        <div><label class="label" for="phone">Phone</label>
          <input class="input" id="phone" name="phone" value="<?= e((string) ($patient['phone'] ?? '')) ?>"></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label" for="gender">Gender</label>
            <select class="select" id="gender" name="gender">
              <?php foreach (['' => '—', 'female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= (string) $patient['gender'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label class="label" for="birth_year">Birth year</label>
            <input class="input" id="birth_year" name="birth_year" inputmode="numeric" maxlength="4"
                   value="<?= e((string) ($patient['birth_year'] ?? '')) ?>"></div>
        </div>
        <div><label class="label" for="notes">Internal note</label>
          <textarea class="textarea" id="notes" name="notes" rows="3" maxlength="500"
                    placeholder="Allergies, accessibility needs, preferred language…"><?= e((string) ($patient['notes'] ?? '')) ?></textarea>
          <p class="help">Visible to clinic staff only. Never shown on a tracking link.</p></div>
        <button class="btn-primary w-full">Save changes</button>
      </form>
    </div>
  </div>
</div>
