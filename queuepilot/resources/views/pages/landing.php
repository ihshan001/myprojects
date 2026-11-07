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
    if ($n < 2) { return ['line' => '', 'dots' => [], 'max' => 0]; }
    $max = max(1, max($values));
    $pts = [];
    $dots = [];
    foreach (array_values($values) as $i => $v) {
        $x = round($i * ($w / ($n - 1)), 1);
        $y = round($h - ($v / $max) * ($h - 8) - 4, 1);
        $pts[] = $x . ',' . $y;
        $dots[] = ['x' => $x, 'y' => $y];
    }
    return ['line' => implode(' ', $pts), 'dots' => $dots, 'max' => $max];
};
$series = array_values($daily);
$chart = $sparkline($series);

$capabilities = [
    ['shield', 'Tokens, not names'],
    ['card', 'Priced in LKR'],
    ['megaphone', 'SMS & WhatsApp'],
    ['wifi', 'Built for a 3G line'],
    ['phone', 'No patient app'],
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
$steps = [
    ['01', 'qr', 'Check in', 'A phone number is enough. Token in seconds.'],
    ['02', 'queue', 'Live queue', 'Right patient, right room, right order.'],
    ['03', 'stethoscope', 'Consult', 'Call, start, finish. Priority where it is earned.'],
    ['04', 'chart', 'Measure', 'Every visit records its wait and consult time.'],
    ['05', 'bell', 'Follow up', 'Appointment reminders and next-day reports.'],
];
// Product principles, not a customer quote. There is no customer to quote yet,
// so this carousel says what the software actually does instead.
$principles = [
    ['“We show a range, not a made-up number.”', 'How waits are estimated', 'Until there are enough of your own finished visits to measure, an estimate is labelled a rough guide.'],
    ['“A public screen shows tokens and rooms. Never a name.”', 'Privacy by default', 'The board, the patient link and this page carry no patient name, phone number or diagnosis.'],
    ['“The board keeps working when the line drops.”', 'Built for a real clinic', 'It shows last-known state and says so, instead of silently freezing or inventing a queue.'],
];
$navLinks = [['#product', 'Product'], ['#how', 'How it works'], ['#pricing', 'Pricing'], ['#patient', 'Patient view']];
?>
<!DOCTYPE html>
<html lang="en" class="h-full scroll-smooth">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>QueuePilot — live patient queues for Sri Lankan clinics</title>
  <meta name="description" content="Replace the paper token book. QueuePilot gives clinics a live waiting board, patient tracking links, doctor screens and wait-time ranges measured from your own consultations.">
  <meta name="theme-color" content="#0F766E">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
  <link rel="preload" href="/assets/fonts/inter-700.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= e(asset('build/app.css')) ?>">
</head>
<body class="lp">
<?php include QP_ROOT . '/resources/views/partials/icons.php'; ?>

<!-- ============================================================ header -->
<header class="sticky top-0 z-40 border-b border-slate-200/70 bg-white/90 backdrop-blur">
  <div class="lp-wrap flex h-[62px] items-center justify-between gap-4">
    <a href="/" class="flex shrink-0 items-center gap-2.5">
      <span class="flex h-8 w-8 items-center justify-center rounded-lg text-[13px] font-extrabold text-white"
            style="background-image: linear-gradient(140deg,#14807A,#0B5F59)">Q</span>
      <span class="text-[16px] font-extrabold tracking-[-0.02em] text-slate-900">QueuePilot</span>
    </a>
    <nav class="hidden items-center gap-7 md:flex">
      <?php foreach ($navLinks as [$href, $label]): ?>
        <a href="<?= e($href) ?>" class="lp-nav-link"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="flex shrink-0 items-center gap-3">
      <a href="/login" class="hidden text-[13px] font-semibold text-slate-700 hover:text-slate-900 sm:block">Sign in</a>
      <a href="/register" class="lp-btn !px-4 !py-2.5 !text-[12.5px]">Start free trial</a>
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
        <h1 class="lp-h1 mt-5">
          Your clinic deserves<br><span class="lp-teal">more than a queue.</span>
        </h1>
        <p class="lp-lead mt-5 max-w-lg">
          QueuePilot is the queue operating system for outpatient clinics. Issue a token in
          seconds, put every room on one board, and let patients follow their place from
          any phone.
        </p>
        <div class="mt-7 flex flex-wrap gap-3">
          <a href="/register" class="lp-btn">
            Start 14-day free trial
            <svg class="h-4 w-4" aria-hidden="true"><use href="#i-arrow-right"/></svg>
          </a>
          <a href="/demo/board" class="lp-btn-ghost">
            <svg class="h-4 w-4" aria-hidden="true"><use href="#i-play"/></svg>
            Open the live demo
          </a>
        </div>
        <ul class="mt-7 flex flex-wrap gap-x-6 gap-y-2.5">
          <?php foreach (['No card required', '14 days, full access', 'Sinhala · Tamil · English'] as $t): ?>
            <li class="flex items-center gap-2 text-[12.5px] font-medium text-slate-600">
              <svg class="h-4 w-4 shrink-0 text-brand-600" aria-hidden="true"><use href="#i-check-circle"/></svg>
              <?= e($t) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <!-- Floating product cards. Real values, or a dash. -->
      <div class="relative lg:h-[382px]">
        <div class="lp-float relative z-10 w-full max-w-[300px] p-4 lg:absolute lg:left-0 lg:top-0 lg:w-[53%] lg:max-w-none">
          <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-[10.5px] font-bold text-brand-700">
              <span class="h-1.5 w-1.5 rounded-full bg-brand-600"></span>Live demo
            </span>
            <span class="truncate text-[12px] font-bold text-slate-800"><?= e($demoQueue['room_name'] ?? 'Main OPD') ?></span>
          </div>
          <div class="mt-3 rounded-xl border border-slate-200/80 p-3.5">
            <p class="text-[11px] font-medium text-slate-500">Now serving</p>
            <div class="mt-1 flex items-center justify-between gap-2">
              <p class="font-mono text-[30px] font-extrabold leading-none tracking-tight text-brand-700">
                <?= e($nowServing['token_label'] ?? '—') ?>
              </p>
              <svg class="h-5 w-5 text-brand-600" aria-hidden="true"><use href="#i-arrow-right"/></svg>
            </div>
            <p class="mt-2.5 truncate text-[12.5px] font-bold text-slate-800"><?= e($demoQueue['doctor_name'] ?? '—') ?></p>
            <p class="text-[11px] font-medium text-brand-700"><?= e($nowServing ? App\Domain\Queue\QueueStateMachine::meta($nowServing['status'])['label'] : 'No token called') ?></p>
          </div>
          <div class="mt-3.5 grid grid-cols-2 gap-3">
            <div>
              <p class="text-[10.5px] font-medium text-slate-500">Waiting</p>
              <p class="lp-num mt-0.5 text-[24px]"><?= $waitingNow ?></p>
            </div>
            <div>
              <p class="text-[10.5px] font-medium text-slate-500">Avg wait</p>
              <p class="lp-num mt-0.5 text-[24px]">
                <?= $avgWait !== null ? $avgWait : '—' ?><span class="ml-1 text-[11px] font-semibold text-slate-400">min</span>
              </p>
            </div>
          </div>
        </div>

        <div class="lp-float relative z-20 mt-4 w-full max-w-[290px] p-4 lg:absolute lg:right-0 lg:top-[54px] lg:mt-0 lg:w-[48%] lg:max-w-none">
          <p class="text-[12px] font-bold text-slate-800">Visits · last 14 days</p>
          <div class="mt-1 flex items-baseline justify-between">
            <p class="text-[11px] font-medium text-slate-500">Patients</p>
            <p class="lp-num text-[19px]"><?= array_sum($series) ?></p>
          </div>
          <?php if ($chart['line'] !== ''): ?>
            <svg viewBox="0 0 260 70" class="mt-2 h-[70px] w-full" role="img" aria-label="Daily patient visits over the last 14 days">
              <polyline points="<?= e($chart['line']) ?>" fill="none" stroke="#0F766E" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"/>
              <?php foreach ($chart['dots'] as $d): ?>
                <circle cx="<?= $d['x'] ?>" cy="<?= $d['y'] ?>" r="2.4" fill="#0F766E"/>
              <?php endforeach; ?>
            </svg>
            <div class="mt-1 flex justify-between text-[9.5px] font-medium text-slate-400">
              <span><?= e(date('j M', strtotime((string) array_key_first($daily)))) ?></span>
              <span><?= e(date('j M', strtotime((string) array_key_last($daily)))) ?></span>
            </div>
          <?php else: ?>
            <p class="mt-4 text-[11.5px] text-slate-400">Not enough visits recorded to draw a trend.</p>
          <?php endif; ?>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3 lg:absolute lg:bottom-0 lg:right-[4%] lg:mt-0 lg:w-[56%]">
          <div class="lp-float p-3.5">
            <p class="flex items-center gap-1.5 text-[10.5px] font-semibold text-slate-500">
              <svg class="h-3.5 w-3.5 text-brand-600" aria-hidden="true"><use href="#i-check-circle"/></svg>Rooms open
            </p>
            <p class="lp-num mt-1 text-[26px]"><?= $roomsOpen ?></p>
          </div>
          <div class="lp-float p-3.5">
            <p class="text-[10.5px] font-semibold text-slate-500">Patients today</p>
            <p class="lp-num mt-1 text-[26px] text-brand-700"><?= $patientsToday ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="lp-wash">
<div class="lp-wrap space-y-5 pb-14">

  <!-- ============================================ what it is built for -->
  <!-- Not a customer logo wall: there are no customers to name yet, so this
       row states capabilities the software actually has. -->
  <section class="lp-panel px-5 py-6 sm:px-8">
    <p class="text-center text-[12px] font-bold text-slate-700">Built for the way Sri Lankan OPDs actually run</p>
    <ul class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
      <?php foreach ($capabilities as [$icon, $label]): ?>
        <li class="flex items-center justify-center gap-2.5">
          <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700">
            <svg class="h-3.5 w-3.5" aria-hidden="true"><use href="#i-<?= e($icon) ?>"/></svg>
          </span>
          <span class="text-[12px] font-bold text-slate-700"><?= e($label) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- ================================================== the challenge -->
  <section id="product" class="lp-panel scroll-mt-20 px-5 py-8 sm:px-8 sm:py-10">
    <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)_minmax(0,0.95fr)]">
      <div>
        <h2 class="lp-h2 !text-[1.3rem] sm:!text-[1.35rem]">The OPD challenge is real</h2>
        <ul class="mt-6 space-y-5">
          <?php foreach ($pains as [$icon, $title, $body]): ?>
            <li class="flex items-start gap-3">
              <span class="lp-ico"><svg class="h-4 w-4" aria-hidden="true"><use href="#i-<?= e($icon) ?>"/></svg></span>
              <div class="min-w-0">
                <p class="text-[13px] font-bold text-slate-800"><?= e($title) ?></p>
                <p class="mt-0.5 text-[11.5px] leading-snug text-slate-500"><?= e($body) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <img src="<?= e(asset('img/landing-opd.jpg')) ?>" width="831" height="661" loading="lazy"
           alt="Isometric view of an outpatient department: reception, waiting area and two consultation rooms"
           class="w-full self-center rounded-2xl mix-blend-multiply">

      <div>
        <h2 class="lp-h2 lp-teal !text-[1.3rem] sm:!text-[1.35rem]">What QueuePilot changes</h2>
        <!-- Mechanisms, not statistics. We have no measured study to quote a
             "2.5 hours saved" or "30% fewer no-shows" from, so none is claimed. -->
        <div class="mt-6 space-y-3">
          <?php foreach ($answers as [$title, $body]): ?>
            <div class="lp-stat">
              <p class="text-[15px] font-extrabold tracking-[-0.01em] text-brand-700"><?= e($title) ?></p>
              <p class="mt-1 text-[11.5px] leading-snug text-slate-500"><?= e($body) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ==================================================== how it works -->
  <section id="how" class="lp-panel scroll-mt-20 px-5 py-8 sm:px-8 sm:py-10">
    <div class="text-center">
      <h2 class="lp-h2">One queue. Every step. No shouting.</h2>
      <p class="lp-lead mx-auto mt-2 max-w-2xl">
        From the first token to the end-of-day report &mdash; the same record follows the patient.
      </p>
    </div>
    <ol class="mt-9 grid gap-5 sm:grid-cols-2 lg:grid-cols-5 lg:gap-3">
      <?php foreach ($steps as $i => [$n, $icon, $title, $body]): ?>
        <li class="relative rounded-2xl border border-slate-200/70 px-4 pb-4 pt-6">
          <span class="lp-step-num"><?= e($n) ?></span>
          <div class="flex items-start gap-2.5">
            <span class="lp-ico !h-8 !w-8"><svg class="h-4 w-4" aria-hidden="true"><use href="#i-<?= e($icon) ?>"/></svg></span>
            <div class="min-w-0">
              <p class="pt-1 text-[12.5px] font-bold text-slate-800"><?= e($title) ?></p>
              <p class="mt-2 text-[11.5px] leading-relaxed text-slate-500"><?= e($body) ?></p>
            </div>
          </div>
          <?php if ($i < count($steps) - 1): ?>
            <svg class="absolute -right-2.5 top-1/2 hidden h-4 w-4 -translate-y-1/2 text-brand-600 lg:block" aria-hidden="true"><use href="#i-arrow-right"/></svg>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>

  <!-- ========================================================= pricing -->
  <section id="pricing" class="scroll-mt-20 pt-4">
    <div class="text-center">
      <span class="lp-badge">Simple, transparent pricing</span>
      <h2 class="lp-h2 mt-4">Plans that grow with your clinic.</h2>
      <p class="lp-lead mx-auto mt-2 max-w-xl">
        Every plan includes the live board, patient tracking links and the doctor screen.
        Cancel whenever you like.
      </p>
      <!-- One currency, because one currency is what we bill in. A USD toggle
           would need an exchange rate we do not have. -->
      <div class="mt-5 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5">
        <span class="rounded-full bg-brand-700 px-3 py-1 text-[11px] font-bold text-white">LKR</span>
        <span class="pr-1 text-[11px] font-medium text-slate-500">billed monthly</span>
      </div>
    </div>

    <div class="mt-9 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
            <span class="absolute -top-3 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-brand-700 px-3.5 py-1 text-[10.5px] font-bold text-white">Recommended</span>
          <?php endif; ?>
          <p class="text-[14.5px] font-extrabold tracking-[-0.01em] text-slate-900"><?= e($plan['name']) ?></p>
          <p class="mt-1 text-[11.5px] text-slate-500">
            <?= $unlimited ? 'For multi-branch networks' : ((int) $plan['max_doctors'] === 1 ? 'For a single-doctor practice' : ((int) $plan['max_doctors'] >= 15 ? 'For a busy multi-room clinic' : 'For a growing clinic')) ?>
          </p>
          <p class="mt-4 flex items-baseline gap-1.5">
            <?php if ($price > 0): ?>
              <span class="lp-num text-[27px]"><?= number_format($price) ?></span>
              <span class="text-[10.5px] font-bold text-slate-500">LKR / month</span>
            <?php else: ?>
              <span class="lp-num text-[24px]">Talk to us</span>
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
  </section>

  <!-- ============================================ principles + product -->
  <section class="grid gap-4 pt-4 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,0.7fr)_minmax(0,1.5fr)]">
    <!-- A carousel of product principles. No invented customer, no invented
         percentage, no hospital brand borrowed for credibility. -->
    <div class="lp-quote flex flex-col" data-lp-carousel>
      <?php foreach ($principles as $i => [$quote, $label, $body]): ?>
        <div data-lp-slide="<?= $i ?>" <?= $i === 0 ? '' : 'hidden' ?>>
          <p class="text-[19px] font-bold leading-snug tracking-[-0.02em]"><?= e($quote) ?></p>
          <p class="mt-5 text-[12.5px] font-bold text-white"><?= e($label) ?></p>
          <p class="mt-1 text-[11.5px] leading-relaxed text-white/70"><?= e($body) ?></p>
        </div>
      <?php endforeach; ?>
      <div class="mt-auto flex items-center gap-3 pt-6">
        <button type="button" data-lp-prev aria-label="Previous"
                class="flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/25">
          <svg class="h-4 w-4" aria-hidden="true"><use href="#i-arrow-left"/></svg>
        </button>
        <button type="button" data-lp-next aria-label="Next"
                class="flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/25">
          <svg class="h-4 w-4" aria-hidden="true"><use href="#i-arrow-right"/></svg>
        </button>
      </div>
    </div>

    <div class="lp-panel flex flex-col justify-center gap-6 px-5 py-7">
      <?php foreach ([
          ['monitor', 'Live dashboard', 'See your day as it happens.'],
          ['megaphone', 'SMS, WhatsApp, email', 'Channel access follows your plan.'],
          ['chart', 'Reports and exports', 'Waits, consults and no-shows, per doctor.'],
      ] as [$icon, $title, $body]): ?>
        <div class="flex items-start gap-3">
          <span class="lp-ico"><svg class="h-4 w-4" aria-hidden="true"><use href="#i-<?= e($icon) ?>"/></svg></span>
          <div class="min-w-0">
            <p class="text-[12.5px] font-bold text-brand-700"><?= e($title) ?></p>
            <p class="mt-0.5 text-[11.5px] leading-snug text-slate-500"><?= e($body) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="lp-panel px-5 py-6">
      <div class="flex items-center gap-2">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-[10.5px] font-bold text-brand-700">
          <span class="h-1.5 w-1.5 rounded-full bg-brand-600"></span>Live demo
        </span>
        <span class="text-[12px] font-bold text-slate-800"><?= e($demoClinic['name'] ?? 'Demo clinic') ?></span>
      </div>
      <div class="mt-4 grid grid-cols-3 gap-3">
        <?php foreach ([['Waiting', (string) $waitingNow], ['Avg wait', $avgWait !== null ? $avgWait . ' min' : '—'], ['Rooms open', (string) $roomsOpen]] as [$k, $v]): ?>
          <div class="rounded-xl bg-slate-50/80 px-3 py-2.5">
            <p class="text-[10px] font-semibold text-slate-500"><?= e($k) ?></p>
            <p class="lp-num mt-0.5 text-[18px]"><?= e($v) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div>
          <p class="text-[11.5px] font-bold text-slate-700">Visits · last 14 days</p>
          <?php if ($chart['line'] !== ''): ?>
            <svg viewBox="0 0 260 70" class="mt-2 h-[74px] w-full" role="img" aria-label="Daily visits over the last 14 days">
              <polyline points="<?= e($chart['line']) ?>" fill="none" stroke="#0F766E" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"/>
              <?php foreach ($chart['dots'] as $d): ?>
                <circle cx="<?= $d['x'] ?>" cy="<?= $d['y'] ?>" r="2.2" fill="#0F766E"/>
              <?php endforeach; ?>
            </svg>
          <?php else: ?>
            <p class="mt-3 text-[11.5px] text-slate-400">No trend to show yet.</p>
          <?php endif; ?>
          <p class="mt-1 text-[10px] text-slate-400">
            <?= $avgWait !== null
                ? 'Average wait measured from ' . $waitSample . ' finished visits.'
                : 'Not enough finished visits yet to publish an average wait.' ?>
          </p>
        </div>
        <div>
          <p class="text-[11.5px] font-bold text-slate-700">Waiting by doctor</p>
          <ul class="mt-2 space-y-2">
            <?php if ($byDoctor === []): ?>
              <li class="text-[11.5px] text-slate-400">No session open.</li>
            <?php endif; ?>
            <?php foreach ($byDoctor as $d): ?>
              <li class="flex items-center justify-between gap-2 border-b border-slate-100 pb-1.5">
                <span class="truncate text-[11px] font-medium text-slate-600"><?= e($d['name']) ?></span>
                <span class="shrink-0 text-[11px] font-bold tabular text-slate-800"><?= (int) $d['waiting'] ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <!-- =================================================== patient view -->
  <section id="patient" class="lp-panel scroll-mt-20 grid gap-8 px-5 py-8 sm:px-8 lg:grid-cols-2">
    <div class="flex items-center gap-6">
      <div class="lp-phone shrink-0 px-3 pb-4 pt-6">
        <p class="text-center text-[9px] font-semibold text-slate-500">Your token</p>
        <p class="mt-1 text-center font-mono text-[26px] font-extrabold leading-none text-brand-700"><?= e($phone['token'] ?? '—') ?></p>
        <p class="mt-2.5 truncate text-center text-[9.5px] font-bold text-slate-800"><?= e($phone['doctor'] ?? '—') ?></p>
        <p class="truncate text-center text-[8.5px] text-slate-500"><?= e($phone['room'] ?? '') ?></p>
        <p class="mt-2 text-center text-[8.5px] font-medium text-slate-500"><?= e($phone['estimate'] ?? '') ?></p>
        <p class="mt-3 rounded-full bg-brand-700 py-1.5 text-center text-[8.5px] font-bold text-white">No app needed</p>
      </div>
      <div class="min-w-0">
        <h3 class="lp-h3 lp-teal">Keep patients in the loop<br>without lifting the phone.</h3>
        <ul class="mt-4 space-y-2.5">
          <?php foreach (['Real-time token status', 'A wait range, not a fake exact time', 'SMS and WhatsApp updates'] as $t): ?>
            <li class="lp-check">
              <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-hidden="true"><use href="#i-check-circle"/></svg>
              <span><?= e($t) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="flex flex-col justify-center gap-5 rounded-2xl bg-slate-50/70 p-5 sm:flex-row sm:items-center">
      <div class="shrink-0">
        <div class="h-[92px] w-[92px] overflow-hidden rounded-xl bg-white p-1.5 [&>svg]:h-full [&>svg]:w-full">
          <?= App\Support\QrCode::svg('/demo/board', 3, 2) ?>
        </div>
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-[12.5px] font-bold text-slate-800">Try the patient view</p>
        <p class="mt-0.5 text-[11.5px] text-slate-500">Scan to open the demo patient link.</p>
        <div class="mt-3 grid grid-cols-3 gap-2.5">
          <?php foreach ([
              [$avgWait !== null ? $avgWait . ' min' : '—', 'Avg wait'],
              [$phone ? (string) max(0, $phone['position'] - 1) : '—', 'People ahead'],
              ['0', 'Apps to install'],
          ] as [$v, $k]): ?>
            <div class="rounded-xl border border-slate-200/70 bg-white px-2 py-2.5 text-center">
              <p class="lp-num text-[16px]"><?= e($v) ?></p>
              <p class="mt-0.5 text-[9.5px] font-medium text-slate-500"><?= e($k) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>
</div>
</div>

<!-- ============================================================ footer -->
<footer class="border-t border-slate-200/70 bg-white">
  <div class="lp-wrap flex flex-wrap items-center justify-between gap-x-8 gap-y-4 py-7">
    <div class="flex items-center gap-2.5">
      <span class="flex h-7 w-7 items-center justify-center rounded-lg text-[12px] font-extrabold text-white"
            style="background-image: linear-gradient(140deg,#14807A,#0B5F59)">Q</span>
      <span class="text-[14px] font-extrabold tracking-[-0.02em] text-slate-900">QueuePilot</span>
    </div>
    <p class="text-[11.5px] text-slate-500">&copy; <?= e(date('Y')) ?> QueuePilot. All rights reserved.</p>
    <nav class="flex flex-wrap items-center gap-x-6 gap-y-2">
      <?php foreach ([['#product', 'Product'], ['#pricing', 'Pricing'], ['/demo/board', 'Live demo'], ['/login', 'Sign in'], ['/register', 'Start trial']] as [$href, $label]): ?>
        <a href="<?= e($href) ?>" class="text-[11.5px] font-semibold text-slate-500 hover:text-slate-900"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="flex items-center gap-1.5">
      <?php foreach (['EN', 'SI', 'TA'] as $lang): ?>
        <span class="flex h-7 items-center rounded-full bg-slate-100 px-2.5 text-[10px] font-bold text-slate-600"><?= e($lang) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</footer>

<script src="<?= e(asset('js/landing.js')) ?>" defer></script>
</body>
</html>
