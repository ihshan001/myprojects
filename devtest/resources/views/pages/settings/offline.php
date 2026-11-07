<?php
/**
 * Offline desks — reconciliation and block management.
 *
 * Two audiences on one page: a receptionist who needs to set aside numbers
 * before the line drops, and a manager the morning after who needs to see what
 * was issued offline and what was never used. The copy leads with the one
 * consequence that matters — offline tokens carry no SMS link until they sync —
 * because that is the promise the clinic would otherwise break quietly.
 *
 * @var list<array<string,mixed>> $queues
 * @var array{blocks: list<array<string,mixed>>, unused: int, expired: int} $report
 */
?>
<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-wifi-off"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">Offline desks</h1>
    <p class="page-sub">
      Keep issuing tokens when the internet goes down — without ever handing two patients the same number.
    </p>
  </div>
  </div>
  <a href="/settings" class="btn-secondary text-xs">Back to settings</a>
</div>

<div class="mt-4 rounded-xl border border-hairline bg-white px-4 py-3">
  <p class="text-xs leading-relaxed text-ink-700">
    <b>How this works, in one paragraph.</b> While the connection is up, set aside a block of numbers for
    a queue. Those numbers are drawn from the same counter your desk uses, so nothing online can be given
    them — they are already yours. If the line drops, issue from the block; the patient gets a real number
    on a real slip. When the connection returns, the desk sends the reservations back and they are entered
    as ordinary tokens. Each reservation carries an id the database will only accept once, so a retry
    cannot produce a second ticket.
  </p>
</div>

<div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
  <p class="text-xs leading-relaxed text-amber-900">
    <b>What an offline token does not have.</b> No SMS tracking link, and no place on the live screen, until
    it has synced. Tell the patient that when you hand them the slip — a printed number is a complete
    token, but a patient who was promised a text will not get one. Numbers left unused in a block are
    <b>not</b> returned to the counter; they are simply skipped, and the only cost is a gap in the sequence.
  </p>
</div>

<section data-offline-desk class="mt-5 rounded-xl border border-hairline bg-white">
  <div class="flex flex-wrap items-center justify-between gap-2 border-b border-hairline bg-slate-50/70 px-4 py-2.5">
    <p class="text-xs font-semibold text-ink-700">This desk</p>
    <div class="flex items-center gap-2">
      <span data-offline-net class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Online</span>
      <span data-offline-pending class="text-[11px] text-ink-500">Nothing waiting to sync</span>
      <button type="button" data-offline-sync class="btn-secondary text-[11px]">Sync now</button>
    </div>
  </div>

  <?php if ($queues !== []): ?>
    <div class="grid gap-4 px-4 py-4 lg:grid-cols-2">
      <?php foreach ($queues as $q): ?>
        <div class="rounded-xl border border-hairline p-3">
          <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-semibold text-ink-900"><?= e($q['name']) ?></p>
            <p class="text-[11px] text-ink-500">
              <b data-offline-available="<?= (int) $q['id'] ?>" class="tabular-nums text-ink-900">0</b> set aside
            </p>
          </div>
          <form method="post" action="/api/queue/offline/block" data-offline-take class="mt-2 flex items-center gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="queue_id" value="<?= (int) $q['id'] ?>">
            <input class="input w-16 py-1 text-xs" type="number" name="size" value="10" min="1" max="50" aria-label="How many numbers">
            <button type="submit" class="btn-secondary text-[11px]">Set aside numbers</button>
          </form>
          <form method="post" action="#" data-offline-issue class="mt-3 space-y-2 border-t border-slate-100 pt-3">
            <input type="hidden" name="queue_id" value="<?= (int) $q['id'] ?>">
            <input class="input py-1 text-xs" name="patient_name" placeholder="Patient name" autocomplete="off">
            <input class="input py-1 text-xs" name="patient_phone" placeholder="Phone (optional)" autocomplete="off">
            <button type="submit" class="btn-primary w-full text-[11px]">Issue from the block</button>
            <p class="text-[10px] leading-relaxed text-ink-500">
              Works with no connection. The patient gets a real number; the SMS link appears once it syncs.
            </p>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <div data-offline-slip class="px-4 pb-4"></div>
  <?php endif; ?>
</section>

<?php if ($queues === []): ?>
  <div class="mt-4 rounded-xl border border-hairline bg-white px-4 py-6 text-center">
    <p class="text-sm font-semibold text-ink-700">No open queues</p>
    <p class="mt-1 text-xs text-ink-500">A block can only be taken for a queue that is open for the day.</p>
  </div>
<?php else: ?>
  <div class="mt-4 overflow-hidden rounded-xl border border-hairline">
    <div class="table-scroll">
      <table class="w-full text-left text-xs">
      <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-ink-500">
      <tr>
      <th class="px-4 py-2 font-semibold">Queue</th>
      <th class="px-4 py-2 font-semibold">Next number</th>
      <th class="px-4 py-2 text-right font-semibold">Set aside</th>
      </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 bg-white">
      <?php foreach ($queues as $q): ?>
      <tr>
      <td class="px-4 py-2 font-medium text-ink-900"><?= e($q['name']) ?></td>
      <td class="px-4 py-2 font-mono text-[11px] text-ink-700">
      <?php $last = (int) \App\Core\Db::scalar('SELECT last_token_no FROM queues WHERE id = ?', [(int) $q['id']]); ?>
      <?= e((string) $q['token_prefix'] . str_pad((string) ($last + 1), (int) $q['token_padding'], '0', STR_PAD_LEFT)) ?>
      </td>
      <td class="px-4 py-2 text-right">
      <form method="post" action="/api/queue/offline/block" class="flex items-center justify-end gap-2"
      data-offline-block>
      <?= csrf_field() ?>
      <input type="hidden" name="queue_id" value="<?= (int) $q['id'] ?>">
      <input class="input w-16 py-1 text-xs" type="number" name="size" value="10" min="1" max="50">
      <button class="btn-secondary text-[11px]">Set aside</button>
      </form>
      </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="mt-5 grid gap-3 sm:grid-cols-3">
  <div class="rounded-xl border border-hairline bg-white p-4">
    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Blocks taken</p>
    <p class="stat-value mt-1"><?= number_format(count($report['blocks'])) ?></p>
  </div>
  <div class="rounded-xl border border-hairline bg-white p-4">
    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Numbers still usable</p>
    <p class="stat-value mt-1"><?= number_format($report['unused']) ?></p>
    <p class="text-xs text-ink-500">in blocks that have not expired</p>
  </div>
  <div class="rounded-xl border border-hairline bg-white p-4">
    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Expired, never used</p>
    <p class="mt-1 text-2xl font-bold tabular-nums <?= $report['expired'] > 0 ? 'text-amber-600' : 'text-ink-900' ?>"><?= number_format($report['expired']) ?></p>
    <p class="text-xs text-ink-500">skipped numbers, no patient affected</p>
  </div>
</div>

<?php if ($report['blocks'] === []): ?>
  <div class="mt-4 rounded-xl border border-hairline bg-white px-4 py-6 text-center">
    <p class="text-sm font-semibold text-ink-700">Nothing has been set aside yet</p>
    <p class="mt-1 text-xs text-ink-500">Take a block above before you need one — this is the step that has to happen while the line is up.</p>
  </div>
<?php else: ?>
  <div class="mt-4 overflow-hidden rounded-xl border border-hairline">
    <div class="table-scroll">
      <table class="w-full text-left text-xs">
      <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-ink-500">
      <tr>
      <th class="px-4 py-2 font-semibold">Taken</th>
      <th class="px-4 py-2 font-semibold">Queue</th>
      <th class="px-4 py-2 font-semibold">Numbers</th>
      <th class="px-4 py-2 text-right font-semibold">Used</th>
      <th class="px-4 py-2 text-right font-semibold">Left</th>
      <th class="px-4 py-2 font-semibold">State</th>
      </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 bg-white">
      <?php foreach ($report['blocks'] as $b): ?>
      <?php
      $pad = (int) ($b['token_padding'] ?? 3);
      $pfx = (string) ($b['token_prefix'] ?? '');
      $from = $pfx . str_pad((string) $b['from_no'], $pad, '0', STR_PAD_LEFT);
      $to = $pfx . str_pad((string) $b['to_no'], $pad, '0', STR_PAD_LEFT);
      ?>
      <tr>
      <td class="px-4 py-2 text-ink-700"><?= e((string) $b['created_at']) ?></td>
      <td class="px-4 py-2 text-ink-900"><?= e((string) ($b['queue_name'] ?? '—')) ?></td>
      <td class="px-4 py-2 font-mono text-[11px] text-ink-700"><?= e($from) ?> – <?= e($to) ?></td>
      <td class="px-4 py-2 text-right tabular-nums text-ink-700"><?= (int) $b['used'] ?></td>
      <td class="px-4 py-2 text-right tabular-nums <?= (int) $b['unused'] > 0 ? 'text-ink-700' : 'text-ink-400' ?>"><?= (int) $b['unused'] ?></td>
      <td class="px-4 py-2">
      <?php if ((int) $b['used'] === 0 && $b['is_expired']): ?>
      <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-ink-700">expired, unused</span>
      <?php elseif ($b['is_expired']): ?>
      <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">synced</span>
      <?php elseif ((int) $b['unused'] === 0): ?>
      <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">fully used</span>
      <?php else: ?>
      <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-semibold text-brand-700">holding</span>
      <?php endif; ?>
      </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="mt-4 rounded-xl border border-hairline bg-white px-4 py-3">
  <p class="text-xs leading-relaxed text-ink-700">
    <b>Why not simply queue the token requests in the browser?</b> Because the browser does not know what
    number is free. Two desks that each decide "the next one is A031" both print A031, and the clinic
    discovers it when two patients stand at the same door. Setting the numbers aside in advance is what
    makes the offline token the same object as an online one, rather than a promise to sort it out later.
  </p>
</div>
