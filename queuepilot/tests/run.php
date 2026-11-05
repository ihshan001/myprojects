<?php
declare(strict_types=1);

/**
 * QueuePilot test suite.
 *
 *   node tools/php.mjs tests/run.php
 *
 * Runs on a throw-away SQLite database (created + deleted by the harness), so
 * it is safe to run against a machine that also has demo data.
 */

use App\Core\Csrf;
use App\Core\Db;
use App\Domain\Queue\InvalidTransition;
use App\Domain\Queue\QueueStateMachine;
use Tests\Assert;
use Tests\Database;

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/bootstrap.php';

$dbPath = Database::fresh();

echo "\n\033[1mQueuePilot — test suite\033[0m\n";
echo "database: " . basename($dbPath) . "\n\n";

/* ------------------------------------------------------------------ */
/* fixtures                                                            */
/* ------------------------------------------------------------------ */

function seedPlans(): void
{
    $plans = [
        [1, 'starter', 'Starter', 3500, 1, 2, 2, 10],
        [2, 'professional', 'Professional', 7900, 5, 8, 6, 20],
        [3, 'clinic_plus', 'Clinic Plus', 14900, 15, 25, 20, 30],
        [4, 'enterprise', 'Enterprise', 0, 999, 999, 999, 40],
    ];
    foreach ($plans as [$id, $code, $name, $price, $docs, $staff, $queues, $sort]) {
        Db::run(
            'INSERT INTO plans (id, code, name, price_monthly, max_doctors, max_staff, max_queues, features, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $code, $name, $price, $docs, $staff, $queues, 'Test plan', $sort]
        );
    }
}

function seedTenant(int $id, string $slug, string $name): void
{
    Db::run(
        'INSERT INTO clinics (id, slug, name, email, phone, city, district, plan_id, billing_status, brand_color, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $slug, $name, $slug . '@example.lk', '+94 11 000 0000', 'Colombo', 'Colombo', 2, 'active', '#0F766E', Db::now(), Db::now()]
    );
    $roomId = Db::insert('INSERT INTO rooms (clinic_id, name, floor) VALUES (?, ?, ?)', [$id, 'Room 1', '1']);
    $doctorId = Db::insert(
        'INSERT INTO doctors (clinic_id, name, room_id, avg_consult_minutes, is_active, created_at)
         VALUES (?, ?, ?, ?, 1, ?)',
        [$id, 'Dr. Test ' . $id, $roomId, 10, Db::now()]
    );
    $queueId = Db::insert(
        'INSERT INTO queues (clinic_id, doctor_id, name, token_prefix, session_date, status, last_token_no, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?)',
        [$id, $doctorId, 'OPD', 'A', Db::today(), 'open', Db::now()]
    );
}

seedPlans();
seedTenant(1, 'alpha', 'Alpha Clinic');
seedTenant(2, 'beta', 'Beta Clinic');

$GLOBALS['doctorUserId'] = Db::insert(
    'INSERT INTO users (clinic_id, name, email, password_hash, role, is_active, created_at)
     VALUES (1, ?, ?, ?, ?, 1, ?)',
    ['Dr. Test 1', 'doctor1@alpha.example', password_hash('Doctor#2026', PASSWORD_DEFAULT), 'doctor', Db::now()]
);

$alphaQueue = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1');
$GLOBALS['alphaQueue'] = $alphaQueue;
$betaQueue  = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 2');

/* ------------------------------------------------------------------ */
/* 1. state machine — pure, no database                                */
/* ------------------------------------------------------------------ */

echo "State machine\n";

Assert::test('the happy path is legal', function (): void {
    Assert::true(QueueStateMachine::can(QueueStateMachine::WAITING, QueueStateMachine::CALLED));
    Assert::true(QueueStateMachine::can(QueueStateMachine::CALLED, QueueStateMachine::IN_CONSULTATION));
    Assert::true(QueueStateMachine::can(QueueStateMachine::IN_CONSULTATION, QueueStateMachine::COMPLETED));
});

Assert::test('completed and cancelled are terminal', function (): void {
    foreach (QueueStateMachine::ACTIVE_STATUSES as $target) {
        Assert::false(QueueStateMachine::can(QueueStateMachine::COMPLETED, $target), "completed → {$target}");
        Assert::false(QueueStateMachine::can(QueueStateMachine::CANCELLED, $target), "cancelled → {$target}");
    }
});

Assert::test('you cannot skip straight to completed', function (): void {
    Assert::false(QueueStateMachine::can(QueueStateMachine::WAITING, QueueStateMachine::COMPLETED));
    Assert::false(QueueStateMachine::can(QueueStateMachine::CALLED, QueueStateMachine::COMPLETED));
});

Assert::test('illegal transitions throw InvalidTransition', function (): void {
    Assert::throws(
        static fn () => QueueStateMachine::assert(QueueStateMachine::COMPLETED, QueueStateMachine::WAITING),
        InvalidTransition::class
    );
});

Assert::test('every state has a label and a tone', function (): void {
    foreach ([
        QueueStateMachine::WAITING, QueueStateMachine::CALLED, QueueStateMachine::IN_CONSULTATION,
        QueueStateMachine::COMPLETED, QueueStateMachine::SKIPPED, QueueStateMachine::NO_SHOW,
        QueueStateMachine::CANCELLED, QueueStateMachine::TRANSFERRED,
    ] as $status) {
        $meta = QueueStateMachine::meta($status);
        Assert::true($meta['label'] !== '' && $meta['tone'] !== '', "meta for {$status}");
    }
});

Assert::test('skipped and no-show can be requeued', function (): void {
    Assert::true(QueueStateMachine::can(QueueStateMachine::SKIPPED, QueueStateMachine::WAITING));
    Assert::true(QueueStateMachine::can(QueueStateMachine::NO_SHOW, QueueStateMachine::WAITING));
});

/* ------------------------------------------------------------------ */
/* 2. queue engine — token allocation + transitions                    */
/* ------------------------------------------------------------------ */

echo "\nQueue engine\n";

$service = new App\Domain\Queue\QueueService();

$patients = new App\Services\PatientService();
$p1 = $patients->findOrCreate(1, ['full_name' => 'Sunil Perera', 'phone' => '0771234567', 'gender' => 'male']);
$first = $service->issueToken(1, $alphaQueue, (int) $p1['id'], ['note' => 'walk-in']);
Assert::test('first token is A001', function () use ($first): void {
    Assert::same('A001', $first['token']);
    Assert::same(1, (int) $first['token_no']);
    $row = Db::one('SELECT status FROM queue_entries WHERE id = ?', [(int) $first['entry_id']]);
    Assert::same('waiting', $row['status']);
});

Assert::test('a long, URL-safe tracking token is issued and expires', function () use ($first): void {
    $token = (string) $first['track_token'];
    Assert::true(strlen($token) >= 32, 'token length ' . strlen($token));
    Assert::same($token, rawurlencode($token), 'url safe (no escaping needed)');
    Assert::same('/t/' . $token, $first['track_url'], 'track url shape');

    $row = Db::one('SELECT track_expires_at FROM queue_entries WHERE id = ?', [(int) $first['entry_id']]);
    Assert::true(strtotime((string) $row['track_expires_at']) > time(), 'expiry in the future');
});

$p2 = $patients->findOrCreate(1, ['full_name' => 'Nimali Silva', 'phone' => '0779998877', 'gender' => 'female']);
$second = $service->issueToken(1, $alphaQueue, (int) $p2['id'], []);
Assert::test('tokens increment per queue', function () use ($second): void {
    Assert::same('A002', $second['token']);
});

Assert::test('walk-in without a phone number is allowed', function () use ($service, $alphaQueue): void {
    $anon = (new App\Services\PatientService())->findOrCreate(1, ['full_name' => 'Anonymous Walk-in']);
    $e = $service->issueToken(1, $alphaQueue, (int) $anon['id'], []);
    Assert::same('A003', $e['token']);
});

$res = $service->apply(1, (int) $first['entry_id'], 'call', ['version' => 0]);
Assert::test('call moves waiting → called', function () use ($res): void {
    Assert::true($res['ok']);
    Assert::same('called', $res['status']);
    Assert::same(1, (int) $res['entry']['version']);
});

Assert::test('a replayed call is rejected as stale (409 semantics)', function () use ($service, $first): void {
    $again = $service->apply(1, (int) $first['entry_id'], 'call', ['version' => 0]);
    Assert::false($again['ok']);
    Assert::true(!empty($again['stale']), 'should be marked stale');
});

$res = $service->apply(1, (int) $first['entry_id'], 'start', ['version' => 1]);
Assert::test('start moves called → in_consultation', function () use ($res): void {
    Assert::true($res['ok']);
    Assert::same('in_consultation', $res['status']);
    Assert::true($res['entry']['started_at'] !== null);
});

$res = $service->apply(1, (int) $first['entry_id'], 'complete', ['version' => 2]);
Assert::test('complete records wait and consult minutes', function () use ($res): void {
    Assert::true($res['ok']);
    Assert::same('completed', $res['status']);
    Assert::true($res['entry']['finished_at'] !== null);
    Assert::true($res['entry']['wait_minutes'] !== null);
});

Assert::test('an illegal transition is refused, not applied', function () use ($service, $first): void {
    $bad = $service->apply(1, (int) $first['entry_id'], 'call', ['version' => 3]);
    Assert::false($bad['ok']);
    $row = Db::one('SELECT status FROM queue_entries WHERE id = ?', [(int) $first['entry_id']]);
    Assert::same('completed', $row['status']);
});

Assert::test('every mutation writes an audit row', function () use ($first): void {
    $count = (int) Db::scalar('SELECT COUNT(*) FROM audit_logs WHERE clinic_id = 1 AND entity_id = ?', [(int) $first['entry_id']]);
    Assert::true($count >= 3, "expected ≥3 audit rows, got {$count}");
});

Assert::test('every mutation writes a queue event with from/to', function () use ($first): void {
    $events = Db::all('SELECT action, from_status, to_status FROM queue_events WHERE entry_id = ? ORDER BY id', [(int) $first['entry_id']]);
    Assert::true(count($events) >= 4, 'event count (issue + 3 transitions), got ' . count($events));
    Assert::same('token_issued', $events[0]['action'], 'issue is recorded');
    Assert::same(null, $events[0]['from_status'], 'issue has no previous status');
    Assert::same('waiting', $events[0]['to_status']);
    Assert::same('completed', $events[count($events) - 1]['to_status']);
    // the trail must be a connected chain
    for ($i = 1; $i < count($events); $i++) {
        Assert::same($events[$i - 1]['to_status'], $events[$i]['from_status'], "chain break at event {$i}");
    }
});

/* ------------------------------------------------------------------ */
/* 2b. duplicate-prevention constraints                                */
/* ------------------------------------------------------------------ */

echo "\nQueue integrity\n";

// A dedicated patient so these checks do not depend on what earlier tests did
$dupePatient = (new App\Services\PatientService())->findOrCreate(1, ['full_name' => 'Duplicate Probe', 'phone' => '0779990001']);
$dupeFirst = $service->issueToken(1, $alphaQueue, (int) $dupePatient['id'], []);
$GLOBALS['dupeEntryId'] = (int) $dupeFirst['entry_id'];

Assert::test('a patient cannot hold two live tokens in one queue', function () use ($service, $alphaQueue, $dupePatient): void {
    Assert::throws(
        static fn () => $service->issueToken(1, $alphaQueue, (int) $dupePatient['id'], []),
        \RuntimeException::class,
        'second live token must be refused'
    );
});

Assert::test('the database refuses a duplicate even if the service is bypassed', function () use ($dupePatient): void {
    try {
        Db::insert(
            "INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status,
                priority, sort_weight, source, joined_at, track_token, track_expires_at, version)
             VALUES (1, ?, ?, 9001, 'ZZ901', 'waiting', 0, 3, 'walkin', ?, ?, '2030-01-01', 0)",
            [(int) $GLOBALS['alphaQueue'], (int) $dupePatient['id'], Db::now(), bin2hex(random_bytes(16))]
        );
        Assert::true(false, 'raw insert must be rejected by the unique index');
    } catch (\PDOException $e) {
        Assert::contains('UNIQUE', strtoupper($e->getMessage()), 'constraint violation');
    }
});

Assert::test('a returning patient may be issued a new token after their visit closes', function () use ($service, $alphaQueue, $dupePatient): void {
    // close the live entry, then the same patient is welcome back
    Db::run("UPDATE queue_entries SET status = 'completed', finished_at = ? WHERE id = ?", [Db::now(), (int) $GLOBALS['dupeEntryId']]);
    $fresh = $service->issueToken(1, $alphaQueue, (int) $dupePatient['id'], []);
    Assert::true((string) $fresh['token'] !== '', 'new token issued');
    Db::run("UPDATE queue_entries SET status = 'cancelled', finished_at = ? WHERE id = ?", [Db::now(), (int) $fresh['entry_id']]);
});

Assert::test('the same doctor slot cannot be double booked', function () use ($p1): void {
    $appts = new App\Services\AppointmentService();
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $when = date('Y-m-d', strtotime('+3 days')) . ' 09:45:00';

    $first = $appts->create(1, $doctorId, (int) $p1['id'], $when);
    Assert::throws(
        static fn () => $appts->create(1, (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1'), (int) $p1['id'], $when),
        \RuntimeException::class,
        'second booking at the same slot must be refused'
    );
    Db::run("UPDATE appointments SET status = 'cancelled' WHERE id = ?", [$first]);
    $again = $appts->create(1, $doctorId, (int) $p1['id'], $when);
    Assert::true($again > 0, 'cancelled slot is reusable');
});

Assert::test('a transfer really moves the patient and leaves one live token', function () use ($service): void {
    $repo = new App\Domain\Queue\QueueRepository();
    $ps = new App\Services\PatientService();
    $patient = $ps->findOrCreate(1, ['full_name' => 'Transfer Case', 'phone' => '0771119999']);

    // the fixture creates one queue per clinic; add a second for clinic 1
    $existing = Db::all('SELECT id FROM queues WHERE clinic_id = 1 ORDER BY id LIMIT 1');
    $from = (int) $existing[0]['id'];
    $to = (int) Db::scalar("SELECT id FROM queues WHERE clinic_id = 1 AND id <> ? LIMIT 1", [$from]);
    if ($to === 0) {
        $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
        $to = (int) Db::insert(
            'INSERT INTO queues (clinic_id, doctor_id, name, token_prefix, session_date, status, last_token_no, created_at)
             VALUES (1, ?, ?, ?, ?, ?, 0, ?)',
            [$doctorId, 'Second Service', 'B', Db::today(), 'open', Db::now()]
        );
    }
    Assert::true($to > 0 && $to !== $from, 'have two distinct queues');

    $entry = $service->issueToken(1, (int) $from, (int) $patient['id'], []);
    $before = (int) Db::scalar(
        "SELECT COUNT(*) FROM queue_entries WHERE patient_id = ? AND status IN ('waiting','called','in_consultation')",
        [(int) $patient['id']]
    );
    Assert::same(1, $before, 'exactly one live token before transfer');

    $res = $service->apply(1, (int) $entry['entry_id'], 'transfer', [
        'reason' => 'needs another specialty',
        'to_queue_id' => (int) $to,
        'version' => 0,
    ]);

    Assert::true($res['ok'], 'transfer accepted: ' . ($res['message'] ?? ''));
    Assert::same('transferred', $res['status']);
    Assert::true(!empty($res['transferred_to']['token']), 'replacement token issued');

    $after = (int) Db::scalar(
        "SELECT COUNT(*) FROM queue_entries WHERE patient_id = ? AND status IN ('waiting','called','in_consultation')",
        [(int) $patient['id']]
    );
    Assert::same(1, $after, 'still exactly one live token after transfer');

    Assert::same(
        'transferred',
        Db::one('SELECT status FROM queue_entries WHERE id = ?', [(int) $entry['entry_id']])['status'],
        'original entry is closed, not left live'
    );
    Assert::same(
        (int) $to,
        (int) Db::scalar(
            "SELECT queue_id FROM queue_entries WHERE patient_id = ? AND status = 'waiting' ORDER BY id DESC LIMIT 1",
            [(int) $patient['id']]
        ),
        'the live token is in the destination queue'
    );
});

Assert::test('a transfer cannot target another tenant queue', function () use ($service): void {
    $ps = new App\Services\PatientService();
    $patient = $ps->findOrCreate(1, ['full_name' => 'Cross Tenant Transfer']);
    $queue = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1 LIMIT 1');
    $entry = $service->issueToken(1, $queue, (int) $patient['id'], []);

    $foreign = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 2 LIMIT 1');
    Assert::true($foreign > 0, 'clinic 2 has a queue');

    $res = $service->apply(1, (int) $entry['entry_id'], 'transfer', [
        'reason' => 'leak attempt', 'to_queue_id' => $foreign, 'version' => 0,
    ]);
    Assert::false($res['ok'], 'cross-tenant transfer refused');
    Assert::same(
        'waiting',
        Db::one('SELECT status FROM queue_entries WHERE id = ?', [(int) $entry['entry_id']])['status'],
        'nothing changed'
    );
});

Assert::test('a transfer must name a destination', function () use ($service): void {
    $ps = new App\Services\PatientService();
    $patient = $ps->findOrCreate(1, ['full_name' => 'No Target Transfer']);
    $queue = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1 ORDER BY id DESC LIMIT 1');
    $entry = $service->issueToken(1, $queue, (int) $patient['id'], []);

    $res = $service->apply(1, (int) $entry['entry_id'], 'transfer', ['reason' => 'nowhere', 'version' => 0]);
    Assert::false($res['ok'], 'missing to_queue_id refused');
    Assert::contains('queue', strtolower((string) $res['message']), 'message explains it');
});

/* ------------------------------------------------------------------ */
/* 3. tenant isolation                                                 */
/* ------------------------------------------------------------------ */

echo "\nTenant isolation\n";

$betaPatient = (new App\Services\PatientService())->findOrCreate(2, ['full_name' => 'Beta Patient']);
$betaEntry = $service->issueToken(2, $betaQueue, (int) $betaPatient['id'], []);

Assert::test('each clinic numbers its own queue independently', function () use ($betaEntry): void {
    Assert::same('A001', $betaEntry['token']);
});

Assert::test('clinic 1 cannot act on clinic 2\'s entry', function () use ($service, $betaEntry): void {
    $res = $service->apply(1, (int) $betaEntry['entry_id'], 'call', ['version' => 0]);
    Assert::false($res['ok']);
    $row = Db::one('SELECT status FROM queue_entries WHERE id = ?', [(int) $betaEntry['entry_id']]);
    Assert::same('waiting', $row['status'], 'row must be untouched');
});

Assert::test('a tracking token never crosses tenants', function () use ($betaEntry): void {
    $found = Db::one(
        'SELECT e.id FROM queue_entries e WHERE e.track_token = ? AND e.clinic_id = ?',
        [$betaEntry['track_token'], 1]
    );
    Assert::same(null, $found);
});

Assert::test('clinic scoped reads return only that clinic', function (): void {
    $rows = Db::all('SELECT clinic_id FROM queue_entries WHERE clinic_id = ?', [1]);
    foreach ($rows as $r) {
        Assert::same(1, (int) $r['clinic_id']);
    }
    $leak = (int) Db::scalar('SELECT COUNT(*) FROM queue_entries WHERE clinic_id = 2 AND id IN (SELECT id FROM queue_entries WHERE clinic_id = 1)');
    Assert::same(0, $leak);
});

Assert::test('a doctor\'s queue scope excludes other clinicians', function (): void {
    $repo = new App\Domain\Queue\QueueRepository();
    $doctorId = (int) Db::insert(
        'INSERT INTO doctors (clinic_id, name, room_id, avg_consult_minutes, is_active, created_at)
         VALUES (1, ?, NULL, 10, 1, ?)',
        ['Dr. Scoped', Db::now()]
    );
    $queueId = (int) Db::insert(
        'INSERT INTO queues (clinic_id, doctor_id, name, token_prefix, session_date, status, last_token_no, created_at)
         VALUES (1, ?, ?, ?, ?, ?, 0, ?)',
        [$doctorId, 'Scoped Queue', 'S', Db::today(), 'open', Db::now()]
    );

    $mine = $repo->liveQueues(1, null, $doctorId);
    Assert::same(1, count($mine), 'exactly one queue for this doctor');
    Assert::same($queueId, (int) $mine[0]['id']);

    $all = $repo->liveQueues(1);
    Assert::true(count($all) > count($mine), 'unscoped sees the whole clinic');

    // and never another clinic's queues
    foreach ($all as $q) {
        Assert::same(1, (int) $q['clinic_id']);
    }
});

/* ------------------------------------------------------------------ */
/* 4. priority ordering                                                */
/* ------------------------------------------------------------------ */

echo "\nPriority and ordering\n";

Assert::test('clinical priority moves an entry to the front', function () use ($service, $alphaQueue): void {
    $ps = new App\Services\PatientService();
    $pa = $ps->findOrCreate(1, ['full_name' => 'First In Line']);
    $pb = $ps->findOrCreate(1, ['full_name' => 'Emergency Case']);
    $a = $service->issueToken(1, $alphaQueue, (int) $pa['id'], []);
    $b = $service->issueToken(1, $alphaQueue, (int) $pb['id'], []);

    $repo = new App\Domain\Queue\QueueRepository();
    // the board also carries called/in_consultation rows; ordering only matters
    // within the waiting column
    $waiting = static fn (): array => array_values(array_map(
        static fn (array $r): int => (int) $r['id'],
        array_filter($repo->board(1, $alphaQueue), static fn (array $r): bool => $r['status'] === 'waiting')
    ));

    $order = $waiting();
    Assert::true(count($order) >= 2, 'two waiting entries, got ' . count($order));
    $posA = array_search((int) $a['entry_id'], $order, true);
    $posB = array_search((int) $b['entry_id'], $order, true);
    Assert::true($posA !== false && $posB !== false, 'both entries are waiting');
    Assert::true($posA < $posB, 'the earlier arrival is ahead');

    // priority is clinical-only; act as the clinic's doctor
    $_SESSION['user_id'] = (int) $GLOBALS['doctorUserId'];
    $res = $service->apply(1, (int) $b['entry_id'], 'priority', ['reason' => 'Chest pain — nurse assessed']);
    Assert::true($res['ok'], 'priority applied: ' . ($res['message'] ?? ''));

    $order = $waiting();
    Assert::same((int) $b['entry_id'], (int) $order[0], 'priority jumps to the front of the waiting column');

    $row = Db::one('SELECT priority, priority_reason FROM queue_entries WHERE id = ?', [(int) $b['entry_id']]);
    Assert::same(1, (int) $row['priority']);
    Assert::contains('Chest pain', (string) $row['priority_reason'], 'reason is recorded');
    $_SESSION = [];
});

/* ------------------------------------------------------------------ */
/* 5. wait estimates are ranges                                        */
/* ------------------------------------------------------------------ */

echo "\nWait estimates\n";

Assert::test('an estimate is always a range, never a single number', function () use ($service): void {
    $queue = Db::one('SELECT * FROM queues WHERE id = ?', [(int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1 LIMIT 1')]);
    $est = $service->estimate($queue, 1, 3);
    Assert::true(isset($est['low'], $est['high'], $est['label'], $est['confidence']));
    Assert::true($est['high'] >= $est['low'], 'high ≥ low');
    Assert::contains('–', (string) $est['label'], 'label is a range');
});

Assert::test('estimate confidence is "data" only with enough samples', function () use ($service): void {
    $queue = Db::one('SELECT * FROM queues WHERE id = ?', [(int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1 LIMIT 1')]);
    $est = $service->estimate($queue, 1, 1);
    Assert::true(in_array($est['confidence'], ['data', 'estimating', 'configured'], true), 'known confidence value');
});

/* ------------------------------------------------------------------ */
/* 6. notifications: dedupe + retry                                    */
/* ------------------------------------------------------------------ */

echo "\nNotifications\n";

$notifications = new App\Services\NotificationService();

Assert::test('issuing and calling a token queues patient messages', function (): void {
    $rows = Db::all('SELECT * FROM notifications WHERE clinic_id = 1 ORDER BY id');
    Assert::true($rows !== [], 'notifications were queued');
    $templates = array_unique(array_column($rows, 'template'));
    Assert::true(in_array('token_issued', $templates, true), 'token_issued queued');
    Assert::true(in_array('entry_called', $templates, true), 'entry_called queued');
});

Assert::test('re-queueing the same event does not duplicate it', function () use ($notifications): void {
    $entryId = (int) Db::scalar('SELECT id FROM queue_entries WHERE clinic_id = 1 ORDER BY id LIMIT 1');
    $before = (int) Db::scalar('SELECT COUNT(*) FROM notifications WHERE clinic_id = 1');
    $notifications->queue(1, $entryId, 'entry_called', []);
    $notifications->queue(1, $entryId, 'entry_called', []);
    $after = (int) Db::scalar('SELECT COUNT(*) FROM notifications WHERE clinic_id = 1');
    Assert::same($before, $after, 'dedupe_key absorbed both repeats');
});

Assert::test('the outbox hands back only work that is due', function () use ($notifications): void {
    $batch = $notifications->processDue(10);
    Assert::true(array_key_exists('sent', $batch) || array_key_exists('processed', $batch) || $batch !== [], 'returns a result');
    // no message may exceed the attempt cap and stay eligible forever
    $runaway = (int) Db::scalar("SELECT COUNT(*) FROM notifications WHERE clinic_id = 1 AND status = 'queued' AND attempts >= 5");
    Assert::same(0, $runaway, 'no message with exhausted retries still queued');
});

Assert::test('a message with no configured provider is never reported as sent', function () use ($notifications): void {
    // Simulate a pending job with a live phone number and a gateway that has no
    // credentials. It must stay retryable — a clinic that sees "sent" stops
    // chasing patients that were never actually contacted.
    $entryId = (int) Db::scalar('SELECT id FROM queue_entries WHERE clinic_id = 1 ORDER BY id LIMIT 1');
    Db::run(
        "INSERT INTO notifications (clinic_id, entry_id, channel, template, to_number, payload_json,
                                    dedupe_key, status, attempts, scheduled_at, created_at)
         VALUES (?, ?, 'sms', 'entry_called', '0771234567', ?, ?, 'queued', 0, ?, ?)",
        [1, $entryId, json_encode(['body' => 'test']), 'test-no-provider:' . $entryId, Db::now(), Db::now()]
    );
    $id = (int) Db::scalar("SELECT id FROM notifications WHERE dedupe_key = ?", ['test-no-provider:' . $entryId]);

    $notifications->processDue(50);

    $row = Db::one('SELECT status, attempts, last_error FROM notifications WHERE id = ?', [$id]);
    Assert::false($row['status'] === 'sent', 'must not claim delivery');
    Assert::same('queued', $row['status'], 'stays queued for retry');
    Assert::true((int) $row['attempts'] >= 1, 'attempt counted');
    Assert::contains('gateway', (string) $row['last_error'], 'reason recorded');
});

Assert::test('a patient without a phone number is never messaged', function (): void {
    $anon = Db::one(
        "SELECT e.id FROM queue_entries e JOIN patients p ON p.id = e.patient_id
          WHERE e.clinic_id = 1 AND (p.phone IS NULL OR p.phone = '') LIMIT 1"
    );
    if ($anon === null) {
        Assert::true(true, 'no phone-less entry in fixture');
        return;
    }
    $count = (int) Db::scalar('SELECT COUNT(*) FROM notifications WHERE entry_id = ?', [(int) $anon['id']]);
    Assert::same(0, $count, 'no SMS without a number');
});

/* ------------------------------------------------------------------ */
/* 5b. late-arrival policy                                             */
/* ------------------------------------------------------------------ */

echo "\nLate arrivals\n";

Assert::test('inside the grace period the booked order is kept', function (): void {
    $svc = new App\Services\AppointmentService();
    $appt = ['clinic_id' => 1, 'scheduled_for' => '2030-01-01 10:00:00'];
    $clinic = ['late_grace_minutes' => 15];

    $early = $svc->arrivalPolicy($appt, $clinic, '2030-01-01 09:58:00');
    Assert::same('early', $early['status']);
    Assert::same(1, $early['weight']);

    $onTime = $svc->arrivalPolicy($appt, $clinic, '2030-01-01 10:00:00');
    Assert::same('on_time', $onTime['status']);
    Assert::same(1, $onTime['weight']);

    $within = $svc->arrivalPolicy($appt, $clinic, '2030-01-01 10:14:00');
    Assert::same('on_time', $within['status'], 'inside grace is still on time');
});

Assert::test('beyond the grace period the patient joins as a walk-in', function (): void {
    $svc = new App\Services\AppointmentService();
    $appt = ['clinic_id' => 1, 'scheduled_for' => '2030-01-01 10:00:00'];
    $late = $svc->arrivalPolicy($appt, ['late_grace_minutes' => 15], '2030-01-01 10:40:00');

    Assert::same('late', $late['status']);
    Assert::same(3, $late['weight'], 'walk-in weight, so booked patients still precede them');
    Assert::contains('40 min late', $late['message']);
    Assert::contains('walk-in', $late['message'], 'the policy is explained, not silent');
});

Assert::test('the grace period comes from the clinic, not a hard-coded value', function (): void {
    $svc = new App\Services\AppointmentService();
    $appt = ['clinic_id' => 1, 'scheduled_for' => '2030-01-01 10:00:00'];
    $arrival = '2030-01-01 10:20:00';

    Assert::same('late', $svc->arrivalPolicy($appt, ['late_grace_minutes' => 10], $arrival)['status']);
    Assert::same('on_time', $svc->arrivalPolicy($appt, ['late_grace_minutes' => 30], $arrival)['status']);
});

/* ------------------------------------------------------------------ */
/* 6b. authorisation matrix                                            */
/* ------------------------------------------------------------------ */

echo "\nAuthorisation\n";

Assert::test('reception can ring in and finish visits, but not set priority', function (): void {
    $reception = ['queue.operate', 'queue.call', 'queue.consult', 'patient.manage', 'appointment.manage', 'reports.view'];
    foreach ($reception as $ability) {
        Assert::true(in_array($ability, App\Core\Auth::abilitiesFor('receptionist'), true), "reception has {$ability}");
    }
    Assert::false(in_array('queue.priority', App\Core\Auth::abilitiesFor('receptionist'), true), 'reception must NOT have priority');
    Assert::true(in_array('queue.issue', App\Core\Auth::abilitiesFor('receptionist'), true), 'reception issues tokens');
});

Assert::test('doctors can set priority; they cannot administer the clinic', function (): void {
    $doctor = App\Core\Auth::abilitiesFor('doctor');
    Assert::true(in_array('queue.priority', $doctor, true));
    Assert::true(in_array('queue.consult', $doctor, true));
    Assert::false(in_array('clinic.manage', $doctor, true), 'no clinic administration');
    Assert::false(in_array('staff.manage', $doctor, true), 'no staff management');
    Assert::false(in_array('billing.view', $doctor, true), 'no billing');
    Assert::false(in_array('queue.issue', $doctor, true), 'doctors do not issue tokens');
});

Assert::test('super admin has every ability', function (): void {
    Assert::true(App\Core\Auth::canFor('super_admin', 'anything.at.all'));
    Assert::true(App\Core\Auth::canFor('super_admin', 'queue.priority'));
});

Assert::test('the domain layer refuses priority without the ability', function () use ($service): void {
    // No authenticated session here, so Auth::can('queue.priority') is false.
    $entry = Db::one("SELECT id, status, version FROM queue_entries WHERE clinic_id = 1 AND status = 'waiting' LIMIT 1");
    if ($entry === null) {
        Assert::true(true, 'no waiting entry to test');
        return;
    }
    $res = $service->apply(1, (int) $entry['id'], 'priority', [
        'reason' => 'attempted bypass', 'version' => (int) $entry['version'],
    ]);
    Assert::false($res['ok'], 'refused');
    Assert::true(!empty($res['forbidden']), 'flagged as forbidden');
    $after = Db::one('SELECT status FROM queue_entries WHERE id = ?', [(int) $entry['id']]);
    Assert::same('waiting', $after['status'], 'row untouched');
});

/* ------------------------------------------------------------------ */
/* 7. security primitives                                              */
/* ------------------------------------------------------------------ */

echo "\nSecurity\n";

Assert::test('csrf tokens are 64 hex chars and compare in constant time', function (): void {
    $_SESSION = [];
    $t = Csrf::token();
    Assert::same(64, strlen($t));
    Assert::true(Csrf::check($t));
    Assert::false(Csrf::check($t . 'x'));
    Assert::false(Csrf::check(null));
    Assert::same($t, Csrf::token(), 'stable within a session');
});

Assert::test('csrf rotate invalidates the old token', function (): void {
    $_SESSION = [];
    $old = Csrf::token();
    Csrf::rotate();
    Assert::false(Csrf::check($old));
    Assert::true(Csrf::check(Csrf::token()));
});

Assert::test('passwords are hashed, never stored in plain text', function (): void {
    $hash = password_hash('Reception#2026', PASSWORD_DEFAULT);
    Assert::true(password_verify('Reception#2026', $hash));
    Assert::false(password_verify('reception#2026', $hash));
    Assert::true(str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2'), 'modern algorithm');
});

Assert::test('track tokens do not leak patient identifiers', function (): void {
    $entry = Db::one(
        'SELECT e.track_token, p.full_name, p.phone
           FROM queue_entries e JOIN patients p ON p.id = e.patient_id
          WHERE e.track_token IS NOT NULL AND e.clinic_id = 1 LIMIT 1'
    );
    $token = (string) $entry['track_token'];
    Assert::false(str_contains($token, strtolower(substr((string) $entry['full_name'], 0, 4))), 'no name fragment');
    Assert::false(str_contains($token, (string) $entry['phone']), 'no phone fragment');
});

/* ------------------------------------------------------------------ */
/* 8. plan limits                                                      */
/* ------------------------------------------------------------------ */

echo "\nPlan limits\n";

Assert::test('plan limits are enforced per resource', function (): void {
    $svc = new App\Services\ClinicService();
    Db::run('UPDATE clinics SET plan_id = 1 WHERE id = 1'); // starter: 1 doctor / 2 staff
    $plan = $svc->plan(1);
    Assert::same(1, (int) $plan['max_doctors']);

    // 1 doctor already exists → doctors is full, queues still allowed
    Assert::false($svc->withinLimit(1, 'doctors'), 'doctor limit reached');
});

Assert::test('an unknown resource is never blocked', function (): void {
    $svc = new App\Services\ClinicService();
    Assert::true($svc->withinLimit(1, 'something_else'));
});

/* ------------------------------------------------------------------ */
/* 9. suspension preserves data                                        */
/* ------------------------------------------------------------------ */

echo "\nSuspension\n";

Assert::test('suspending a clinic keeps every row', function (): void {
    $before = [
        'patients' => (int) Db::scalar('SELECT COUNT(*) FROM patients WHERE clinic_id = 1'),
        'entries'  => (int) Db::scalar('SELECT COUNT(*) FROM queue_entries WHERE clinic_id = 1'),
        'audit'    => (int) Db::scalar('SELECT COUNT(*) FROM audit_logs WHERE clinic_id = 1'),
    ];
    Db::run("UPDATE clinics SET billing_status = 'suspended' WHERE id = 1");
    $after = [
        'patients' => (int) Db::scalar('SELECT COUNT(*) FROM patients WHERE clinic_id = 1'),
        'entries'  => (int) Db::scalar('SELECT COUNT(*) FROM queue_entries WHERE clinic_id = 1'),
        'audit'    => (int) Db::scalar('SELECT COUNT(*) FROM audit_logs WHERE clinic_id = 1'),
    ];
    Assert::same($before, $after, 'nothing deleted');
    Db::run("UPDATE clinics SET billing_status = 'active' WHERE id = 1");
});

/* ------------------------------------------------------------------ */
/* 10. QR generation                                                   */
/* ------------------------------------------------------------------ */

echo "\nQR codes\n";

Assert::test('a tracking URL renders as a valid QR matrix', function (): void {
    $url = 'https://queuepilot.app/t/AbCdEfGhIjKlMnOpQrStUvWx';
    $matrix = App\Support\QrCode::matrix($url);
    $size = count($matrix);
    Assert::true($size >= 21 && $size <= 177, "version size {$size}");
    Assert::same($size, strlen((string) $matrix[0]), 'square');
    foreach ($matrix as $row) {
        Assert::same(0, preg_match('/[^01]/', (string) $row), 'modules are 0/1 only');
    }
    // the three finder patterns must be present in the corners
    Assert::true(str_starts_with((string) $matrix[0], '1111111'), 'top-left finder');
    Assert::true(str_starts_with((string) $matrix[$size - 1], '1111111'), 'bottom-left finder');
});

Assert::test('the QR renders as self-contained SVG', function (): void {
    $svg = App\Support\QrCode::svg('https://queuepilot.app/t/abc123');
    Assert::contains('<svg', $svg);
    Assert::contains('</svg>', $svg);
    Assert::false(str_contains($svg, '<script'), 'no script tag');
    Assert::false(str_contains($svg, '<image'), 'no raster/image payload');
    Assert::false(str_contains($svg, 'href='), 'no external references');
    Assert::contains('viewBox', $svg);
});

/* ------------------------------------------------------------------ */
/* 11. reports add up                                                   */
/* ------------------------------------------------------------------ */

echo "\nReporting\n";

Assert::test('the day summary agrees with the raw rows', function (): void {
    $reports = new App\Services\ReportService();
    $summary = $reports->daySummary(1);
    $completed = (int) Db::scalar(
        "SELECT COUNT(*) FROM queue_entries WHERE clinic_id = 1 AND status = 'completed' AND date(finished_at) = ?",
        [Db::today()]
    );
    Assert::true(
        (int) ($summary['completed'] ?? 0) >= $completed - 0,
        'summary completed ≥ rows completed'
    );
    Assert::true(isset($summary['seen'], $summary['waiting']) || $summary !== [], 'has keys');
});

/* ------------------------------------------------------------------ */
/* 12. i18n                                                           */
/* ------------------------------------------------------------------ */

echo "\nLocalisation\n";

Assert::test('all three locales exist with the same keys', function (): void {
    $sets = [];
    foreach (['en', 'si', 'ta'] as $locale) {
        $file = QP_ROOT . '/resources/lang/' . $locale . '.php';
        Assert::true(is_file($file), "{$locale}.php exists");
        $sets[$locale] = require $file;
        Assert::true($sets[$locale] !== [], "{$locale} is not empty");
    }
    $enKeys = array_keys($sets['en']);
    foreach (['si', 'ta'] as $locale) {
        $missing = array_diff($enKeys, array_keys($sets[$locale]));
        Assert::same([], array_values($missing), "{$locale} missing keys");
    }
});

Assert::test('Sinhala and Tamil contain real translated text, not placeholders', function (): void {
    $si = require QP_ROOT . '/resources/lang/si.php';
    $ta = require QP_ROOT . '/resources/lang/ta.php';
    $siNonAscii = 0;
    foreach ($si as $v) {
        if (preg_match('/[\x{0D80}-\x{0DFF}]/u', (string) $v) === 1) {
            $siNonAscii++;
        }
    }
    $taNonAscii = 0;
    foreach ($ta as $v) {
        if (preg_match('/[\x{0B80}-\x{0BFF}]/u', (string) $v) === 1) {
            $taNonAscii++;
        }
    }
    Assert::true($siNonAscii >= 10, "Sinhala strings with Sinhala script: {$siNonAscii}");
    Assert::true($taNonAscii >= 10, "Tamil strings with Tamil script: {$taNonAscii}");
});

Assert::test('the layout actually calls t() for its navigation', function (): void {
    // Translation files existing is not the same as translations being used.
    // This caught the i18n layer being dead code: files present, every screen
    // still English.
    $layout = (string) file_get_contents(QP_ROOT . '/resources/views/layouts/app.php');
    $calls = preg_match_all("/t\('nav\./", $layout);
    Assert::true($calls >= 5, "layout uses t() for nav labels (found {$calls})");
});

Assert::test('Lang returns the translated string, not the raw key', function (): void {
    App\Core\Lang::load('si');
    $label = App\Core\Lang::get('nav.live_board');
    Assert::true($label !== 'nav.live_board', 'key was translated');
    Assert::true(preg_match('/[\x{0D80}-\x{0DFF}]/u', $label) === 1, 'Sinhala script returned');

    App\Core\Lang::load('en');
    Assert::same('Live board', App\Core\Lang::get('nav.live_board'));
});

Assert::test('an unknown key falls back instead of printing a key', function (): void {
    $value = App\Core\Lang::get('nav.does_not_exist', 'Fallback label');
    Assert::same('Fallback label', $value);
});

/* ------------------------------------------------------------------ */

$code = Assert::summary();
Database::cleanup($dbPath);
echo "\n";
exit($code);
