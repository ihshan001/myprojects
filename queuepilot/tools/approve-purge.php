<?php
declare(strict_types=1);

/**
 * Record — or revoke — a human decision to delete a closed clinic's data.
 *
 * This tool exists because a decision to destroy a patient's queue history
 * should not be a database edit typed from memory at 11pm. It requires a note,
 * it records who decided, and it refuses to act on a clinic that is not
 * actually past its retention window.
 *
 * It does NOT delete anything. `tools/purge.php` does that, separately, and
 * only for clinics this tool has approved. Two commands instead of one because
 * the second one is irreversible.
 *
 *   php tools/approve-purge.php --clinic 42 --note "Spoke to owner 2026-11-06, closed permanently"
 *   php tools/approve-purge.php --clinic 42 --revoke --note "Owner called back, reopening"
 *   php tools/approve-purge.php --list
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Services\LifecycleService;

if (App\Support\Argv::has('--help') || App\Support\Argv::has('-h')) {
    echo <<<TXT
    Approve or revoke deletion of a closed clinic's data.

      --clinic <id>    the clinic
      --note <text>    why the decision was made (10 characters minimum, required)
      --actor <name>   who decided (defaults to the OS user)
      --revoke         take back a previous approval
      --list           show approved and awaiting-contact clinics
      --yes            actually write; without it this only reports

    TXT;
    exit(0);
}

$service = new LifecycleService();
$actor   = (string) (App\Support\Argv::value('--actor') ?: (getenv('USER') ?: 'operator'));

if (App\Support\Argv::has('--list')) {
    $approved = $service->approvedForPurge();
    $due      = $service->purgeable();

    echo "Approved for deletion (tools/purge.php will delete these)\n";
    if ($approved === []) {
        echo "  none\n";
    }
    foreach ($approved as $c) {
        echo "  #{$c['id']}  {$c['name']}\n";
        echo "        approved {$c['purge_approved_at']} by {$c['purge_approved_by']}\n";
    }

    echo "\nClosed and due, but NOT authorised — contact these people\n";
    if ($due === []) {
        echo "  none\n";
    }
    foreach ($due as $c) {
        $days = (int) $c['days_closed'];
        echo "  #{$c['id']}  {$c['name']}  ({$days} days closed)\n";
        echo "        email {$c['owner_email']}   phone " . ($c['owner_phone'] ?: 'none on file') . "\n";
    }
    exit(0);
}

$clinicId = (int) (App\Support\Argv::value('--clinic') ?? 0);
if ($clinicId <= 0) {
    err("Which clinic? Pass --clinic <id>, or --list to see them.\n");
    exit(1);
}

$clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
if ($clinic === null) {
    err("No clinic with id {$clinicId}.\n");
    exit(1);
}

$note = trim((string) (App\Support\Argv::value('--note') ?? ''));
$dry  = !App\Support\Argv::has('--yes');

if (App\Support\Argv::has('--revoke')) {
    if ($dry) {
        echo "[dry run] would revoke the deletion approval for #{$clinicId} {$clinic['name']}.\n";
        echo "          Re-run with --yes to write it.\n";
        exit(0);
    }
    $result = $service->revokePurgeApproval($clinicId, $note !== '' ? $note : 'revoked', $actor);
    echo $result['ok']
        ? "Revoked. #{$clinicId} {$clinic['name']} will not be deleted.\n"
        : "Could not revoke: {$result['error']}\n";
    exit($result['ok'] ? 0 : 1);
}

// Approving. Refuse loudly rather than silently recording a decision that
// purge.php will ignore, because the operator would believe they were done.
if ($note === '') {
    err("A note is required. It becomes the audit trail for deleting this clinic.\n");
    err("  e.g. --note \"Spoke to Dr Perera 2026-11-06: clinic closed permanently\"\n");
    exit(1);
}
if (strlen($note) < 10) {
    err("The note is too short to be useful ({$note}). Say who you spoke to and what they said.\n");
    exit(1);
}

$due = $service->purgeable();
$isDue = false;
foreach ($due as $c) {
    if ((int) $c['id'] === $clinicId) {
        $isDue = true;
        break;
    }
}

if ($dry) {
    echo "[dry run] would approve deletion of #{$clinicId} {$clinic['name']}.\n";
    echo "          note:  {$note}\n";
    echo "          actor: {$actor}\n";
    if (!$isDue) {
        echo "\n  NOTE: this clinic is not currently past its retention window, so\n";
        echo "        tools/purge.php will not act on the approval until it is.\n";
        echo "        Check --list first — you may be approving the wrong clinic.\n";
    }
    echo "\n  Re-run with --yes to record it. Then tools/purge.php --yes deletes.\n";
    exit(0);
}

$result = $service->approvePurge($clinicId, $note, $actor);
if (!$result['ok']) {
    err("Not approved: {$result['error']}\n");
    exit(1);
}

echo "Approved. #{$clinicId} {$clinic['name']} will be deleted by tools/purge.php.\n";
echo "Until that runs, you can still reverse this:\n";
echo "  php tools/approve-purge.php --clinic {$clinicId} --revoke --note \"...\"\n";
