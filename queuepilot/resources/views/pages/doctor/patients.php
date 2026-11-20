<?php
/**
 * "My patients / visit history" — scoped to this doctor by the query, not by
 * filtering after the fact, so there is no page size at which another
 * doctor's patient appears.
 *
 * @var array $visits
 */
use App\Domain\Queue\QueueStateMachine;
?>

<div class="card card-pad mb-4">
  <h2 class="text-base font-semibold text-slate-800">My patients</h2>
  <p class="mt-1 text-sm text-slate-500">
    Everyone you have seen, most recent first. This is your own visit history — the clinic directory is
    available to reception and clinic owners.
  </p>
</div>

<div class="card">
  <?php if ($visits === []): ?>
    <p class="px-4 py-12 text-center text-sm text-slate-500">You have not seen any patients yet.</p>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="table">
        <thead>
          <tr>
            <th>Token</th>
            <th>Patient</th>
            <th>Status</th>
            <th>Joined</th>
            <th>Wait</th>
            <th>Consult</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($visits as $v): ?>
            <?php $status = (string) $v['status']; ?>
            <tr>
              <td><span class="token-chip"><?= e($v['token_label']) ?></span></td>
              <td>
                <p class="font-medium text-slate-800"><?= e($v['full_name']) ?></p>
                <p class="text-xs text-slate-400"><?= e($v['patient_code'] ?? '') ?></p>
              </td>
              <td>
                <?php include QP_ROOT . '/resources/views/components/badge.php'; ?>
              </td>
              <td class="tabular text-slate-500"><?= e(fmt_time($v['joined_at'], 'j M g:i A')) ?></td>
              <td class="tabular text-slate-500"><?= $v['wait_minutes'] !== null ? e((string) $v['wait_minutes']) . ' min' : '—' ?></td>
              <td class="tabular text-slate-500"><?= $v['consult_minutes'] !== null ? e((string) $v['consult_minutes']) . ' min' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
