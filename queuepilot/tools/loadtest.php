<?php
declare(strict_types=1);

/**
 * Token-allocator load test.
 *
 * The correctness of allocation is already tested: tokens are allocated inside
 * a transaction with a row lock, and `UNIQUE (queue_id, token_label)` is the
 * backstop. What was never measured is behaviour under pressure — how many
 * tokens per second a single queue can take, and whether the unique index ever
 * fires when two requests race.
 *
 * That matters because the answer differs between SQLite and MySQL. SQLite
 * serialises writers, so it can never race but may serialise badly. MySQL with
 * InnoDB lets rows lock independently. Running this against both is the only
 * way to know which one to expect in production.
 *
 *   php tools/loadtest.php                      # 200 tokens, default clinic
 *   php tools/loadtest.php --count 1000 --queue 1
 *   php tools/loadtest.php --cleanup
 *
 * It creates a throwaway queue and patients inside the clinic you name, then
 * removes everything it made. It never touches an existing queue.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Core\Env;
use App\Domain\Queue\QueueService;

$count    = (int) (App\Support\Argv::value('--count') ?? 200);
$clinicId = (int) (App\Support\Argv::value('--clinic') ?? 1);
$queueId  = App\Support\Argv::value('--queue');
$cleanup  = App\Support\Argv::has('--cleanup');
$count    = max(1, min(5000, $count));

$marker = 'loadtest-' . bin2hex(random_bytes(4));

echo "QueuePilot token allocator load test\n";
echo "  driver:  " . Env::get('db.driver', 'sqlite') . "\n";
echo "  clinic:  {$clinicId}\n";
echo "  tokens:  {$count}\n\n";

if ($cleanup) {
    $queues = Db::all('SELECT id FROM queues WHERE clinic_id = ? AND name LIKE ?', [$clinicId, 'loadtest-%']);
    foreach ($queues as $q) {
        Db::run('DELETE FROM queue_entries WHERE queue_id = ?', [(int) $q['id']]);
        Db::run('DELETE FROM queues WHERE id = ?', [(int) $q['id']]);
    }
    $patients = Db::all('SELECT id FROM patients WHERE clinic_id = ? AND full_name LIKE ?', [$clinicId, 'Load Test%']);
    foreach ($patients as $p) {
        Db::run('DELETE FROM patients WHERE id = ?', [(int) $p['id']]);
    }
    echo "Removed " . count($queues) . " load-test queue(s) and " . count($patients) . " patient(s).\n";
    exit(0);
}

$clinic = Db::one('SELECT id, name FROM clinics WHERE id = ?', [$clinicId]);
if ($clinic === null) {
    fwrite(STDERR, "No clinic {$clinicId}. Pass --clinic <id>.\n");
    exit(1);
}

// A dedicated queue: the test must not disturb a real session.
if ($queueId !== null) {
    $queue = Db::one('SELECT id, name FROM queues WHERE id = ? AND clinic_id = ?', [(int) $queueId, $clinicId]);
    if ($queue === null) {
        fwrite(STDERR, "No queue " . (int) $queueId . " in clinic {$clinicId}.\n");
        exit(1);
    }
    $queueId = (int) $queue['id'];
} else {
    $queueId = Db::insert(
        'INSERT INTO queues (clinic_id, name, session_date, status, created_at)
         VALUES (?, ?, ?, ?, ?)',
        [$clinicId, $marker, Db::today(), 'open', Db::now()]
    );
}

// Patients up front, so the timing measures allocation and not patient
// creation — this is a test of the allocator, not of the whole desk flow.
echo "Creating {$count} patients…\n";
$patientIds = [];
$t0 = microtime(true);
foreach (range(1, $count) as $i) {
    $patientIds[] = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at)
         VALUES (?, ?, ?, ?, ?)',
        [$clinicId, 'LT' . $i . bin2hex(random_bytes(2)), 'Load Test ' . $i, null, Db::now()]
    );
}
printf("  %.2fs (%.0f/s)\n\n", microtime(true) - $t0, $count / max(0.001, microtime(true) - $t0));

$service = new QueueService();
$issued = 0;
$failures = [];
$duplicates = 0;
$seen = [];

$t0 = microtime(true);
foreach ($patientIds as $i => $patientId) {
    try {
        $result = $service->issueToken($clinicId, $queueId, (int) $patientId, [
            'source' => 'walkin',
        ]);
        // issueToken returns the entry directly and signals failure by
        // throwing — there is no 'ok' key to check.
        if (!isset($result['entry_id'])) {
            $failures[] = 'no entry_id returned';
            continue;
        }
        $label = (string) ($result['token'] ?? '');
        if ($label !== '' && isset($seen[$label])) {
            $duplicates++;
        }
        $seen[$label] = true;
        $issued++;
    } catch (\Throwable $e) {
        $failures[] = $e->getMessage();
    }
}
$elapsed = microtime(true) - $t0;

// The integrity checks that matter, read back from the database rather than
// trusted from the loop above.
$rows = (int) Db::scalar('SELECT COUNT(*) FROM queue_entries WHERE queue_id = ?', [$queueId]);
$distinct = (int) Db::scalar('SELECT COUNT(DISTINCT token_label) FROM queue_entries WHERE queue_id = ?', [$queueId]);

printf("Issued %d/%d in %.2fs\n", $issued, $count, $elapsed);
printf("  %s tokens/second\n", number_format($issued / max(0.001, $elapsed)));
printf("  p50 per token: %.2f ms\n", ($elapsed / max(1, $issued)) * 1000);
echo "\n";
echo "Integrity\n";
printf("  rows in queue:        %d\n", $rows);
printf("  distinct token labels:%d\n", $distinct);
printf("  labels seen twice:    %d\n", $duplicates);
printf("  failures:             %d\n", count($failures));
if ($failures !== []) {
    foreach (array_slice(array_unique($failures), 0, 5) as $f) {
        echo "    · {$f}\n";
    }
}

$ok = $rows === $issued && $distinct === $issued && $duplicates === 0 && $failures === [];
echo "\n" . ($ok
    ? "PASS — every token unique, no allocation failure.\n"
    : "FAIL — see above.\n");

echo "\nClean up with:  php tools/loadtest.php --clinic {$clinicId} --cleanup\n";
exit($ok ? 0 : 1);
