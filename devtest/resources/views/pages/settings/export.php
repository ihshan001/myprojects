<?php
/**
 * Export clinic data.
 *
 * The page exists to answer three questions a manager will actually ask
 * before clicking: what exactly is in the file, what is deliberately not,
 * and what will I owe afterwards. It also says plainly that the archive is
 * a one-shot download rather than a link they can come back to, because a
 * manager who assumes the second will be surprised at the worst moment.
 *
 * @var array<string,mixed>|null $clinic
 * @var list<array{table: string, label: string, rows: int}> $counts
 * @var array<string,mixed>|null $lastExport
 */
$totalRows = 0;
foreach ($counts as $c) {
    $totalRows += $c['rows'];
}
$lastAt = $lastExport !== null ? (string) $lastExport['created_at'] : null;
$lastBy = null;
if ($lastExport !== null) {
    $meta = json_decode((string) $lastExport['meta_json'], true);
    $lastBy = is_array($meta) ? ($meta['file'] ?? null) : null;
}
?>
<div class="page-head">
  <div class="flex min-w-0 items-start gap-3">
    <span class="page-icon"><svg class="h-5 w-5"><use href="#i-download"/></svg></span>
    <div class="min-w-0">
    <h1 class="page-title">Export clinic data</h1>
    <p class="page-sub">
      Everything <?= e((string) ($clinic['name'] ?? 'this clinic')) ?> has recorded, in one archive you keep.
    </p>
  </div>
  </div>
  <a href="/settings" class="btn-secondary text-xs">Back to settings</a>
</div>

<div class="mt-4 rounded-xl border border-hairline bg-white px-4 py-3">
  <p class="text-xs leading-relaxed text-ink-700">
    <b>This is your data, not ours.</b> The archive is a zip of plain CSV files — one per table — plus a
    <code class="rounded bg-slate-100 px-1">MANIFEST.json</code> listing every file and its row count, and a
    <code class="rounded bg-slate-100 px-1">README.txt</code> written for a person. It opens in Excel, Numbers or
    LibreOffice, and because ids are preserved the files can be imported elsewhere without losing the links
    between a token and its patient. Sinhala and Tamil names come out correctly.
  </p>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-3">
  <div class="rounded-xl border border-hairline bg-white p-4">
    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">In this export</p>
    <p class="stat-value mt-1"><?= number_format(count($counts)) ?></p>
    <p class="text-xs text-ink-500">tables</p>
  </div>
  <div class="rounded-xl border border-hairline bg-white p-4">
    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Rows</p>
    <p class="stat-value mt-1"><?= number_format($totalRows) ?></p>
    <p class="text-xs text-ink-500">patient records, tokens and history included</p>
  </div>
  <div class="rounded-xl border border-hairline bg-white p-4">
    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Last taken</p>
    <p class="mt-1 text-sm font-semibold text-ink-900"><?= $lastAt !== null ? e($lastAt) : 'Never' ?></p>
    <p class="text-xs text-ink-500"><?= $lastAt !== null ? 'every export is recorded in the audit log' : 'you have not taken a copy yet' ?></p>
  </div>
</div>

<div class="mt-4 overflow-hidden rounded-xl border border-hairline">
  <div class="table-scroll">
    <table class="w-full text-left text-xs">
    <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-ink-500">
    <tr>
    <th class="px-4 py-2 font-semibold">File</th>
    <th class="px-4 py-2 font-semibold">What it holds</th>
    <th class="px-4 py-2 text-right font-semibold">Rows</th>
    </tr>
    </thead>
    <tbody class="divide-y divide-slate-100 bg-white">
    <?php foreach ($counts as $c): ?>
    <tr>
    <td class="px-4 py-2 font-mono text-[11px] text-ink-700"><?= e($c['table']) ?>.csv</td>
    <td class="px-4 py-2 text-ink-700"><?= e($c['label']) ?></td>
    <td class="px-4 py-2 text-right tabular-nums text-ink-700"><?= number_format($c['rows']) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="bg-slate-50">
    <td class="px-4 py-2 font-mono text-[11px] text-ink-700">clinic.csv</td>
    <td class="px-4 py-2 text-ink-700">Clinic profile</td>
    <td class="px-4 py-2 text-right tabular-nums text-ink-700">1</td>
    </tr>
    </tbody>
    </table>
  </div>
</div>

<div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
  <p class="text-xs leading-relaxed text-amber-900">
    <b>Three things are left out, and that is on purpose.</b>
    Staff <b>passwords</b>, <b>two-factor secrets</b> and <b>recovery codes</b> are never included — they are
    credentials belonging to a person, not records belonging to the clinic. The waiting-room <b>display token</b>
    and the self-service <b>kiosk token</b> are working credentials too, so both are left out by default and only
    included if you tick the box below because you are moving the display or the kiosk tablet to new hardware.
  </p>
</div>

<form method="post" action="/settings/export" class="mt-4 rounded-xl border border-hairline bg-white p-4">
  <?= csrf_field() ?>
  <label class="flex items-start gap-3 text-xs text-ink-700">
    <input type="checkbox" name="screen_config" value="1" class="mt-0.5">
    <span>
      <b>Include the waiting-room screen and kiosk configuration.</b><br>
      Only needed when you are moving the display or the kiosk tablet to new hardware or another system. Anyone
      holding the resulting file can act as those machines for this clinic — show and hide public token numbers
      on the screen, or issue tokens at the kiosk — so treat it as a credential and delete it once the hardware
      is set up.
    </span>
  </label>
  <div class="mt-3 flex flex-wrap items-center gap-3">
    <button type="submit" class="btn-primary text-xs">Build and download archive</button>
    <span class="text-[11px] text-ink-500">
      Built on demand from live data — usually a second or two. This download is recorded against your name.
    </span>
  </div>
</form>

<div class="mt-4 rounded-xl border border-hairline bg-white px-4 py-3">
  <p class="text-xs leading-relaxed text-ink-700">
    <b>Keep it safe.</b> The archive contains every patient name and phone number your clinic has recorded. Once it
    is on your laptop it is your clinic's record to protect — under data-protection law that is the clinic's
    responsibility, not the software's. The archive is not stored on the server afterwards: it is streamed to you
    once and deleted.
  </p>
  <p class="mt-2 text-xs leading-relaxed text-ink-700">
    <b>Leaving QueuePilot?</b> Take this export before the account closes. Closing a clinic starts a countdown to
    permanent deletion, and after that day the records are gone — the archive is the copy you keep.
  </p>
</div>
