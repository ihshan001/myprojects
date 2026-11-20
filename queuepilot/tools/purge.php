<?php
/**
 * Data retention sweep.
 *
 * Run daily by cron. Finds clinics whose retention window has expired and
 * purges them. Deliberately NOT reachable from the web: an irreversible
 * delete should require shell access and appear in the operator's log, not
 * be one CSRF-protected click away from any administrator.
 *
 *   php tools/purge.php --dry-run     # show what would go
 *   php tools/purge.php --yes         # actually purge
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Db;
use App\Services\LifecycleService;

// $argv is empty under the php-wasm harness, so this goes through the helper.
// That mattered: with no argument ever arriving, --yes was ignored and the
// purge tool could only run dry, which made a working retention sweep look
// like an unimplemented one.
$dryRun = App\Support\Argv::has('--dry-run') || !App\Support\Argv::has('--yes');

$service = new LifecycleService();
$due = $service->purgeable();

/* Deletion requires a decision, not a date.
 *
 * This tool used to delete every clinic whose retention window had passed.
 * The instruction is the opposite: once a clinic has been closed for 90 days
 * we email, then telephone, find out what actually happened, and *then* a
 * person decides. `purge_approved_at` records that decision and is set only
 * from the admin screen — so this tool's job is to surface the list and to act
 * on it only where somebody has already said yes.
 *
 * A timer that deletes on its own is one unanswered email away from destroying
 * a clinic's records because nobody was at their desk in month four.
 */
$approved = $service->approvedForPurge();
$awaiting = array_values(array_filter($due, static fn (array $c) => $c['purge_approved_at'] === null));

echo '[' . date('Y-m-d H:i:s') . '] ' . count($due) . " clinic(s) past their retention window; "
    . count($approved) . " approved for deletion, " . count($awaiting) . " awaiting contact\n";

if ($awaiting !== []) {
    echo "\n  NOT deleted — a person must contact them and approve deletion:\n";
    foreach ($awaiting as $c) {
        echo sprintf(
            "    clinic %d  %-34s  contact: %s %s  (closed %s)\n",
            (int) $c['id'], $c['name'],
            (string) ($c['email'] ?? 'no email'), (string) ($c['phone'] ?? ''),
            substr((string) $c['purge_after'], 0, 10)
        );
    }
    echo "\n  Approve from the platform admin, or leave them alone. Nothing here is automatic.\n";
}

if ($approved === []) {
    echo "\nNothing approved for deletion. Exiting.\n";
    exit(0);
}

$due = $approved;

foreach ($due as $clinic) {
    echo sprintf(
        "  clinic %d  %-40s  requested %s  purge after %s  approved %s\n",
        (int) $clinic['id'],
        $clinic['name'],
        substr((string) $clinic['closure_requested_at'], 0, 10),
        substr((string) $clinic['purge_after'], 0, 10),
        substr((string) $clinic['purge_approved_at'], 0, 10)
    );

    if ($dryRun) {
        // Count what a real run would remove, so the operator can sanity-check
        // the blast radius without committing.
        foreach (['queue_entries', 'patients', 'appointments', 'doctors', 'users'] as $table) {
            $n = (int) Db::scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE clinic_id = ?', [(int) $clinic['id']]);
            echo "      would delete {$n} {$table}\n";
        }
        continue;
    }

    $result = $service->purge((int) $clinic['id']);
    if ($result['ok']) {
        $summary = [];
        foreach ($result['removed'] as $table => $count) {
            if ($count > 0) {
                $summary[] = "{$table}={$count}";
            }
        }
        echo '      purged: ' . implode(' ', $summary) . "\n";
    } else {
        echo '      refused: ' . $result['error'] . "\n";
    }
}

echo $dryRun ? "\nDry run — nothing was deleted. Add --yes to purge.\n" : "\nDone.\n";
exit(0);
