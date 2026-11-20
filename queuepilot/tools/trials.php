<?php
declare(strict_types=1);

/**
 * Close the trial loop.
 *
 *   php tools/trials.php                 # show what would lapse, change nothing
 *   php tools/trials.php --yes           # actually suspend expired trials
 *   php tools/trials.php --yes --today 2026-12-01
 *
 * Run from cron once a day, next to tools/invoices.php and tools/purge.php.
 *
 * Why this exists: `clinics.trial_ends_on` was written at signup and never
 * compared to anything, so a clinic whose trial ended in March was still
 * issuing tokens in November. A trial date that nothing enforces is not a
 * trial, it is a comment.
 *
 * Expiry suspends rather than deletes, reusing the gate that already works:
 * the clinic lands on the billing page, its data is untouched, and paying
 * clears it. Dry run is the default, like the other two money-adjacent tools.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Services\LifecycleService;
use App\Support\Argv;

$apply = Argv::has('--yes');
$today = Argv::value('--today') ?? date('Y-m-d');
$service = new LifecycleService();

$due = Db::all(
    "SELECT id, name, slug, trial_ends_on FROM clinics
      WHERE billing_status = 'trial'
        AND trial_ends_on IS NOT NULL AND trial_ends_on <> ''
        AND substr(trial_ends_on, 1, 10) < ?
        AND closed_at IS NULL
      ORDER BY trial_ends_on",
    [$today]
);

if ($due === []) {
    echo "No trials have lapsed as of {$today}.\n";
    exit(0);
}

echo ($apply ? 'Expiring' : 'Would expire') . ' ' . count($due) . " trial(s) as of {$today}:\n\n";
foreach ($due as $c) {
    echo sprintf("  #%d  %-28s ended %s\n", (int) $c['id'], (string) $c['name'], (string) $c['trial_ends_on']);
}

if (!$apply) {
    echo "\nDry run. Re-run with --yes to suspend these clinics.\n";
    exit(0);
}

$expired = $service->expireTrials($today);
echo "\nSuspended " . count($expired) . " clinic(s). They can still sign in and pay.\n";
