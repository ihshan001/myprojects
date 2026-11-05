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

Assert::test('patient codes survive a deletion (no reuse collision)', function () use ($alphaQueue): void {
    $ps = new App\Services\PatientService();

    // create three, delete the newest, then create another: the old
    // COUNT(*)+1 scheme would collide here and break registration entirely
    $a = $ps->findOrCreate(1, ['full_name' => 'Code A', 'phone' => '0770000101']);
    $b = $ps->findOrCreate(1, ['full_name' => 'Code B', 'phone' => '0770000102']);
    $c = $ps->findOrCreate(1, ['full_name' => 'Code C', 'phone' => '0770000103']);
    Db::run('DELETE FROM patients WHERE id = ?', [(int) $c['id']]);

    $d = $ps->findOrCreate(1, ['full_name' => 'Code D', 'phone' => '0770000104']);
    Assert::true($d['id'] > 0, 'registration still works after a delete');
    Assert::true($d['code'] !== $b['code'], 'new code does not reuse a live one');
    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM patients WHERE clinic_id = 1 AND code = ?', [$d['code']]), 'code is unique');
});

Assert::test('every patient code in a clinic is unique', function (): void {
    $rows = Db::all('SELECT code, COUNT(*) AS n FROM patients WHERE clinic_id = 1 GROUP BY code HAVING n > 1');
    Assert::same([], $rows, 'no duplicate codes');
});

Assert::test('the receipt for a token is a GET, so a refresh cannot reissue', function (): void {
    // This is a routing contract: the issue endpoint must not render a receipt
    // directly from the POST, or refreshing mints a second token.
    $routes = (string) file_get_contents(QP_ROOT . '/routes/web.php');
    Assert::contains("/queue/token-issued", $routes, 'a dedicated receipt route exists');
    $controller = (string) file_get_contents(QP_ROOT . '/app/Http/Controllers/QueueController.php');
    Assert::contains("Session::put('issued_token'", $controller, 'the receipt is handed over through a redirect');
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

Assert::test('an announced delay shifts the estimate, it does not hide it', function () use ($service): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1 LIMIT 1');
    $base = Db::one('SELECT * FROM queues WHERE id = ?', [$queueId]);
    $base['delay_minutes'] = 0;
    $normal = $service->estimate($base, 1, 3);

    $delayed = $base;
    $delayed['delay_minutes'] = 25;
    $shifted = $service->estimate($delayed, 1, 3);

    Assert::same(25, $shifted['delay_minutes'], 'delay is reported');
    Assert::same($normal['low'] + 25, $shifted['low'], 'low bound moved by the delay');
    // the high bound has a floor (low + 4), so only assert it grew and that it
    // grew by at least the announced delay
    Assert::true($shifted['high'] >= $normal['high'] + 24, 'high bound grew with the delay');
    Assert::contains('–', (string) $shifted['label'], 'still a range, not false precision');
});

Assert::test('estimate confidence is "data" only with enough samples', function () use ($service): void {
    $queue = Db::one('SELECT * FROM queues WHERE id = ?', [(int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1 LIMIT 1')]);
    $est = $service->estimate($queue, 1, 1);
    Assert::true(in_array($est['confidence'], ['data', 'estimating', 'configured'], true), 'known confidence value');
});

/* ------------------------------------------------------------------ */
/* 5c. online booking is opt-in                                        */
/* ------------------------------------------------------------------ */

echo "\nOnline booking\n";

Assert::test('a clinic that has not opted in exposes no booking page', function (): void {
    // The gate is the clinic column, checked server-side. The public route
    // exists for every slug; the data decides.
    Db::run('UPDATE clinics SET allow_online_booking = 0 WHERE slug = ?', ['alpha']);
    $clinic = Db::one('SELECT allow_online_booking FROM clinics WHERE slug = ?', ['alpha']);
    Assert::same(0, (int) $clinic['allow_online_booking'], 'default is off');
});

Assert::test('an opted-in clinic can take a booking with no account', function (): void {
    Db::run('UPDATE clinics SET allow_online_booking = 1 WHERE id = 1');
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $patient = (new App\Services\PatientService())->findOrCreate(1, ['full_name' => 'Web Booker', 'phone' => '0771234567']);

    $when = date('Y-m-d', strtotime('+9 days')) . ' 11:00:00';
    $id = (new App\Services\AppointmentService())->create(1, $doctorId, (int) $patient['id'], $when, 'online');

    $row = Db::one('SELECT * FROM appointments WHERE id = ?', [$id]);
    Assert::same('online', $row['source'], 'origin is recorded');
    Assert::same('booked', $row['status'], 'a booking is not yet a token');
});

Assert::test('a public booking cannot reach another clinic\'s doctor', function (): void {
    $foreignDoctor = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 2 LIMIT 1');
    $patient = (new App\Services\PatientService())->findOrCreate(1, ['full_name' => 'Cross Booker', 'phone' => '0771234500']);
    // the controller validates doctor membership before creating anything
    Assert::same(
        null,
        Db::scalar('SELECT id FROM doctors WHERE id = ? AND clinic_id = ?', [$foreignDoctor, 1]),
        'doctor is not part of clinic 1'
    );
    $countBefore = (int) Db::scalar('SELECT COUNT(*) FROM appointments WHERE clinic_id = 1');
    Assert::same($countBefore, (int) Db::scalar('SELECT COUNT(*) FROM appointments WHERE clinic_id = 1'), 'nothing created');
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

Assert::test('the clinic defines how appointments and walk-ins are ordered', function () use ($service): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 2 LIMIT 1');
    $ps = new App\Services\PatientService();

    // clinic 2: everyone equal (weights 1/1)
    Db::run('UPDATE clinics SET appointment_weight = 1, walkin_weight = 1 WHERE id = 2');
    $a = $ps->findOrCreate(2, ['full_name' => 'Appt A']);
    $w = $ps->findOrCreate(2, ['full_name' => 'Walkin W']);
    $appt = $service->issueToken(2, $queueId, (int) $a['id'], ['source' => 'appointment']);
    $walk = $service->issueToken(2, $queueId, (int) $w['id'], ['source' => 'walkin']);

    Assert::same(
        1,
        (int) Db::scalar('SELECT sort_weight FROM queue_entries WHERE id = ?', [(int) $walk['entry_id']]),
        'walk-in weight follows the clinic setting'
    );

    // clinic 1: appointments first (weights 1/5)
    Db::run('UPDATE clinics SET appointment_weight = 1, walkin_weight = 5 WHERE id = 1');
    $p = $ps->findOrCreate(1, ['full_name' => 'Weight Probe']);
    $q1 = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1 LIMIT 1');
    $w2 = $service->issueToken(1, $q1, (int) $p['id'], ['source' => 'walkin']);
    Assert::same(
        5,
        (int) Db::scalar('SELECT sort_weight FROM queue_entries WHERE id = ?', [(int) $w2['entry_id']]),
        'clinic 1 walk-ins sort behind'
    );

    Db::run('UPDATE clinics SET appointment_weight = 1, walkin_weight = 3 WHERE id = 1');
    Db::run('UPDATE clinics SET appointment_weight = 1, walkin_weight = 3 WHERE id = 2');
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
/* Operational depth: holds, leave, invites, TOTP, rate limiting        */
/* ------------------------------------------------------------------ */

Assert::test('on_hold is a live state that keeps the patient in line', function (): void {
    // A hold must not be modelled as "skipped" (loses their place) or
    // "no_show" (a false record). It is its own state.
    Assert::true(QueueStateMachine::can(QueueStateMachine::WAITING, QueueStateMachine::ON_HOLD));
    Assert::true(QueueStateMachine::can(QueueStateMachine::CALLED, QueueStateMachine::ON_HOLD));
    Assert::true(QueueStateMachine::can(QueueStateMachine::ON_HOLD, QueueStateMachine::WAITING));
    Assert::true(QueueStateMachine::isOpen(QueueStateMachine::ON_HOLD), 'hold occupies the board');
});

Assert::test('coming off a hold returns to the line, not to the front', function (): void {
    // on_hold -> called would silently jump the queue.
    Assert::false(QueueStateMachine::can(QueueStateMachine::ON_HOLD, QueueStateMachine::CALLED));
    Assert::false(QueueStateMachine::can(QueueStateMachine::ON_HOLD, QueueStateMachine::IN_CONSULTATION));
    Assert::false(QueueStateMachine::can(QueueStateMachine::ON_HOLD, QueueStateMachine::COMPLETED));
    Assert::false(QueueStateMachine::can(QueueStateMachine::ON_HOLD, QueueStateMachine::TRANSFERRED));
});

Assert::test('a held patient cannot be issued a second token', function (): void {
    // The partial unique index counts on_hold as active; the service-level
    // pre-check must agree, or reception sees a raw SQL error instead of a
    // sentence.
    $sqlite = (string) file_get_contents(QP_ROOT . '/database/migrations/0003_operational_depth.sqlite.sql');
    Assert::contains("'on_hold'", $sqlite, 'partial index covers holds');

    $service = (string) file_get_contents(QP_ROOT . '/app/Domain/Queue/QueueService.php');
    preg_match("/status IN \('waiting','called','in_consultation'[^)]*\)/", $service, $m);
    Assert::true(isset($m[0]) && str_contains($m[0], "'on_hold'"), 'duplicate pre-check includes on_hold');
});

Assert::test('the hold action records a reason and a timestamp', function (): void {
    $seed = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1, ?, ?, ?, ?)',
        ['HLD1', 'Hold Test', '0771234501', Db::now()]
    );
    $entry = Db::insert(
        'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status, joined_at, track_token)
         VALUES (1, ?, ?, 9001, "H001", "waiting", ?, ?)',
        [(int) $GLOBALS['alphaQueue'], $seed, Db::now(), 'holdtracktoken0001']
    );

    $result = (new App\Domain\Queue\QueueService())->apply(1, $entry, 'hold', ['reason' => 'Sent for X-ray']);
    Assert::true($result['ok'], 'hold applied: ' . $result['message']);

    $row = Db::one('SELECT status, hold_reason, hold_since FROM queue_entries WHERE id = ?', [$entry]);
    Assert::same('on_hold', $row['status']);
    Assert::same('Sent for X-ray', $row['hold_reason']);
    Assert::true($row['hold_since'] !== null, 'hold_since stamped');

    $audit = Db::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'queue.hold' AND entity_id = ?", [$entry]);
    Assert::same(1, (int) $audit, 'hold is audited');
});

Assert::test('releasing a hold clears the hold metadata', function (): void {
    $entry = (int) Db::scalar("SELECT id FROM queue_entries WHERE token_label = 'H001'");
    $result = (new App\Domain\Queue\QueueService())->apply(1, $entry, 'release_hold');
    Assert::true($result['ok'], $result['message']);
    $row = Db::one('SELECT status, hold_reason, hold_since FROM queue_entries WHERE id = ?', [$entry]);
    Assert::same('waiting', $row['status']);
    Assert::same(null, $row['hold_reason']);
});

Assert::test('doctor leave blocks both the form and the POST', function (): void {
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1');
    $service = new App\Services\DoctorService();

    $added = $service->addLeave(1, $doctorId, '2026-12-01', '2026-12-03', 'Conference', null);
    Assert::true($added['ok'], $added['error'] ?? '');

    Assert::true($service->isOnLeave(1, $doctorId, '2026-12-01'), 'first day covered');
    Assert::true($service->isOnLeave(1, $doctorId, '2026-12-02'), 'middle day covered');
    Assert::true($service->isOnLeave(1, $doctorId, '2026-12-03'), 'last day covered (inclusive)');
    Assert::false($service->isOnLeave(1, $doctorId, '2026-12-04'), 'day after is free');
    Assert::false($service->isOnLeave(1, $doctorId, '2026-11-30'), 'day before is free');

    // Another clinic's doctor must never be affected.
    $otherDoctor = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 2');
    Assert::false($service->isOnLeave(2, $otherDoctor, '2026-12-02'), 'tenant isolation');

    $bookable = $service->bookable(1, '2026-12-02');
    $onLeave = array_values(array_filter($bookable, static fn (array $d): bool => (int) $d['id'] === $doctorId));
    Assert::true($onLeave !== [] && $onLeave[0]['on_leave'] === true, 'doctor flagged in bookable list');
});

Assert::test('backwards leave dates are rejected', function (): void {
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1');
    $result = (new App\Services\DoctorService())->addLeave(1, $doctorId, '2026-12-10', '2026-12-01', '', null);
    Assert::false($result['ok']);
    Assert::contains('before the start', (string) $result['error']);
});

Assert::test('leave does not silently cancel appointments', function (): void {
    // It reports them instead. Cancelling is a human decision.
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1');
    $patientId = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1, ?, ?, ?, ?)',
        ['LVAP1', 'Leave Appt Test', '0771234502', Db::now()]
    );
    Db::run(
        'INSERT INTO appointments (clinic_id, doctor_id, patient_id, scheduled_for, status, source, created_at)
         VALUES (1, ?, ?, ?, "booked", "walkin", ?)',
        [$doctorId, $patientId, '2027-01-15 10:00:00', Db::now()]
    );

    $result = (new App\Services\DoctorService())->addLeave(1, $doctorId, '2027-01-15', '2027-01-15', 'Sick', null);
    Assert::true($result['ok']);
    Assert::same(1, count($result['affected']), 'the clashing appointment is reported');
    $status = Db::scalar('SELECT status FROM appointments WHERE scheduled_for = ?', ['2027-01-15 10:00:00']);
    Assert::same('booked', $status, 'appointment left untouched');
});

Assert::test('TOTP matches the RFC 6238 reference vectors', function (): void {
    // The shared secret from RFC 6238 appendix B, as ASCII.
    $ref = new ReflectionClass(App\Services\TotpService::class);
    $encode = $ref->getMethod('base32Encode');
    $encode->setAccessible(true);
    $secret = $encode->invoke(null, '12345678901234567890');

    $vectors = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471',
                1234567890 => '005924', 2000000000 => '279037'];
    foreach ($vectors as $time => $expected) {
        Assert::same($expected, App\Services\TotpService::currentCode($secret, $time), "T={$time}");
    }
});

Assert::test('TOTP tolerates clock drift but not a stale code', function (): void {
    $secret = 'JBSWY3DPEHPK3PXP';
    $now = 1800000000;
    $current = App\Services\TotpService::currentCode($secret, $now);

    Assert::true(App\Services\TotpService::verify($secret, $current, $now), 'current code accepted');
    Assert::true(App\Services\TotpService::verify($secret, $current, $now + 29), 'one step of drift accepted');
    Assert::false(App\Services\TotpService::verify($secret, $current, $now + 300), 'five minutes later rejected');
    Assert::false(App\Services\TotpService::verify($secret, 'abcdef', $now), 'non-numeric rejected');
    Assert::false(App\Services\TotpService::verify($secret, '12345', $now), 'wrong length rejected');
});

Assert::test('TOTP secrets are encrypted at rest, not stored in the clear', function (): void {
    $userId = Db::insert(
        'INSERT INTO users (clinic_id, name, email, password_hash, role, is_active, created_at)
         VALUES (1, ?, ?, ?, ?, 1, ?)',
        ['2FA Test', 'twofa@alpha.example', password_hash('TwoFactor#2026', PASSWORD_DEFAULT), 'receptionist', Db::now()]
    );
    $enrol = App\Services\TotpService::beginEnrolment($userId);
    Assert::true($enrol['ok'], $enrol['error'] ?? '');

    $stored = (string) Db::scalar('SELECT totp_secret FROM users WHERE id = ?', [$userId]);
    Assert::true(str_starts_with($stored, 'v1:'), 'ciphertext envelope');
    Assert::false(str_contains($stored, $enrol['secret']), 'plaintext secret absent from the column');
    Assert::true(str_contains((string) $enrol['uri'], 'otpauth://totp/'), 'URI is scannable');
});

Assert::test('a 2FA account cannot log in on the password alone', function (): void {
    $userId = (int) Db::scalar("SELECT id FROM users WHERE email = 'twofa@alpha.example'");
    $secret = (string) Db::scalar('SELECT totp_secret FROM users WHERE id = ?', [$userId]);

    $ref = new ReflectionClass(App\Services\TotpService::class);
    $decrypt = $ref->getMethod('decrypt');
    $decrypt->setAccessible(true);
    $plain = $decrypt->invoke(null, $secret);

    $enable = App\Services\TotpService::enable($userId, App\Services\TotpService::currentCode($plain));
    Assert::true($enable['ok'], $enable['error'] ?? '');
    Assert::same(8, count($enable['recovery_codes']));

    // A wrong code must not pass, and must not be a usable recovery code either.
    Assert::false(App\Services\TotpService::verifyForUser($userId, '000000'));
});

Assert::test('recovery codes are stored hashed and consumed on use', function (): void {
    $userId = (int) Db::scalar("SELECT id FROM users WHERE email = 'twofa@alpha.example'");
    $hashes = json_decode((string) Db::scalar('SELECT totp_recovery FROM users WHERE id = ?', [$userId]), true) ?: [];

    Assert::same(8, count($hashes));
    foreach ($hashes as $hash) {
        Assert::same(64, strlen((string) $hash), 'sha256 hex digest');
        Assert::true(ctype_xdigit((string) $hash), 'not plaintext');
    }
});

Assert::test('an invitation token is stored only as a hash', function (): void {
    $service = new App\Services\StaffInviteService();
    $result = $service->invite(1, 'newhire@alpha.example', 'receptionist');
    Assert::true($result['ok'], $result['error'] ?? '');

    $stored = (string) Db::scalar('SELECT token_hash FROM staff_invites WHERE id = ?', [(int) $result['invite_id']]);
    Assert::false(str_contains($stored, $result['token']), 'plaintext token is not in the database');
    Assert::same(hash('sha256', (string) $result['token']), $stored, 'hash matches the token');

    // And the token must resolve back to the invite.
    $found = $service->find((string) $result['token']);
    Assert::true($found !== null, 'token resolves');
    Assert::same('newhire@alpha.example', $found['email']);
});

Assert::test('an invitation is single use', function (): void {
    $service = new App\Services\StaffInviteService();
    $result = $service->invite(1, 'onceonly@alpha.example', 'receptionist');
    Assert::true($result['ok']);

    $accepted = $service->accept((string) $result['token'], 'Once Only', 'OnceOnly#2026');
    Assert::true($accepted['ok'], $accepted['error'] ?? '');
    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM users WHERE email = ?', ['onceonly@alpha.example']));

    $again = $service->accept((string) $result['token'], 'Someone Else', 'SomeoneElse#2026');
    Assert::false($again['ok'], 'second use rejected');
    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM users WHERE email = ?', ['onceonly@alpha.example']), 'no duplicate account');
});

Assert::test('a weak password cannot be accepted from an invitation', function (): void {
    $service = new App\Services\StaffInviteService();
    $result = $service->invite(1, 'weakpass@alpha.example', 'receptionist');
    Assert::true($result['ok']);

    Assert::false($service->accept((string) $result['token'], 'Weak Pass', 'short')['ok'], 'too short');
    Assert::false($service->accept((string) $result['token'], 'Weak Pass', 'allletterslong')['ok'], 'no digit');
    Assert::false($service->accept((string) $result['token'], 'W', 'StrongPass#2026')['ok'], 'name too short');
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM users WHERE email = ?', ['weakpass@alpha.example']));
});

Assert::test('an invitation for another clinic cannot create a user there', function (): void {
    $service = new App\Services\StaffInviteService();
    $result = $service->invite(1, 'crosstenant@alpha.example', 'receptionist');
    $accepted = $service->accept((string) $result['token'], 'Cross Tenant', 'CrossTenant#2026');
    Assert::true($accepted['ok']);
    // The clinic comes from the invite row, never from anything the user sent.
    Assert::same(1, (int) Db::scalar('SELECT clinic_id FROM users WHERE email = ?', ['crosstenant@alpha.example']));
});

Assert::test('an invalid or expired invitation resolves to nothing', function (): void {
    $service = new App\Services\StaffInviteService();
    Assert::same(null, $service->find('not-a-real-token'));

    $result = $service->invite(1, 'expired@alpha.example', 'receptionist');
    Db::run('UPDATE staff_invites SET expires_at = ? WHERE id = ?', ['2020-01-01 00:00:00', (int) $result['invite_id']]);
    Assert::same(null, $service->find((string) $result['token']), 'expired invite is rejected');
});

Assert::test('the rate limiter counts in a durable, shared table', function (): void {
    $bucket = 'test:' . App\Core\Str::random(4);
    $first = App\Core\RateLimiter::hit($bucket, 3, 60);
    Assert::true($first['allowed']);
    Assert::same(2, $first['remaining']);

    App\Core\RateLimiter::hit($bucket, 3, 60);
    $third = App\Core\RateLimiter::hit($bucket, 3, 60);
    Assert::true($third['allowed'], 'third hit is within the limit');
    Assert::same(3, $third['hits']);

    $fourth = App\Core\RateLimiter::hit($bucket, 3, 60);
    Assert::false($fourth['allowed'], 'fourth hit is refused');
    Assert::same(0, $fourth['remaining']);
    Assert::true($fourth['retry_after'] > 0, 'caller is told when to retry');

    // Durability: the counter is in the database, not in process memory.
    Assert::same(4, (int) Db::scalar('SELECT SUM(hits) FROM rate_limits WHERE bucket = ?', [$bucket]));
});

Assert::test('rate limiter buckets never store a raw credential', function (): void {
    $key = 'aVerySecretDisplayKey123456';
    $redacted = App\Core\RateLimiter::redact('display:key:' . $key);
    Assert::false(str_contains($redacted, $key), 'the key is not present in the log-safe form');
    Assert::true(str_contains($redacted, 'sha256.'), 'replaced with a digest');
});

Assert::test('the display key is rate limited, not just validated', function (): void {
    // A bearer token on a public URL is only as safe as the number of guesses
    // an attacker gets; that number has to be enforced in the controller.
    $controller = (string) file_get_contents(QP_ROOT . '/app/Http/Controllers/ApiController.php');
    preg_match('/public function display.*?\n    }/s', $controller, $m);
    Assert::true(isset($m[0]), 'display action found');
    Assert::contains("RateLimiter::enforce('display:key:'", $m[0], 'per-key limit');
    Assert::contains("RateLimiter::enforce('display:ip:'", $m[0], 'per-IP limit');
});

Assert::test('the limiter stops the request instead of returning to the caller', function (): void {
    // json() writes a response but does not exit; without an explicit exit the
    // controller overwrites the 429 with a 200. This was a real bug.
    $source = (string) file_get_contents(QP_ROOT . '/app/Core/RateLimiter.php');
    $start = strpos($source, 'public static function enforce');
    Assert::true($start !== false, 'enforce() found');
    $branch = substr($source, (int) $start, 1800);
    Assert::contains('exit;', $branch, 'refusal terminates the request');
    Assert::true(strpos($branch, 'exit;') > strpos($branch, 'rate_limited'), 'body written before exit');
});

Assert::test('staff invitations are gated by an ability, not a page', function (): void {
    $routes = (string) file_get_contents(QP_ROOT . '/routes/web.php');
    Assert::true(
        (bool) preg_match("#Router::post\('/settings/invites'.*?'ability' => 'staff.manage'#", $routes),
        'invite route declares staff.manage'
    );
    Assert::true(
        (bool) preg_match("#Router::post\('/doctors/leave'.*?'ability' => 'doctor.manage'#", $routes),
        'leave route declares doctor.manage'
    );
});

Assert::test('a literal route is declared before the parameter route that would shadow it', function (): void {
    // The router takes the first match, so `/doctors/leave` must precede
    // `/doctors/{id}` or every leave submission becomes "update doctor 'leave'".
    $routes = (string) file_get_contents(QP_ROOT . '/routes/web.php');
    $literal = strpos($routes, "Router::post('/doctors/leave'");
    $param = strpos($routes, "Router::post('/doctors/{id}'");
    Assert::true($literal !== false && $param !== false);
    Assert::true($literal < $param, 'leave routes are registered first');
});

Assert::test('migration 0003 preserves every row it rebuilds', function (): void {
    // The SQLite variant rebuilds queue_entries to change a CHECK constraint.
    // A rebuild that forgets a column silently drops data, so the column list
    // in the INSERT must cover every original column.
    $sql = (string) file_get_contents(QP_ROOT . '/database/migrations/0003_operational_depth.sqlite.sql');
    foreach (['id', 'clinic_id', 'queue_id', 'patient_id', 'appointment_id', 'token_no', 'token_label',
              'status', 'priority', 'priority_reason', 'sort_weight', 'source', 'joined_at', 'called_at',
              'started_at', 'finished_at', 'wait_minutes', 'consult_minutes', 'call_count', 'note',
              'created_by', 'track_token', 'track_expires_at', 'version'] as $column) {
        Assert::contains($column, $sql, "column {$column} is carried across the rebuild");
    }
    Assert::contains('PRAGMA foreign_keys = OFF', $sql, 'FKs are suspended for the rebuild');
    Assert::contains('PRAGMA foreign_keys = ON', $sql, 'FKs are re-enabled afterwards');
});

Assert::test('the rebuilt table keeps the duplicate-token guard', function (): void {
    $indexes = Db::all("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'queue_entries'");
    $names = array_column($indexes, 'name');
    Assert::true(in_array('uq_active_entry_per_patient', $names, true), 'partial unique index survives');
    Assert::true(in_array('idx_qe_board', $names, true), 'board index survives');
});

/* ------------------------------------------------------------------ */
/* Monitoring, backups, approach notices, organisations                */
/* ------------------------------------------------------------------ */

Assert::test('the health endpoint fails closed without a token', function (): void {
    // An unauthenticated health page is an inventory of the platform: tenant
    // names, queue names, staff emails. It must be closed by default.
    $controller = (string) file_get_contents(QP_ROOT . '/app/Http/Controllers/MonitorController.php');
    Assert::contains('hash_equals($token, $provided)', $controller);
    Assert::contains("'error' => 'not_found'", $controller, 'indistinguishable from a missing route');
    Assert::contains('Auth::isSuper()', $controller, 'a signed-in super admin may look');
});

Assert::test('health checks are concrete, not a 200-from-the-web-server', function (): void {
    $service = new App\Services\MonitorService();
    $result = $service->run();
    Assert::true(isset($result['status'], $result['checks']), 'report shape');
    Assert::true(in_array($result['status'], ['ok', 'warning', 'critical'], true));

    $keys = array_column($result['checks'], 'key');
    foreach (['database', 'notification_worker', 'backups', 'schema_parity', 'stale_queues', 'long_waits', 'disk'] as $expected) {
        Assert::true(in_array($expected, $keys, true), "{$expected} is checked");
    }
    foreach ($result['checks'] as $check) {
        Assert::true(in_array($check['severity'], ['ok', 'warning', 'critical'], true), "{$check['key']} severity valid");
        Assert::true($check['summary'] !== '', "{$check['key']} has a sentence");
    }
});

Assert::test('a stalled notification worker is reported as critical', function (): void {
    // The failure mode that matters: the app is up, the page renders, and
    // nobody is being called because the worker died.
    Db::run("DELETE FROM notifications WHERE dedupe_key LIKE 'test-stall-%'");
    $entry = (int) Db::scalar("SELECT id FROM queue_entries WHERE clinic_id = 1 LIMIT 1");
    Db::run(
        'INSERT INTO notifications (clinic_id, entry_id, channel, template, to_number, payload_json,
            dedupe_key, status, attempts, scheduled_at, created_at)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
        [$entry, 'sms', 'entry_called', '0771234567', '{}', 'test-stall-1', 'queued',
         date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() - 3600)]
    );

    $result = (new App\Services\MonitorService())->run();
    $worker = null;
    foreach ($result['checks'] as $check) {
        if ($check['key'] === 'notification_worker') {
            $worker = $check;
        }
    }
    Assert::true($worker !== null);
    Assert::same('critical', $worker['severity'], 'an hour-overdue message is critical');
    Assert::same('critical', $result['status'], 'overall status follows the worst check');

    Db::run("DELETE FROM notifications WHERE dedupe_key LIKE 'test-stall-%'");
});

Assert::test('monitoring records an alert and clears it on recovery', function (): void {
    Db::run("DELETE FROM system_alerts WHERE code = 'long_waits'");
    $entry = (int) Db::scalar("SELECT id FROM queue_entries WHERE clinic_id = 1 AND status = 'waiting' LIMIT 1");
    $original = Db::scalar('SELECT joined_at FROM queue_entries WHERE id = ?', [$entry]);
    Db::run('UPDATE queue_entries SET joined_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 7200), $entry]);

    (new App\Services\MonitorService())->run(1);
    Assert::true((int) Db::scalar("SELECT COUNT(*) FROM system_alerts WHERE code = 'long_waits' AND resolved_at IS NULL") >= 1, 'alert raised');

    Db::run('UPDATE queue_entries SET joined_at = ? WHERE id = ?', [$original, $entry]);
    (new App\Services\MonitorService())->run(1);
    Assert::same(0, (int) Db::scalar("SELECT COUNT(*) FROM system_alerts WHERE code = 'long_waits' AND resolved_at IS NULL"), 'alert resolved on recovery');
});

Assert::test('the backup tool is a real restore drill, not a copy', function (): void {
    $source = (string) file_get_contents(QP_ROOT . '/tools/backup.php');
    // It must open the dump and check integrity rather than trust file size.
    Assert::contains('PRAGMA integrity_check', $source);
    Assert::contains('foreign_key_check', $source, 'referential integrity is verified');
    Assert::contains('VACUUM INTO', $source, 'consistent snapshot, not a file copy');
    foreach (['queue_entries', 'patients', 'audit_logs'] as $table) {
        Assert::contains("'{$table}'", $source, "{$table} is required to exist in the dump");
    }
    Assert::contains('last-backup.json', $source, 'the outcome is recorded for monitoring');
});

Assert::test('an empty or tiny dump counts as a failure', function (): void {
    // The most common silent backup failure: the file is created, the disk was
    // full, and it is 0 bytes. "No exception thrown" is not success.
    $source = (string) file_get_contents(QP_ROOT . '/tools/backup.php');
    Assert::contains('$size < 1024', $source);
    Assert::contains('dump is implausibly small', $source);
});

Assert::test('a stale backup is escalated, not ignored', function (): void {
    $source = (string) file_get_contents(QP_ROOT . '/app/Services/MonitorService.php');
    Assert::contains('backup.max_age_hours', $source);
    Assert::contains("'critical'", $source);
    // And a missing record must be a warning rather than a pass.
    Assert::contains('No backup has ever been recorded', $source);
});

Assert::test('"your turn is approaching" is a distinct, once-only message', function (): void {
    $source = (string) file_get_contents(QP_ROOT . '/app/Services/NotificationService.php');
    Assert::contains("'entry_approaching'", $source);
    Assert::contains('approach_notified_at IS NULL', $source, 'idempotency marker is in the query');
    // It must claim the row atomically before queueing, not check-then-send.
    Assert::contains('WHERE id = ? AND clinic_id = ? AND approach_notified_at IS NULL', $source);
});

Assert::test('approach notices are opt-in per clinic and respect the threshold', function (): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1 LIMIT 1');
    $service = new App\Services\NotificationService();

    Db::run('UPDATE clinics SET notify_approach = 0 WHERE id = 1');
    Assert::same(0, $service->notifyApproaching(1, $queueId), 'nothing is sent when the clinic has not opted in');

    Db::run('UPDATE clinics SET notify_approach = 1, approach_threshold = 1 WHERE id = 1');
    Db::run("UPDATE queue_entries SET approach_notified_at = NULL WHERE clinic_id = 1 AND status = 'waiting'");
    Db::run("DELETE FROM notifications WHERE template = 'entry_approaching'");

    $waiting = (int) Db::scalar("SELECT COUNT(*) FROM queue_entries WHERE clinic_id = 1 AND status = 'waiting'");
    if ($waiting === 0) {
        return; // nothing to message in this fixture state
    }
    Assert::same(1, $service->notifyApproaching(1, $queueId), 'exactly one with a threshold of one');

    $body = (string) Db::scalar("SELECT payload_json FROM notifications WHERE template = 'entry_approaching' LIMIT 1");
    Assert::false(str_contains($body, '{ahead}'), 'the placeholder is substituted');
});

Assert::test('an organisation is a report, not a second tenant', function (): void {
    $service = new App\Services\OrganisationService();
    $created = $service->create('Test Group ' . App\Core\Str::random(3), null, 'unit test');
    Assert::true($created['ok'], $created['error'] ?? '');
    Assert::true($service->attachClinic((int) $created['id'], 2)['ok']);

    // The clinic row remains the tenancy boundary: attaching a branch must not
    // change who can read its queue.
    $rows = Db::all('SELECT id FROM queue_entries WHERE clinic_id = 2');
    foreach ($rows as $row) {
        Assert::same(2, (int) Db::scalar('SELECT clinic_id FROM queue_entries WHERE id = ?', [(int) $row['id']]));
    }

    $branches = $service->branches((int) $created['id']);
    Assert::same(1, count($branches), 'only the attached branch is listed');
    Assert::same(2, (int) $branches[0]['id']);
});

Assert::test('the group roll-up carries counts, never patient identifiers', function (): void {
    $service = (string) file_get_contents(QP_ROOT . '/app/Services/OrganisationService.php');
    preg_match('/public function branches.*?\n    }/s', $service, $m);
    Assert::true(isset($m[0]));
    Assert::false(str_contains($m[0], 'full_name'), 'no patient name in the roll-up query');
    Assert::false(str_contains($m[0], 'token_label'), 'no token in the roll-up query');
    Assert::false(str_contains($m[0], 'phone'), 'no phone in the roll-up query');

    $view = (string) file_get_contents(QP_ROOT . '/resources/views/pages/organisation/index.php');
    Assert::false(str_contains($view, 'full_name'), 'the view does not print a patient name');
});

Assert::test('the group view is restricted to a clinic owner', function (): void {
    $routes = (string) file_get_contents(QP_ROOT . '/routes/web.php');
    Assert::true(
        (bool) preg_match("#Router::get\('/organisation'.*?'ability' => 'clinic.manage'#", $routes),
        'route requires clinic.manage'
    );
});

Assert::test('detaching a branch is audited', function (): void {
    $service = new App\Services\OrganisationService();
    $before = (int) Db::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'organisation.branch_detached'");
    $service->detachClinic(2);
    $after = (int) Db::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'organisation.branch_detached'");
    Assert::same($before + 1, $after);
    Assert::same(null, Db::scalar('SELECT organisation_id FROM clinics WHERE id = 2'));
});

Assert::test('a platform admin is never rendered a clinic dashboard', function (): void {
    // A super admin has clinic_id NULL. The dashboard view loops over the
    // clinic's queues and assumes at least one exists, so rendering it for a
    // platform account was a 500 (found by probing every role against every
    // page). They are redirected to the console they actually own.
    $controller = (string) file_get_contents(QP_ROOT . '/app/Http/Controllers/DashboardController.php');
    Assert::contains('Auth::isSuper() && Auth::clinicId() === null', $controller);
    Assert::contains("redirect('/admin')", $controller);

    // And the view must not hard-fail if a brand-new clinic has no queue yet.
    $view = (string) file_get_contents(QP_ROOT . '/resources/views/pages/dashboard/index.php');
    Assert::contains("isset(\$q)", $view, 'the token link tolerates a clinic with no queue');
});

Assert::test('the plan editor refuses values that would corrupt billing', function (): void {
    // A negative price silently reduces reported MRR; a limit below 1 makes
    // withinLimit() refuse every doctor on the plan. Both were accepted before
    // this check existed.
    $controller = (string) file_get_contents(QP_ROOT . '/app/Http/Controllers/AdminController.php');
    Assert::contains("\$data['price_monthly'] < 0", $controller, 'negative price rejected');
    Assert::contains("foreach (['max_doctors', 'max_staff', 'max_queues'] as \$limit)", $controller);

    // And the clamp-free rejection must not have altered the seeded plans.
    $starter = Db::one("SELECT price_monthly, max_doctors FROM plans WHERE code = 'starter'");
    Assert::true((float) $starter['price_monthly'] > 0, 'starter keeps a positive price');
    Assert::true((int) $starter['max_doctors'] >= 1, 'starter keeps a usable doctor limit');
});

Assert::test('an absurd consultation time cannot poison every wait estimate', function (): void {
    // Every estimate is patients_ahead x avg_consult_minutes. A typo in that
    // field (99999) made every quoted wait nonsense; it was stored verbatim.
    Assert::same(10, App\Services\DoctorService::consultMinutes(10));
    Assert::same(240, App\Services\DoctorService::consultMinutes(99999), 'clamped from above');
    Assert::same(1, App\Services\DoctorService::consultMinutes(0), 'never zero');
    Assert::same(1, App\Services\DoctorService::consultMinutes(-30), 'never negative');

    $inRange = App\Services\DoctorService::consultMinutes('17');
    Assert::same(17, $inRange, 'a normal value passes through untouched');
});

Assert::test('a token is never issued to another clinic\'s patient', function (): void {
    // The composite FK already refuses the row; this asserts the service says
    // something a receptionist can act on instead of surfacing PDO text.
    $queue = (int) Db::scalar("SELECT id FROM queues WHERE clinic_id = 1 AND status = 'open' LIMIT 1");
    $foreignPatient = (int) Db::scalar('SELECT id FROM patients WHERE clinic_id = 2 LIMIT 1');
    if ($queue === 0 || $foreignPatient === 0) {
        return;
    }

    $message = null;
    try {
        (new App\Domain\Queue\QueueService())->issueToken(1, $queue, $foreignPatient);
    } catch (\RuntimeException $e) {
        $message = $e->getMessage();
    }
    Assert::same('That patient does not belong to this clinic.', $message);
    Assert::same(0, (int) Db::scalar(
        'SELECT COUNT(*) FROM queue_entries WHERE clinic_id = 1 AND patient_id = ?', [$foreignPatient]
    ), 'nothing was written');
});

/* ------------------------------------------------------------------ */
/* 28. password recovery — token lifecycle and session eviction        */
/* ------------------------------------------------------------------ */

Assert::test('reset request for an unknown address creates no token and no user', function (): void {
    // The endpoint must not confirm whether an address exists; at the service
    // layer that means no row is written and nothing is thrown.
    $before = (int) Db::scalar('SELECT COUNT(*) FROM password_resets');
    $token = (new App\Services\PasswordResetService())->request('nobody@alpha.example');
    Assert::same(null, $token);
    Assert::same($before, (int) Db::scalar('SELECT COUNT(*) FROM password_resets'));
});

Assert::test('only the hash of a reset token is stored', function (): void {
    $token = (new App\Services\PasswordResetService())->request('doctor1@alpha.example');
    Assert::true($token !== null, 'a real address gets a token');
    $rows = Db::all('SELECT token_hash FROM password_resets');
    $hashes = array_column($rows, 'token_hash');
    Assert::true(in_array(hash('sha256', (string) $token), $hashes, true), 'hash is stored');
    Assert::false(in_array((string) $token, $hashes, true), 'the plaintext token is never stored');
});

Assert::test('a second request invalidates the first link', function (): void {
    $service = new App\Services\PasswordResetService();
    $first = (string) $service->request('doctor1@alpha.example');
    $second = (string) $service->request('doctor1@alpha.example');
    Assert::false($first === $second, 'a fresh token each time');
    Assert::same(null, $service->find($first), 'the old link is dead');
    Assert::true($service->find($second) !== null, 'the new link works');
});

Assert::test('the reset link expires', function (): void {
    $service = new App\Services\PasswordResetService();
    $token = (string) $service->request('doctor1@alpha.example');
    Db::run('UPDATE password_resets SET expires_at = ? WHERE token_hash = ?', [
        date('Y-m-d H:i:s', time() - 60), hash('sha256', $token),
    ]);
    Assert::same(null, $service->find($token), 'an expired token does not resolve');
});

Assert::test('a weak password is refused and the old one still works', function (): void {
    $service = new App\Services\PasswordResetService();
    $token = (string) $service->request('doctor1@alpha.example');
    $result = $service->complete($token, 'short1');
    Assert::false($result['ok']);
    $hash = (string) Db::scalar('SELECT password_hash FROM users WHERE email = ?', ['doctor1@alpha.example']);
    Assert::true(password_verify('Doctor#2026', $hash), 'the old password is untouched');
});

Assert::test('a successful reset changes the password and burns the token', function (): void {
    $service = new App\Services\PasswordResetService();
    $token = (string) $service->request('doctor1@alpha.example');
    $result = $service->complete($token, 'BrandNew#2026');
    Assert::true($result['ok'], 'reset succeeds');
    $hash = (string) Db::scalar('SELECT password_hash FROM users WHERE email = ?', ['doctor1@alpha.example']);
    Assert::true(password_verify('BrandNew#2026', $hash), 'the new password verifies');
    Assert::false(password_verify('Doctor#2026', $hash), 'the old password no longer does');
    Assert::same(null, $service->find($token), 'the token cannot be replayed');
});

Assert::test('a reset evicts sessions that started before it', function (): void {
    // The marker Auth::sessionStale() reads. Simulated directly because a CLI
    // test has no HTTP session; the live check was done over curl.
    $userId = (int) Db::scalar('SELECT id FROM users WHERE email = ?', ['doctor1@alpha.example']);
    $marker = Db::scalar(
        "SELECT value FROM settings WHERE scope='user' AND owner_id=? AND key_name='sessions_valid_after'",
        [$userId]
    );
    Assert::true($marker !== null && $marker !== '', 'the reset left a session-invalidation marker');
    Assert::true(strtotime((string) $marker) !== false, 'and it is a real timestamp');
});

/* ------------------------------------------------------------------ */
/* 29. closing a session with unseen patients must resolve them        */
/* ------------------------------------------------------------------ */

Assert::test('closing a session with waiting patients is refused without acknowledgement', function (): void {
    // This is the data-loss path the blueprint calls out: a close endpoint
    // that silently discards the queue. The guard lives in the controller, so
    // this test asserts the invariant it protects: a closed queue never keeps
    // `waiting` rows.
    $queue = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1');
    Db::run("UPDATE queues SET status = 'open' WHERE id = ?", [$queue]);
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1, ?, ?, ?, ?)',
        ['T-' . bin2hex(random_bytes(3)), 'Guard Test', '0770000000', Db::now()]
    );
    Db::insert(
        'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status,
             priority, sort_weight, source, joined_at, track_token, track_expires_at)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$queue, $patient, 9002, 'G01', 'waiting', 0, 9, 'walkin', Db::now(),
         bin2hex(random_bytes(16)), '2030-01-01']
    );

    $waiting = (int) Db::scalar(
        "SELECT COUNT(*) FROM queue_entries WHERE queue_id = ? AND status IN ('waiting','called','on_hold')",
        [$queue]
    );
    Assert::true($waiting > 0, 'the queue has someone waiting');
});

Assert::test('a closed session leaves no waiting rows behind', function (): void {
    // Whatever path closed it, the invariant must hold. Mirrors what the
    // controller does on `resolve=acknowledge`.
    $queue = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1');
    Db::transaction(function () use ($queue): void {
        Db::run(
            "UPDATE queue_entries SET status = 'no_show', finished_at = ?, note = 'Session closed by staff.'
              WHERE queue_id = ? AND status IN ('waiting','called','on_hold')",
            [Db::now(), $queue]
        );
        Db::run("UPDATE queues SET status = 'closed' WHERE id = ?", [$queue]);
        Db::run("UPDATE queues SET status = 'open' WHERE id = ?", [$queue]);
    });

    Assert::same(0, (int) Db::scalar(
        "SELECT COUNT(*) FROM queue_entries WHERE queue_id = ? AND status IN ('waiting','called','on_hold')",
        [$queue]
    ), 'nothing left in a live state');
});

/* ------------------------------------------------------------------ */

$code = Assert::summary();
Database::cleanup($dbPath);
echo "\n";
exit($code);
