<?php
declare(strict_types=1);

/**
 * The five-day warning, and suspension when it runs out.
 *
 *   php tools/dunning.php                 # show what would happen
 *   php tools/dunning.php --yes           # start grace / suspend as due
 *   php tools/dunning.php --yes --today 2026-12-01
 *
 * Run daily. This is the job that makes "we allow five more days, then
 * suspend" true rather than a sentence in a policy document.
 *
 * Two passes, in this order:
 *
 *   1. **Start** the warning clock for any active clinic whose renewal date has
 *      passed with no confirmed payment. The deadline is written as a date, so
 *      a missed run does not buy the clinic extra time.
 *   2. **Suspend** any clinic whose deadline has passed.
 *
 * Doing it in two passes in one invocation means a clinic that has been unpaid
 * for a month transitions correctly on the first run after deploy, without
 * anybody having to reason about backfill.
 *
 * Dry run is the default, like invoices.php, purge.php and trials.php: this
 * tool can take a clinic's queue offline, and doing that unasked is not helpful.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Services\LifecycleService;
use App\Support\Argv;

$apply = Argv::has('--yes');
$today = Argv::value('--today') ?? date('Y-m-d');
$service = new LifecycleService();

/* ---------------------------------------------------------- 1. start grace */

$toWarn = Db::all(
    "SELECT c.id, c.name, c.renews_on, p.price_monthly
       FROM clinics c
       LEFT JOIN plans p ON p.id = c.plan_id
      WHERE c.billing_status = 'active'
        AND c.grace_until IS NULL
        AND c.renews_on IS NOT NULL AND c.renews_on <> ''
        AND substr(c.renews_on, 1, 10) <= ?
        AND c.closed_at IS NULL
        AND NOT EXISTS (
              SELECT 1 FROM invoices i
               WHERE i.clinic_id = c.id
                 AND i.status IN ('paid', 'verified')
                 AND substr(i.period_from, 1, 10) >= substr(c.renews_on, 1, 7) || '-01'
            )
      ORDER BY c.renews_on",
    [$today]
);

/* -------------------------------------------------------- 2. end of grace */

$toSuspend = Db::all(
    "SELECT id, name, grace_until FROM clinics
      WHERE billing_status = 'active'
        AND grace_until IS NOT NULL
        AND substr(grace_until, 1, 10) < ?
        AND closed_at IS NULL
      ORDER BY grace_until",
    [$today]
);

if ($toWarn === [] && $toSuspend === []) {
    echo "Nothing overdue as of {$today}.\n";
    exit(0);
}

if ($toWarn !== []) {
    echo ($apply ? 'Warning' : 'Would warn') . ' ' . count($toWarn)
        . ' clinic(s) — ' . LifecycleService::GRACE_DAYS . " days before suspension:\n\n";
    foreach ($toWarn as $c) {
        $until = date('Y-m-d', strtotime($today . ' +' . LifecycleService::GRACE_DAYS . ' days'));
        echo sprintf("  #%d  %-28s due %s   -> %s\n",
            (int) $c['id'], (string) $c['name'],
            substr((string) $c['renews_on'], 0, 10), $until);
    }
    echo "\n";
}

if ($toSuspend !== []) {
    echo ($apply ? 'Suspending' : 'Would suspend') . ' ' . count($toSuspend)
        . " clinic(s) whose warning period has ended:\n\n";
    foreach ($toSuspend as $c) {
        echo sprintf("  #%d  %-28s deadline %s\n",
            (int) $c['id'], (string) $c['name'], (string) $c['grace_until']);
    }
    echo "\n";
}

if (!$apply) {
    echo "Dry run. Re-run with --yes to apply.\n";
    exit(0);
}

foreach ($toWarn as $c) {
    // Anchored to the due date, not to today. Passing $today meant that a
    // dunning run which was late — a missed cron, a weekend, a holiday — gave
    // the clinic five days from the moment the job noticed, so a clinic could
    // sit unpaid for a fortnight and still be inside a "five day" grace. The
    // owner asked for the deadline to be the due date; a scheduled job must
    // not move the deadline by failing to run.
    $dueOn = substr((string) $c['renews_on'], 0, 10);
    $service->beginGrace((int) $c['id'], $dueOn);
}
$suspended = $service->suspendOverdue($today);

echo "Started grace for " . count($toWarn) . ", suspended " . count($suspended) . ".\n";
echo "Suspended clinics can still sign in and reach the billing page.\n";
