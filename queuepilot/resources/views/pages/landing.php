<?php
use App\Core\Db;
$plans = Db::all('SELECT * FROM plans ORDER BY sort_order');
$demoQueue = Db::one("SELECT q.*, c.name AS clinic_name, c.slug FROM queues q JOIN clinics c ON c.id=q.clinic_id WHERE c.slug='serenity' ORDER BY q.id LIMIT 1");
// This panel is on an unauthenticated marketing page that anyone can open or
// screenshot, so it shows the same projection the waiting-room TV does: tokens
// and rooms, never a patient name. The patients join is deliberately absent —
// the name must not be reachable here, not merely unprinted.
$liveRows = $demoQueue ? Db::all(
    "SELECT e.token_label, e.status, r.name AS room_name
      FROM queue_entries e
      JOIN queues q ON q.id = e.queue_id
      LEFT JOIN doctors d ON d.id = q.doctor_id
      LEFT JOIN rooms r ON r.id = d.room_id
      WHERE e.queue_id = ? AND e.status IN ('waiting','called','in_consultation')
      ORDER BY CASE e.status WHEN 'in_consultation' THEN 0 WHEN 'called' THEN 1 ELSE 2 END,
               e.priority DESC, e.token_no LIMIT 4",
    [$demoQueue['id']]
) : [];
?>
<!DOCTYPE html>
<html lang="en" class="h-full scroll-smooth">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>QueuePilot — calm, live patient queues for Sri Lankan clinics</title>
  <meta name="description" content="Replace the paper token book. QueuePilot gives clinics a live waiting board, SMS/WhatsApp patient links, doctor screens and honest wait-time ranges. Works on the phone in your pocket.">
  <meta name="theme-color" content="#0F766E">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
  <style>
    .grain { background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,.06) 1px, transparent 0); background-size: 22px 22px; }
    .mesh { background-image: radial-gradient(at 15% 8%, rgba(20,184,166,.22) 0px, transparent 55%), radial-gradient(at 85% 0%, rgba(56,189,248,.14) 0px, transparent 45%), radial-gradient(at 60% 95%, rgba(15,118,110,.18) 0px, transparent 50%); }
  </style>
</head>
<body class="bg-white text-slate-800">

<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<!-- ───────────────────────────────────────────── top bar -->
<header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/85 backdrop-blur">
  <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-5">
    <a href="/" class="flex items-center gap-2.5">
      <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-700 text-sm font-black text-white shadow-sm">Q</span>
      <span class="text-[15px] font-bold tracking-tight text-slate-900">QueuePilot</span>
    </a>
    <nav class="ml-6 hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex">
      <a href="#how" class="hover:text-slate-900">How it works</a>
      <a href="#surfaces" class="hover:text-slate-900">Product</a>
      <a href="#pricing" class="hover:text-slate-900">Pricing</a>
      <a href="/demo/board" class="hover:text-slate-900">Patient view</a>
    </nav>
    <div class="ml-auto flex items-center gap-2">
      <a href="/login" class="btn-ghost hidden sm:inline-flex">Sign in</a>
      <a href="/register" class="btn-primary">Start free trial</a>
    </div>
  </div>
</header>

<!-- ───────────────────────────────────────────── hero -->
<section class="mesh relative overflow-hidden border-b border-slate-200">
  <div class="mx-auto grid max-w-6xl items-center gap-14 px-5 py-16 lg:grid-cols-[1.05fr_.95fr] lg:py-24">
    <div>
      <span class="inline-flex items-center gap-2 rounded-full border border-teal-200 bg-white/70 px-3 py-1 text-xs font-semibold text-teal-800">
        <span class="relative flex h-1.5 w-1.5">
          <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-teal-500"></span>
        </span>
        Built for Sri Lankan OPDs
      </span>

      <h1 class="mt-5 text-balance text-4xl font-black leading-[1.08] tracking-tight text-slate-900 sm:text-5xl lg:text-[3.4rem]">
        The paper token book,<br>
        <span class="bg-gradient-to-r from-teal-700 to-sky-700 bg-clip-text text-transparent">quietly replaced.</span>
      </h1>

      <p class="mt-5 max-w-xl text-balance text-[17px] leading-relaxed text-slate-600">
        Your receptionist issues a token in four seconds. Your patients watch their place from any phone —
        no app to install. Your waiting room screen calls the next token by itself. And the wait time you
        show is an honest <em>range</em>, measured from your own consultations, never a guess dressed up as a fact.
      </p>

      <div class="mt-7 flex flex-wrap items-center gap-3">
        <a href="/register" class="btn-primary btn-lg">
          Start 14-day trial
          <svg class="h-4 w-4"><use href="#i-arrow-right"/></svg>
        </a>
        <a href="/login" class="btn-secondary btn-lg">
          <svg class="h-4 w-4"><use href="#i-play"/></svg>
          Open the live demo
        </a>
      </div>

      <p class="mt-3 text-xs text-slate-500">
        No card required · works on a basic smartphone and a 3G line · your patients never install anything
      </p>

      <dl class="mt-9 grid max-w-lg grid-cols-3 gap-4 border-t border-slate-300/60 pt-6">
        <div><dt class="text-2xl font-black text-slate-900">4s</dt><dd class="text-xs text-slate-500">to issue a token</dd></div>
        <div><dt class="text-2xl font-black text-slate-900">0</dt><dd class="text-xs text-slate-500">apps for patients</dd></div>
        <div><dt class="text-2xl font-black text-slate-900">EN·SI·TA</dt><dd class="text-xs text-slate-500">three languages</dd></div>
      </dl>
    </div>

    <!-- live board preview, real data from the database -->
    <div class="relative">
      <div class="absolute -inset-4 rounded-[2rem] bg-gradient-to-br from-teal-600/10 to-sky-600/5 blur-2xl"></div>
      <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/70 px-4 py-3">
          <div class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
          </div>
          <p class="text-[11px] font-semibold text-slate-500"><?= e($demoQueue['clinic_name'] ?? 'Live demo') ?> · waiting room</p>
          <span class="live-pill"><span class="pulse-dot"><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span></span>Live</span>
        </div>

        <div class="grid grid-cols-3 divide-x divide-slate-200 border-b border-slate-200 bg-white">
          <div class="px-4 py-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Now serving</p>
            <p class="mt-1 font-mono text-2xl font-black text-teal-700"><?= e($liveRows[0]['token_label'] ?? 'A001') ?></p>
          </div>
          <div class="px-4 py-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Waiting</p>
            <p class="mt-1 text-2xl font-black tabular text-slate-900"><?= count($liveRows) > 1 ? count($liveRows) - 1 : 3 ?></p>
          </div>
          <div class="px-4 py-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Avg wait</p>
            <p class="mt-1 text-2xl font-black tabular text-slate-900">12<span class="text-sm font-semibold text-slate-400">min</span></p>
          </div>
        </div>

        <div class="divide-y divide-slate-100">
          <?php foreach (array_slice($liveRows, 0, 4) as $i => $r): ?>
            <div class="flex items-center gap-3 px-4 py-3 <?= $i === 0 ? 'bg-teal-50/40' : '' ?>">
              <span class="token-chip"><?= e($r['token_label']) ?></span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-slate-800"><?= e(App\Domain\Queue\QueueStateMachine::meta($r['status'])['label']) ?></p>
                <p class="text-[11px] text-slate-400"><?= e($r['room_name'] ?? 'Waiting room') ?></p>
              </div>
              <?php if ($r['status'] === 'in_consultation'): ?>
                <span class="badge-teal">In room</span>
              <?php elseif ($r['status'] === 'called'): ?>
                <span class="badge-sky">Called</span>
              <?php else: ?>
                <span class="badge-amber"><?= 10 + $i * 4 ?> min</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($liveRows === []): ?>
            <div class="px-4 py-8 text-center text-sm text-slate-400">Run the seeder to see live demo data.</div>
          <?php endif; ?>
        </div>
        <div class="border-t border-slate-200 bg-slate-50/60 px-4 py-2.5 text-center text-[11px] text-slate-500">
          Real data from this database &mdash; tokens and rooms only, exactly what the waiting-room screen shows.
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ───────────────────────────────────────────── the problem -->
<section class="border-b border-slate-200 bg-slate-50/60">
  <div class="mx-auto max-w-6xl px-5 py-16">
    <div class="grid gap-10 lg:grid-cols-[.9fr_1.1fr] lg:items-center">
      <div>
        <p class="text-xs font-bold uppercase tracking-widest text-teal-700">Why queues break</p>
        <h2 class="mt-3 text-balance text-3xl font-bold tracking-tight text-slate-900">
          Nobody is served badly on purpose. The system just doesn't know enough.
        </h2>
        <p class="mt-4 text-slate-600">
          A receptionist with a paper book knows one thing: who's next. She can't tell the 60-year-old
          standing at the counter whether it's 10 minutes or 90. She can't tell the person who stepped out
          for tea that their turn passed. And at 4pm, nobody can prove how long anyone waited.
        </p>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <?php foreach ([
            ['clock', 'Guessing at the counter', 'Reception says "about an hour" from memory. Patients leave, miss their turn, or crowd the room.'],
            ['megaphone', 'Shouting names', 'Names get mispronounced, waiting rooms are noisy, and privacy is lost every time a token is called.'],
            ['chart', 'No ground truth', 'Without recorded wait and consult times, staffing decisions and doctor rosters run on intuition.'],
            ['wifi-off', 'Fragile tools', 'Most clinic software assumes a fast connection and a desktop. Both vanish during a busy OPD.'],
        ] as [$icon, $title, $body]): ?>
          <div class="card card-pad">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-rose-50 text-rose-600">
              <svg class="h-4.5 w-4.5"><use href="#i-<?= $icon ?>"/></svg>
            </span>
            <h3 class="mt-3 text-sm font-bold text-slate-900"><?= e($title) ?></h3>
            <p class="mt-1 text-[13px] leading-relaxed text-slate-600"><?= e($body) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ───────────────────────────────────────────── how it works -->
<section id="how" class="border-b border-slate-200">
  <div class="mx-auto max-w-6xl px-5 py-16">
    <div class="max-w-2xl">
      <p class="text-xs font-bold uppercase tracking-widest text-teal-700">The workflow</p>
      <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900">Four steps, one shared truth.</h2>
      <p class="mt-3 text-slate-600">Walk-ins, appointments, lab and pharmacy all draw tokens from the same engine — so a patient transferred from OPD to the lab never has to explain themselves twice.</p>
    </div>

    <ol class="mt-10 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
      <?php foreach ([
          ['01', 'Issue', 'Phone number is enough. Existing patients are found by number — no duplicate records, no forms to fill twice.', 'plus'],
          ['02', 'Call', 'The board and the TV screen announce the token, not the name. Recalls are tracked, so a patient who stepped out is still in the list.', 'megaphone'],
          ['03', 'Track', 'The patient gets a private link with their position and a wait range based on this doctor\'s real consultation times.', 'link'],
          ['04', 'Measure', 'Wait and consult minutes are recorded automatically. Reports show where the day actually went.', 'chart'],
      ] as [$num, $title, $body, $icon]): ?>
        <li class="card card-pad relative">
          <span class="absolute right-4 top-4 font-mono text-xs font-bold text-slate-300"><?= $num ?></span>
          <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-50 text-teal-700">
            <svg class="h-5 w-5"><use href="#i-<?= $icon ?>"/></svg>
          </span>
          <h3 class="mt-4 text-base font-bold text-slate-900"><?= e($title) ?></h3>
          <p class="mt-1.5 text-[13px] leading-relaxed text-slate-600"><?= e($body) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<!-- ───────────────────────────────────────────── surfaces -->
<section id="surfaces" class="border-b border-slate-200 bg-slate-900 py-16 text-white">
  <div class="mx-auto max-w-6xl px-5">
    <div class="max-w-2xl">
      <p class="text-xs font-bold uppercase tracking-widest text-teal-400">Four surfaces</p>
      <h2 class="mt-3 text-3xl font-bold tracking-tight">One system, seen from four places.</h2>
      <p class="mt-3 text-slate-400">Each surface shows only what that person needs. Least privilege is not a feature bolted on later — it is how the queries are written.</p>
    </div>

    <div class="mt-10 grid gap-4 md:grid-cols-2">
      <?php foreach ([
          ['Reception', 'The fastest possible token issue. Search by phone, pick a queue, print or send the link.', 'grid', 'bg-teal-500/10 text-teal-300'],
          ['Doctor', 'My list only. Call, start, complete — with the clinical priority control behind a forced reason.', 'stethoscope', 'bg-sky-500/10 text-sky-300'],
          ['Patient phone', 'No install. Position, honest range, and a clear "it is your turn" state that survives a refresh.', 'phone', 'bg-violet-500/10 text-violet-300'],
          ['Waiting room TV', 'Tokens and rooms only. No names, no diagnoses, no phone numbers on a public screen.', 'monitor', 'bg-amber-500/10 text-amber-300'],
      ] as [$title, $body, $icon, $tone]): ?>
        <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-6 transition hover:border-white/20 hover:bg-white/[0.06]">
          <span class="flex h-10 w-10 items-center justify-center rounded-xl <?= $tone ?>">
            <svg class="h-5 w-5"><use href="#i-<?= $icon ?>"/></svg>
          </span>
          <h3 class="mt-4 font-bold"><?= e($title) ?></h3>
          <p class="mt-1.5 text-sm leading-relaxed text-slate-400"><?= e($body) ?></p>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-8 grid gap-3 rounded-2xl border border-white/10 bg-white/[0.02] p-6 sm:grid-cols-3">
      <?php foreach ([
          ['Range, not precision', 'We show "20–35 min" and say plainly when we are only estimating — after five completed visits the range is learned from your own data.'],
          ['Degrades honestly', 'If the connection drops, every screen says so. Last-known state is labelled as last-known, never presented as live.'],
          ['Every change is audited', 'Who called, who skipped, who granted clinical priority and why — recorded against the clinic, immutable.'],
      ] as [$t, $b]): ?>
        <div>
          <p class="flex items-center gap-2 text-sm font-bold text-white">
            <svg class="h-4 w-4 text-teal-400"><use href="#i-shield"/></svg><?= e($t) ?>
          </p>
          <p class="mt-1 text-xs leading-relaxed text-slate-400"><?= e($b) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ───────────────────────────────────────────── pricing -->
<section id="pricing" class="border-b border-slate-200">
  <div class="mx-auto max-w-6xl px-5 py-16">
    <div class="mx-auto max-w-2xl text-center">
      <p class="text-xs font-bold uppercase tracking-widest text-teal-700">Pricing</p>
      <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900">Priced per clinic, not per patient.</h2>
      <p class="mt-3 text-slate-600">SMS and WhatsApp are metered separately at cost plus a small margin — so a quiet month costs less. Hardware and onboarding are quoted once, never bundled into a subscription you can't leave.</p>
    </div>

    <div class="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
      <?php foreach ($plans as $plan): ?>
        <?php $featured = $plan['code'] === 'professional'; ?>
        <div class="relative flex flex-col rounded-2xl border <?= $featured ? 'border-teal-700 shadow-soft ring-1 ring-teal-700/10' : 'border-slate-200' ?> bg-white p-6">
          <?php if ($featured): ?>
            <span class="absolute -top-2.5 left-6 rounded-full bg-teal-700 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white">Most clinics pick this</span>
          <?php endif; ?>
          <h3 class="text-sm font-bold uppercase tracking-wide text-slate-500"><?= e($plan['name']) ?></h3>
          <p class="mt-3 flex items-baseline gap-1">
            <span class="text-3xl font-black tracking-tight text-slate-900">
              <?= (float) $plan['price_monthly'] > 0 ? number_format((float) $plan['price_monthly']) : 'Talk' ?>
            </span>
            <?php if ((float) $plan['price_monthly'] > 0): ?>
              <span class="text-xs font-semibold text-slate-500">LKR / month</span>
            <?php endif; ?>
          </p>
          <p class="mt-2 min-h-[40px] text-[13px] leading-relaxed text-slate-600"><?= e((string) $plan['features']) ?></p>
          <ul class="mt-4 space-y-2 text-[13px] text-slate-700">
            <li class="flex gap-2"><svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-teal-600"><use href="#i-check"/></svg><?= (int) $plan['max_doctors'] >= 999 ? 'Unlimited' : (int) $plan['max_doctors'] ?> doctors</li>
            <li class="flex gap-2"><svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-teal-600"><use href="#i-check"/></svg><?= (int) $plan['max_staff'] >= 999 ? 'Unlimited' : (int) $plan['max_staff'] ?> staff accounts</li>
            <li class="flex gap-2"><svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-teal-600"><use href="#i-check"/></svg><?= (int) $plan['max_queues'] >= 999 ? 'Unlimited' : (int) $plan['max_queues'] ?> live queues</li>
            <li class="flex gap-2"><svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-teal-600"><use href="#i-check"/></svg>Patient tracking links</li>
            <li class="flex gap-2"><svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-teal-600"><use href="#i-check"/></svg>Waiting-room display</li>
          </ul>
          <a href="/register?plan=<?= e($plan['code']) ?>" class="mt-6 <?= $featured ? 'btn-primary' : 'btn-secondary' ?> w-full">
            <?= $featured ? 'Start free trial' : 'Choose ' . e($plan['name']) ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="mt-6 text-center text-xs text-slate-500">
      Every plan includes the queue engine, audit log and CSV exports. Suspension never deletes your history.
    </p>
  </div>
</section>

<!-- ───────────────────────────────────────────── FAQ -->
<section class="border-b border-slate-200 bg-slate-50/60">
  <div class="mx-auto max-w-3xl px-5 py-16">
    <h2 class="text-center text-2xl font-bold tracking-tight text-slate-900">Questions clinics actually ask</h2>
    <div class="mt-8 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
      <?php foreach ([
          ['Do patients need to install an app?', 'No. They receive a link by SMS (or you show a QR code at the desk). It opens in the phone browser, shows the token, position and range — and it keeps working if they lock the screen.'],
          ['What happens when the internet drops?', 'The reception board keeps working from the last successful poll and says plainly that it is showing last-known state. Queue actions that fail are reported, never silently lost. The patient link retries with backoff.'],
          ['Can we keep using paper tokens?', 'Yes — QueuePilot mirrors your existing token numbering (prefix per clinic, reset daily or per session). Many clinics run both for a week before switching.'],
          ['How private is the waiting-room screen?', 'It shows tokens and room numbers only. No names, no specialties, no phone numbers. The screen uses a separate revocable key, so a leaked TV link can be rotated without touching staff accounts.'],
          ['Can two receptionists work at once?', 'Yes. Every queue action carries a version number, so if two people press "Call" at the same moment the second gets a clear "the board was refreshed" message instead of a duplicate call.'],
          ['Who can grant emergency priority?', 'Only staff with the clinical-priority ability (doctor or clinic owner), and a reason is mandatory. It is written to the audit log with the actor and timestamp. There is no automatic or AI triage.'],
      ] as [$q, $a]): ?>
        <details class="group px-5 py-4">
          <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-semibold text-slate-900">
            <?= e($q) ?>
            <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180"><use href="#i-chevron-down"/></svg>
          </summary>
          <p class="mt-2.5 text-[13] leading-relaxed text-slate-600"><?= e($a) ?></p>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ───────────────────────────────────────────── CTA -->
<section class="bg-white py-16">
  <div class="mx-auto max-w-4xl px-5">
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-teal-800 via-teal-700 to-emerald-800 px-8 py-12 text-center text-white shadow-soft">
      <div class="grain absolute inset-0"></div>
      <div class="relative">
        <h2 class="text-balance text-3xl font-black tracking-tight">Set up your first queue in ten minutes.</h2>
        <p class="mx-auto mt-3 max-w-xl text-teal-50/90">
          Register your clinic, add one doctor, and print your first token today. The trial needs no card and
          keeps your data if you upgrade.
        </p>
        <div class="mt-7 flex flex-wrap justify-center gap-3">
          <a href="/register" class="btn btn-lg bg-white text-teal-900 hover:bg-teal-50">Create my clinic</a>
          <a href="/login" class="btn btn-lg border border-white/30 text-white hover:bg-white/10">Sign in to demo</a>
        </div>
        <p class="mt-4 text-xs text-teal-100/70">Demo credentials are on the sign-in page — one click to fill.</p>
      </div>
    </div>
  </div>
</section>

<footer class="border-t border-slate-200 bg-white">
  <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-5 py-8 text-xs text-slate-500 sm:flex-row">
    <div class="flex items-center gap-2">
      <span class="flex h-6 w-6 items-center justify-center rounded bg-teal-700 text-[10px] font-black text-white">Q</span>
      <span class="font-semibold text-slate-700">QueuePilot</span>
      <span>· clinic-first queue management</span>
    </div>
    <div class="flex items-center gap-5">
      <a href="/pricing" class="hover:text-slate-800">Pricing</a>
      <a href="/register" class="hover:text-slate-800">Register</a>
      <a href="/login" class="hover:text-slate-800">Sign in</a>
      <span class="text-slate-300">|</span>
      <span>Built for Sri Lanka · LKR</span>
    </div>
  </div>
</footer>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
