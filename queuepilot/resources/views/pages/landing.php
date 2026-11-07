<?php
/**
 * Public homepage.
 *
 * Every number on this page is read from the database or from the plan table.
 * Nothing is invented: no customer logos we do not have, no testimonial from a
 * person who does not exist, no "92% on time" we cannot measure. Where there is
 * no data the tile shows a dash, the same rule the console follows.
 *
 * Privacy: the demo panels show tokens, rooms, doctors and status. Never a
 * patient name, phone number or track token — this URL is unauthenticated and
 * screenshot-able, and the page itself promises "tokens and rooms only".
 */

use App\Core\Db;

$plans = Db::all('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order');
$today = Db::today();

$demoClinic = Db::one("SELECT * FROM clinics WHERE slug = 'serenity'");
$cid = (int) ($demoClinic['id'] ?? 0);

$demoQueue = $cid ? Db::one(
    "SELECT q.*, d.name AS doctor_name, r.name AS room_name
       FROM queues q
       LEFT JOIN doctors d ON d.id = q.doctor_id
       LEFT JOIN rooms r ON r.id = d.room_id
      WHERE q.clinic_id = ? AND q.session_date = ? AND q.status <> 'closed'
      ORDER BY q.id LIMIT 1",
    [$cid, $today]
) : null;

// Tokens, status and room only. The patients table is deliberately not joined.
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
$nowServing = $liveRows[0] ?? null;

$count = static function (string $sql, array $args) use ($cid): int {
    return $cid ? (int) Db::scalar($sql, $args) : 0;
};
$waitingNow = $count("SELECT COUNT(*) FROM queue_entries WHERE clinic_id = ? AND status = 'waiting'", [$cid]);
$patientsToday = $count(
    'SELECT COUNT(*) FROM queue_entries WHERE clinic_id = ? AND date(joined_at) = ?',
    [$cid, $today]
);
$completedToday = $count(
    "SELECT COUNT(*) FROM queue_entries WHERE clinic_id = ? AND status = 'completed' AND date(finished_at) = ?",
    [$cid, $today]
);
$roomsOpen = $count(
    "SELECT COUNT(*) FROM queues WHERE clinic_id = ? AND session_date = ? AND status <> 'closed'",
    [$cid, $today]
);

// Measured, not asserted: the average wait across this clinic's own finished
// visits. Null when there is nothing to measure, so the tile can show a dash.
$waitRow = $cid ? Db::one(
    "SELECT ROUND(AVG(wait_minutes)) AS avg_wait, COUNT(*) AS n
       FROM queue_entries
      WHERE clinic_id = ? AND wait_minutes IS NOT NULL AND finished_at >= ?",
    [$cid, date('Y-m-d 00:00:00', strtotime('-30 days'))]
) : null;
$avgWait = ($waitRow && (int) $waitRow['n'] >= 5) ? (int) $waitRow['avg_wait'] : null;
$waitSample = (int) ($waitRow['n'] ?? 0);

// Real daily volume for the charts. Fewer than two points draws no line rather
// than a decorative curve pretending to be data.
$dailyRows = $cid ? Db::all(
    'SELECT date(joined_at) AS d, COUNT(*) AS n FROM queue_entries
      WHERE clinic_id = ? AND joined_at >= ? GROUP BY date(joined_at) ORDER BY d',
    [$cid, date('Y-m-d 00:00:00', strtotime('-13 days'))]
) : [];
$daily = [];
foreach ($dailyRows as $r) {
    if (!empty($r['d'])) { $daily[(string) $r['d']] = (int) $r['n']; }
}

$byDoctor = $cid ? Db::all(
    "SELECT d.name, r.name AS room_name,
            SUM(CASE WHEN e.status = 'waiting' THEN 1 ELSE 0 END) AS waiting
       FROM queues q
       JOIN doctors d ON d.id = q.doctor_id
       LEFT JOIN rooms r ON r.id = d.room_id
       LEFT JOIN queue_entries e ON e.queue_id = q.id
      WHERE q.clinic_id = ? AND q.session_date = ? AND q.status <> 'closed'
      GROUP BY d.id, d.name, r.name ORDER BY waiting DESC, d.name LIMIT 4",
    [$cid, $today]
) : [];

// The phone mock-up shows a real visit through the same public snapshot the
// patient link uses, so the range on screen is the range the engine produces.
// The service returns whitelisted fields only; no capability token reaches here.
$phone = $cid ? (new App\Domain\Queue\QueueService())->nextWaitingPublic($cid, $today) : null;

/** Map a series of counts to an SVG polyline inside a 260x70 box. */
$sparkline = static function (array $values, int $w = 260, int $h = 70): array {
    $n = count($values);
    if ($n < 2) { return ['line' => '', 'area' => '', 'dots' => [], 'max' => 0]; }
    $max = max(1, max($values));
    $pts = [];
    $dots = [];
    foreach (array_values($values) as $i => $v) {
        $x = round($i * ($w / ($n - 1)), 1);
        $y = round($h - ($v / $max) * ($h - 8) - 4, 1);
        $pts[] = $x . ',' . $y;
        $dots[] = ['x' => $x, 'y' => $y];
    }
    return ['line' => implode(' ', $pts), 'area' => implode(' ', $pts) . ' ' . $w . ',' . $h . ' 0,' . $h, 'dots' => $dots, 'max' => $max];
};
$series = array_values($daily);
$chart = $sparkline($series);

// The conditions a Sri Lankan OPD actually works under, and the answer to
// each. Every answer maps to behaviour verified in the code: the 30s stale
// threshold in app.js, the token-only public surfaces, LKR plan rows, the
// en/si/ta lang files, and the plan-gated notification channels.
$conditions = [
    ['The line drops mid-session', 'Every live surface marks itself stale after 30 seconds without data, rather than showing a queue that quietly stopped moving.'],
    ['Nobody is installing an app', 'The token opens as a link in any browser. Nothing to download, nothing to keep updated.'],
    ['The board is read across a room', 'Tokens and rooms only. No patient name reaches a public screen or a shared link.'],
    ['The budget is in rupees', 'Plans are priced in LKR. No conversion, and no exchange rate appearing on an invoice.'],
    ['The counter speaks three languages', 'The interface ships in Sinhala, Tamil and English.'],
    ['A reminder has to reach a basic phone', 'SMS and WhatsApp notifications, with channel access following the plan.'],
];
// One visit, seen from both sides of the counter at the same moment.
$visit = [
    ['01', 'Arrive', 'Find the patient by phone number and issue the next token.', 'A link carrying the token. No app, no account.'],
    ['02', 'Wait', 'The room’s queue, in order, on one board.', 'Their position, and a wait range instead of a false exact time.'],
    ['03', 'Call', 'One tap calls the next token into a room.', 'The status changes and the room name appears.'],
    ['04', 'Consult', 'Start, then finish. Priority applied where it is earned.', 'The same status, with no clinical detail on the link.'],
    ['05', 'After', 'Wait and consult minutes land in the day’s report.', 'A reminder before the next appointment.'],
];
$pains = [
    ['users', 'Crowded waiting rooms', 'Patients uncomfortable, staff overwhelmed.'],
    ['eye', 'No visibility', 'Hard to know what is happening, right now.'],
    ['file', 'Manual chaos', 'Paper tokens, shout-outs, and double work.'],
    ['user-x', 'Anxious patients', 'Nobody knows how much longer it will be.'],
];
$answers = [
    ['One board', 'Every room on one screen, each token the same size.'],
    ['Honest ranges', 'Measured from your own consultation times, never a guess.'],
    ['Tokens, not names', 'No patient name on any public screen or link.'],
];
// The decisions behind the marked numbers on the dashboard. This replaces a
// carousel of unattributed "principles", which read like a testimonial and hid
// two thirds of its content behind a click.
$decisions = [
    ['A count, not a forecast', 'Waiting is the number of entries actually in the queue right now. It is never smoothed, rounded up, or predicted.'],
    ['Measured, or a dash', 'The average comes only from finished visits. Below five samples the tile shows a dash rather than a guess dressed as a number.'],
    ['Rooms and tokens, never names', 'Nothing on a public surface identifies a patient. The names in this list are the clinicians, not the people waiting.'],
];
$navLinks = [['#product', 'Product'], ['#how', 'How it works'], ['#pricing', 'Pricing'], ['#patient', 'Patient view']];

// The brand mark: a token-shaped Q whose tail ends in a gold signal dot.
// Inline so it inherits no external request and stays crisp at any size.
$mark = '<svg viewBox="0 0 32 32" class="h-full w-full" aria-hidden="true">'
      . '<circle cx="14" cy="14" r="6.4" fill="none" stroke="#fff" stroke-width="2.6"/>'
      . '<path d="M18.2 18.2 L22.6 22.6" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>'
      . '<circle cx="23.4" cy="23.4" r="2.6" fill="#E0A63C"/>'
      . '</svg>';
$markBg = 'background-image: linear-gradient(140deg,#0F766E,#06322E)';
?>
<!DOCTYPE html>
<html lang="en" class="lp-root h-full scroll-smooth">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>QueuePilot — live patient queues for Sri Lankan clinics</title>
  <meta name="description" content="Replace the paper token book. QueuePilot gives clinics a live waiting board, patient tracking links, doctor screens and wait-time ranges measured from your own consultations.">
  <meta name="theme-color" content="#0F766E">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="preload" href="<?= e(asset('fonts/fraunces-var.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="lp">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<!-- ============================================================ header -->
<header class="lp-header" data-lp-header>
  <span class="lp-progress" data-lp-progress aria-hidden="true"></span>
  <div class="lp-wrap flex h-[3.875rem] items-center justify-between gap-4">
    <a href="/" class="flex shrink-0 items-center gap-2.5">
      <span class="h-8 w-8 shrink-0 rounded-[0.625rem] p-0.5" style="<?= e($markBg) ?>"><?= $mark ?></span>
      <span class="lp-wordmark text-[1.1875rem]">QueuePilot</span>
    </a>
    <nav class="hidden items-center gap-7 md:flex">
      <?php foreach ($navLinks as [$href, $label]): ?>
        <a href="<?= e($href) ?>" class="lp-nav-link"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="flex shrink-0 items-center gap-3">
      <a href="/login" class="hidden text-[0.8125rem] font-semibold text-slate-700 hover:text-slate-900 sm:block">Sign in</a>
      <a href="/register" class="lp-btn !px-4 !py-2.5 !text-[0.78125rem]">Start free trial</a>
    </div>
  </div>
</header>

<!-- ============================================================== hero -->
<section class="lp-wash relative overflow-hidden">
  <div class="lp-hero-photo" style="background-image:url('<?= e(asset('img/landing-clinic.jpg')) ?>')"></div>
  <div class="lp-wrap relative">
    <div class="grid items-center gap-10 py-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.02fr)] lg:gap-8 lg:py-16">

      <div class="max-w-xl">
        <span class="lp-badge">
          <span class="h-1.5 w-1.5 rounded-full bg-brand-600"></span>Built for Sri Lankan OPDs
        </span>
        <h1 class="lp-h1 mt-5" data-reveal>
          Your clinic deserves<br><span class="lp-teal lp-underline">more than a queue.</span>
        </h1>
        <p class="lp-lead mt-5 max-w-lg" data-reveal style="--reveal-delay:90ms">
          QueuePilot is the queue operating system for outpatient clinics. Issue a token in
          seconds, put every room on one board, and let patients follow their place from
          any phone.
        </p>
        <div class="mt-7 flex flex-wrap gap-3" data-reveal style="--reveal-delay:160ms">
          <a href="/register" class="lp-btn">
            Start 14-day free trial
            <svg class="h-4 w-4" aria-hidden="true"><use href="#i-arrow-right"/></svg>
          </a>
          <a href="/demo/board" class="lp-btn-ghost">
            <svg class="h-4 w-4" aria-hidden="true"><use href="#i-play"/></svg>
            Open the live demo
          </a>
        </div>
        <ul class="mt-7 flex flex-wrap gap-x-6 gap-y-2.5" data-reveal style="--reveal-delay:230ms">
          <?php foreach (['No card required', '14 days, full access', 'Sinhala · Tamil · English'] as $t): ?>
            <li class="flex items-center gap-2 text-[0.78125rem] font-medium text-slate-600">
              <svg class="h-4 w-4 shrink-0 text-brand-600" aria-hidden="true"><use href="#i-check-circle"/></svg>
              <?= e($t) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <!-- Floating product cards. Real values, or a dash. -->
      <div class="relative lg:h-[23.875rem]">
        <div data-reveal style="--reveal-delay:120ms" class="lp-float relative z-10 w-full p-4 lg:absolute lg:left-0 lg:top-0 lg:w-[53%] lg:max-w-none">
          <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-[0.65625rem] font-bold text-brand-700">
              <span class="h-1.5 w-1.5 rounded-full bg-brand-600"></span>Live demo
            </span>
            <span class="truncate text-[0.75rem] font-bold text-slate-800"><?= e($demoQueue['room_name'] ?? 'Main OPD') ?></span>
          </div>
          <div class="lp-ticket mt-3">
            <div class="px-3.5 pb-3 pt-3.5">
              <p class="lp-mono text-[0.625rem] uppercase tracking-[0.16em] text-slate-400">Now serving</p>
              <div class="mt-1 flex items-center justify-between gap-2">
                <p class="lp-mono text-[1.875rem] font-bold leading-none tracking-tight text-brand-700">
                  <?= e($nowServing['token_label'] ?? '—') ?>
                </p>
                <svg class="h-5 w-5 text-brand-600" aria-hidden="true"><use href="#i-arrow-right"/></svg>
              </div>
            </div>
            <div class="lp-ticket-tear px-3.5 pb-3.5 pt-3">
              <p class="truncate text-[0.78125rem] font-bold text-slate-800"><?= e($demoQueue['doctor_name'] ?? '—') ?></p>
              <p class="text-[0.6875rem] font-medium text-brand-700"><?= e($nowServing ? App\Domain\Queue\QueueStateMachine::meta($nowServing['status'])['label'] : 'No token called') ?></p>
            </div>
          </div>
          <div class="mt-3.5 grid grid-cols-2 gap-3">
            <div>
              <p class="text-[0.65625rem] font-medium text-slate-500">Waiting</p>
              <p class="lp-num mt-0.5 text-[1.5rem]" data-count="<?= (int) $waitingNow ?>"><?= $waitingNow ?></p>
            </div>
            <div>
              <p class="text-[0.65625rem] font-medium text-slate-500">Avg wait</p>
              <p class="lp-num mt-0.5 text-[1.5rem]">
                <?= $avgWait !== null ? $avgWait : '—' ?><span class="ml-1 text-[0.6875rem] font-semibold text-slate-400">min</span>
              </p>
            </div>
          </div>
        </div>

        <div data-reveal style="--reveal-delay:220ms" class="lp-float relative z-20 mt-4 w-full p-4 lg:absolute lg:right-0 lg:top-[3.375rem] lg:mt-0 lg:w-[48%] lg:max-w-none">
          <p class="text-[0.75rem] font-bold text-slate-800">Visits · last 14 days</p>
          <div class="mt-1 flex items-baseline justify-between">
            <p class="text-[0.6875rem] font-medium text-slate-500">Patients</p>
            <p class="lp-num text-[1.1875rem]"><?= array_sum($series) ?></p>
          </div>
          <?php if ($chart['line'] !== ''): ?>
            <svg viewBox="0 0 260 70" class="mt-2 h-[4.375rem] w-full" role="img" aria-label="Daily patient visits over the last 14 days">
              <defs>
                <linearGradient id="lpChart1" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0" stop-color="#0F766E" stop-opacity=".22"/>
                  <stop offset="1" stop-color="#0F766E" stop-opacity="0"/>
                </linearGradient>
              </defs>
              <polygon points="<?= e($chart['area']) ?>" fill="url(#lpChart1)"/>
              <polyline points="<?= e($chart['line']) ?>" fill="none" stroke="#0F766E" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"/>
              <?php foreach ($chart['dots'] as $d): ?>
                <circle cx="<?= $d['x'] ?>" cy="<?= $d['y'] ?>" r="2.4" fill="#0F766E"/>
              <?php endforeach; ?>
            </svg>
            <div class="mt-1 flex justify-between text-[0.59375rem] font-medium text-slate-400">
              <span><?= e(date('j M', strtotime((string) array_key_first($daily)))) ?></span>
              <span><?= e(date('j M', strtotime((string) array_key_last($daily)))) ?></span>
            </div>
          <?php else: ?>
            <p class="mt-4 text-[0.71875rem] text-slate-400">Not enough visits recorded to draw a trend.</p>
          <?php endif; ?>
        </div>

        <div data-reveal style="--reveal-delay:320ms" class="mt-4 grid grid-cols-2 gap-3 lg:absolute lg:bottom-0 lg:right-[4%] lg:mt-0 lg:w-[56%]">
          <div class="lp-float p-3.5">
            <p class="flex items-center gap-1.5 text-[0.65625rem] font-semibold text-slate-500">
              <svg class="h-3.5 w-3.5 text-brand-600" aria-hidden="true"><use href="#i-check-circle"/></svg>Rooms open
            </p>
            <p class="lp-num mt-1 text-[1.625rem]" data-count="<?= (int) $roomsOpen ?>"><?= $roomsOpen ?></p>
          </div>
          <div class="lp-float p-3.5">
            <p class="text-[0.65625rem] font-semibold text-slate-500">Patients today</p>
            <p class="lp-num mt-1 text-[1.625rem] text-brand-700" data-count="<?= (int) $patientsToday ?>"><?= $patientsToday ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>


  <!-- ============================================ what it is built for -->
  <?php /* Not a customer logo wall: there are no customers to name yet, so this
       row states capabilities the software actually has. */ ?>
  <section data-reveal class="lp-band lp-band--paper !py-12">
    <div class="lp-wrap">
      <div class="grid gap-8 md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.6fr)] md:gap-12">
        <div>
          <span class="lp-eyebrow">Working conditions</span>
          <h2 class="lp-h2 mt-3 !text-[1.6rem] sm:!text-[1.9rem]">Built for the room you actually have.</h2>
          <p class="lp-lead mt-3">
            Not the demo room. The one with a shared line, a full waiting area, and a
            counter that has to keep moving.
          </p>
        </div>
        <dl class="lp-ledger" data-reveal-group>
          <?php foreach ($conditions as $i => [$cond, $ans]): ?>
            <div class="lp-ledger-row">
              <span class="lp-ledger-idx hidden md:block"><?= e(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) ?></span>
              <dt class="lp-ledger-cond"><?= e($cond) ?></dt>
              <dd class="lp-ledger-ans"><?= e($ans) ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </div>
    </div>
  </section>

  <!-- ================================================== the challenge -->
  <section id="product" data-reveal class="lp-band lp-band--paper scroll-mt-20 !pt-2">
  <div class="lp-wrap">
    <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)_minmax(0,0.95fr)]">
      <div>
        <span class="lp-eyebrow">01 &mdash; The problem</span>
        <h2 class="lp-h2 mt-3 !text-[1.45rem] sm:!text-[1.6rem]">The OPD challenge is real</h2>
        <ul data-reveal-group class="mt-6 space-y-5">
          <?php foreach ($pains as [$icon, $title, $body]): ?>
            <li class="flex items-start gap-3">
              <span class="lp-ico"><svg class="h-4 w-4" aria-hidden="true"><use href="#i-<?= e($icon) ?>"/></svg></span>
              <div class="min-w-0">
                <p class="text-[0.8125rem] font-bold text-slate-800"><?= e($title) ?></p>
                <p class="mt-0.5 text-[0.71875rem] leading-snug text-slate-500"><?= e($body) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <img src="<?= e(asset('img/landing-opd.jpg')) ?>" width="831" height="661" loading="lazy"
           alt="Isometric view of an outpatient department: reception, waiting area and two consultation rooms"
           class="w-full self-center rounded-2xl mix-blend-multiply">

      <div>
        <span class="lp-eyebrow">02 &mdash; The answer</span>
        <h2 class="lp-h2 lp-teal mt-3 !text-[1.45rem] sm:!text-[1.6rem]">What QueuePilot changes</h2>
        <?php /* Mechanisms, not statistics. We have no measured study to quote a
             "2.5 hours saved" or "30% fewer no-shows" from, so none is claimed. */ ?>
        <div class="mt-6 space-y-3">
          <?php foreach ($answers as [$title, $body]): ?>
            <div class="lp-stat">
              <p class="text-[0.9375rem] font-extrabold tracking-[-0.01em] text-brand-700"><?= e($title) ?></p>
              <p class="mt-1 text-[0.71875rem] leading-snug text-slate-500"><?= e($body) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  </section>

  <!-- ==================================================== how it works -->
  <section id="how" data-reveal class="lp-band lp-band--mist scroll-mt-20">
    <div class="lp-wrap">
      <div class="max-w-2xl">
        <span class="lp-eyebrow">03 &mdash; How it works</span>
        <h2 class="lp-h2 mt-3">One visit. Two sides of the counter.</h2>
        <p class="lp-lead mt-3">
          The same token, kept in step: what reception does, and what the patient
          sees at that moment.
        </p>
      </div>

      <div class="lp-duet-head mt-10 hidden md:grid">
        <p class="lp-duet-label !mb-0">At the desk</p>
        <span aria-hidden="true"></span>
        <p class="lp-duet-label !mb-0">In their hand</p>
      </div>

      <ol class="lp-duet mt-6 md:mt-0" data-reveal-group>
        <?php foreach ($visit as [$n, $moment, $desk, $hand]): ?>
          <li class="lp-duet-row">
            <div class="lp-duet-node order-1 md:order-2">
              <span class="lp-duet-dot"><?= e($n) ?></span>
              <span class="lp-duet-moment"><?= e($moment) ?></span>
            </div>
            <div class="lp-duet-side lp-duet-side--desk order-2 md:order-1">
              <span class="lp-duet-label md:hidden">At the desk</span>
              <p class="lp-duet-text"><?= e($desk) ?></p>
            </div>
            <div class="lp-duet-side lp-duet-side--hand order-3">
              <span class="lp-duet-label md:hidden">In their hand</span>
              <p class="lp-duet-text"><?= e($hand) ?></p>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <!-- ========================================================= pricing -->
  <section id="pricing" data-reveal class="lp-band lp-band--paper scroll-mt-20">
  <div class="lp-wrap">
    <div class="text-center">
      <span class="lp-badge">Simple, transparent pricing</span>
      <h2 class="lp-h2 mt-4">Plans that grow with your clinic.</h2>
      <p class="lp-lead mx-auto mt-2 max-w-xl">
        Every plan includes the live board, patient tracking links and the doctor screen.
        Cancel whenever you like.
      </p>
      <?php /* One currency, because one currency is what we bill in. A USD toggle
           would need an exchange rate we do not have. */ ?>
      <div class="mt-5 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5">
        <span class="rounded-full bg-brand-700 px-3 py-1 text-[0.6875rem] font-bold text-white">LKR</span>
        <span class="pr-1 text-[0.6875rem] font-medium text-slate-500">billed monthly</span>
      </div>
    </div>

    <div data-reveal-group class="mt-9 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <?php foreach ($plans as $plan): ?>
        <?php
          $featured = ($plan['code'] ?? '') === 'professional';
          $price = (float) $plan['price_monthly'];
          $unlimited = (int) $plan['max_doctors'] >= 999;
          // Straight from the plan row: real limits, real description. No
          // feature is listed here that the plan table does not carry.
          $bullets = [
              $unlimited ? 'Unlimited doctors' : 'Up to ' . (int) $plan['max_doctors'] . ' doctor' . ((int) $plan['max_doctors'] === 1 ? '' : 's'),
              $unlimited ? 'Unlimited staff accounts' : (int) $plan['max_staff'] . ' staff accounts',
              $unlimited ? 'Unlimited queues' : (int) $plan['max_queues'] . ' queues',
          ];
          $extra = trim((string) ($plan['features'] ?? ''));
        ?>
        <div class="lp-plan <?= $featured ? 'lp-plan-featured' : '' ?>">
          <?php if ($featured): ?>
            <span class="lp-mono absolute -top-3 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full px-3.5 py-1 text-[0.625rem] font-bold uppercase tracking-[0.12em] text-white" style="background-image:linear-gradient(100deg,#0F766E,#06322E)">Recommended</span>
          <?php endif; ?>
          <p class="text-[0.90625rem] font-extrabold tracking-[-0.01em] text-slate-900"><?= e($plan['name']) ?></p>
          <p class="mt-1 text-[0.71875rem] text-slate-500">
            <?= $unlimited ? 'For multi-branch networks' : ((int) $plan['max_doctors'] === 1 ? 'For a single-doctor practice' : ((int) $plan['max_doctors'] >= 15 ? 'For a busy multi-room clinic' : 'For a growing clinic')) ?>
          </p>
          <p class="mt-4 flex items-baseline gap-1.5">
            <?php if ($price > 0): ?>
              <span class="lp-num text-[1.6875rem]"><?= number_format($price) ?></span>
              <span class="text-[0.65625rem] font-bold text-slate-500">LKR / month</span>
            <?php else: ?>
              <span class="lp-num text-[1.5rem]">Talk to us</span>
            <?php endif; ?>
          </p>
          <ul class="mt-5 flex-1 space-y-2.5">
            <?php foreach ($bullets as $b): ?>
              <li class="lp-check">
                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600" aria-hidden="true"><use href="#i-check"/></svg>
                <span><?= e($b) ?></span>
              </li>
            <?php endforeach; ?>
            <?php if ($extra !== ''): ?>
              <li class="lp-check">
                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600" aria-hidden="true"><use href="#i-check"/></svg>
                <span><?= e($extra) ?></span>
              </li>
            <?php endif; ?>
          </ul>
          <a href="/register" class="<?= $featured ? 'lp-btn' : 'lp-btn-ghost' ?> mt-6 w-full">
            <?= $price > 0 ? 'Start free trial' : 'Start a conversation' ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  </section>

  <!-- ============================================ principles + product -->
  <section data-reveal class="lp-band lp-band--dark">
    <div class="lp-wrap">
      <div class="max-w-2xl">
        <span class="lp-eyebrow">04 &mdash; Why it holds up</span>
        <h2 class="lp-h2 mt-3">Nothing on this screen is decoration.</h2>
        <p class="lp-lead mt-3">
          Every figure is either measured or absent. These are the decisions behind
          the three marked numbers.
        </p>
      </div>

      <div class="mt-10 grid gap-10 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:gap-14">
    <div class="lp-panel self-start px-5 py-6">
      <div class="flex items-center gap-2">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-[0.65625rem] font-bold text-brand-700">
          <span class="h-1.5 w-1.5 rounded-full bg-brand-600"></span>Live demo
        </span>
        <span class="text-[0.75rem] font-bold text-slate-800"><?= e($demoClinic['name'] ?? 'Demo clinic') ?></span>
      </div>
      <div class="mt-4 grid grid-cols-3 gap-3">
        <?php foreach ([
            ['Waiting', (string) $waitingNow, '1'],
            ['Avg wait', $avgWait !== null ? $avgWait . ' min' : '—', '2'],
            ['Rooms open', (string) $roomsOpen, ''],
        ] as [$k, $v, $mk]): ?>
          <div class="rounded-xl bg-slate-50/80 px-3 py-2.5">
            <p class="flex items-center text-[0.625rem] font-semibold text-slate-500">
              <?= e($k) ?><?php if ($mk !== ''): ?><span class="lp-mark" aria-hidden="true"><?= e($mk) ?></span><?php endif; ?>
            </p>
            <p class="lp-num mt-0.5 text-[1.125rem]"><?= e($v) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div>
          <p class="text-[0.71875rem] font-bold text-slate-700">Visits · last 14 days</p>
          <?php if ($chart['line'] !== ''): ?>
            <svg viewBox="0 0 260 70" class="mt-2 h-[4.625rem] w-full" role="img" aria-label="Daily visits over the last 14 days">
              <defs>
                <linearGradient id="lpChart2" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0" stop-color="#0F766E" stop-opacity=".22"/>
                  <stop offset="1" stop-color="#0F766E" stop-opacity="0"/>
                </linearGradient>
              </defs>
              <polygon points="<?= e($chart['area']) ?>" fill="url(#lpChart2)"/>
              <polyline points="<?= e($chart['line']) ?>" fill="none" stroke="#0F766E" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"/>
              <?php foreach ($chart['dots'] as $d): ?>
                <circle cx="<?= $d['x'] ?>" cy="<?= $d['y'] ?>" r="2.2" fill="#0F766E"/>
              <?php endforeach; ?>
            </svg>
          <?php else: ?>
            <p class="mt-3 text-[0.71875rem] text-slate-400">No trend to show yet.</p>
          <?php endif; ?>
          <p class="mt-1 text-[0.625rem] text-slate-400">
            <?= $avgWait !== null
                ? 'Average wait measured from ' . $waitSample . ' finished visits.'
                : 'Not enough finished visits yet to publish an average wait.' ?>
          </p>
        </div>
        <div>
          <p class="flex items-center text-[0.71875rem] font-bold text-slate-700">Waiting by doctor<span class="lp-mark" aria-hidden="true">3</span></p>
          <ul class="mt-2 space-y-2">
            <?php if ($byDoctor === []): ?>
              <li class="text-[0.71875rem] text-slate-400">No session open.</li>
            <?php endif; ?>
            <?php foreach ($byDoctor as $d): ?>
              <li class="flex items-center justify-between gap-2 border-b border-slate-100 pb-1.5">
                <span class="truncate text-[0.6875rem] font-medium text-slate-600"><?= e($d['name']) ?></span>
                <span class="shrink-0 text-[0.6875rem] font-bold tabular text-slate-800"><?= (int) $d['waiting'] ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>

        <ol class="lp-divide-dark self-start">
          <?php foreach ($decisions as $i => [$title, $body]): ?>
            <li class="lp-decision">
              <span class="lp-decision-num"><?= e((string) ($i + 1)) ?></span>
              <div class="min-w-0">
                <p class="text-[0.9375rem] font-bold text-white"><?= e($title) ?></p>
                <p class="mt-1.5 text-[0.8125rem] leading-relaxed" style="color:#A9C6BF"><?= e($body) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </div>
  </section>

  <!-- =================================================== patient view -->
  <section id="patient" data-reveal class="lp-band lp-band--paper scroll-mt-20">
    <div class="lp-wrap">
      <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">

        <div class="flex flex-col items-center gap-7 sm:flex-row sm:items-center sm:gap-9">
          <div class="lp-phone shrink-0 px-3 pb-4 pt-6">
            <p class="text-center text-[0.5625rem] font-semibold text-slate-500">Your token</p>
            <p class="lp-mono mt-1 text-center text-[1.625rem] font-bold leading-none text-brand-700"><?= e($phone['token'] ?? '—') ?></p>
            <p class="mt-2.5 truncate text-center text-[0.59375rem] font-bold text-slate-800"><?= e($phone['doctor'] ?? '—') ?></p>
            <p class="truncate text-center text-[0.53125rem] text-slate-500"><?= e($phone['room'] ?? '') ?></p>
            <p class="mt-2 text-center text-[0.53125rem] font-medium text-slate-500"><?= e($phone['estimate'] ?? '') ?></p>
            <p class="mt-3 rounded-full bg-brand-700 py-1.5 text-center text-[0.53125rem] font-bold text-white">No app needed</p>
          </div>
          <div class="w-full min-w-0">
            <span class="lp-eyebrow">05 &mdash; For the patient</span>
            <h2 class="lp-h2 mt-3 !text-[1.5rem] sm:!text-[1.75rem]">Keep patients in the loop without lifting the phone.</h2>
            <ul class="mt-5 space-y-3">
              <?php foreach (['Real-time token status', 'A wait range, not a fake exact time', 'SMS and WhatsApp updates'] as $txt): ?>
                <li class="lp-check">
                  <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-hidden="true"><use href="#i-check-circle"/></svg>
                  <span><?= e($txt) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>

        <div class="lp-panel p-5 sm:p-6">
          <div class="flex items-center gap-4 sm:gap-5">
            <div class="h-[5.25rem] w-[5.25rem] shrink-0 overflow-hidden rounded-xl border border-slate-200/70 bg-white p-1.5 [&>svg]:h-full [&>svg]:w-full">
              <?= App\Support\QrCode::svg('/demo/board', 3, 2) ?>
            </div>
            <div class="min-w-0">
              <p class="text-[0.9375rem] font-bold" style="color:var(--lp-ink)">Try the patient view</p>
              <p class="mt-1 text-[0.78125rem] leading-snug">Scan to open the demo patient link.</p>
              <a href="/demo/board" class="lp-tap mt-1 text-[0.78125rem] font-bold lp-teal">Or open it here &rarr;</a>
            </div>
          </div>
          <div class="mt-5 grid grid-cols-3 gap-2.5">
            <?php foreach ([
                [$avgWait !== null ? $avgWait . ' min' : '—', 'Avg wait'],
                [$phone ? (string) max(0, $phone['position'] - 1) : '—', 'People ahead'],
                ['0', 'Apps to install'],
            ] as [$v, $k]): ?>
              <div class="rounded-xl px-2 py-3 text-center" style="background:var(--lp-mist)">
                <p class="lp-num text-[1.0625rem]"><?= e($v) ?></p>
                <p class="mt-1 text-[0.625rem] font-medium leading-tight"><?= e($k) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>
    </div>
  </section>

<!-- ============================================================ footer -->
<footer class="lp-footer">
  <div class="lp-wrap py-12">
    <div class="flex flex-wrap items-start justify-between gap-8">
      <div class="max-w-xs">
        <div class="flex items-center gap-2.5">
          <span class="h-8 w-8 shrink-0 rounded-[0.625rem] p-0.5" style="<?= e($markBg) ?>"><?= $mark ?></span>
          <span class="lp-display text-[1.1875rem] font-semibold text-white">QueuePilot</span>
        </div>
        <p class="mt-4 text-[0.75rem] leading-relaxed" style="color:#9FBDB6">
          The queue operating system for Sri Lankan outpatient clinics. Tokens and rooms
          on every public screen &mdash; never a patient name.
        </p>
      </div>
      <nav class="grid w-full grid-cols-2 gap-x-6 sm:flex sm:w-auto sm:flex-wrap sm:gap-x-8 sm:gap-y-1">
        <?php foreach ([['#product', 'Product'], ['#how', 'How it works'], ['#pricing', 'Pricing'], ['#patient', 'Patient view'], ['/demo/board', 'Live demo'], ['/login', 'Sign in'], ['/register', 'Start trial']] as [$href, $label]): ?>
          <a href="<?= e($href) ?>" class="lp-footer-link lp-tap"><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="flex items-center gap-1.5">
        <?php foreach (['EN', 'SI', 'TA'] as $lang): ?>
          <span class="lp-mono flex h-7 items-center rounded-full px-2.5 text-[0.625rem] font-bold" style="background:rgba(255,255,255,.08);color:#CFE0DB"><?= e($lang) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="lp-rail my-9 opacity-25"></div>

    <!-- The wordmark as the closing image: the last thing on the page is the brand. -->
    <div class="flex flex-wrap items-end justify-between gap-4">
      <p class="lp-display select-none text-[3.25rem] leading-none text-white sm:text-[5rem] lg:text-[6.5rem]">
        QueuePilot<span style="color:#E0A63C">.</span>
      </p>
      <p class="lp-mono pb-2 text-[0.6875rem]" style="color:#7FA098">&copy; <?= e(date('Y')) ?> QueuePilot</p>
    </div>
  </div>
</footer>

<script src="<?= e(asset('js/landing.js')) ?>" defer></script>
</body>
</html>
