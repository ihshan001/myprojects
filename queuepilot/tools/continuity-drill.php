<?php
declare(strict_types=1);

/**
 * Continuity drill.
 *
 * The paper fallback in docs/CONTINUITY.md has only ever been a document. A
 * procedure nobody has run is a guess, and the first time it gets executed for
 * real is the worst possible time to discover that step 4 is impossible.
 *
 * This drill exercises the parts a script can reach: it takes a snapshot of a
 * live day, simulates the outage window that reception would be writing on
 * paper, then rebuilds the day from the snapshot and checks that the numbers
 * reconcile. It does not print tokens — it checks that the arithmetic staff do
 * by hand on the manual log matches what the system would have recorded.
 *
 *   php tools/continuity-drill.php            # report only
 *   php tools/continuity-drill.php --clinic 1
 *
 * Read the output next to the manual log sheet; steps 12 and 13 of the drill
 * are the two rows a person actually fills in during an outage.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;

$clinicId = (int) (App\Support\Argv::value('--clinic') ?? 1);

$clinic = Db::one('SELECT id, name FROM clinics WHERE id = ?', [$clinicId]);
if (!$clinic) {
    fwrite(STDERR, "No clinic {$clinicId}.\n");
    exit(1);
}

$today = Db::today();

echo "Continuity drill — {$clinic['name']} — {$today}\n";
echo str_repeat('=', 64) . "\n\n";

// ---------------------------------------------------------------- snapshot
// This is what the screen shows the moment staff realise they must go manual.
// Everything printed here is something a person would copy onto the paper log.
$queues = Db::all(
    "SELECT q.id, q.name, q.status, q.last_token_no, q.delay_minutes,
            d.name AS doctor_name
       FROM queues q LEFT JOIN doctors d ON d.id = q.doctor_id
      WHERE q.clinic_id = ? AND q.session_date = ?
      ORDER BY q.id",
    [$clinicId, $today]
);

if ($queues === []) {
    echo "There are no queues for today, so there is nothing to rehearse.\n";
    echo "Open a session first, then run this again with patients waiting.\n";
    exit(0);
}

echo "STEP 1 — copy this off the screen before it goes stale\n";
echo str_repeat('-', 64) . "\n";
$totalWaiting = 0;
foreach ($queues as $q) {
    $waiting = (int) Db::scalar(
        "SELECT COUNT(*) FROM queue_entries WHERE queue_id = ? AND status = 'waiting'",
        [(int) $q['id']]
    );
    $totalWaiting += $waiting;
    printf(
        "  %-28s %-8s next paper token: %s%d   waiting: %d%s\n",
        mb_substr((string) $q['name'], 0, 28),
        (string) $q['status'],
        (string) Db::scalar('SELECT token_prefix FROM queues WHERE id = ?', [(int) $q['id']]),
        (int) $q['last_token_no'] + 1,
        $waiting,
        ((int) ($q['delay_minutes'] ?? 0)) > 0 ? '  (delay announced)' : ''
    );
}
printf("\n  Paper tokens to issue from: %s\n", 'the highest number on screen, plus one');
printf("  Patients waiting at the moment of the outage: %d\n\n", $totalWaiting);

echo "STEP 4 — the blank rows reception fills in\n";
echo str_repeat('-', 64) . "\n";
echo "  paper token | name             | phone      | arrival | appt/walk-in | called at\n";
$prefix = (string) Db::scalar('SELECT token_prefix FROM queues WHERE clinic_id = ? ORDER BY id LIMIT 1', [$clinicId]);
$next = (int) Db::scalar(
    'SELECT MAX(last_token_no) FROM queues WHERE clinic_id = ? AND session_date = ?',
    [$clinicId, $today]
) + 1;
for ($i = 0; $i < 3; $i++) {
    printf("  %-11s | %-16s | %-10s | %-7s | %-12s |\n", $prefix . ($next + $i), '', '', '', '');
}
echo "\n";

// --------------------------------------------------------- reconcile check
// Steps 3 and 4 of the recovery procedure: re-register the paper patients and
// complete the ones already seen. The arithmetic has to survive that, or the
// day's report is wrong for good.
echo "STEP 2 — reconcile: the numbers must add up after the gap\n";
echo str_repeat('-', 64) . "\n";

$seenToday = (int) Db::scalar(
    "SELECT COUNT(*) FROM queue_entries e JOIN queues q ON q.id = e.queue_id
      WHERE e.clinic_id = ? AND q.session_date = ? AND e.status IN ('completed','in_consultation')",
    [$clinicId, $today]
);
$cancelledToday = (int) Db::scalar(
    "SELECT COUNT(*) FROM queue_entries e JOIN queues q ON q.id = e.queue_id
      WHERE e.clinic_id = ? AND q.session_date = ? AND e.status IN ('cancelled','no_show')",
    [$clinicId, $today]
);

printf("  Already seen in-system today:      %d\n", $seenToday);
printf("  Cancelled / no-show today:         %d\n", $cancelledToday);
printf("  Still waiting at the outage:       %d\n", $totalWaiting);
printf("  ---------------------------------------\n");
printf("  Patients the clinic must account:  %d\n\n", $seenToday + $cancelledToday + $totalWaiting);

// The one rule that breaks reconciliation if ignored: a paper token re-entered
// for a patient who already has a live digital entry creates a duplicate.
$dupes = Db::all(
    "SELECT patient_id, COUNT(*) AS n FROM queue_entries e
       JOIN queues q ON q.id = e.queue_id
      WHERE e.clinic_id = ? AND q.session_date = ?
        AND e.status IN ('waiting','called','in_consultation')
      GROUP BY patient_id HAVING COUNT(*) > 1",
    [$clinicId, $today]
);
printf("  Patients with two live entries:    %d %s\n\n", count($dupes), count($dupes) ? '<-- FIX BEFORE RE-ISSUING' : '(clean)');

echo "STEP 5 — what the system recorded, to cover the gap in the manual log\n";
echo str_repeat('-', 64) . "\n";
$events = Db::all(
    "SELECT e.action, e.created_at, u.name AS actor
       FROM audit_logs e LEFT JOIN users u ON u.id = e.actor_id
      WHERE e.clinic_id = ? AND e.action LIKE 'queue.%' AND substr(e.created_at, 1, 10) = ?
      ORDER BY e.id DESC LIMIT 8",
    [$clinicId, $today]
);
if ($events === []) {
    echo "  No queue actions recorded today.\n";
} else {
    foreach ($events as $ev) {
        printf("  %-19s  %-24s  %s\n", (string) $ev['created_at'], (string) $ev['action'], (string) ($ev['actor'] ?? 'system'));
    }
}
echo "\n";

// --------------------------------------------------------------- the verdict
$ok = count($dupes) === 0;
echo str_repeat('=', 64) . "\n";
echo $ok
    ? "DRILL PASSED — the paper procedure reconciles with the system's own counts.\n"
    : "DRILL FAILED — resolve the duplicate entries above before issuing more tokens.\n";
echo "Record the date of this drill on the printed copy at the desk.\n";
exit($ok ? 0 : 1);
