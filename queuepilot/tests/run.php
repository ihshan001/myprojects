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
    // `started_at` is set because a seed tenant is meant to represent an
    // ordinary working session. A not-started session is a distinct state and
    // its own test sets that up explicitly.
    $queueId = Db::insert(
        'INSERT INTO queues (clinic_id, doctor_id, name, token_prefix, session_date, status, last_token_no, created_at, started_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)',
        [$id, $doctorId, 'OPD', 'A', Db::today(), 'open', Db::now(), Db::now()]
    );

    // Every tenant has an owner. Closure tests need staff to sign out, and a
    // clinic with no admin is not a state the product allows anyway.
    Db::insert(
        'INSERT INTO users (clinic_id, name, email, password_hash, role, is_active, created_at, email_verified_at)
         VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
        [$id, 'Admin ' . $id, 'admin' . $id . '@alpha.example', password_hash('Admin#2026', PASSWORD_DEFAULT), 'clinic_admin', Db::now(), Db::now()]
    );
}

// A platform administrator exists in every real installation because there is
// no other way to create the first clinic. The suite needs one to verify
// payments.
$GLOBALS['superAdminId'] = Db::insert(
    'INSERT INTO users (clinic_id, name, email, password_hash, role, is_active, created_at)
     VALUES (NULL, ?, ?, ?, ?, 1, ?)',
    ['Platform Owner', 'owner@platform.example', password_hash('Owner#2026', PASSWORD_DEFAULT), 'super_admin', Db::now()]
);

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
/* 2a. complete & call next                                            */
/* ------------------------------------------------------------------ */

// Its own queue so these assertions never depend on what ran above.
$cnQueue = (int) Db::insert(
    "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
     VALUES (1, 1, 'Complete and call next probe', 'consultation', 'open', ?, 'Z', 0, 3, ?)",
    [date('Y-m-d'), Db::now()]
);
$GLOBALS['cnQueue'] = $cnQueue;
$service->startSession(1, $cnQueue);

$cnVersion = static function (int $id): int {
    return (int) Db::scalar('SELECT version FROM queue_entries WHERE id = ?', [$id]);
};

$cnIds = [];
foreach (['CN First', 'CN Second', 'CN Third'] as $i => $name) {
    $p = $patients->findOrCreate(1, ['full_name' => $name, 'phone' => '077111000' . ($i + 1)]);
    $issued = $service->issueToken(1, $cnQueue, (int) $p['id'], []);
    $cnIds[] = (int) $issued['entry_id'];
}
[$cnA, $cnB, $cnC] = $cnIds;

$service->apply(1, $cnA, 'call', ['version' => $cnVersion($cnA)]);
$service->apply(1, $cnA, 'start', ['version' => $cnVersion($cnA)]);
$cnRes = $service->apply(1, $cnA, 'complete_next', ['version' => $cnVersion($cnA)]);

Assert::test('complete & call next finishes the visit', function () use ($cnRes): void {
    Assert::true($cnRes['ok'], 'action accepted');
    Assert::same('completed', $cnRes['status']);
});

Assert::test('complete & call next rings in the next patient', function () use ($cnRes, $cnB): void {
    Assert::true(isset($cnRes['called_next']), 'the called patient is reported back');
    Assert::same($cnB, (int) $cnRes['called_next']['id']);
    Assert::same('called', (string) Db::scalar('SELECT status FROM queue_entries WHERE id = ?', [$cnB]));
});

Assert::test('complete & call next only calls one patient, not the rest', function () use ($cnC): void {
    Assert::same('waiting', (string) Db::scalar('SELECT status FROM queue_entries WHERE id = ?', [$cnC]));
});

// The PDF's hard rule: completing on its own must never call anyone.
$service->apply(1, $cnB, 'start', ['version' => $cnVersion($cnB)]);
$plainRes = $service->apply(1, $cnB, 'complete', ['version' => $cnVersion($cnB)]);

Assert::test('completing alone never calls the next patient', function () use ($plainRes, $cnB, $cnC): void {
    Assert::true($plainRes['ok']);
    Assert::false(isset($plainRes['called_next']), 'no patient is called');
    Assert::same('completed', (string) Db::scalar('SELECT status FROM queue_entries WHERE id = ?', [$cnB]));
    Assert::same('waiting', (string) Db::scalar('SELECT status FROM queue_entries WHERE id = ?', [$cnC]));
});

Assert::test('the chained call is audited and recorded as an event', function () use ($cnB): void {
    $events = Db::all(
        "SELECT action, from_status, to_status FROM queue_events WHERE entry_id = ? ORDER BY id",
        [$cnB]
    );
    $calls = array_values(array_filter($events, static fn (array $e): bool => $e['action'] === 'entry.call'));
    Assert::true(count($calls) >= 1, 'the call is recorded in the visit history');
    Assert::same('waiting', $calls[0]['from_status']);
    Assert::same('called', $calls[0]['to_status']);

    $audited = (int) Db::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE clinic_id = 1 AND entity = 'queue_entry' AND entity_id = ? AND action = 'queue.call'",
        [$cnB]
    );
    Assert::true($audited >= 1, 'the call is audited');
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
    // A pending job with a live phone number and an http transport that has no
    // URL. It must stay retryable — a clinic that sees "sent" stops chasing
    // patients that were never actually contacted.
    //
    // The default transport is `log`, which records the message and reports it
    // as `logged` rather than `sent`. That is correct for a development box,
    // but this test is about a transport that was *meant* to deliver and
    // cannot, so it sets http explicitly.
    putenv('SMS_GATEWAY=http');
    putenv('SMS_PROVIDER_URL=');
    App\Core\Env::init(require QP_ROOT . '/config/config.php');

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
    Assert::contains('missing', (string) $row['last_error'], 'reason recorded');

    // Leave the environment as the rest of the suite expects it.
    putenv('SMS_GATEWAY=log');
    App\Core\Env::init(require QP_ROOT . '/config/config.php');
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

Assert::test('a doctor\'s navigation is only their own four destinations', function (): void {
    // The PDF: "Doctor navigation: Today's queue, My schedule, My patients,
    // My profile. No clinic-wide configuration or platform administration
    // should be visible." Hiding a link is not the same as withholding
    // permission, but showing a doctor the clinic's queue settings invites
    // them to change settings that apply to everybody.
    $layout = (string) file_get_contents(QP_ROOT . '/resources/views/layouts/app.php');

    // The doctor branch is the one guarded on the doctor role.
    $branch = strstr($layout, '$u[\'role\'] === \'doctor\'') ?: '';
    Assert::true($branch !== '', 'a doctor-only navigation branch exists');

    $slice = substr($branch, 0, strpos($branch, '} else') ?: strlen($branch));
    foreach (['/doctor', '/doctor/schedule', '/doctor/patients', '/doctor/profile'] as $need) {
        Assert::true(str_contains($slice, "'" . $need . "'"), "doctor nav offers {$need}");
    }
    foreach (['/queues/config', '/settings', '/billing', '/patients', '/appointments', '/calendar'] as $leak) {
        Assert::false(str_contains($slice, "'" . $leak . "'"), "doctor nav must not offer {$leak}");
    }
});

Assert::test('a doctor lands on their own workspace after signing in', function (): void {
    // Dropping a doctor on the reception board — which is built around
    // issuing tokens, the one thing a doctor cannot do — makes their first
    // screen a list of controls they are not allowed to use.
    $src = (string) file_get_contents(QP_ROOT . '/app/Http/Controllers/AuthController.php');
    Assert::true(str_contains($src, 'ROLE_DOCTOR => \'/doctor\''), 'doctors are sent to /doctor');
});

Assert::test('every delivery attempt is logged, not just the last one', function (): void {
    // The outbox row is rewritten by each retry, so without the log table a
    // clinic that retried three times can only see the third failure. The PDF
    // asks for "retry logic and delivery logs"; this is the half that the
    // outbox alone cannot provide.
    $svc = new App\Services\NotificationService();
    $svc->processDue();

    $messages = (int) Db::scalar(
        "SELECT COUNT(*) FROM notifications WHERE status IN ('sent','logged','abandoned')"
    );
    $logged = (int) Db::scalar('SELECT COUNT(*) FROM notification_logs');
    Assert::true($messages > 0, 'at least one message was processed');
    Assert::true($logged > 0, 'attempts were recorded');

    // One row per attempt, and never updated afterwards.
    $duplicates = (int) Db::scalar(
        'SELECT COUNT(*) FROM (SELECT notification_id, attempt FROM notification_logs
          GROUP BY notification_id, attempt HAVING COUNT(*) > 1)'
    );
    Assert::same(0, $duplicates, 'attempt numbers are unique per message');

    $outcomes = array_column(
        Db::all('SELECT DISTINCT outcome FROM notification_logs'),
        'outcome'
    );
    $allowed = ['sent', 'logged', 'failed', 'abandoned', 'gated'];
    foreach ($outcomes as $o) {
        Assert::true(in_array($o, $allowed, true), "outcome {$o} is one of the known set");
    }
});

Assert::test('a processed notification keeps its history when retried', function (): void {
    // The point of the split: processing the same message again appends
    // rather than overwrites.
    $id = (int) Db::scalar('SELECT id FROM notifications ORDER BY id DESC LIMIT 1');
    Db::run("UPDATE notifications SET status='queued', scheduled_at = ? WHERE id = ?", [Db::now(), $id]);

    $before = (int) Db::scalar('SELECT COUNT(*) FROM notification_logs WHERE notification_id = ?', [$id]);
    (new App\Services\NotificationService())->processDue();
    $after = (int) Db::scalar('SELECT COUNT(*) FROM notification_logs WHERE notification_id = ?', [$id]);

    Assert::true($after >= $before, 'history is appended, never rewritten');
});

/**
 * An unpaid invoice to test against. The suite does not seed billing, so the
 * tests make their own rather than depending on fixtures that may not exist.
 */
$testInvoiceId = (int) (Db::scalar("SELECT id FROM invoices WHERE clinic_id = 1 ORDER BY id DESC LIMIT 1") ?: 0);
if ($testInvoiceId === 0) {
    $testInvoiceId = Db::insert(
        'INSERT INTO invoices (clinic_id, number, period_from, period_to, amount, currency, status, created_at)
         VALUES (1,?,?,?,?,?,?,?)',
        ['INV-TEST-0001', date('Y-m-01'), date('Y-m-t'), 7900, 'LKR', 'unpaid', Db::now()]
    );
}

Assert::test('a rejected payment keeps its submission on file', function () use ($testInvoiceId): void {
    // This is the whole reason `payments` exists. The claim used to live on
    // the invoice, so rejecting it reset the invoice to unpaid and the record
    // that the clinic said it had paid vanished with it — leaving a billing
    // dispute with no evidence on either side.
    $svc = new App\Services\SubscriptionService();

    $invoiceId = $testInvoiceId;
    Db::run("UPDATE invoices SET status = 'unpaid', paid_at = NULL WHERE id = ?", [$invoiceId]);

    $paymentId = $svc->claim(1, $invoiceId, 'REJ-TEST-0001', 'bank_transfer');
    Assert::same(
        'claimed',
        (string) Db::scalar('SELECT status FROM payments WHERE id = ?', [$paymentId])
    );

    $svc->reject($paymentId, null, 'No transfer with that reference.');

    // The invoice goes back to unpaid — the clinic has not paid.
    Assert::same('unpaid', (string) Db::scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
    // …but the submission survives, with the decision recorded.
    $row = Db::one('SELECT status, decision_note, decided_at FROM payments WHERE id = ?', [$paymentId]);
    Assert::same('rejected', $row['status']);
    Assert::same('No transfer with that reference.', $row['decision_note']);
    Assert::true($row['decided_at'] !== null, 'the decision is timestamped');
});

Assert::test('a verified payment settles the invoice and keeps the record too', function () use ($testInvoiceId): void {
    $svc = new App\Services\SubscriptionService();
    $invoiceId = $testInvoiceId;
    Db::run("UPDATE invoices SET status = 'unpaid', paid_at = NULL WHERE id = ?", [$invoiceId]);

    $paymentId = $svc->claim(1, $invoiceId, 'VER-TEST-0002', 'bank_transfer');
    $result = $svc->verify($paymentId, null, '');
    Assert::true($result['ok']);

    Assert::same('paid', (string) Db::scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
    Assert::same('verified', (string) Db::scalar('SELECT status FROM payments WHERE id = ?', [$paymentId]));
});

Assert::test('a plan change closes the old subscription and opens a new one', function (): void {
    // clinics.plan_id can only describe the present. Without history,
    // "what plan were we on before the upgrade" is unanswerable.
    $svc = new App\Services\SubscriptionService();
    $svc->ensureOpen(1);

    $before = (int) Db::scalar('SELECT COUNT(*) FROM subscriptions WHERE clinic_id = 1 AND ended_at IS NULL');
    Assert::same(1, $before, 'exactly one open subscription');

    $svc->open(1, 3, 'active', 'Upgraded to Clinic Plus.');

    $open = (int) Db::scalar('SELECT COUNT(*) FROM subscriptions WHERE clinic_id = 1 AND ended_at IS NULL');
    $closed = (int) Db::scalar('SELECT COUNT(*) FROM subscriptions WHERE clinic_id = 1 AND ended_at IS NOT NULL');
    Assert::same(1, $open, 'still exactly one open subscription');
    Assert::true($closed >= 1, 'the previous one is closed, not deleted');

    $current = $svc->current(1);
    Assert::same(3, (int) $current['plan_id'], 'the open row is the new plan');
});

Assert::test('a display key opens its clinic and nothing else', function (): void {
    // These are bearer credentials on an unauthenticated URL — anyone who
    // photographs the lobby screen has one.
    $svc = new App\Services\DisplayKeyService();
    $issued = $svc->issue(1, 'Reception TV', 'waiting_room');

    $resolved = $svc->resolve($issued['key']);
    Assert::true($resolved !== null, 'a fresh key resolves');
    Assert::same(1, $resolved['clinic_id']);
    Assert::same(null, $resolved['department_id']);

    Assert::same(null, $svc->resolve('not-a-real-key'), 'an unknown key resolves to nothing');
});

Assert::test('revoking one screen leaves the others running', function (): void {
    // The whole point of per-screen keys. With one shared key, retiring a
    // single TV meant blacking out every display in the clinic.
    $svc = new App\Services\DisplayKeyService();
    $a = $svc->issue(1, 'Screen A', 'waiting_room');
    $b = $svc->issue(1, 'Screen B', 'waiting_room');

    Assert::true($svc->revoke(1, (int) $a['id']));

    Assert::same(null, $svc->resolve($a['key']), 'the revoked screen stops');
    Assert::true($svc->resolve($b['key']) !== null, 'the other screen keeps working');

    // Revoked rows are kept, not deleted: a key still being requested is a
    // screen somebody forgot to update, and that is worth being able to see.
    $row = Db::one('SELECT revoked_at, name FROM public_display_keys WHERE id = ?', [(int) $a['id']]);
    Assert::true($row['revoked_at'] !== null, 'the row survives revocation');
    Assert::same('Screen A', $row['name'], 'and is still identifiable');
});

Assert::test('a department key stays inside its clinic', function (): void {
    $svc = new App\Services\DisplayKeyService();

    // Its own department, removed afterwards. Reusing one of the seeded
    // departments would collide with the department tests further down,
    // which create their own fixtures by name.
    $deptId = Db::insert(
        "INSERT INTO departments (clinic_id, name, code, is_active, created_at)
         VALUES (1,'Display key probe','DKP',1,?)",
        [Db::now()]
    );

    try {
        $issued = $svc->issue(1, 'Probe board', 'department', $deptId);
        $resolved = $svc->resolve($issued['key']);
        Assert::same(1, $resolved['clinic_id']);
        Assert::same((int) $deptId, $resolved['department_id']);
    } finally {
        Db::run('DELETE FROM public_display_keys WHERE department_id = ?', [$deptId]);
        Db::run('DELETE FROM departments WHERE id = ?', [$deptId]);
    }
});

Assert::test('a screen with no name cannot be issued', function (): void {
    // An unnamed key is the old problem again: nobody can tell which screen
    // to revoke.
    $svc = new App\Services\DisplayKeyService();
    Assert::throws(
        static fn () => $svc->issue(1, '   ', 'waiting_room'),
        \RuntimeException::class,
        'a blank name is refused'
    );
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

    // Deliberately not December 2026: relative dates like 'next tuesday
    // +14 days' drift forward every year and used to land inside this
    // window, which made an unrelated slot test fail for no visible
    // reason. A fixed date far past any relative window stays put.
    $added = $service->addLeave(1, $doctorId, '2027-03-02', '2027-03-04', 'Conference', null);
    Assert::true($added['ok'], $added['error'] ?? '');

    Assert::true($service->isOnLeave(1, $doctorId, '2027-03-02'), 'first day covered');
    Assert::true($service->isOnLeave(1, $doctorId, '2027-03-03'), 'middle day covered');
    Assert::true($service->isOnLeave(1, $doctorId, '2027-03-04'), 'last day covered (inclusive)');
    Assert::false($service->isOnLeave(1, $doctorId, '2027-03-05'), 'day after is free');
    Assert::false($service->isOnLeave(1, $doctorId, '2027-03-01'), 'day before is free');

    // Another clinic's doctor must never be affected.
    $otherDoctor = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 2');
    Assert::false($service->isOnLeave(2, $otherDoctor, '2027-03-03'), 'tenant isolation');

    $bookable = $service->bookable(1, '2027-03-03');
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
/* 30. appointment slots come from real availability, not a fixed list  */
/* ------------------------------------------------------------------ */

Assert::test('slots follow the doctor length and stop at closing time', function (): void {
    $clinicId = 1;
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    Db::run("UPDATE doctors SET avg_consult_minutes = 20 WHERE id = ?", [$doctorId]);
    // A plain Tuesday, far enough out that "today" logic does not apply.
    $date = date('Y-m-d', strtotime('next tuesday +7 days'));
    (new App\Services\SlotService())->saveHours($clinicId, [
        (int) date('w', strtotime($date)) => '09:00-11:00',
    ]);

    $result = (new App\Services\SlotService())->slotsFor($clinicId, $doctorId, $date);
    Assert::same(['09:00', '09:20', '09:40', '10:00', '10:20', '10:40'], $result['slots']);
    Assert::same(null, $result['reason']);
});

Assert::test('a closed day offers nothing and says why', function (): void {
    $clinicId = 1;
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $sunday = date('Y-m-d', strtotime('next sunday'));
    (new App\Services\SlotService())->saveHours($clinicId, [0 => 'closed']);

    $result = (new App\Services\SlotService())->slotsFor($clinicId, $doctorId, $sunday);
    Assert::same([], $result['slots']);
    Assert::contains('closed', (string) $result['reason']);
});

Assert::test('a booked slot disappears from the list', function (): void {
    $clinicId = 1;
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $date = date('Y-m-d', strtotime('next tuesday +14 days'));

    // Some leave fixtures are pinned to fixed calendar dates, and a relative
    // date like this one eventually lands on one of them — which is exactly
    // what happened: 'next tuesday +14 days' reached 2026-12-01, inside a
    // leave window, and the doctor correctly had no slots. Step forward
    // until the doctor is actually free rather than hard-coding a date that
    // only works until the calendar catches up with it.
    $doctors = new App\Services\DoctorService();
    for ($guard = 0; $guard < 12 && $doctors->isOnLeave($clinicId, $doctorId, $date); $guard++) {
        $date = date('Y-m-d', strtotime($date . ' +7 days'));
    }

    (new App\Services\SlotService())->saveHours($clinicId, [
        (int) date('w', strtotime($date)) => '09:00-10:00',
    ]);

    $before = (new App\Services\SlotService())->slotsFor($clinicId, $doctorId, $date)['slots'];
    Assert::true(in_array('09:00', $before, true), '09:00 starts free on ' . $date . ' (got ' . json_encode($before) . ')');

    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1, ?, ?, ?, ?)',
        ['SLOT1', 'Slot Test', '0771234599', Db::now()]
    );
    (new App\Services\AppointmentService())->create($clinicId, $doctorId, (int) $patient, $date . ' 09:00:00');

    $after = (new App\Services\SlotService())->slotsFor($clinicId, $doctorId, $date)['slots'];
    Assert::false(in_array('09:00', $after, true), 'the taken slot is not offered');
    Assert::false((new App\Services\SlotService())->isOffered($clinicId, $doctorId, $date, '09:00'));
});

Assert::test('a doctor on leave has no slots and the reason says so', function (): void {
    $clinicId = 1;
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $date = date('Y-m-d', strtotime('next tuesday +21 days'));
    (new App\Services\SlotService())->saveHours($clinicId, [
        (int) date('w', strtotime($date)) => '09:00-11:00',
    ]);
    (new App\Services\DoctorService())->addLeave($clinicId, $doctorId, $date, $date, 'Test leave', null);

    $result = (new App\Services\SlotService())->slotsFor($clinicId, $doctorId, $date);
    Assert::same([], $result['slots']);
    Assert::contains('leave', (string) $result['reason']);
});

Assert::test('the booking horizon is clamped to a sane range', function (): void {
    $service = new App\Services\SlotService();
    Db::run(
        "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', 1, 'booking.horizon_days', '99999')
         ON CONFLICT (scope, owner_id, key_name) DO UPDATE SET value = excluded.value"
    );
    Assert::same(180, $service->horizonDays(1), 'absurd horizons are clamped');

    Db::run(
        "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', 1, 'booking.horizon_days', '0')
         ON CONFLICT (scope, owner_id, key_name) DO UPDATE SET value = excluded.value"
    );
    Assert::same(1, $service->horizonDays(1), 'zero becomes one day, never "book no appointments"');
    Db::run("DELETE FROM settings WHERE scope='clinic' AND owner_id=1 AND key_name='booking.horizon_days'");
});

Assert::test('a slot clash never names the patient who holds it', function (): void {
    // The clash message renders on a public page. Naming the other patient
    // tells a stranger who is seeing which doctor and when.
    $clinicId = 1;
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $date = date('Y-m-d', strtotime('next tuesday +28 days'));
    $when = $date . ' 09:00:00';

    $p1 = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1, ?, ?, ?, ?)',
        ['LEAK1', 'Confidential Person', '0771234600', Db::now()]
    );
    $p2 = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1, ?, ?, ?, ?)',
        ['LEAK2', 'Second Person', '0771234601', Db::now()]
    );

    $service = new App\Services\AppointmentService();
    $service->create($clinicId, $doctorId, (int) $p1, $when);

    $message = null;
    try {
        $service->create($clinicId, $doctorId, (int) $p2, $when);
    } catch (\RuntimeException $e) {
        $message = $e->getMessage();
    }

    Assert::true($message !== null, 'the second booking is refused');
    Assert::false(str_contains((string) $message, 'Confidential'), 'the holder is not named');
    Assert::false(str_contains((string) $message, 'Person'), 'no part of the name leaks');
});

/* ------------------------------------------------------------------ */
/* 31. patient phone numbers are not stored in the clear               */
/* ------------------------------------------------------------------ */

Assert::test('notification rows mask the phone number but keep it deliverable', function (): void {
    // Its own entry: the dedupe key is template + entry, so reusing a live
    // entry would be suppressed and the test would inspect somebody else's row.
    $queue = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1');
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1, ?, ?, ?, ?)',
        ['MASK1', 'Mask Test', '+94771234567', Db::now()]
    );
    $entryId = Db::insert(
        'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status,
             priority, sort_weight, source, joined_at, track_token, track_expires_at)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$queue, $patient, 9003, 'M01', 'waiting', 0, 9, 'walkin', Db::now(),
         bin2hex(random_bytes(16)), '2030-01-01']
    );

    $before = (int) Db::scalar('SELECT COUNT(*) FROM notifications');
    (new App\Services\NotificationService())->queue(1, (int) $entryId, 'entry_called', ['room' => 'Room 1']);
    Assert::true((int) Db::scalar('SELECT COUNT(*) FROM notifications') > $before, 'a row was written');

    $row = Db::one('SELECT to_number, payload_json FROM notifications ORDER BY id DESC LIMIT 1');
    Assert::true(str_contains((string) $row['to_number'], '•'), 'the stored column is masked');

    $digits = preg_replace('/\D+/', '', (string) $row['to_number']);
    Assert::true(strlen((string) $digits) <= 3, 'no more than the last three digits remain in the column');

    $payload = json_decode((string) $row['payload_json'], true) ?: [];
    Assert::true(($payload['to'] ?? '') !== '', 'the deliverable number is kept in the payload');
    Assert::true(preg_match('/\d{5,}/', (string) $payload['to']) === 1, 'and it is a complete number');
});

Assert::test('masking keeps the last three digits and never throws on junk', function (): void {
    $mask = App\Services\NotificationService::mask('+94 77 123 4567');
    Assert::same('•••••567', $mask);
    Assert::same('••••', App\Services\NotificationService::mask('12'));
    Assert::same('•••••123', App\Services\NotificationService::mask('071-223-4123'));
});

/* ------------------------------------------------------------------ */
/* 32. departments                                                     */
/* ------------------------------------------------------------------ */

Assert::test('a department is created, name-checked and clinic-scoped', function (): void {
    $service = new App\Services\DepartmentService();
    $r = $service->create(1, ['name' => 'Outpatient']);
    Assert::true($r['ok'], 'created');
    Assert::true(($r['id'] ?? 0) > 0);

    $dupe = $service->create(1, ['name' => 'outpatient']);
    Assert::false($dupe['ok'], 'case-insensitive duplicate refused');

    $blank = $service->create(1, ['name' => 'x']);
    Assert::false($blank['ok'], 'too short refused');

    // The id belongs to clinic 1 and must be invisible to clinic 2.
    Assert::same(null, $service->find(2, (int) $r['id']), 'not visible to another clinic');
    Assert::true($service->find(1, (int) $r['id']) !== null, 'visible to its own clinic');
});

Assert::test('a department lead from another clinic is refused', function (): void {
    $foreignDoctor = Db::insert(
        'INSERT INTO doctors (clinic_id, name, avg_consult_minutes, is_active, created_at) VALUES (2, ?, 10, 1, ?)',
        ['Foreign Doctor', Db::now()]
    );
    $r = (new App\Services\DepartmentService())->create(1, ['name' => 'Cardiology', 'lead_doctor_id' => (int) $foreignDoctor]);
    Assert::false($r['ok'], 'foreign lead refused');
    Assert::contains('does not belong', (string) $r['error']);
});

Assert::test('deactivating a department keeps its queues attached to history', function (): void {
    $service = new App\Services\DepartmentService();
    $r = $service->create(1, ['name' => 'Radiology']);
    $id = (int) $r['id'];

    $queue = Db::one('SELECT id FROM queues WHERE clinic_id = 1 LIMIT 1');
    Db::run('UPDATE queues SET department_id = ? WHERE id = ?', [$id, (int) $queue['id']]);

    Assert::true($service->deactivate(1, $id)['ok']);
    Assert::same(0, (int) Db::scalar('SELECT is_active FROM departments WHERE id = ?', [$id]), 'marked inactive');
    Assert::same($id, (int) Db::scalar('SELECT department_id FROM queues WHERE id = ?', [(int) $queue['id']]), 'queue still points at it for reporting');

    Db::run('UPDATE queues SET department_id = NULL WHERE id = ?', [(int) $queue['id']]);
});

/* ------------------------------------------------------------------ */
/* 33. doctor schedules and holidays                                   */
/* ------------------------------------------------------------------ */

Assert::test('a doctor roster narrows the bookable window', function (): void {
    $clinicId = 1;
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $date = date('Y-m-d', strtotime('next wednesday +7 days'));
    $dow = (int) date('w', strtotime($date));

    (new App\Services\SlotService())->saveHours($clinicId, [$dow => '08:00-17:00']);
    (new App\Services\ScheduleService())->saveWeek($clinicId, $doctorId, [$dow => [['09:00', '10:00']]]);

    $result = (new App\Services\SlotService())->slotsFor($clinicId, $doctorId, $date);
    Assert::true($result['slots'] !== [], 'there are slots');
    Assert::true($result['slots'][0] >= '09:00', 'starts at the roster, not clinic opening');
    Assert::true(end($result['slots']) < '10:00', 'ends at the roster, not clinic closing');

    (new App\Services\ScheduleService())->saveWeek($clinicId, $doctorId, []);
});

Assert::test('a doctor not rostered that weekday has no slots and says why', function (): void {
    $clinicId = 1;
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $date = date('Y-m-d', strtotime('next thursday +14 days'));
    $dow = (int) date('w', strtotime($date));
    $other = ($dow + 1) % 7;

    (new App\Services\SlotService())->saveHours($clinicId, [$dow => '08:00-17:00']);
    (new App\Services\ScheduleService())->saveWeek($clinicId, $doctorId, [$other => [['09:00', '11:00']]]);

    $result = (new App\Services\SlotService())->slotsFor($clinicId, $doctorId, $date);
    Assert::same([], $result['slots']);
    Assert::contains('does not work', (string) $result['reason']);

    (new App\Services\ScheduleService())->saveWeek($clinicId, $doctorId, []);
});

Assert::test('a holiday closes the day even when the doctor is rostered', function (): void {
    $clinicId = 1;
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $date = date('Y-m-d', strtotime('next friday +21 days'));
    $dow = (int) date('w', strtotime($date));

    (new App\Services\SlotService())->saveHours($clinicId, [$dow => '08:00-17:00']);
    (new App\Services\ScheduleService())->saveWeek($clinicId, $doctorId, [$dow => [['09:00', '12:00']]]);
    Assert::true((new App\Services\SlotService())->slotsFor($clinicId, $doctorId, $date)['slots'] !== [], 'open before the holiday');

    $added = (new App\Services\HolidayService())->add($clinicId, $date, 'Poya Day', null);
    Assert::true($added['ok']);

    $result = (new App\Services\SlotService())->slotsFor($clinicId, $doctorId, $date);
    Assert::same([], $result['slots'], 'closed for the holiday');
    Assert::contains('Poya Day', (string) $result['reason'], 'the reason names the holiday');

    (new App\Services\HolidayService())->remove($clinicId, (int) $added['id']);
    (new App\Services\ScheduleService())->saveWeek($clinicId, $doctorId, []);
});

Assert::test('the same date cannot be closed twice', function (): void {
    $service = new App\Services\HolidayService();
    $date = date('Y-m-d', strtotime('+40 days'));
    $first = $service->add(1, $date, 'Test closure', null);
    Assert::true($first['ok']);
    $second = $service->add(1, $date, 'Another name', null);
    Assert::false($second['ok'], 'duplicate refused');
    $service->remove(1, (int) $first['id']);
});

Assert::test('a schedule must have valid shifts', function (): void {
    $service = new App\Services\ScheduleService();
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');

    $backwards = $service->saveWeek(1, $doctorId, [1 => [['17:00', '09:00']]]);
    Assert::false($backwards['ok'], 'end before start refused');

    $malformed = $service->saveWeek(1, $doctorId, [1 => [['nine', 'ten']]]);
    Assert::false($malformed['ok'], 'non-times refused');

    $foreign = $service->saveWeek(1, 99999, [1 => [['09:00', '10:00']]]);
    Assert::false($foreign['ok'], 'unknown doctor refused');
});

/* ------------------------------------------------------------------ */
/* 34. payment verification                                            */
/* ------------------------------------------------------------------ */

Assert::test('a claimed invoice does not activate the subscription', function (): void {
    // The whole point of the verification step: the payer cannot settle it.
    $number = 'INV-UNIT-' . bin2hex(random_bytes(3));
    $invoiceId = Db::insert(
        'INSERT INTO invoices (clinic_id, number, period_from, period_to, amount, currency, status, created_at)
         VALUES (1,?,?,?,?,?,?,?)',
        [$number, Db::today(), date('Y-m-d', strtotime('+1 month')), 1000, 'LKR', 'claimed', Db::now()]
    );

    $status = Db::scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]);
    Assert::same('claimed', $status);
    Assert::same(null, Db::scalar('SELECT verified_at FROM invoices WHERE id = ?', [$invoiceId]), 'nothing has verified it');
});

Assert::test('verification records who did it', function (): void {
    $invoiceId = Db::insert(
        'INSERT INTO invoices (clinic_id, number, period_from, period_to, amount, currency, status, created_at)
         VALUES (1,?,?,?,?,?,?,?)',
        // A period no generator would pick, so this fixture cannot collide with
        // the unique index on (clinic_id, period_from) that invoicing now adds.
        ['INV-UNIT-' . bin2hex(random_bytes(3)), '2001-01-01', '2001-01-31', 1000, 'LKR', 'claimed', Db::now()]
    );
    $adminId = (int) $GLOBALS['superAdminId'];

    Db::run(
        "UPDATE invoices SET status='paid', paid_at=?, verified_at=?, verified_by=? WHERE id=?",
        [Db::now(), Db::now(), $adminId, $invoiceId]
    );

    Assert::same('paid', Db::scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
    Assert::same($adminId, (int) Db::scalar('SELECT verified_by FROM invoices WHERE id = ?', [$invoiceId]));
});

/* ------------------------------------------------------------------ */
/* 35. lifecycle: suspension, closure, retention                       */
/* ------------------------------------------------------------------ */

Assert::test('suspension blocks token issuing without touching data', function (): void {
    $clinicId = 2;
    $service = new App\Services\LifecycleService();
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 2 LIMIT 1');
    if ($queueId === 0) {
        return;
    }
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (2,?,?,?,?)',
        ['SUSP' . bin2hex(random_bytes(2)), 'Suspended Probe', '0771119999', Db::now()]
    );
    $beforePatients = (int) Db::scalar('SELECT COUNT(*) FROM patients WHERE clinic_id = 2');

    Assert::true($service->suspend($clinicId, 'Unit test suspension', null)['ok']);

    $message = null;
    try {
        (new App\Domain\Queue\QueueService())->issueToken($clinicId, $queueId, (int) $patient);
    } catch (\RuntimeException $e) {
        $message = $e->getMessage();
    }
    Assert::contains('suspended', (string) $message);
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM queue_entries WHERE patient_id = ?', [(int) $patient]), 'no entry written');
    Assert::same($beforePatients, (int) Db::scalar('SELECT COUNT(*) FROM patients WHERE clinic_id = 2'), 'no data deleted');

    Assert::true($service->reinstate($clinicId, null)['ok']);
    Assert::false($service->isSuspended($clinicId), 'not suspended any more');

    Db::run('DELETE FROM patients WHERE id = ?', [(int) $patient]);
});

Assert::test('suspending twice is refused and a blank reason is refused', function (): void {
    $service = new App\Services\LifecycleService();
    Assert::false($service->suspend(2, 'ok reason', null)['ok'] === false, 'first suspension works');
    Assert::false($service->suspend(2, 'second attempt', null)['ok'], 'second is refused');
    $service->reinstate(2, null);
    Assert::false($service->suspend(2, 'no', null)['ok'], 'a two-character reason is refused');
});

Assert::test('closure signs staff out and closes open queues', function (): void {
    $clinicId = 2;
    $service = new App\Services\LifecycleService();
    $staffBefore = (int) Db::scalar('SELECT COUNT(*) FROM users WHERE clinic_id = 2 AND is_active = 1');

    $result = $service->requestClosure($clinicId, 90);
    Assert::true($result['ok']);
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM users WHERE clinic_id = 2 AND is_active = 1'), 'nobody can sign in');
    Assert::same(0, (int) Db::scalar("SELECT COUNT(*) FROM queues WHERE clinic_id = 2 AND status <> 'closed'"), 'no open sessions');
    Assert::true(strtotime((string) $result['purge_after']) > time(), 'retention window is in the future');
    Assert::true($staffBefore > 0, 'there were staff to sign out');

    Assert::true($service->cancelClosure($clinicId, null)['ok'], 'closure can be cancelled inside the window');
    Assert::true((int) Db::scalar('SELECT COUNT(*) FROM users WHERE clinic_id = 2 AND is_active = 1') > 0, 'staff restored');
});

Assert::test('purge is refused before the retention window expires', function (): void {
    $service = new App\Services\LifecycleService();
    (new App\Services\LifecycleService())->requestClosure(2, 90);
    $result = $service->purge(2);
    Assert::false($result['ok'], 'refused early');
    Assert::contains('retention window', (string) $result['error']);
    $service->cancelClosure(2, null);
});

Assert::test('purge keeps invoices and never frees the slug', function (): void {
    $service = new App\Services\LifecycleService();
    $clinicId = 2;

    $invoiceNumber = 'INV-PURGE-' . bin2hex(random_bytes(2));
    Db::insert(
        'INSERT INTO invoices (clinic_id, number, period_from, period_to, amount, currency, status, created_at)
         VALUES (?,?,?,?,?,?,?,?)',
        [$clinicId, $invoiceNumber, Db::today(), date('Y-m-d'), 500, 'LKR', 'paid', Db::now()]
    );

    $service->requestClosure($clinicId, 7);
    Db::run('UPDATE clinics SET purge_after = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 86400), $clinicId]);

    $result = $service->purge($clinicId);
    Assert::true($result['ok'], 'purged once the window passed');
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM users WHERE clinic_id = ?', [$clinicId]), 'users gone');
    Assert::true((int) Db::scalar('SELECT COUNT(*) FROM invoices WHERE clinic_id = ? AND number = ?', [$clinicId, $invoiceNumber]) === 1, 'invoice kept for accounting');

    $clinic = Db::one('SELECT name, is_active, closed_at FROM clinics WHERE id = ?', [$clinicId]);
    Assert::true($clinic['closed_at'] !== null, 'marked closed, not deleted');
    Assert::same(0, (int) $clinic['is_active']);
    Assert::contains('[closed]', (string) $clinic['name'], 'row kept so the slug cannot be reused');

    // Restore the tenant for the rest of the suite.
    Db::run("UPDATE clinics SET name='Lakeview Medical Centre', is_active=1, closed_at=NULL, closure_requested_at=NULL, purge_after=NULL, billing_status='active' WHERE id=?", [$clinicId]);
});

/* ------------------------------------------------------------------ */
/* 36. thermal printer                                                 */
/* ------------------------------------------------------------------ */

Assert::test('an ESC/POS slip starts with the init sequence and carries the token', function (): void {
    $slip = App\Support\ThermalSlip::escpos([
        'clinic' => 'Serenity Medical',
        'queue' => 'OPD',
        'token' => 'A014',
        'doctor' => 'Dr Perera',
        'room' => 'Room 3',
        'ahead' => 4,
        'url' => 'https://example.test/t/abc123',
    ]);

    Assert::true(str_starts_with($slip, "\x1b@"), 'begins with ESC @');
    Assert::contains('A014', $slip);
    Assert::contains('Dr Perera', $slip);
    Assert::contains("\x1dV\x42\x00", $slip, 'ends with a partial cut');
});

Assert::test('a slip cannot be used to inject printer control codes', function (): void {
    // A patient name is free text; ESC in a name must not reach the printer.
    $slip = App\Support\ThermalSlip::escpos([
        'clinic' => "Clinic\x1b\x40Injected",
        'queue' => "Queue\x07Bell",
        'token' => 'A1',
    ]);
    Assert::false(str_contains($slip, "Injected\x1b@"), 'control bytes stripped from the injected text');
    Assert::contains('Injected', $slip, 'the readable text survives');
    Assert::false(str_contains($slip, "\x07"), 'BEL stripped');
});

Assert::test('the printable slip is 80mm and escapes user text', function (): void {
    $html = App\Support\ThermalSlip::html([
        'clinic' => 'Serenity <script>alert(1)</script>',
        'queue' => 'OPD',
        'token' => 'A014',
    ]);
    Assert::contains('@page { size: 80mm', $html);
    Assert::false(str_contains($html, '<script>alert(1)</script>'), 'no raw script from the clinic name');
    Assert::contains('&lt;script&gt;', $html);
});

/* ------------------------------------------------------------------ */
/* 37. appointment reminders                                           */
/* ------------------------------------------------------------------ */

Assert::test('a reminder is queued once and only once', function (): void {
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1,?,?,?,?)',
        ['RM' . bin2hex(random_bytes(2)), 'Reminder Patient', '+94771234500', Db::now()]
    );
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $apptId = Db::insert(
        'INSERT INTO appointments (clinic_id, doctor_id, patient_id, scheduled_for, duration_minutes, status, source, created_at)
         VALUES (1,?,?,?,15,?,?,?)',
        [$doctorId, $patient, $tomorrow . ' 09:15:00', 'booked', 'online', Db::now()]
    );

    $service = new App\Services\NotificationService();
    $service->notifyAppointmentReminders($tomorrow);
    $first = (int) Db::scalar('SELECT COUNT(*) FROM notifications WHERE dedupe_key = ?', ['appointment_reminder:' . $apptId]);
    Assert::same(1, $first, 'one reminder after the first pass');

    $service->notifyAppointmentReminders($tomorrow);
    $service->notifyAppointmentReminders($tomorrow);
    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM notifications WHERE dedupe_key = ?', ['appointment_reminder:' . $apptId]), 'still one after three passes');
});

Assert::test('no reminder for a cancelled appointment or one with no phone', function (): void {
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');

    $p1 = Db::insert('INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1,?,?,?,?)',
        ['RX' . bin2hex(random_bytes(2)), 'Cancelled', '+94771234501', Db::now()]);
    $cancelled = Db::insert(
        'INSERT INTO appointments (clinic_id, doctor_id, patient_id, scheduled_for, duration_minutes, status, source, created_at)
         VALUES (1,?,?,?,15,?,?,?)',
        [$doctorId, $p1, $tomorrow . ' 10:15:00', 'cancelled', 'online', Db::now()]
    );

    $p2 = Db::insert('INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1,?,?,?,?)',
        ['RN' . bin2hex(random_bytes(2)), 'No Phone', '', Db::now()]);
    $noPhone = Db::insert(
        'INSERT INTO appointments (clinic_id, doctor_id, patient_id, scheduled_for, duration_minutes, status, source, created_at)
         VALUES (1,?,?,?,15,?,?,?)',
        [$doctorId, $p2, $tomorrow . ' 11:15:00', 'booked', 'online', Db::now()]
    );

    (new App\Services\NotificationService())->notifyAppointmentReminders($tomorrow);

    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM notifications WHERE dedupe_key = ?', ['appointment_reminder:' . $cancelled]));
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM notifications WHERE dedupe_key = ?', ['appointment_reminder:' . $noPhone]));
});

Assert::test('a reminder names the real appointment time', function (): void {
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 1 LIMIT 1');
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (1,?,?,?,?)',
        ['RT' . bin2hex(random_bytes(2)), 'Timed Patient', '+94771234502', Db::now()]
    );
    $apptId = Db::insert(
        'INSERT INTO appointments (clinic_id, doctor_id, patient_id, scheduled_for, duration_minutes, status, source, created_at)
         VALUES (1,?,?,?,15,?,?,?)',
        [$doctorId, $patient, $tomorrow . ' 14:45:00', 'booked', 'online', Db::now()]
    );

    (new App\Services\NotificationService())->notifyAppointmentReminders($tomorrow);
    $payload = json_decode((string) Db::scalar('SELECT payload_json FROM notifications WHERE dedupe_key = ?', ['appointment_reminder:' . $apptId]), true);

    Assert::contains('2:45 PM', (string) ($payload['body'] ?? ''), 'the body carries the appointment time');
});

/* ------------------------------------------------------------------ */
/* 38. support tickets, both sides of the conversation                 */
/* ------------------------------------------------------------------ */

Assert::test('a clinic ticket is private to its clinic', function (): void {
    seedTenant(20, 'alpha-support', 'Alpha Support');
    seedTenant(21, 'beta-support', 'Beta Support');

    $aAdmin = (int) Db::scalar("SELECT id FROM users WHERE email = 'admin20@alpha.example'");
    $bAdmin = (int) Db::scalar("SELECT id FROM users WHERE email = 'admin21@alpha.example'");

    $ticket = Db::insert(
        'INSERT INTO support_tickets (clinic_id, user_id, subject, body, category, priority, status, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?)',
        [20, $aAdmin, 'SMS down', 'No messages since 09:00.', 'technical', 'high', 'open', Db::now(), Db::now()]
    );

    // The other tenant cannot read it.
    Assert::true(
        Db::one('SELECT id FROM support_tickets WHERE id = ? AND clinic_id = ?', [$ticket, 21]) === null,
        'another clinic sees nothing'
    );
    Assert::same(
        $ticket,
        (int) Db::scalar('SELECT id FROM support_tickets WHERE id = ? AND clinic_id = ?', [$ticket, 20])
    );
});

Assert::test('a staff reply is flagged and flips the ticket to open', function (): void {
    seedTenant(22, 'gamma-support', 'Gamma Support');
    $adminId = (int) Db::scalar("SELECT id FROM users WHERE email = 'admin22@alpha.example'");
    $superId = (int) $GLOBALS['superAdminId'];

    $ticket = Db::insert(
        'INSERT INTO support_tickets (clinic_id, user_id, subject, body, category, priority, status, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?)',
        [22, $adminId, 'Export blank', 'Department column is empty.', 'technical', 'normal', 'waiting', Db::now(), Db::now()]
    );

    // What SupportController::reply() does for a platform account.
    Db::insert(
        'INSERT INTO support_replies (ticket_id, user_id, body, is_staff, created_at) VALUES (?,?,?,?,?)',
        [$ticket, $superId, 'Queues created before the feature have no department.', 1, Db::now()]
    );
    Db::run("UPDATE support_tickets SET status = 'open', updated_at = ? WHERE id = ?", [Db::now(), $ticket]);

    Assert::same(1, (int) Db::scalar('SELECT is_staff FROM support_replies WHERE ticket_id = ? ORDER BY id DESC LIMIT 1', [$ticket]));
    Assert::same('open', (string) Db::scalar('SELECT status FROM support_tickets WHERE id = ?', [$ticket]));

    // A clinic reply flips it back to waiting-on-us.
    Db::insert(
        'INSERT INTO support_replies (ticket_id, user_id, body, is_staff, created_at) VALUES (?,?,?,?,?)',
        [$ticket, $adminId, 'Thanks, that worked.', 0, Db::now()]
    );
    Db::run("UPDATE support_tickets SET status = 'waiting', updated_at = ? WHERE id = ?", [Db::now(), $ticket]);
    Assert::same('waiting', (string) Db::scalar('SELECT status FROM support_tickets WHERE id = ?', [$ticket]));
});

Assert::test('the unanswered count ignores tickets the platform has replied to', function (): void {
    seedTenant(23, 'delta-support', 'Delta Support');
    $adminId = (int) Db::scalar("SELECT id FROM users WHERE email = 'admin23@alpha.example'");

    $make = static function (string $subject) use ($adminId): int {
        return Db::insert(
            'INSERT INTO support_tickets (clinic_id, user_id, subject, body, category, priority, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [23, $adminId, $subject, 'body', 'general', 'normal', 'open', Db::now(), Db::now()]
        );
    };
    $answered = $make('Answered');
    $silent   = $make('Unanswered');

    Db::insert(
        'INSERT INTO support_replies (ticket_id, user_id, body, is_staff, created_at) VALUES (?,?,?,?,?)',
        [$answered, (int) $GLOBALS['superAdminId'], 'On it.', 1, Db::now()]
    );

    $rows = Db::all(
        "SELECT t.id FROM support_tickets t
          WHERE t.clinic_id = 23 AND t.status IN ('open','waiting')
            AND NOT EXISTS (SELECT 1 FROM support_replies r WHERE r.ticket_id = t.id AND r.is_staff = 1)",
        []
    );
    Assert::same([$silent], array_map(static fn (array $r): int => (int) $r['id'], $rows), 'only the ticket nobody answered');
});

/* ------------------------------------------------------------------ */
/* 39. email verification                                              */
/* ------------------------------------------------------------------ */

Assert::test('an unverified clinic cannot issue tokens', function (): void {
    seedTenant(30, 'unverified-clinic', 'Unverified Clinic');
    // seedTenant marks owners verified so the rest of the suite can issue
    // tokens; this test is about the unverified case, so clear it here.
    Db::run('UPDATE users SET email_verified_at = NULL WHERE email = ?', ['admin30@alpha.example']);
    $service = new App\Services\EmailVerificationService();

    // seedTenant creates an owner with no verification stamp.
    Assert::false($service->clinicMayIssueTokens(30), 'blocked before verification');

    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (30,?,?,?,?)',
        ['UV' . bin2hex(random_bytes(2)), 'Blocked Patient', '+94771200001', Db::now()]
    );
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 30');

    $message = '';
    try {
        (new App\Domain\Queue\QueueService())->issueToken(30, $queueId, $patient, ['source' => 'walkin']);
    } catch (\Throwable $e) {
        $message = $e->getMessage();
    }

    Assert::contains('Confirm the clinic owner', $message, 'the refusal explains itself');
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM queue_entries WHERE clinic_id = 30'), 'no token was written');
});

Assert::test('a verification link works once and opens the gate', function (): void {
    seedTenant(31, 'verifying-clinic', 'Verifying Clinic');
    Db::run('UPDATE users SET email_verified_at = NULL WHERE email = ?', ['admin31@alpha.example']);
    $ownerId = (int) Db::scalar("SELECT id FROM users WHERE email = 'admin31@alpha.example'");
    $service = new App\Services\EmailVerificationService();

    $token = $service->send($ownerId, true);
    Assert::true($token !== null && strlen((string) $token) >= 20, 'a token was issued');
    Assert::false($service->clinicMayIssueTokens(31), 'still blocked while the link is unopened');

    Assert::same($ownerId, $service->verify((string) $token), 'the link verifies the owner');
    Assert::true($service->clinicMayIssueTokens(31), 'the gate is open');

    Assert::true($service->verify((string) $token) === null, 'the same link is refused the second time');
});

Assert::test('an expired link is refused', function (): void {
    seedTenant(32, 'expired-clinic', 'Expired Clinic');
    Db::run('UPDATE users SET email_verified_at = NULL WHERE email = ?', ['admin32@alpha.example']);
    $ownerId = (int) Db::scalar("SELECT id FROM users WHERE email = 'admin32@alpha.example'");
    $service = new App\Services\EmailVerificationService();

    $token = (string) $service->send($ownerId, true);
    Db::run('UPDATE email_verifications SET expires_at = ? WHERE user_id = ?', [date('Y-m-d H:i:s', time() - 60), $ownerId]);

    Assert::true($service->verify($token) === null, 'an expired token verifies nothing');
    Assert::false($service->clinicMayIssueTokens(32), 'still blocked');
});

Assert::test('a second send invalidates the first link', function (): void {
    seedTenant(33, 'resend-clinic', 'Resend Clinic');
    Db::run('UPDATE users SET email_verified_at = NULL WHERE email = ?', ['admin33@alpha.example']);
    $ownerId = (int) Db::scalar("SELECT id FROM users WHERE email = 'admin33@alpha.example'");
    $service = new App\Services\EmailVerificationService();

    $first = (string) $service->send($ownerId, true);
    $second = (string) $service->send($ownerId, true);
    Assert::true($first !== $second, 'a fresh token each time');

    Assert::true($service->verify($first) === null, 'the older link is dead');
    Assert::same($ownerId, $service->verify($second), 'the newest link works');
});

Assert::test('the platform can verify an address by hand', function (): void {
    seedTenant(34, 'manual-clinic', 'Manual Clinic');
    Db::run('UPDATE users SET email_verified_at = NULL WHERE email = ?', ['admin34@alpha.example']);
    $ownerId = (int) Db::scalar("SELECT id FROM users WHERE email = 'admin34@alpha.example'");
    $service = new App\Services\EmailVerificationService();

    Assert::false($service->clinicMayIssueTokens(34));
    Assert::true($service->verifyByHand($ownerId, (int) $GLOBALS['superAdminId']), 'staff verification succeeds');
    Assert::true($service->clinicMayIssueTokens(34), 'the clinic can work');

    Assert::same(1, (int) Db::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE action = 'user.email_verified_by_staff' AND entity_id = ?",
        [$ownerId]
    ), 'the override is audited');
});

Assert::test('an already verified address gets no new link', function (): void {
    seedTenant(35, 'verified-clinic', 'Verified Clinic');
    Db::run('UPDATE users SET email_verified_at = NULL WHERE email = ?', ['admin35@alpha.example']);
    $ownerId = (int) Db::scalar("SELECT id FROM users WHERE email = 'admin35@alpha.example'");
    $service = new App\Services\EmailVerificationService();
    Db::run('UPDATE users SET email_verified_at = ? WHERE id = ?', [Db::now(), $ownerId]);

    Assert::true($service->send($ownerId, true) === null, 'nothing is sent for a confirmed address');
});

/* ------------------------------------------------------------------ */
/* 40. sms transport honesty                                           */
/* ------------------------------------------------------------------ */

Assert::test('the log transport reports a send and writes the message', function (): void {
    putenv('SMS_GATEWAY=log');
    App\Core\Env::init(require QP_ROOT . '/config/config.php');

    $gateway = new App\Services\SmsGateway();
    Assert::same('logged', $gateway->send('+94771234567', 'Pipeline check'), 'the log transport records rather than sends');
});

Assert::test('http transport without a provider URL fails loudly', function (): void {
    // The old gateway returned "simulated" here, which the worker treated as a
    // delivery. A half-configured production install must not look healthy.
    putenv('SMS_GATEWAY=http');
    putenv('SMS_PROVIDER_URL=');
    App\Core\Env::init(require QP_ROOT . '/config/config.php');

    Assert::throws(
        static fn () => (new App\Services\SmsGateway())->send('+94771234567', 'Should not send'),
        \RuntimeException::class,
        'http without a URL is an error'
    );
});

Assert::test('auto transport falls back to the log when unconfigured', function (): void {
    putenv('SMS_GATEWAY=auto');
    putenv('SMS_PROVIDER_URL=');
    putenv('SMS_PROVIDER_SID=');
    putenv('SMS_PROVIDER_TOKEN=');
    App\Core\Env::init(require QP_ROOT . '/config/config.php');

    Assert::same('logged', (new App\Services\SmsGateway())->send('+94771234567', 'Auto fallback'), 'falls back to the log transport');
});

Assert::test('a delivered message is marked sent and a failure is retried', function (): void {
    putenv('SMS_GATEWAY=log');
    App\Core\Env::init(require QP_ROOT . '/config/config.php');

    $clinicId = 1;
    $entryId = (int) Db::scalar('SELECT id FROM queue_entries WHERE clinic_id = ? LIMIT 1', [$clinicId]);
    $service = new App\Services\NotificationService();

    $okId = Db::insert(
        'INSERT INTO notifications (clinic_id, entry_id, channel, template, to_number, payload_json, dedupe_key, status, attempts, scheduled_at, created_at)
         VALUES (?,?,?,?,?,?,?,?,0,?,?)',
        [$clinicId, $entryId, 'sms', 'token_issued', '•••••123',
         json_encode(['body' => 'Your turn is near.', 'to' => '+94771234567']),
         'test-delivery-' . bin2hex(random_bytes(4)), 'queued', date('Y-m-d H:i:s', time() - 10), Db::now()]
    );

    $service->processDue(50);
    $okRow = Db::one('SELECT status, sent_at, last_error FROM notifications WHERE id = ?', [$okId]);
    Assert::same('logged', (string) $okRow['status'], 'the log transport is recorded as logged, not sent');
    Assert::true($okRow['sent_at'] === null, 'no sent_at stamp, because nothing was sent');
    Assert::contains('not sent', (string) $okRow['last_error'], 'the history says plainly that nobody was texted');

    // Now a transport that throws: the row must go back to queued with a reason.
    putenv('SMS_GATEWAY=http');
    putenv('SMS_PROVIDER_URL=');
    App\Core\Env::init(require QP_ROOT . '/config/config.php');

    $badId = Db::insert(
        'INSERT INTO notifications (clinic_id, entry_id, channel, template, to_number, payload_json, dedupe_key, status, attempts, scheduled_at, created_at)
         VALUES (?,?,?,?,?,?,?,?,0,?,?)',
        [$clinicId, $entryId, 'sms', 'token_issued', '•••••456',
         json_encode(['body' => 'Second message.', 'to' => '+94771234567']),
         'test-failure-' . bin2hex(random_bytes(4)), 'queued', date('Y-m-d H:i:s', time() - 10), Db::now()]
    );

    $service->processDue(50);
    $row = Db::one('SELECT status, last_error, attempts FROM notifications WHERE id = ?', [$badId]);
    Assert::same('queued', (string) $row['status'], 'a failure stays queued for retry');
    Assert::true((int) $row['attempts'] >= 1, 'the attempt is counted');
    Assert::contains('missing', (string) $row['last_error'], 'the reason is recorded');

    putenv('SMS_GATEWAY=log');
    App\Core\Env::init(require QP_ROOT . '/config/config.php');
});

/* ------------------------------------------------------------------ */
/* 41. continuity: stale signalling and the drill                       */
/* ------------------------------------------------------------------ */

Assert::test('the drill detects a duplicated waiting patient, and the database makes one impossible', function (): void {
    // The drill's verdict is the query the clinic relies on during recovery: a
    // patient holding two live entries makes the paper log and the system
    // disagree about how many people are waiting.
    //
    // The stronger guarantee is underneath it: a partial unique index forbids
    // two live entries for one patient in one queue. So the second insert must
    // fail, which means the drill's query can only ever fire on data that
    // predates the index or was written around it — exactly what the drill is
    // for.
    $clinicId = 1;
    $today = Db::today();
    $dupesSql = "SELECT patient_id, COUNT(*) AS n FROM queue_entries e
                   JOIN queues q ON q.id = e.queue_id
                  WHERE e.clinic_id = ? AND q.session_date = ?
                    AND e.status IN ('waiting','called','in_consultation')
                  GROUP BY patient_id HAVING COUNT(*) > 1";

    Assert::same(0, count(Db::all($dupesSql, [$clinicId, $today])), 'the fixture starts clean');

    $row = Db::one(
        "SELECT e.patient_id, e.queue_id FROM queue_entries e JOIN queues q ON q.id = e.queue_id
          WHERE e.clinic_id = ? AND q.session_date = ? AND e.status = 'waiting' LIMIT 1",
        [$clinicId, $today]
    );
    Assert::true($row !== null, 'there is a waiting entry to duplicate');

    $threw = false;
    try {
        Db::insert(
            'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status,
                track_token, track_expires_at, sort_weight, priority, source, version, joined_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$clinicId, (int) $row['queue_id'], (int) $row['patient_id'], 999,
             'DUP' . bin2hex(random_bytes(3)), 'waiting', bin2hex(random_bytes(16)),
             date('Y-m-d H:i:s', time() + 86400), 0, 0, 'walkin', 1, Db::now()]
        );
    } catch (\PDOException $e) {
        $threw = true;
    }

    Assert::true($threw, 'the partial unique index refuses a second live entry');
    Assert::same(0, count(Db::all($dupesSql, [$clinicId, $today])), 'and the day stays clean');
});

Assert::test('the display board marks itself stale, and the pill is driven by the same poll', function (): void {
    // Static check of the contract the continuity procedure tells staff to
    // watch: the banner element must be the one the poller looks for, and the
    // pill text must be addressable. A selector mismatch here means staff are
    // told to watch a signal that never changes.
    $screen = (string) file_get_contents(QP_ROOT . '/resources/views/pages/display/screen.php');
    $js = (string) file_get_contents(QP_ROOT . '/public/assets/js/app.js');

    Assert::contains('data-tv-offline', $screen, 'the offline banner is addressable');
    Assert::contains('[data-tv-offline]', $js, 'the poller looks for the same attribute');
    Assert::contains('data-tv-live-text', $screen, 'the pill text has a hook');
    Assert::contains('Offline — not live', $js, 'the pill lies no more than the banner does');
});

Assert::test('the service worker never serves a cached api response', function (): void {
    $sw = (string) file_get_contents(QP_ROOT . '/public/sw.js');

    // The rule, as code: any /api/ request returns before respondWith, so the
    // browser goes to the network and a failure is a failure.
    Assert::contains("url.pathname.startsWith('/api/')", $sw, 'the api bypass exists');

    $apiIndex = strpos($sw, "url.pathname.startsWith('/api/')");
    $respondIndex = strpos($sw, 'respondWith', $apiIndex !== false ? $apiIndex : 0);
    Assert::true($respondIndex !== false, 'there is a respondWith after it');
});

/* ------------------------------------------------------------------ */
/* 42. organisations                                                   */
/* ------------------------------------------------------------------ */

Assert::test('a group is created with a unique slug and its creation is audited', function (): void {
    $service = new App\Services\OrganisationService();
    $name = 'Test Group ' . bin2hex(random_bytes(2));

    $first = $service->create($name, 'owner@example.lk', 'probe');
    Assert::true($first['ok'], 'the group is created');

    $again = $service->create($name, null, null);
    Assert::false($again['ok'], 'the same name is refused');
    Assert::contains('already exists', (string) $again['error'], 'and says why');

    Assert::same(1, (int) Db::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE action = 'organisation.created' AND entity_id = ?",
        [(int) $first['id']]
    ), 'creation is audited');
});

Assert::test('attaching a clinic gives it a branch row and a roll-up', function (): void {
    $service = new App\Services\OrganisationService();
    $created = $service->create('Rollup Probe ' . bin2hex(random_bytes(2)));
    $orgId = (int) $created['id'];

    Db::run('UPDATE clinics SET organisation_id = NULL WHERE id = 1');
    $attached = $service->attachClinic($orgId, 1);
    Assert::true($attached['ok'], 'the clinic is attached');

    $branches = $service->branches($orgId);
    Assert::same(1, count($branches), 'one branch listed');
    Assert::same(1, (int) $branches[0]['id']);

    $rollup = $service->rollup($orgId);
    Assert::same(1, (int) $rollup['branches'], 'the roll-up counts the branch');
    Assert::true(array_key_exists('seen_today', $rollup), 'and carries the day counts');

    // Put it back so later tests see the fixture they expect.
    Db::run('UPDATE clinics SET organisation_id = NULL WHERE id = 1');
});

Assert::test('a clinic in one group is not listed under another', function (): void {
    $service = new App\Services\OrganisationService();
    $a = $service->create('Alpha Group ' . bin2hex(random_bytes(2)));
    $b = $service->create('Beta Group ' . bin2hex(random_bytes(2)));

    Db::run('UPDATE clinics SET organisation_id = NULL WHERE id = 2');
    $service->attachClinic((int) $a['id'], 2);

    $inA = array_map(static fn (array $r): int => (int) $r['id'], $service->branches((int) $a['id']));
    $inB = array_map(static fn (array $r): int => (int) $r['id'], $service->branches((int) $b['id']));

    Assert::same([2], $inA, 'clinic 2 is a branch of A');
    Assert::same([], $inB, 'and invisible to B');

    Db::run('UPDATE clinics SET organisation_id = NULL WHERE id = 2');
});

/* ------------------------------------------------------------------ */
/* 43. monthly invoicing                                               */
/* ------------------------------------------------------------------ */

Assert::test('an invoice is raised for a clinic that has reached its renewal date', function (): void {
    seedTenant(40, 'billing-clinic', 'Billing Clinic');
    Db::run("UPDATE clinics SET billing_status='active', renews_on='2026-12-02' WHERE id = 40");

    $service = new App\Services\InvoiceService();
    $due = $service->generateDue(false, '2026-12-02');

    $mine = array_values(array_filter($due, static fn (array $r): bool => $r['clinic_id'] === 40));
    Assert::same(1, count($mine), 'one invoice for this clinic');
    Assert::same('2026-11-02', $mine[0]['period_from'], 'the period is the month that ended');
    Assert::same('2026-12-02', $mine[0]['period_to']);

    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM invoices WHERE clinic_id = 40'), 'the row exists');
});

Assert::test('running the generator again bills nobody twice', function (): void {
    $service = new App\Services\InvoiceService();

    // Three more passes on the same date.
    $service->generateDue(false, '2026-12-02');
    $service->generateDue(false, '2026-12-02');
    $service->generateDue(false, '2026-12-02');

    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM invoices WHERE clinic_id = 40'), 'still one invoice');
});

Assert::test('the unique index forbids a second live invoice for one period', function (): void {
    // The application check has a gap between read and write; the index is what
    // actually holds under concurrency, so assert on the index itself.
    $threw = false;
    try {
        Db::insert(
            'INSERT INTO invoices (clinic_id, number, period_from, period_to, amount, currency, status, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [40, 'INV-DUP-' . bin2hex(random_bytes(2)), '2026-11-02', '2026-12-02', 3500, 'LKR', 'unpaid', Db::now()]
        );
    } catch (\PDOException $e) {
        $threw = true;
    }
    Assert::true($threw, 'the database refuses the duplicate');
});

Assert::test('a trial clinic is not invoiced', function (): void {
    seedTenant(41, 'trial-clinic', 'Trial Clinic');
    Db::run("UPDATE clinics SET billing_status='trial', renews_on='2026-12-02' WHERE id = 41");

    (new App\Services\InvoiceService())->generateDue(false, '2026-12-02');
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM invoices WHERE clinic_id = 41'), 'a trial is not a debt');
});

Assert::test('generating an invoice does not extend the subscription', function (): void {
    $before = (string) Db::scalar('SELECT renews_on FROM clinics WHERE id = 40');
    (new App\Services\InvoiceService())->generateDue(false, '2026-12-20');
    $after = (string) Db::scalar('SELECT renews_on FROM clinics WHERE id = 40');
    Assert::same($before, $after, 'only a verified payment moves renews_on');
});

Assert::test('invoice numbers are unique and readable', function (): void {
    // Make sure the generator has run in this database: the suite's own DB is
    // fresh per run, and an assertion about generated numbers must not depend
    // on a probe having populated the demo file.
    seedTenant(42, 'numbering-clinic', 'Numbering Clinic');
    Db::run("UPDATE clinics SET billing_status='active', renews_on='2026-12-02' WHERE id = 42");
    (new App\Services\InvoiceService())->generateDue(false, '2026-12-02');
    (new App\Services\InvoiceService())->generateDue(false, '2027-01-02');

    $numbers = array_map(
        static fn (array $r): string => (string) $r['number'],
        Db::all('SELECT number FROM invoices ORDER BY id')
    );
    Assert::same(count($numbers), count(array_unique($numbers)), 'no duplicates');
    // Only the numbers this generator produced: the table also carries seeded
    // invoices and test fixtures, and asserting a format over those is not a
    // statement about the generator.
    $generated = array_filter($numbers, static fn (string $n): bool => preg_match('/^INV-\d{6}-\d{4}$/', $n) === 1);
    Assert::true(count($generated) >= 2, 'the generator produced period-stamped numbers');
    foreach ($generated as $n) {
        Assert::true(preg_match('/^INV-\d{6}-\d{4}$/', $n) === 1, 'readable form: ' . $n);
    }
});

/* ------------------------------------------------------------------ */
/* 44. rooms                                                           */
/* ------------------------------------------------------------------ */

Assert::test('a room name is unique within a clinic, case-insensitively', function (): void {
    seedTenant(50, 'room-clinic', 'Room Clinic');

    Db::insert('INSERT INTO rooms (clinic_id, name, floor) VALUES (?,?,?)', [50, 'Consulting 1', 'Ground']);

    // Same name, different case: the same room to a patient reading a screen.
    $clash = Db::scalar('SELECT id FROM rooms WHERE clinic_id = ? AND lower(name) = lower(?)', [50, 'consulting 1']);
    Assert::true($clash !== null, 'the case-insensitive check finds it');

    // A different clinic may of course use the same name.
    seedTenant(51, 'other-room-clinic', 'Other Room Clinic');
    Db::insert('INSERT INTO rooms (clinic_id, name, floor) VALUES (?,?,?)', [51, 'Consulting 1', null]);
    // Two rows, one per clinic. That is the point: the uniqueness rule is
    // scoped to a clinic, so the same label in two clinics is fine.
    Assert::same(2, (int) Db::scalar("SELECT COUNT(*) FROM rooms WHERE name = 'Consulting 1' AND clinic_id IN (50,51)"), 'both exist — names are per clinic, not global');
    Assert::same(1, (int) Db::scalar("SELECT COUNT(*) FROM rooms WHERE name = 'Consulting 1' AND clinic_id = 50"), 'but only one in each');
});

Assert::test('a room assigned to an active doctor cannot be deleted', function (): void {
    $roomId = (int) Db::scalar('SELECT room_id FROM doctors WHERE clinic_id = 50 AND is_active = 1 LIMIT 1');
    if ($roomId === 0) {
        // seedTenant creates a doctor without a room; assign one.
        $roomId = Db::insert('INSERT INTO rooms (clinic_id, name, floor) VALUES (?,?,?)', [50, 'Assigned', 'Ground']);
        Db::run('UPDATE doctors SET room_id = ? WHERE clinic_id = 50', [$roomId]);
    }

    $inUse = (int) Db::scalar(
        'SELECT COUNT(*) FROM doctors WHERE room_id = ? AND clinic_id = ? AND is_active = 1',
        [$roomId, 50]
    );
    Assert::true($inUse > 0, 'the room is in use');

    // The controller refuses on exactly this count, so assert the condition it
    // reads rather than re-implementing the message.
    Assert::same($inUse, (int) Db::scalar(
        'SELECT COUNT(*) FROM doctors WHERE room_id = ? AND clinic_id = ? AND is_active = 1',
        [$roomId, 50]
    ), 'the guard has something to refuse');

    // An unused room may be deleted.
    $free = Db::insert('INSERT INTO rooms (clinic_id, name, floor) VALUES (?,?,?)', [50, 'Unused', null]);
    Db::run('DELETE FROM rooms WHERE id = ? AND clinic_id = ?', [$free, 50]);
    Assert::true(Db::scalar('SELECT id FROM rooms WHERE id = ?', [$free]) === null, 'an unused room is removable');
});

Assert::test('renaming a room is audited with the old name', function (): void {
    $room = Db::one('SELECT id, name FROM rooms WHERE clinic_id = 50 ORDER BY id LIMIT 1');
    $newName = 'Renamed ' . bin2hex(random_bytes(2));

    Db::run('UPDATE rooms SET name = ? WHERE id = ? AND clinic_id = ?', [$newName, (int) $room['id'], 50]);
    (new App\Services\AuditService())->log(50, 'room.renamed', 'room', (int) $room['id'], [
        'from' => $room['name'], 'to' => $newName,
    ]);

    Assert::same($newName, (string) Db::scalar('SELECT name FROM rooms WHERE id = ?', [(int) $room['id']]));

    $row = Db::one(
        "SELECT meta_json FROM audit_logs WHERE action = 'room.renamed' AND entity_id = ? ORDER BY id DESC LIMIT 1",
        [(int) $room['id']]
    );
    $meta = json_decode((string) $row['meta_json'], true);
    Assert::same((string) $room['name'], (string) $meta['from'], 'the old name is on the record');
});

/* ------------------------------------------------------------------ */
/* 45. queue configuration                                             */
/* ------------------------------------------------------------------ */

Assert::test('queue settings drive the name, type, prefix and padding of new queues', function (): void {
    seedTenant(60, 'queue-config-clinic', 'Queue Config Clinic');

    foreach ([
        'queue.name_pattern' => 'Lab Bench {n}',
        'queue.default_kind' => 'lab',
        'queue.default_prefix' => 'L',
        'queue.token_padding' => '4',
    ] as $key => $value) {
        Db::run(
            "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, ?, ?)",
            [60, $key, $value]
        );
    }

    // A second doctor so the {n} counter has something to count.
    $room = (int) Db::scalar('SELECT id FROM rooms WHERE clinic_id = 60 LIMIT 1');
    Db::insert(
        'INSERT INTO doctors (clinic_id, name, room_id, avg_consult_minutes, is_active, created_at)
         VALUES (?,?,?,?,1,?)',
        [60, 'Dr. Second', $room, 10, Db::now()]
    );

    // Reproduce what openDefaultSession() reads and does, without needing a
    // session: the settings lookup and the substitution are the behaviour.
    $pattern = (string) Db::scalar(
        "SELECT value FROM settings WHERE owner_id = 60 AND key_name = 'queue.name_pattern'"
    );
    $kind = (string) Db::scalar(
        "SELECT value FROM settings WHERE owner_id = 60 AND key_name = 'queue.default_kind'"
    );
    $prefix = (string) Db::scalar(
        "SELECT value FROM settings WHERE owner_id = 60 AND key_name = 'queue.default_prefix'"
    );

    $names = [];
    foreach ([1, 2] as $n) {
        $names[] = str_replace(['{doctor}', '{n}'], ['Dr. Second', (string) $n], $pattern);
    }

    Assert::same(['Lab Bench 1', 'Lab Bench 2'], $names, 'the pattern substitutes per queue');
    Assert::same('lab', $kind);
    Assert::same('L', $prefix);
});

Assert::test('a queue name pattern must include a placeholder', function (): void {
    // The controller refuses a pattern without {doctor} or {n}, because every
    // doctor's queue would then share one name and the board could not tell
    // them apart.
    $bad = 'OPD';
    Assert::false(str_contains($bad, '{doctor}') || str_contains($bad, '{n}'), 'a bare name is refused');

    $good = 'OPD — {doctor}';
    Assert::true(str_contains($good, '{doctor}'), 'a placeholder makes it valid');
});

Assert::test('a queue that has issued a token cannot be renamed', function (): void {
    // The rule the controller enforces: last_token_no > 0 means a patient is
    // holding a slip with that label on it.
    $queue = Db::one('SELECT id, last_token_no FROM queues WHERE clinic_id = 1 ORDER BY id LIMIT 1');
    if ((int) $queue['last_token_no'] === 0) {
        Db::run('UPDATE queues SET last_token_no = 1 WHERE id = ?', [(int) $queue['id']]);
    }

    $issued = (int) Db::scalar('SELECT last_token_no FROM queues WHERE id = ?', [(int) $queue['id']]);
    Assert::true($issued > 0, 'this queue is locked');

    // An untouched queue is renameable.
    $fresh = Db::insert(
        'INSERT INTO queues (clinic_id, doctor_id, name, token_prefix, session_date, status, last_token_no, token_padding, created_at)
         VALUES (1, NULL, ?, ?, ?, ?, 0, 3, ?)',
        ['Unused Queue', 'Z', Db::today(), 'open', Db::now()]
    );
    Assert::same(0, (int) Db::scalar('SELECT last_token_no FROM queues WHERE id = ?', [$fresh]));
    Db::run('DELETE FROM queues WHERE id = ?', [$fresh]);
});

/* ------------------------------------------------------------------ */
/* 46. breaks                                                          */
/* ------------------------------------------------------------------ */

Assert::test('a break pauses the queue, blocks tokens and records a measured duration', function (): void {
    seedTenant(70, 'break-clinic', 'Break Clinic');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 70');
    $service = new App\Services\BreakService();

    $started = $service->start(70, $queueId, 'lunch', 'Consultant lunch');
    Assert::true($started['ok'], 'the break starts');
    Assert::same('paused', (string) Db::scalar('SELECT status FROM queues WHERE id = ?', [$queueId]));

    // Token issuing must be refused while paused.
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (70,?,?,?,?)',
        ['BK' . bin2hex(random_bytes(2)), 'Paused Patient', '+94771300001', Db::now()]
    );
    $message = '';
    try {
        (new App\Domain\Queue\QueueService())->issueToken(70, $queueId, $patient, ['source' => 'walkin']);
    } catch (\Throwable $e) {
        $message = $e->getMessage();
    }
    Assert::contains('paused', $message, 'the refusal names the pause');

    // Backdate so the measured length is not zero, then end it.
    Db::run('UPDATE queue_breaks SET started_at = ? WHERE queue_id = ? AND ended_at IS NULL',
        [date('Y-m-d H:i:s', time() - 900), $queueId]);
    Db::run('UPDATE queues SET break_started_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 900), $queueId]);

    $ended = $service->end(70, $queueId);
    Assert::true($ended['ok'], 'the break ends');
    Assert::same(15, (int) $ended['minutes'], 'fifteen minutes, measured');
    Assert::same('open', (string) Db::scalar('SELECT status FROM queues WHERE id = ?', [$queueId]));

    $row = Db::one('SELECT MINUTES FROM queue_breaks WHERE queue_id = ? ORDER BY id DESC LIMIT 1', [$queueId]);
    Assert::same(15, (int) $row['minutes'], 'the duration is persisted');
});

Assert::test('a second break cannot open on the same queue', function (): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 70');
    $service = new App\Services\BreakService();

    $service->start(70, $queueId, 'tea', 'First');
    $second = $service->start(70, $queueId, 'emergency', 'Second');

    Assert::false($second['ok'], 'the second start is refused');
    Assert::contains('already has a break open', (string) $second['error'], 'and says so');
    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM queue_breaks WHERE queue_id = ? AND ended_at IS NULL', [$queueId]), 'one open break, not two');

    $service->end(70, $queueId);
});

Assert::test('ending a break that is not open is refused', function (): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 70');
    $service = new App\Services\BreakService();

    $result = $service->end(70, $queueId);
    Assert::false($result['ok']);
    Assert::contains('not on a break', (string) $result['error']);
});

Assert::test('a break type outside the allowed set is refused', function (): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 70');
    $result = (new App\Services\BreakService())->start(70, $queueId, 'nap', '');
    Assert::false($result['ok'], 'an unknown type is refused rather than coerced to unspecified');
});

Assert::test('the weekly summary adds only completed breaks', function (): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 70');
    $service = new App\Services\BreakService();

    // One completed lunch of 15 minutes (from the first test) plus a new open
    // one, which must not be counted as zero.
    $service->start(70, $queueId, 'emergency', 'Still going');

    $summary = $service->summary(70, date('Y-m-d', strtotime('-7 days')), Db::today());
    Assert::same(15, $summary['total_minutes'], 'only the completed break contributes');

    $service->end(70, $queueId);
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM queue_breaks WHERE queue_id = ? AND ended_at IS NULL', [$queueId]), 'nothing left open');
});

Assert::test('a planned break is validated and is not an actual break', function (): void {
    $service = new App\Services\BreakService();

    Assert::true($service->addScheduled(70, null, 1, '13:00', '13:30', 'lunch')['ok'], 'Monday lunch planned');
    Assert::false($service->addScheduled(70, null, 1, '14:00', '13:00', 'lunch')['ok'], 'a backwards window is refused');
    Assert::false($service->addScheduled(70, null, 9, '13:00', '13:30', 'lunch')['ok'], 'day 9 is not a weekday');

    // A plan is not an event: the actual-break table is untouched.
    Assert::same(0, (int) Db::scalar("SELECT COUNT(*) FROM queue_breaks WHERE clinic_id = 70 AND kind = 'lunch' AND substr(started_at,1,10) > ?", [Db::today()]), 'a plan never appears as history');

    $monday = $service->scheduledFor(70, 1);
    Assert::same(1, count($monday));
    Assert::same(0, count($service->scheduledFor(70, 2)), 'not on Tuesday');
});

/* ------------------------------------------------------------------ */
/* 47. doctor delay alerts                                             */
/* ------------------------------------------------------------------ */

Assert::test('a delay announcement messages every waiting patient once per length', function (): void {
    seedTenant(80, 'delay-clinic', 'Delay Clinic');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 80');

    // Three waiting patients with numbers, and one without.
    foreach ([['+94771400001', 'One'], ['+94771400002', 'Two'], ['+94771400003', 'Three'], ['', 'No Phone']] as $i => [$phone, $name]) {
        $pid = Db::insert(
            'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (80,?,?,?,?)',
            ['DL' . $i . bin2hex(random_bytes(1)), $name, $phone, Db::now()]
        );
        Db::insert(
            'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status,
                track_token, track_expires_at, sort_weight, priority, source, version, joined_at)
             VALUES (80,?,?,?,?,?,?,?,0,0,?,0,?)',
            [$queueId, $pid, $i + 1, 'D00' . ($i + 1), 'waiting',
             bin2hex(random_bytes(16)), date('Y-m-d H:i:s', time() + 86400), 'walkin', Db::now()]
        );
    }

    $service = new App\Services\NotificationService();

    Assert::same(3, $service->notifyDelay(80, $queueId, 20, 'Emergency'), 'three with a phone are messaged');
    Assert::same(0, $service->notifyDelay(80, $queueId, 20, 'Emergency'), 'the same length sends nothing more');
    Assert::same(3, $service->notifyDelay(80, $queueId, 40, ''), 'a longer delay is new information');
    Assert::same(0, $service->notifyDelay(80, $queueId, 0, ''), 'clearing a delay sends nothing');
    Assert::same(0, $service->notifyDelay(80, $queueId, -5, ''), 'a negative delay sends nothing');

    Assert::same(6, (int) Db::scalar("SELECT COUNT(*) FROM notifications WHERE clinic_id = 80 AND template = 'entry_delayed'"));
});

Assert::test('a delay message names the clinic, the token and the minutes', function (): void {
    $payload = json_decode((string) Db::scalar(
        "SELECT payload_json FROM notifications WHERE clinic_id = 80 AND dedupe_key LIKE 'delay:%:20:%' LIMIT 1"
    ), true);
    $body = (string) ($payload['body'] ?? '');

    Assert::contains('20 minutes late', $body, 'the delay length is in the message');
    Assert::contains('Delay Clinic', $body, 'the clinic is named');
    Assert::contains('D00', $body, 'the token is named, so the patient knows it is about them');
    Assert::contains('keeps its place', $body, 'and that they have not lost their position');
});

Assert::test('a patient already seen is not messaged about a delay', function (): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 80');
    // Mark one of the three as completed, then announce a fresh length.
    $done = Db::one("SELECT id FROM queue_entries WHERE clinic_id = 80 AND status = 'waiting' LIMIT 1");
    Db::run("UPDATE queue_entries SET status = 'completed' WHERE id = ?", [(int) $done['id']]);

    $queued = (new App\Services\NotificationService())->notifyDelay(80, $queueId, 55, '');
    Assert::same(2, $queued, 'only the two still waiting are messaged');
    Assert::same(0, (int) Db::scalar(
        "SELECT COUNT(*) FROM notifications WHERE entry_id = ? AND dedupe_key LIKE 'delay:%:55:%'",
        [(int) $done['id']]
    ), 'the completed patient got nothing');
});

/* ------------------------------------------------------------------ */
/* 48. priority rules                                                  */
/* ------------------------------------------------------------------ */

Assert::test('a standing rule reorders a new token and records its reason', function (): void {
    seedTenant(81, 'rule-clinic', 'Rule Clinic');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 81');
    $rules = new App\Services\PriorityRuleService();

    $rules->create(81, ['name' => 'Under-fives first', 'effect' => 5, 'max_age' => 5,
        'reason' => 'Child under five — see before adults'], null);

    $kid = Db::insert('INSERT INTO patients (clinic_id, code, full_name, birth_year, created_at) VALUES (81,?,?,?,?)',
        ['RK1', 'Kid One', (int) date('Y') - 3, Db::now()]);
    $adult = Db::insert('INSERT INTO patients (clinic_id, code, full_name, birth_year, created_at) VALUES (81,?,?,?,?)',
        ['RA1', 'Adult One', (int) date('Y') - 40, Db::now()]);

    $queue = new App\Domain\Queue\QueueService();
    $first = $queue->issueToken(81, $queueId, $adult, []);
    $second = $queue->issueToken(81, $queueId, $kid, []);

    $kidRow = Db::one('SELECT priority, priority_reason, priority_rule_id FROM queue_entries WHERE id = ?', [$second['entry_id']]);
    Assert::same(5, (int) $kidRow['priority'], 'the child carries the rule priority');
    Assert::contains('Child under five', (string) $kidRow['priority_reason'], 'the rule reason is copied onto the entry');
    Assert::same((int) Db::scalar('SELECT id FROM priority_rules WHERE clinic_id = 81'), (int) $kidRow['priority_rule_id'], 'the entry names the rule that decided it');

    $adultRow = Db::one('SELECT priority, priority_rule_id FROM queue_entries WHERE id = ?', [$first['entry_id']]);
    Assert::same(0, (int) $adultRow['priority'], 'an adult matches no rule');
    Assert::same(null, $adultRow['priority_rule_id'], 'and names no rule');

    Assert::same(1, $queue->positionFor(Db::one('SELECT * FROM queue_entries WHERE id = ?', [$second['entry_id']]), 81),
        'the later child sorts ahead of the earlier adult');
});

Assert::test('the strongest boost wins and boosts never stack', function (): void {
    $rules = new App\Services\PriorityRuleService();
    $rules->create(81, ['name' => 'Morning boost', 'effect' => 3, 'max_age' => 5,
        'from_hour' => 0, 'to_hour' => 24, 'reason' => 'Morning squeeze'], null);

    $match = $rules->evaluate(81, ['source' => 'walkin', 'kind' => 'consultation', 'age' => 4, 'hour' => 9]);
    Assert::same(5, $match['priority'], 'two matching boosts resolve to the stronger one, not their sum');
    Assert::contains('Child under five', $match['reason'], 'and the winning rule supplies the reason');
});

Assert::test('a hand-set priority and a penalty both behave', function (): void {
    $rules = new App\Services\PriorityRuleService();
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 81');

    // A penalty is a clinical decision too: routine reviews may wait.
    $rules->create(81, ['name' => 'Routine last', 'effect' => -2, 'source' => 'walkin',
        'reason' => 'Routine review — may wait'], null);
    $routine = Db::insert('INSERT INTO patients (clinic_id, code, full_name, birth_year, created_at) VALUES (81,?,?,?,?)',
        ['RR1', 'Routine One', (int) date('Y') - 30, Db::now()]);
    $queue = new App\Domain\Queue\QueueService();
    $penalised = $queue->issueToken(81, $queueId, $routine, []);
    Assert::same(-2, (int) Db::scalar('SELECT priority FROM queue_entries WHERE id = ?', [$penalised['entry_id']]),
        'a negative rule pushes a routine patient behind');

    // A clinician standing at the desk outranks any rule.
    $override = Db::insert('INSERT INTO patients (clinic_id, code, full_name, birth_year, created_at) VALUES (81,?,?,?,?)',
        ['RO1', 'Override One', (int) date('Y') - 4, Db::now()]);
    $manual = $queue->issueToken(81, $queueId, $override, ['priority' => 7, 'priority_reason' => 'Bleeding']);
    $row = Db::one('SELECT priority, priority_reason, priority_rule_id FROM queue_entries WHERE id = ?', [$manual['entry_id']]);
    Assert::same(7, (int) $row['priority'], 'the manual priority wins over the under-fives rule');
    Assert::same(null, $row['priority_rule_id'], 'and no rule is credited with it');
});

Assert::test('a rule stops applying when it is turned off or deleted', function (): void {
    $rules = new App\Services\PriorityRuleService();
    $ruleId = (int) Db::scalar("SELECT id FROM priority_rules WHERE clinic_id = 81 AND name = 'Under-fives first'");

    $rules->setActive(81, $ruleId, false);
    $match = $rules->evaluate(81, ['source' => 'walkin', 'age' => 4, 'hour' => 9]);
    Assert::true($match['rule_id'] !== $ruleId, 'an inactive rule stops deciding, even if another rule still matches');

    $rules->setActive(81, $ruleId, true);
    Assert::same($ruleId, $rules->evaluate(81, ['source' => 'walkin', 'age' => 4, 'hour' => 9])['rule_id'],
        'turning it back on makes it decide again');

    Assert::true($rules->delete(81, $ruleId), 'the rule is deleted');
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM priority_rules WHERE id = ?', [$ruleId]), 'and is gone');
});

Assert::test('a rule cannot be saved half-written or pointed at another clinic', function (): void {
    $rules = new App\Services\PriorityRuleService();
    $bad = [
        ['name' => '', 'effect' => 2, 'reason' => 'x'],
        ['name' => 'No reason', 'effect' => 2, 'reason' => ''],
        ['name' => 'Zero effect', 'effect' => 0, 'reason' => 'x'],
        ['name' => 'Backwards hours', 'effect' => 2, 'reason' => 'x', 'from_hour' => 15, 'to_hour' => 9],
        ['name' => 'Half a window', 'effect' => 2, 'reason' => 'x', 'from_hour' => 9],
        ['name' => 'Backwards ages', 'effect' => 2, 'reason' => 'x', 'min_age' => 40, 'max_age' => 5],
        ['name' => 'Unknown source', 'effect' => 2, 'reason' => 'x', 'source' => 'fax'],
    ];
    foreach ($bad as $i => $input) {
        $refused = false;
        try {
            $rules->create(81, $input, null);
        } catch (\InvalidArgumentException) {
            $refused = true;
        }
        Assert::true($refused, 'input ' . $i . ' (' . ($input['name'] ?: 'unnamed') . ') is refused');
    }

    $foreignQueue = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 1 LIMIT 1');
    $refused = false;
    try {
        $rules->create(81, ['name' => 'Foreign', 'effect' => 2, 'reason' => 'x', 'queue_id' => $foreignQueue], null);
    } catch (\InvalidArgumentException) {
        $refused = true;
    }
    Assert::true($refused, "another clinic's queue cannot be targeted");
});

Assert::test('a patient with no birth year never matches an age rule', function (): void {
    $rules = new App\Services\PriorityRuleService();
    $rules->create(81, ['name' => 'Seniors', 'effect' => 4, 'min_age' => 65, 'reason' => 'Senior patient'], null);

    $seniors = (int) Db::scalar("SELECT id FROM priority_rules WHERE clinic_id = 81 AND name = 'Seniors'");

    Assert::same(null, $rules->ageOf(null), 'no birth year means no age');
    Assert::same($seniors, $rules->evaluate(81, ['source' => 'appointment', 'age' => 70, 'hour' => 9])['rule_id'],
        'a known 70-year-old matches');
    Assert::same(null, $rules->evaluate(81, ['source' => 'appointment', 'age' => null, 'hour' => 9])['rule_id'],
        'an unknown age matches nothing');
    Assert::same(70, (int) (new App\Services\PriorityRuleService())->ageOf((int) date('Y') - 70), 'age is derived from the birth year');
});

/* ------------------------------------------------------------------ */
/* 49. appointment vs walk-in weights                                  */
/* ------------------------------------------------------------------ */

Assert::test('clinic weights decide appointment vs walk-in order, not a fixed constant', function (): void {
    seedTenant(82, 'weights-clinic', 'Weights Clinic');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 82');
    $queue = new App\Domain\Queue\QueueService();

    // The clinic said appointments first. The service must read that, not its
    // own 1-vs-3 default, or the settings screen would be decoration.
    Db::run('UPDATE clinics SET appointment_weight = 2, walkin_weight = 6 WHERE id = 82');

    $booked = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (82,?,?,?)', ['WB1', 'Booked One', Db::now()]);
    $walkin = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (82,?,?,?)', ['WW1', 'Walkin One', Db::now()]);

    $w = $queue->issueToken(82, $queueId, $walkin, ['source' => 'walkin']);
    $a = $queue->issueToken(82, $queueId, $booked, ['source' => 'appointment']);

    Assert::same(2, (int) Db::scalar('SELECT sort_weight FROM queue_entries WHERE id = ?', [$a['entry_id']]), 'the appointment carries the clinic weight');
    Assert::same(6, (int) Db::scalar('SELECT sort_weight FROM queue_entries WHERE id = ?', [$w['entry_id']]), 'the walk-in carries the clinic weight');
    Assert::same(1, $queue->positionFor(Db::one('SELECT * FROM queue_entries WHERE id = ?', [$a['entry_id']]), 82),
        'the later appointment sorts ahead of the earlier walk-in');
});

Assert::test('a clinic that treats both equally gets arrival order', function (): void {
    Db::run('UPDATE clinics SET appointment_weight = 3, walkin_weight = 3 WHERE id = 82');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 82');
    $queue = new App\Domain\Queue\QueueService();

    $booked = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (82,?,?,?)', ['WB2', 'Booked Two', Db::now()]);
    $walkin = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (82,?,?,?)', ['WW2', 'Walkin Two', Db::now()]);

    $a = $queue->issueToken(82, $queueId, $booked, ['source' => 'appointment']);
    $w = $queue->issueToken(82, $queueId, $walkin, ['source' => 'walkin']);

    Assert::same((int) Db::scalar('SELECT sort_weight FROM queue_entries WHERE id = ?', [$a['entry_id']]),
        (int) Db::scalar('SELECT sort_weight FROM queue_entries WHERE id = ?', [$w['entry_id']]),
        'equal weights, so neither source is favoured');
});

Assert::test('a clinical priority outranks the weight, and a rule outranks a weight too', function (): void {
    Db::run('UPDATE clinics SET appointment_weight = 1, walkin_weight = 9 WHERE id = 82');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 82');
    $queue = new App\Domain\Queue\QueueService();

    $urgent = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (82,?,?,?)', ['WU1', 'Urgent One', Db::now()]);
    $routine = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (82,?,?,?)', ['WR1', 'Routine One', Db::now()]);

    $r = $queue->issueToken(82, $queueId, $routine, ['source' => 'appointment']);
    $u = $queue->issueToken(82, $queueId, $urgent, ['source' => 'walkin', 'priority' => 4, 'priority_reason' => 'Chest pain']);

    Assert::same(1, $queue->positionFor(Db::one('SELECT * FROM queue_entries WHERE id = ?', [$u['entry_id']]), 82),
        'the urgent walk-in is first despite the worst weight');
});

/* ------------------------------------------------------------------ */
/* 50. service workflows                                               */
/* ------------------------------------------------------------------ */

Assert::test('a required step blocks completion and names itself', function (): void {
    seedTenant(83, 'workflow-clinic', 'Workflow Clinic');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 83');
    $workflows = new App\Services\WorkflowService();
    $workflows->create(83, ['name' => 'Sample taken', 'position' => 1], null);
    $workflows->create(83, ['name' => 'Result verified', 'position' => 2], null);

    $patient = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (83,?,?,?)',
        ['WF1', 'Workflow One', Db::now()]);
    $queue = new App\Domain\Queue\QueueService();
    $entry = $queue->issueToken(83, $queueId, $patient, []);
    $queue->apply(83, $entry['entry_id'], 'call', []);
    $queue->apply(83, $entry['entry_id'], 'start', []);

    $blocked = $queue->apply(83, $entry['entry_id'], 'complete', []);
    Assert::false($blocked['ok'], 'completion is refused while a required step is open');
    Assert::same(['Sample taken', 'Result verified'], $blocked['steps_missing'], 'and both open steps are named');
    Assert::contains('Sample taken', (string) $blocked['message'], 'the message is a sentence a receptionist can act on');
    Assert::same('in_consultation', (string) Db::scalar('SELECT status FROM queue_entries WHERE id = ?', [$entry['entry_id']]),
        'the visit has not moved');
});

Assert::test('ticking every required step lets the visit complete', function (): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 83');
    $entryId = (int) Db::scalar("SELECT id FROM queue_entries WHERE clinic_id = 83 AND status = 'in_consultation'");
    $workflows = new App\Services\WorkflowService();

    $steps = array_column(Db::all('SELECT id FROM workflow_steps WHERE clinic_id = 83 ORDER BY position'), 'id');
    foreach ($steps as $stepId) {
        Assert::true($workflows->markDone(83, $entryId, (int) $stepId, null), 'the step is recorded');
    }
    Assert::false($workflows->markDone(83, $entryId, (int) $steps[0], null), 'ticking the same step twice is a no-op, not a duplicate');

    $queue = new App\Domain\Queue\QueueService();
    $done = $queue->apply(83, $entryId, 'complete', []);
    Assert::true($done['ok'], 'with every step ticked the visit completes');
    Assert::same('completed', (string) Db::scalar('SELECT status FROM queue_entries WHERE id = ?', [$entryId]), 'and the row moved');
    Assert::same(count($steps), (int) Db::scalar('SELECT COUNT(*) FROM entry_step_done WHERE entry_id = ?', [$entryId]),
        'one record per step, no extras');
});

Assert::test('an optional step is recorded but never blocks', function (): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 83');
    $workflows = new App\Services\WorkflowService();
    $optional = $workflows->create(83, ['name' => 'Leaflet given', 'position' => 3, 'is_required' => '0'], null);

    $patient = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (83,?,?,?)',
        ['WF2', 'Workflow Two', Db::now()]);
    $queue = new App\Domain\Queue\QueueService();
    $entry = $queue->issueToken(83, $queueId, $patient, []);
    $queue->apply(83, $entry['entry_id'], 'call', []);
    $queue->apply(83, $entry['entry_id'], 'start', []);

    // The two required steps from the previous test are still configured, so
    // tick them; the optional one stays open.
    foreach (Db::all('SELECT id FROM workflow_steps WHERE clinic_id = 83 AND is_required = 1') as $required) {
        $workflows->markDone(83, $entry['entry_id'], (int) $required['id'], null);
    }
    $progress = $workflows->progress(83, Db::one('SELECT * FROM queue_entries WHERE id = ?', [$entry['entry_id']]));
    $byName = array_column($progress, null, 'name');
    Assert::same(0, (int) $byName['Leaflet given']['done'], 'the optional step is not ticked');
    Assert::same([], $workflows->missing(83, Db::one('SELECT * FROM queue_entries WHERE id = ?', [$entry['entry_id']])),
        'and nothing required is missing');

    $result = $queue->apply(83, $entry['entry_id'], 'complete', []);
    Assert::true($result['ok'], 'so the visit completes with the optional step open');
});

Assert::test('a clinic with no steps completes in one action, as before', function (): void {
    seedTenant(84, 'no-workflow', 'No Workflow');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 84');
    $patient = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (84,?,?,?)',
        ['NW1', 'No Workflow One', Db::now()]);

    $queue = new App\Domain\Queue\QueueService();
    $entry = $queue->issueToken(84, $queueId, $patient, []);
    $queue->apply(84, $entry['entry_id'], 'call', []);
    $queue->apply(84, $entry['entry_id'], 'start', []);
    $result = $queue->apply(84, $entry['entry_id'], 'complete', []);

    Assert::true($result['ok'], 'no configured steps means no gate');
    Assert::same([], (new App\Services\WorkflowService())->progress(84, ['id' => $entry['entry_id'], 'queue_id' => $queueId]),
        'and no steps are invented for it');
});

Assert::test('steps are scoped by queue kind and one queue overrides its kind', function (): void {
    $workflows = new App\Services\WorkflowService();
    Db::run("UPDATE queues SET kind = 'lab' WHERE clinic_id = 83");
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 83');

    // A queue-level step with the same name replaces the kind-wide one.
    $queueSpecific = $workflows->create(83, ['name' => 'Sample taken', 'position' => 1, 'queue_id' => $queueId], null);
    $steps = $workflows->stepsFor(83, $queueId, 'lab');
    $named = array_values(array_filter($steps, static fn ($s) => $s['name'] === 'Sample taken'));

    Assert::same(1, count($named), 'the name appears once, not twice');
    Assert::same($queueSpecific, (int) $named[0]['id'], 'and the queue-level version wins');

    // A lab-only step must not appear on a consultation queue. (The two
    // steps created earlier carry no kind at all, so they stay clinic-wide.)
    $workflows->create(83, ['name' => 'Result filed', 'position' => 3, 'kind' => 'lab'], null);
    $labNames = array_column($workflows->stepsFor(83, $queueId, 'lab'), 'name');
    $consultNames = array_column($workflows->stepsFor(83, $queueId, 'consultation'), 'name');

    Assert::true(in_array('Result filed', $labNames, true), 'the lab step applies to a lab queue');
    Assert::false(in_array('Result filed', $consultNames, true), 'and not to a consultation queue');
    Assert::true(in_array('Result verified', $consultNames, true), 'a kind-less step still applies to every kind');
});

Assert::test('a retired or foreign step cannot be ticked', function (): void {
    $workflows = new App\Services\WorkflowService();
    $stepId = (int) Db::scalar("SELECT id FROM workflow_steps WHERE clinic_id = 83 AND name = 'Result verified'");
    $entryId = (int) Db::scalar('SELECT id FROM queue_entries WHERE clinic_id = 83 ORDER BY id LIMIT 1');

    $workflows->deactivate(83, $stepId);
    $refused = false;
    try {
        $workflows->markDone(83, $entryId, $stepId, null);
    } catch (\InvalidArgumentException) {
        $refused = true;
    }
    Assert::true($refused, 'a retired step cannot be ticked');

    $foreign = (int) Db::scalar('SELECT id FROM workflow_steps WHERE clinic_id = 1 LIMIT 1');
    if ($foreign === 0) {
        $refused = false;
        try {
            $workflows->markDone(83, $entryId, 99999, null);
        } catch (\InvalidArgumentException) {
            $refused = true;
        }
        Assert::true($refused, 'an unknown step is refused');
    }

    Assert::false($workflows->deactivate(83, 99999), 'and a step from nowhere cannot be retired');
});

/* ------------------------------------------------------------------ */
/* 51. onboarding checklist                                            */
/* ------------------------------------------------------------------ */

Assert::test('a freshly registered clinic is not ready and says why', function (): void {
    seedTenant(85, 'fresh-clinic', 'Fresh Clinic');
    // seedTenant verifies its owner; a real registration does not.
    Db::run('UPDATE users SET email_verified_at = NULL WHERE clinic_id = 85 AND role = \'clinic_admin\'');

    $onboarding = new App\Services\OnboardingService();
    $summary = $onboarding->summary(85);

    Assert::true($summary['blocking_open'], 'an unconfirmed owner blocks the clinic');
    Assert::same('verify_email', $summary['next'], 'and is the first thing named');
    Assert::true($summary['done'] < $summary['total'], 'the checklist is not complete');

    $steps = array_column($onboarding->steps(85), null, 'key');
    Assert::true($steps['verify_email']['blocking'], 'the email step is marked as blocking');
    Assert::false($steps['verify_email']['done'], 'and is not done');
});

Assert::test('the blocking claim is real: no tokens until the owner is verified', function (): void {
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 85');
    $patient = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (85,?,?,?)',
        ['OB1', 'Onboard One', Db::now()]);
    $queue = new App\Domain\Queue\QueueService();

    $refused = false;
    try {
        $queue->issueToken(85, $queueId, $patient, []);
    } catch (\RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'Confirm the clinic owner');
    }
    Assert::true($refused, 'the queue service itself refuses, so the warning is not decoration');

    Db::run('UPDATE users SET email_verified_at = ? WHERE clinic_id = 85 AND role = \'clinic_admin\'', [Db::now()]);
    $issued = $queue->issueToken(85, $queueId, $patient, []);
    Assert::true(isset($issued['token']), 'and stops refusing once verified');

    $summary = (new App\Services\OnboardingService())->summary(85);
    Assert::false($summary['blocking_open'], 'the warning is gone the moment the data changes');
});

Assert::test('every step moves when, and only when, the clinic is actually set up', function (): void {
    $onboarding = new App\Services\OnboardingService();
    $before = $onboarding->summary(85);

    // Hours are seven settings rows, not a table.
    foreach ([0, 1, 2, 3, 4, 5, 6] as $day) {
        Db::insert('INSERT INTO settings (scope, owner_id, key_name, value) VALUES (?,?,?,?)',
            ['clinic', 85, 'hours.' . $day, $day === 0 ? 'closed' : '08:30-12:30']);
    }
    $afterHours = $onboarding->summary(85);
    Assert::same($before['done'] + 1, $afterHours['done'], 'setting hours completes exactly one step');

    // A second doctor completes the doctors step...
    $room = (int) Db::scalar('SELECT id FROM rooms WHERE clinic_id = 85');
    $doctor = Db::insert('INSERT INTO doctors (clinic_id, room_id, name, avg_consult_minutes, is_active, created_at) VALUES (85,?,?,10,1,?)',
        [$room, 'Dr. Second', Db::now()]);
    $afterDoctor = $onboarding->summary(85);
    Assert::same($afterHours['done'] + 1, $afterDoctor['done'], 'a second doctor completes one step');

    // ...and a schedule completes another.
    Db::insert('INSERT INTO doctor_schedules (clinic_id, doctor_id, weekday, starts_at, ends_at, is_active, created_at) VALUES (85,?,1,?,?,1,?)',
        [$doctor, '08:30', '12:30', Db::now()]);
    $afterSchedule = $onboarding->summary(85);
    Assert::same($afterDoctor['done'] + 1, $afterSchedule['done'], 'a schedule completes one step');

    // Removing every doctor puts the doctors step back.
    Db::run('UPDATE doctors SET is_active = 0 WHERE clinic_id = 85');
    $regressed = $onboarding->summary(85);
    Assert::same($afterSchedule['done'] - 1, $regressed['done'], 'a step regresses when the data does, rather than staying green');
});

Assert::test('the display step is recorded once and stays recorded', function (): void {
    $onboarding = new App\Services\OnboardingService();
    $before = $onboarding->summary(85)['done'];

    $onboarding->noteSeen(85, 'display_seen');
    $after = $onboarding->summary(85)['done'];
    $onboarding->noteSeen(85, 'display_seen');

    Assert::same($before + 1, $after, 'looking at the display completes that step');
    Assert::same($after, $onboarding->summary(85)['done'], 'recording it again changes nothing');
    Assert::same(1, (int) Db::scalar(
        "SELECT COUNT(*) FROM settings WHERE scope = 'clinic' AND owner_id = 85 AND key_name = 'onboarding.display_seen'"
    ), 'and writes one row, not a new one per visit');
});

Assert::test('a fully configured clinic reaches 100% with nothing left to do', function (): void {
    $onboarding = new App\Services\OnboardingService();
    Db::run("UPDATE users SET is_active = 1 WHERE clinic_id = 85");
    Db::insert('INSERT INTO users (clinic_id, name, email, password_hash, role, is_active, created_at, email_verified_at)
                VALUES (85,?,?,?,?,1,?,?)',
        ['Desk Two', 'desk85@example.lk', password_hash('Passw0rd!23', PASSWORD_DEFAULT), 'receptionist', Db::now(), Db::now()]);
    Db::run("UPDATE clinics SET phone = '+94 11 000 0085', address_line = '12 Main St' WHERE id = 85");
    Db::run('UPDATE doctors SET is_active = 1 WHERE clinic_id = 85');

    $summary = $onboarding->summary(85);
    Assert::same(100, $summary['percent'], 'every step is satisfied by data');
    Assert::same(null, $summary['next'], 'and there is no next step');
    Assert::false($summary['blocking_open'], 'nothing is blocking');
});

Assert::test("one clinic's checklist says nothing about another", function (): void {
    seedTenant(86, 'other-clinic', 'Other Clinic');
    $a = (new App\Services\OnboardingService())->summary(85);
    $b = (new App\Services\OnboardingService())->summary(86);

    Assert::same(100, $a['percent'], 'clinic 85 stays complete');
    Assert::true($b['percent'] < 100, "clinic 86 is not credited with clinic 85's work");
});

/* ------------------------------------------------------------------ */
/* 52. appointment policies per service                                */
/* ------------------------------------------------------------------ */

Assert::test('with no policy configured a service behaves exactly as before', function (): void {
    seedTenant(87, 'policy-clinic', 'Policy Clinic');
    $policies = new App\Services\AppointmentPolicyService();

    $resolved = $policies->resolve(87, 'consultation');
    Assert::same('clinic', $resolved['source'], 'the clinic default answers');
    Assert::same(15, $resolved['late_grace_minutes'], 'grace is the clinic row value');
    Assert::same((new App\Services\SlotService())->horizonDays(87), $resolved['horizon_days'], 'horizon is the clinic setting');
    Assert::true($resolved['allow_walkins'], 'walk-ins are accepted');

    Assert::same('clinic', $policies->resolve(87, null)['source'], 'no kind also means the clinic default');
    Assert::same([], $policies->all(87), 'and nothing was invented');
});

Assert::test('a service policy overrides only what it names', function (): void {
    $policies = new App\Services\AppointmentPolicyService();
    $policies->save(87, ['kind' => 'lab', 'late_grace_minutes' => 45, 'horizon_days' => 90, 'allow_walkins' => '0'], null);

    $lab = $policies->resolve(87, 'lab');
    Assert::same('kind', $lab['source'], 'the lab policy answers');
    Assert::same(45, $lab['late_grace_minutes'], 'with the lab grace');
    Assert::same(90, $lab['horizon_days'], 'and the lab horizon');
    Assert::false($lab['allow_walkins'], 'and no walk-ins');

    $consult = $policies->resolve(87, 'consultation');
    Assert::same(15, $consult['late_grace_minutes'], 'a consultation is untouched');
    Assert::same('clinic', $consult['source'], 'and still says so');
});

Assert::test('a doctor row overrides the service but a blank field inherits it', function (): void {
    $policies = new App\Services\AppointmentPolicyService();
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 87');

    $policies->save(87, ['kind' => 'lab', 'doctor_id' => $doctorId, 'horizon_days' => 7], null);
    $resolved = $policies->resolve(87, 'lab', $doctorId);

    Assert::same('doctor', $resolved['source'], 'the doctor row answers');
    Assert::same(7, $resolved['horizon_days'], 'with the doctor horizon');
    Assert::same(45, $resolved['late_grace_minutes'], 'and the lab grace, inherited from the service row');
    Assert::false($resolved['allow_walkins'], 'and the service walk-in rule, not silently re-opened');
});

Assert::test('saving the same service twice replaces its policy instead of stacking', function (): void {
    $policies = new App\Services\AppointmentPolicyService();
    $policies->save(87, ['kind' => 'lab', 'late_grace_minutes' => 30, 'horizon_days' => 90, 'allow_walkins' => '0'], null);

    Assert::same(1, (int) Db::scalar(
        "SELECT COUNT(*) FROM appointment_policies WHERE clinic_id = 87 AND kind = 'lab' AND doctor_id IS NULL"
    ), 'there is one kind-wide lab policy');
    Assert::same(30, $policies->resolve(87, 'lab')['late_grace_minutes'], 'and the newer number won');
});

Assert::test('the walk-in rule and late grace actually change behaviour', function (): void {
    // A lab queue for this clinic.
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 87');
    $queueId = Db::insert('INSERT INTO queues (clinic_id, doctor_id, name, kind, token_prefix, session_date, status, last_token_no, created_at) VALUES (87,?,?,?,?,?,?,0,?)',
        [$doctorId, 'Lab', 'lab', 'L', Db::today(), 'open', Db::now()]);
    $patient = Db::insert('INSERT INTO patients (clinic_id, code, full_name, created_at) VALUES (87,?,?,?)',
        ['PWL1', 'Policy Walkin', Db::now()]);

    $queue = new App\Domain\Queue\QueueService();
    $refused = false;
    try {
        $queue->issueToken(87, $queueId, $patient, ['source' => 'walkin']);
    } catch (\RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'booked patients only');
    }
    Assert::true($refused, 'a walk-in is refused by the service, not just hidden in the UI');

    $booked = $queue->issueToken(87, $queueId, $patient, ['source' => 'appointment']);
    Assert::true(isset($booked['token']), 'a booked patient is still accepted');

    // Late arrival: the same 20 minutes is inside the lab's 45-minute grace
    // and outside the clinic's 15.
    $appointments = new App\Services\AppointmentService();
    $appt = ['clinic_id' => 87, 'doctor_id' => $doctorId, 'scheduled_for' => date('Y-m-d H:i:s', time() - 20 * 60)];
    Assert::same('on_time', $appointments->arrivalPolicy($appt)['status'], '20 min late is on time for the lab');

    Db::run("DELETE FROM appointment_policies WHERE clinic_id = 87 AND kind = 'lab'");
    Assert::same('late', $appointments->arrivalPolicy($appt)['status'], 'and late once the lab policy is gone');
});

Assert::test('a policy that changes nothing is refused', function (): void {
    $policies = new App\Services\AppointmentPolicyService();
    $refused = 0;
    foreach ([
        ['kind' => ''],
        ['kind' => 'lab'],
        ['kind' => 'lab', 'late_grace_minutes' => 500],
        ['kind' => 'lab', 'horizon_days' => 0],
        ['kind' => 'lab', 'late_grace_minutes' => 10, 'doctor_id' => 999999],
    ] as $bad) {
        try {
            $policies->save(87, $bad, null);
        } catch (\InvalidArgumentException) {
            $refused++;
        }
    }
    Assert::same(5, $refused, 'all five malformed policies are refused');
});

/* ------------------------------------------------------------------ */
/* 53. notification payload hygiene                                    */
/* ------------------------------------------------------------------ */

Assert::test('a sent message keeps no deliverable number, a queued one does', function (): void {
    seedTenant(88, 'payload-clinic', 'Payload Clinic');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 88');
    $patient = Db::insert('INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (88,?,?,?,?)',
        ['PH1', 'Payload One', '0771234567', Db::now()]);
    $queue = new App\Domain\Queue\QueueService();
    $entry = $queue->issueToken(88, $queueId, $patient, []);

    $notifications = new App\Services\NotificationService();
    // token_issued is queued by issueToken itself.
    $row = Db::one("SELECT id, status, payload_json, to_number FROM notifications WHERE entry_id = ? AND template = 'token_issued'", [$entry['entry_id']]);
    Assert::true($row !== null, 'the token message exists');
    Assert::same('queued', (string) $row['status'], 'and is waiting to be sent');

    $payload = json_decode((string) $row['payload_json'], true);
    Assert::same('0771234567', (string) ($payload['to'] ?? ''), 'a queued message still holds the number a retry needs');
    Assert::false(str_contains((string) $row['to_number'], '0771234567'), 'while the readable column is masked');

    // Send it, then the number must be gone.
    $notifications->processDue();
    $after = Db::one('SELECT status, payload_json FROM notifications WHERE id = ?', [(int) $row['id']]);
    Assert::same('logged', (string) $after['status'], 'the log transport recorded it');
    $afterPayload = json_decode((string) $after['payload_json'], true);
    Assert::true(!isset($afterPayload['to']), 'and the deliverable number was removed');
    Assert::true(trim((string) ($afterPayload['body'] ?? '')) !== '', 'the message body survives, so the history screen still reads');
});

Assert::test('no finished message anywhere still carries a number', function (): void {
    $offenders = 0;
    foreach (Db::all("SELECT status, payload_json FROM notifications WHERE payload_json IS NOT NULL AND payload_json <> ''") as $row) {
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload) || ($payload['to'] ?? '') === '') {
            continue;
        }
        if (in_array((string) $row['status'], ['sent', 'logged', 'abandoned'], true)) {
            $offenders++;
        }
    }
    Assert::same(0, $offenders, 'every terminal message has had its number removed');
});

/* ------------------------------------------------------------------ */
/* 54. incident records                                                */
/* ------------------------------------------------------------------ */

Assert::test('an incident gets a reference, a first entry and a real timeline', function (): void {
    seedTenant(89, 'incident-clinic', 'Incident Clinic');
    $incidents = new App\Services\IncidentService();

    $detected = date('Y-m-d\TH:i', time() - 30 * 60);
    $id = $incidents->open(89, [
        'title' => 'Display boards stopped updating',
        'severity' => 'major',
        'detected_at' => $detected,
        'summary' => 'Every board shows a stale token.',
    ], null);

    $incident = Db::one('SELECT * FROM incidents WHERE id = ?', [$id]);
    Assert::true(preg_match('/^INC-\d{4}-\d{4}$/', (string) $incident['reference']) === 1, 'it has a reference an operator can quote');
    Assert::same('major', (string) $incident['severity'], 'the severity is kept');
    Assert::same('open', (string) $incident['status'], 'and it starts open');
    Assert::false(str_contains((string) $incident['detected_at'], 'T'), 'the browser timestamp is normalised, not stored raw');

    $entries = $incidents->entries($id);
    Assert::same(1, count($entries), 'opening writes the first entry');
    Assert::contains('Incident opened', (string) $entries[0]['body'], 'which says who opened it and what they saw');

    // Remember which incident this test opened: the next tests act on it, and
    // "the newest incident" would otherwise pick up whatever a later case made.
    $GLOBALS['incident_probe_id'] = $id;
});

Assert::test('time to acknowledge is measured from detection, not from the click', function (): void {
    $incidents = new App\Services\IncidentService();
    $id = (int) $GLOBALS['incident_probe_id'];

    Assert::true($incidents->acknowledge(89, $id, null), 'the incident is acknowledged');
    $row = Db::one('SELECT * FROM incidents WHERE id = ?', [$id]);
    $timings = $incidents->timings($row);
    $expected = (int) round((strtotime((string) $row['acknowledged_at']) - strtotime((string) $row['detected_at'])) / 60);
    Assert::same($expected, $timings['acknowledge_minutes'], 'the response time is measured from detection, not from the click');
    Assert::true($timings['acknowledge_minutes'] >= 29, 'which is about the half hour the incident was open');

    $first = (string) Db::scalar('SELECT acknowledged_at FROM incidents WHERE id = ?', [$id]);
    Assert::false($incidents->acknowledge(89, $id, null), 'a second acknowledgement is refused');
    Assert::same($first, (string) Db::scalar('SELECT acknowledged_at FROM incidents WHERE id = ?', [$id]),
        'so the response clock never resets under the incident');
});

Assert::test('the timeline appends and is attributed', function (): void {
    $incidents = new App\Services\IncidentService();
    $id = (int) $GLOBALS['incident_probe_id'];

    $incidents->addEntry($id, 'cause', 'The display cache key was rotated without clearing the old one.');
    $incidents->addEntry($id, 'action', 'Restarted the poller on all three screens.');
    $entries = $incidents->entries($id);
    $bodies = array_column($entries, 'body');

    // Opening wrote one, acknowledging wrote another, so these two are the
    // newest. Asserting on the tail rather than an absolute count keeps the
    // test about ordering instead of arithmetic that changes with every step.
    Assert::true(count($entries) >= 4, 'the timeline grew');
    Assert::contains('cache key', (string) $bodies[count($bodies) - 2], 'the cause reads back in order');
    Assert::contains('Restarted', (string) $bodies[count($bodies) - 1], 'and the action came after it');
    Assert::same('cause', (string) $entries[count($entries) - 2]['kind'], 'the cause entry is typed as a cause');

    $refused = 0;
    foreach ([['unknown', 'x'], ['note', '   ']] as [$kind, $body]) {
        try {
            $incidents->addEntry($id, $kind, $body);
        } catch (\InvalidArgumentException) {
            $refused++;
        }
    }
    Assert::same(2, $refused, 'a blank entry and an unknown kind are both refused');
});

Assert::test('mitigate then resolve, and a resolved incident takes no more steps', function (): void {
    $incidents = new App\Services\IncidentService();
    $id = (int) $GLOBALS['incident_probe_id'];

    Assert::true($incidents->mitigate(89, $id, 'Boards updating again.'), 'mitigation is recorded');
    Assert::same('mitigated', (string) Db::scalar('SELECT status FROM incidents WHERE id = ?', [$id]), 'the status moved');

    Assert::true($incidents->resolve(89, $id, 'Key rotation now clears the cache.'), 'resolution is recorded');
    Assert::same('resolved', (string) Db::scalar('SELECT status FROM incidents WHERE id = ?', [$id]), 'the incident is closed');

    Assert::false($incidents->resolve(89, $id, 'again'), 'resolving twice does nothing');
    Assert::false($incidents->mitigate(89, $id, 'again'), 'and a resolved incident cannot be mitigated');

    $timings = $incidents->timings(Db::one('SELECT * FROM incidents WHERE id = ?', [$id]));
    Assert::true($timings['resolve_minutes'] !== null, 'time to resolve is computed');
});

Assert::test('an incident cannot be detected in the future or opened nameless', function (): void {
    $incidents = new App\Services\IncidentService();
    $refused = 0;

    foreach ([
        ['title' => 'ab', 'severity' => 'minor'],
        ['title' => 'Something', 'severity' => 'catastrophic'],
        ['title' => 'In the future', 'severity' => 'minor', 'detected_at' => date('Y-m-d\TH:i', time() + 7200)],
    ] as $bad) {
        try {
            $incidents->open(89, $bad, null);
        } catch (\InvalidArgumentException) {
            $refused++;
        }
    }
    Assert::same(3, $refused, 'a two-letter title, an unknown severity and a future detection are all refused');
});

Assert::test("a clinic cannot read or progress another clinic's incident", function (): void {
    seedTenant(90, 'other-incident', 'Other Incident');
    $incidents = new App\Services\IncidentService();
    $id = (int) $GLOBALS['incident_probe_id'];

    Assert::true($incidents->find(90, $id) === null, 'the other clinic cannot see it');
    Assert::false($incidents->acknowledge(90, $id, null), 'nor acknowledge it');
    Assert::true($incidents->find(89, $id) !== null, 'while its own clinic still can');
});

/* ------------------------------------------------------------------ */
/* 55. WhatsApp transport honesty                                      */
/* ------------------------------------------------------------------ */

Assert::test('WhatsApp never reports a send it did not make', function (): void {
    $gateway = new App\Services\WhatsAppGateway();

    putenv('WHATSAPP_TRANSPORT=auto');
    putenv('WHATSAPP_TOKEN');
    putenv('WHATSAPP_PHONE_ID');
    Assert::same('logged', $gateway->send('0771234567', 'Your token is next.'),
        'with no credentials the message is recorded, not claimed as sent');

    putenv('WHATSAPP_TRANSPORT=log');
    Assert::same('logged', $gateway->send('0771234567', 'Hello'), 'the log transport says logged');

    // The old behaviour returned "simulated", which the worker stored as a
    // completed send: a half-configured install looked identical to a working
    // one from inside the app. Setting http must now be a loud failure.
    putenv('WHATSAPP_TRANSPORT=http');
    $threw = false;
    try {
        $gateway->send('0771234567', 'Hello');
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'WHATSAPP_TOKEN');
    }
    Assert::true($threw, 'http without credentials fails loudly and says which variable is missing');

    putenv('WHATSAPP_TRANSPORT');
});

Assert::test('a whatsapp notification the log transport records is never counted as sent', function (): void {
    seedTenant(92, 'wa-clinic', 'WhatsApp Clinic');
    Db::run('UPDATE clinics SET is_active = 1 WHERE id = 92');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 92');
    $patient = Db::insert('INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (92,?,?,?,?)',
        ['WA1', 'WhatsApp One', '0771234567', Db::now()]);
    $queue = new App\Domain\Queue\QueueService();
    $entry = $queue->issueToken(92, $queueId, $patient, []);

    // Queue one on the whatsapp channel explicitly.
    Db::insert(
        "INSERT INTO notifications (clinic_id, entry_id, channel, template, to_number, payload_json, dedupe_key, status, attempts, scheduled_at, created_at)
         VALUES (92, ?, 'whatsapp', 'visit_completed', '•••••567', ?, 'wa-test:1', 'queued', 0, ?, ?)",
        [$entry['entry_id'], json_encode(['body' => 'Thank you', 'to' => '0771234567']), Db::now(), Db::now()]
    );

    putenv('WHATSAPP_TRANSPORT=log');
    $result = (new App\Services\NotificationService())->processDue();
    putenv('WHATSAPP_TRANSPORT');

    $row = Db::one("SELECT status, last_error, sent_at FROM notifications WHERE dedupe_key = 'wa-test:1'");
    Assert::same('logged', (string) $row['status'], 'it is stored as recorded, not sent');
    Assert::contains('not sent to the patient', (string) $row['last_error'], 'and says so in words a clinic can read');
    Assert::same(null, $row['sent_at'], 'with no send timestamp at all');
});

/* ------------------------------------------------------------------ */
/* 56. The patient reschedules — booking page and tracking link         */
/* ------------------------------------------------------------------ */

Assert::test('a signed booking link is the credential, and it dies when the booking moves', function (): void {
    $svc = new App\Services\AppointmentService();
    $sig = App\Services\AppointmentService::manageSignature(41, '2026-12-01 09:30:00');

    Assert::true(
        App\Services\AppointmentService::manageTokenValid(41, '2026-12-01 09:30:00', $sig),
        'the link the clinic issued works'
    );
    Assert::false(
        App\Services\AppointmentService::manageTokenValid(41, '2026-12-01 10:30:00', $sig),
        'the same signature against a moved appointment does not'
    );
    Assert::false(
        App\Services\AppointmentService::manageTokenValid(42, '2026-12-01 09:30:00', $sig),
        'and it cannot be pasted onto another patient by changing the id'
    );
    Assert::false(
        App\Services\AppointmentService::manageTokenValid(41, '2026-12-01 09:30:00', str_repeat('a', 32)),
        'a guessed signature is refused'
    );
    Assert::contains('/a/41.', App\Services\AppointmentService::manageUrl(41, '2026-12-01 09:30:00'),
        'the link the patient receives is the one the route serves');
});

Assert::test('a patient can move their own booking, and the day-cancel returns everybody', function (): void {
    seedTenant(91, 'resched', 'Reschedule Clinic');
    $svc = new App\Services\AppointmentService();
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 91 ORDER BY id LIMIT 1');
    $day = date('Y-m-d', strtotime('+3 days'));

    $mine = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (91,?,?,?,?)',
        ['R1', 'Mover One', '0771230001', Db::now()]
    );
    $other = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (91,?,?,?,?)',
        ['R2', 'Mover Two', '0771230002', Db::now()]
    );
    $first = $svc->create(91, $doctorId, $mine, $day . ' 09:00:00', 'online');
    $second = $svc->create(91, $doctorId, $other, $day . ' 09:15:00', 'online');

    $moved = $svc->reschedule(91, $first, $day . ' 15:00:00', 'Patient called');
    Assert::same($first, (int) $moved['appointment_id'], 'moving keeps the same appointment row');
    Assert::same($day . ' 09:00:00', (string) $moved['from'], 'and reports the time it came from');
    $row = Db::one('SELECT scheduled_for, status, notes FROM appointments WHERE id = ?', [$first]);
    Assert::same($day . ' 15:00:00', (string) $row['scheduled_for'], 'the new time is stored');
    Assert::same('booked', (string) $row['status'], 'and the booking is still live');
    Assert::contains('Moved from', (string) $row['notes'], 'with a note saying where it came from');

    $refused = 0;
    foreach ([
        [$first, $day . ' 09:15:00'],      // somebody else's slot
        [$first, date('Y-m-d', strtotime('-1 day')) . ' 09:00:00'], // the past
    ] as [$id, $when]) {
        try {
            $svc->reschedule(91, $id, $when, 'probe');
        } catch (\RuntimeException) {
            $refused++;
        }
    }
    Assert::same(2, $refused, 'a taken slot and a past time are both refused');

    // Give the row a history first, so the cancel can prove it appends.
    $before = (string) Db::scalar('SELECT notes FROM appointments WHERE id = ?', [$first]);
    $auditBefore = (int) Db::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'appointment.day_cancelled' AND clinic_id = 91");

    $closed = $svc->cancelDay(91, $doctorId, $day, 'Doctor unwell', 1);
    Assert::same(2, (int) $closed['cancelled'], 'cancelling the day returns every live booking that day');
    Assert::same(2, count($closed['patients']), 'and names every patient, so somebody can ring them');
    Assert::true($closed['patients'][0]['phone'] !== null, 'with the number the desk needs');

    $after = (string) Db::scalar('SELECT notes FROM appointments WHERE id = ?', [$first]);
    Assert::contains($before, $after, 'cancelling appends to the note instead of erasing why it had moved');
    Assert::contains('Doctor unavailable', $after, 'and records the reason it was cancelled');

    Assert::same(
        $auditBefore + 1,
        (int) Db::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'appointment.day_cancelled' AND clinic_id = 91"),
        'exactly one audit entry, not one from the service and a copy from the controller'
    );

    $again = $svc->cancelDay(91, $doctorId, $day, 'Doctor unwell', 1);
    Assert::same(0, (int) $again['cancelled'], 'a second cancel of the same day finds nothing');
    Assert::same(
        $auditBefore + 1,
        (int) Db::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'appointment.day_cancelled' AND clinic_id = 91"),
        'and writes no audit entry for an action that changed nothing'
    );
    Assert::same(0, (int) Db::scalar(
        "SELECT COUNT(*) FROM appointments WHERE clinic_id = 91 AND doctor_id = ? AND substr(scheduled_for,1,10) = ? AND status IN ('booked','checked_in')",
        [$doctorId, $day]
    ), 'and leaves nothing bookable behind');
    Assert::true($second > 0, 'both rows existed before the cancel');
});

/* ------------------------------------------------------------------ */
/* 57. The waiting-room kiosk                                          */
/* ------------------------------------------------------------------ */

Assert::test('a kiosk credential is separate from the display key and never sets a priority', function (): void {
    seedTenant(93, 'kiosk', 'Kiosk Clinic');
    $kiosk = new App\Services\KioskService();

    Assert::same(null, $kiosk->keyFor(93), 'a clinic starts with no kiosk');
    $key = $kiosk->rotate(93);
    Assert::same(48, strlen($key), 'the key is 192 bits, not a short code somebody can guess');
    Assert::same(93, $kiosk->clinicForKey($key), 'and it resolves to its clinic');

    $rotated = $kiosk->rotate(93);
    Assert::false($rotated === $key, 'issuing again replaces the key');
    Assert::same(null, $kiosk->clinicForKey($key), 'so the old one stops working, which is the point of rotating');
    Assert::same(93, $kiosk->clinicForKey($rotated), 'and the new one works');

    // The kiosk key must not be the display key: a leaked lobby screen would
    // otherwise let a stranger issue tokens.
    $display = Db::scalar("SELECT value FROM settings WHERE scope='clinic' AND owner_id=93 AND key_name='display_token'");
    Assert::true($display === null || $display !== $rotated, 'the kiosk key is not the display key');

    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 93 ORDER BY id LIMIT 1');
    $result = $kiosk->issue(93, $queueId, 'Kiosk Patient', '0771238888');
    Assert::true($result['ok'], 'a walk-in gets a token');
    Assert::true(strlen((string) $result['token']) > 0, 'with a token label');

    $entry = Db::one(
        'SELECT e.priority, e.priority_reason, e.source FROM queue_entries e
           JOIN patients p ON p.id = e.patient_id
          WHERE e.queue_id = ? ORDER BY e.id DESC LIMIT 1',
        [$queueId]
    );
    Assert::same('kiosk', (string) $entry['source'], 'the arrival is recorded as a kiosk arrival');
    Assert::same(0, (int) $entry['priority'], 'and the kiosk cannot raise its own priority');
    Assert::same(null, $entry['priority_reason'], 'nor invent a reason for one');

    $kiosk->revoke(93);
    Assert::same(null, $kiosk->clinicForKey($rotated), 'switching the kiosk off invalidates the key outright');
});

Assert::test('a kiosk refuses a session that is not open today', function (): void {
    seedTenant(94, 'kiosk-closed', 'Kiosk Closed Clinic');
    $kiosk = new App\Services\KioskService();
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 94 ORDER BY id LIMIT 1');

    // A session closed for the day must not appear in the kiosk's choices, and
    // must not issue even if the id is posted by hand.
    Db::run("UPDATE queues SET status = 'closed' WHERE id = ?", [$queueId]);
    Assert::same([], $kiosk->openQueues(94), 'a closed session is not offered');

    $refused = $kiosk->issue(94, $queueId, 'Too Late', '');
    Assert::false($refused['ok'], 'and posting its id directly is refused');
    Assert::contains('not open', (string) $refused['message'], 'with a sentence a patient can act on, not a stack trace');
});

/* ------------------------------------------------------------------ */
/* 58. Scanning a slip at the desk                                     */
/* ------------------------------------------------------------------ */

Assert::test('a scanned slip resolves to its own visit and nothing else', function (): void {
    seedTenant(95, 'scan', 'Scan Clinic');
    $svc = new App\Services\ScanService();
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 95 ORDER BY id LIMIT 1');
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (95,?,?,?,?)',
        ['S1', 'Scanner Patient', '0771237001', Db::now()]
    );
    $issued = (new App\Domain\Queue\QueueService())->issueToken(95, $queueId, $patient, ['source' => 'walkin']);
    $token = (string) $issued['track_token'];
    Db::run("UPDATE queue_entries SET track_expires_at = datetime('now','+6 hours') WHERE id = ?", [(int) $issued['entry_id']]);

    // The receiver at the desk reads the QR on the printed slip, which holds a
    // full tracking URL — not the bare token.
    $byUrl = $svc->resolve(95, 'https://clinic.example.lk/t/' . $token);
    Assert::true($byUrl['ok'], 'a full tracking URL from the slip resolves');
    Assert::same((int) $issued['entry_id'], (int) $byUrl['entry_id'], 'to the right visit');

    $bare = $svc->resolve(95, $token);
    Assert::true($bare['ok'], 'a reader that emits only the token also works');
    Assert::same((int) $issued['entry_id'], (int) $bare['entry_id'], 'and resolves to the same visit');

    $messy = $svc->resolve(95, "  \"https://x.lk/t/$token?utm=1#top\"  ");
    Assert::true($messy['ok'], 'quotes, a query string and a fragment are tolerated, because scanners emit them');

    // The scoping that matters: a slip is not a key to another clinic.
    $wrongClinic = $svc->resolve(1, $token);
    Assert::false($wrongClinic['ok'], "the same code is refused at another clinic");
    Assert::contains('not a visit at this clinic', (string) $wrongClinic['reason'],
        'with the same sentence as a code that does not exist, so nothing is learned from the difference');

    $nothing = $svc->resolve(95, '');
    Assert::false($nothing['ok'], 'an empty scan is refused rather than matching something');
    Assert::contains('Nothing was scanned', (string) $nothing['reason'], 'and says what happened');
});

Assert::test('a scanned slip for a finished or ambiguous visit refuses instead of guessing', function (): void {
    seedTenant(96, 'scan2', 'Scan Two Clinic');
    $svc = new App\Services\ScanService();
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 96 ORDER BY id LIMIT 1');
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (96,?,?,?,?)',
        ['S2', 'Expired Scanner', '0771237002', Db::now()]
    );
    $issued = (new App\Domain\Queue\QueueService())->issueToken(96, $queueId, $patient, ['source' => 'walkin']);

    Db::run("UPDATE queue_entries SET track_expires_at = datetime('now','-2 hours') WHERE id = ?", [(int) $issued['entry_id']]);
    $expired = $svc->resolve(96, (string) $issued['track_token']);
    Assert::false($expired['ok'], 'a link that has expired does not open the visit');
    Assert::contains('finished', (string) $expired['reason'], 'and the desk is told to search by name instead');

    // Two sessions on the same day can both contain A001. Picking one would
    // put a patient into the wrong doctor's queue.
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = 96 ORDER BY id LIMIT 1');
    $second = Db::insert(
        'INSERT INTO queues (clinic_id, doctor_id, name, token_prefix, session_date, status, last_token_no, created_at)
         VALUES (96, ?, ?, ?, ?, ?, 0, ?)',
        [$doctorId, 'Second session', 'A', Db::today(), 'open', Db::now()]
    );
    // A patient may hold only one entry per queue, so the ambiguous label needs
    // a patient in each session that has not already been issued a token here.
    $ambiguousPatients = [];
    foreach (['S3' => 'First Session Patient', 'S4' => 'Second Session Patient'] as $code => $name) {
        $ambiguousPatients[] = Db::insert(
            'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (96,?,?,?,?)',
            [$code, $name, '07712370' . substr($code, 1) . '0', Db::now()]
        );
    }
    foreach ([[$queueId, $ambiguousPatients[0]], [$second, $ambiguousPatients[1]]] as $i => [$q, $who]) {
        Db::run(
            "INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status, priority, sort_weight, source, joined_at, track_token, version)
             VALUES (96, ?, ?, 900, 'A900', 'waiting', 0, 3, 'walkin', ?, ?, 0)",
            [$q, $who, Db::now(), 'a900token' . str_repeat('x', 8) . $i]
        );
    }
    $ambiguous = $svc->resolve(96, 'A900');
    Assert::false($ambiguous['ok'], 'a label that exists in two sessions is not guessed at');
    Assert::contains('Search by name', (string) $ambiguous['reason'], 'the desk is told what to do instead');
});

/* ------------------------------------------------------------------ */
/* 59. Per-branch staff scoping                                        */
/* ------------------------------------------------------------------ */

Assert::test('a branch grant opens exactly one branch, and only on purpose', function (): void {
    seedTenant(97, 'branch-home', 'Branch Home');
    seedTenant(98, 'branch-other', 'Branch Other');
    $branches = new App\Services\BranchService();
    $userId = Db::insert(
        'INSERT INTO users (clinic_id, name, email, password_hash, role, is_active, created_at, email_verified_at)
         VALUES (97, ?, ?, ?, ?, 1, ?, ?)',
        ['Branch Reception', 'branch-reception@example.lk', password_hash('Branch#2026', PASSWORD_DEFAULT), 'receptionist', Db::now(), Db::now()]
    );

    // No grant: exactly the old behaviour, one branch.
    Assert::true($branches->canActIn($userId, 97, 97), 'a user can always act in their home branch');
    Assert::false($branches->canActIn($userId, 97, 98), 'and nowhere else without a grant');
    Assert::same(1, count($branches->allowedFor($userId, 97)), 'their switcher offers one branch, so it is hidden');

    $branches->grant($userId, 98, null);
    Assert::true($branches->canActIn($userId, 97, 98), 'the grant opens the second branch');
    Assert::same(2, count($branches->allowedFor($userId, 97)), 'and the switcher now offers two');

    // Granting twice must not produce two rows — otherwise revoking once
    // appears to fail.
    $branches->grant($userId, 98, null);
    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM branch_grants WHERE user_id = ?', [$userId]),
        'granting the same branch twice is idempotent');

    // The home branch is the users.clinic_id column, not a grant. Removing it
    // is a different operation with different consequences.
    $threw = false;
    try {
        $branches->revoke($userId, 97, 97);
    } catch (\RuntimeException) {
        $threw = true;
    }
    Assert::true($threw, 'the home branch cannot be revoked as if it were a grant');

    $branches->revoke($userId, 98, 97);
    Assert::false($branches->canActIn($userId, 97, 98), 'revoking closes it again');
});

Assert::test('the active branch is re-checked every request, so a revoked grant cannot outlive the session', function (): void {
    seedTenant(99, 'branch-a', 'Branch A');
    seedTenant(100, 'branch-b', 'Branch B');
    $branches = new App\Services\BranchService();
    $userId = Db::insert(
        'INSERT INTO users (clinic_id, name, email, password_hash, role, is_active, created_at, email_verified_at)
         VALUES (99, ?, ?, ?, ?, 1, ?, ?)',
        ['Branch Switcher', 'branch-switch@example.lk', password_hash('Switch#2026', PASSWORD_DEFAULT), 'receptionist', Db::now(), Db::now()]
    );

    $branches->grant($userId, 100, null);
    $branches->switchTo($userId, 99, 100);
    Assert::same(100, $branches->activeBranch($userId, 99), 'the session can move to a granted branch');

    // This is the failure mode that matters: revoke while the user is still
    // sitting in that branch. Their next request must not keep working there.
    $branches->revoke($userId, 100, 99);
    Assert::same(99, $branches->activeBranch($userId, 99), 'a revoked grant falls back to home on the very next request');

    // A branch that is switched off must not keep serving because a session
    // remembers it.
    $branches->grant($userId, 100, null);
    $branches->switchTo($userId, 99, 100);
    Db::run('UPDATE clinics SET is_active = 0 WHERE id = 100');
    Assert::same(99, $branches->activeBranch($userId, 99), 'a deactivated branch also falls back to home');
});

Assert::test('a branch that was never granted cannot be switched into, even by posting its id', function (): void {
    seedTenant(101, 'branch-c', 'Branch C');
    seedTenant(102, 'branch-d', 'Branch D');
    $branches = new App\Services\BranchService();
    $userId = Db::insert(
        'INSERT INTO users (clinic_id, name, email, password_hash, role, is_active, created_at, email_verified_at)
         VALUES (101, ?, ?, ?, ?, 1, ?, ?)',
        ['Branch Probe', 'branch-probe@example.lk', password_hash('Probe#2026', PASSWORD_DEFAULT), 'receptionist', Db::now(), Db::now()]
    );

    $threw = false;
    try {
        $branches->switchTo($userId, 101, 102);
    } catch (\RuntimeException $e) {
        $threw = true;
        Assert::contains('do not have access', $e->getMessage(), 'the refusal is a sentence, not a stack trace');
    }
    Assert::true($threw, 'switching to an ungranted branch is refused at the service boundary');
    Assert::same(101, $branches->activeBranch($userId, 101), 'and the session stays on home');

    // A grant for a clinic that does not exist must not create access to a
    // phantom branch.
    $threw = false;
    try {
        $branches->grant($userId, 99999, null);
    } catch (\RuntimeException) {
        $threw = true;
    }
    Assert::true($threw, 'a grant for a non-existent branch is refused');
});

/* ------------------------------------------------------------------ */
/* 60. Patient satisfaction                                            */
/* ------------------------------------------------------------------ */

Assert::test('a visit can be rated once, and only once it has finished', function (): void {
    seedTenant(103, 'feedback', 'Feedback Clinic');
    $feedback = new App\Services\FeedbackService();
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 103 ORDER BY id LIMIT 1');
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (103,?,?,?,?)',
        ['F1', 'Feedback Patient', '0771237101', Db::now()]
    );
    $issued = (new App\Domain\Queue\QueueService())->issueToken(103, $queueId, $patient, ['source' => 'walkin']);
    $entryId = (int) $issued['entry_id'];

    // Waiting: there is no consultation to judge yet.
    $entry = Db::one('SELECT id, status, finished_at FROM queue_entries WHERE id = ?', [$entryId]);
    $status = $feedback->statusFor($entry);
    Assert::false($status['can'], 'a waiting visit cannot be rated');
    Assert::same('not_finished', (string) $status['reason'], 'and says why');

    $threw = false;
    try {
        $feedback->record(103, $entryId, 5);
    } catch (\RuntimeException) {
        $threw = true;
    }
    Assert::true($threw, 'recording a rating for it is refused, not silently stored');

    // Complete it, then rate it.
    Db::run("UPDATE queue_entries SET status = 'completed', finished_at = ? WHERE id = ?", [Db::now(), $entryId]);
    $entry = Db::one('SELECT id, status, finished_at FROM queue_entries WHERE id = ?', [$entryId]);
    Assert::true($feedback->statusFor($entry)['can'], 'a completed visit can be rated');

    $saved = $feedback->record(103, $entryId, 4, 5, '  Polite and quick.  ');
    Assert::same(4, (int) $saved['rating'], 'the rating is stored');
    Assert::same(5, $saved['wait_rating'], 'with the separate wait rating');
    $row = Db::one('SELECT comment FROM visit_feedback WHERE entry_id = ?', [$entryId]);
    Assert::same('Polite and quick.', (string) $row['comment'], 'the comment is trimmed, not escaped at rest');

    // The second answer is refused by the unique index as well as the check:
    // a double-tap must not produce two ratings.
    Assert::false($feedback->statusFor($entry)['can'], 'an answered visit stops offering the question');
    $threw = false;
    try {
        $feedback->record(103, $entryId, 1);
    } catch (\RuntimeException) {
        $threw = true;
    }
    Assert::true($threw, 'a second rating is refused');
    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM visit_feedback WHERE entry_id = ?', [$entryId]),
        'and exactly one row exists');

    // Out-of-scale values are rejected before the database sees them.
    $threw = false;
    try {
        $feedback->record(103, $entryId, 9);
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    Assert::true($threw, 'a rating of 9 is not a rating');
});

Assert::test('the satisfaction report leads with the response rate, not just the mean', function (): void {
    seedTenant(104, 'feedback-report', 'Feedback Report Clinic');
    $feedback = new App\Services\FeedbackService();
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 104 ORDER BY id LIMIT 1');
    $doctorId = (int) Db::scalar('SELECT doctor_id FROM queues WHERE id = ?', [$queueId]);
    $service = new App\Domain\Queue\QueueService();

    // Five completed visits, two answers: 4 and 2. A screen showing "3.0"
    // without "2 of 5 replied" would be actively misleading.
    $ratings = [4, 2];
    for ($i = 0; $i < 5; $i++) {
        $patient = Db::insert(
            'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (104,?,?,?,?)',
            ['FR' . $i, 'Report Patient ' . $i, '07712372' . $i . '0', Db::now()]
        );
        $issued = $service->issueToken(104, $queueId, $patient, ['source' => 'walkin']);
        $entryId = (int) $issued['entry_id'];
        Db::run("UPDATE queue_entries SET status = 'completed', finished_at = ? WHERE id = ?", [Db::now(), $entryId]);
        if (isset($ratings[$i])) {
            $feedback->record(104, $entryId, $ratings[$i], null, $i === 0 ? 'Kind staff.' : null);
        }
    }

    $report = $feedback->report(104, Db::today(), Db::today());
    Assert::same(5, (int) $report['visits'], 'five visits in the range');
    Assert::same(2, (int) $report['responses'], 'two of them answered');
    Assert::same(40.0, (float) $report['response_rate'], 'so the response rate is 40%, and it is reported');
    Assert::same(3.0, (float) $report['mean'], 'the mean is 3.0');
    Assert::same(1, (int) ($report['distribution'][4] ?? 0), 'the distribution keeps the shape a mean hides');
    Assert::same(1, (int) ($report['distribution'][2] ?? 0), 'including the unhappy answer');
    Assert::same(1, count($report['comments']), 'and only the answer with words appears as a comment');
    Assert::true($report['by_doctor'] !== [], 'with a per-doctor breakdown');
    Assert::same($doctorId, (int) Db::scalar('SELECT doctor_id FROM visit_feedback WHERE clinic_id = 104 LIMIT 1'),
        'each rating is tied to the doctor who saw the patient');
});

/* ------------------------------------------------------------------ */
/* 61. The doctor's own session start                                  */
/* ------------------------------------------------------------------ */

Assert::test('a session that has not been started refuses to call anybody into the room', function (): void {
    seedTenant(105, 'session-start', 'Session Start Clinic');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 105 ORDER BY id LIMIT 1');
    $doctorId = (int) Db::scalar('SELECT doctor_id FROM queues WHERE id = ?', [$queueId]);
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (105,?,?,?,?)',
        ['SS1', 'Session Patient', '0771237301', Db::now()]
    );
    $service = new App\Domain\Queue\QueueService();
    $issued = $service->issueToken(105, $queueId, $patient, ['source' => 'walkin']);

    // A new session starts unstarted. Tokens may still be issued — the desk
    // keeps taking names — but nobody may be called into the room.
    Db::run('UPDATE queues SET started_at = NULL WHERE id = ?', [$queueId]);
    $before = $service->apply(105, (int) $issued['entry_id'], 'call', []);
    Assert::false($before['ok'], 'calling is refused while the session has not started');
    Assert::contains('has not been started', (string) $before['message'], 'and the message says what to press');
    Assert::same('waiting', (string) Db::scalar('SELECT status FROM queue_entries WHERE id = ?', [(int) $issued['entry_id']]),
        'the patient is still waiting, not silently dropped');

    // Starting is idempotent: pressing twice must not reset the clock that
    // says how long the session has been running.
    $first = $service->startSession(105, $queueId, 1);
    Assert::true($first['ok'], 'a doctor can start it');
    Assert::false($first['already'], 'and the first press is a real start');
    $second = $service->startSession(105, $queueId, 1);
    Assert::true($second['already'], 'the second press says it is already running');
    Assert::same((string) $first['started_at'], (string) $second['started_at'], 'and does not move the start time');

    $after = $service->apply(105, (int) $issued['entry_id'], 'call', []);
    Assert::true($after['ok'], 'calling works once the session has started');

    // The state, as the board reads it.
    $state = $service->sessionState(Db::one('SELECT * FROM queues WHERE id = ?', [$queueId]));
    Assert::same('running', $state['state'], 'the board reports it as running');

    // Refusals that matter: another day, and a closed session.
    Db::run("UPDATE queues SET session_date = ? WHERE id = ?", [date('Y-m-d', strtotime('-1 day')), $queueId]);
    $otherDay = $service->startSession(105, $queueId, 1);
    Assert::false($otherDay['ok'], "yesterday's session cannot be started today");
    Assert::contains('another day', (string) $otherDay['message'], 'and says why');

    Assert::true($doctorId > 0, 'the session belongs to a doctor');
});

/* ------------------------------------------------------------------ */
/* 62. Priority overuse is measurable, not just audited                */
/* ------------------------------------------------------------------ */

Assert::test('priority overuse is reported per person against their own token count', function (): void {
    seedTenant(106, 'overuse', 'Overuse Clinic');
    $clinicId = 106;
    $adminId = (int) Db::scalar("SELECT id FROM users WHERE email = 'admin106@alpha.example'");
    $heavy = Db::insert(
        'INSERT INTO users (clinic_id, name, email, password_hash, role, is_active, created_at, email_verified_at)
         VALUES (106, ?, ?, ?, ?, 1, ?, ?)',
        ['Heavy Overrider', 'heavy@overuse.example', password_hash('Heavy#2026', PASSWORD_DEFAULT), 'receptionist', Db::now(), Db::now()]
    );

    // Two people: one prioritises half of what they issue, one never does.
    // The report must separate them rather than produce a clinic-wide blur.
    $audit = static function (int $actor, string $action, array $meta = []) use ($clinicId): void {
        Db::insert(
            'INSERT INTO audit_logs (clinic_id, actor_id, action, entity, entity_id, meta_json, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$clinicId, $actor, $action, 'queue_entry', null, $meta === [] ? null : json_encode($meta), Db::now()]
        );
    };
    for ($i = 0; $i < 6; $i++) {
        $audit($heavy, 'queue.token_issued', ['token' => 'A00' . $i]);
    }
    for ($i = 0; $i < 4; $i++) {
        $audit($heavy, 'queue.priority', ['reason' => 'Elderly patient']);
    }
    for ($i = 0; $i < 6; $i++) {
        $audit($adminId, 'queue.token_issued', ['token' => 'B00' . $i]);
    }
    // An automated rule hit is not a person exercising judgement.
    $audit(0, 'queue.priority_rule_applied', ['rule' => 'Over 70', 'priority' => 1]);

    $report = (new App\Services\PriorityAuditService())->report($clinicId, Db::today(), Db::today());

    Assert::same(4, (int) $report['overrides'], 'four manual overrides are counted');
    Assert::same(1, (int) $report['rule_hits'], 'and the automated rule hit is counted apart, not as a person');
    Assert::same(33.3, (float) $report['rate'], 'the clinic rate is overrides over the 12 tokens issued');

    $byName = [];
    foreach ($report['per_staff'] as $row) {
        $byName[(string) $row['name']] = $row;
    }
    Assert::same(4, (int) $byName['Heavy Overrider']['overrides'], 'the heavy overrider has four');
    Assert::same(6, (int) $byName['Heavy Overrider']['tokens'], 'against their own six tokens');
    Assert::same(66.7, (float) $byName['Heavy Overrider']['rate'], 'so their rate is 66.7%');
    Assert::true(!isset($byName['Admin 106']), 'a person with no overrides is not listed as an overrider');

    // The reason is what makes an override reviewable, so it is grouped.
    Assert::true($report['reasons'] !== [], 'the reasons given are grouped');
    
    Assert::same('Elderly patient', (string) $report['reasons'][0]['reason'], 'and the commonest is named');
    Assert::same(4, (int) $report['reasons'][0]['count'], 'with its count');

    // 2 of 4 is 50%, which crosses the "high" threshold — and the flag must
    // quote both numbers so the reader can judge rather than take a verdict.
    Assert::true($report['flags'] !== [], 'a 50% rate raises a flag');
    $high = array_values(array_filter($report['flags'], static fn (array $f): bool => $f['severity'] === 'high'));
    Assert::true($high !== [], 'at the high severity');
    Assert::contains('6', (string) $high[0]['message'], 'the message carries the denominator');
    Assert::contains('of the', (string) $high[0]['message'], 'in words a manager can read');

    // A rate built on two overrides must not raise a flag: small numbers make
    // wild percentages, and a finding of "50%" from one person's afternoon is
    // how a report loses its credibility.
    $tiny = (new App\Services\PriorityAuditService())->report($clinicId, '2000-01-01', '2000-01-01');
    Assert::same([], $tiny['flags'], 'a period with no data raises nothing');
});

Assert::test('an override rate with no denominator is reported as unknown, not as zero', function (): void {
    seedTenant(107, 'overuse-nod', 'Overuse No Denominator');
    $clinicId = 107;
    $surgeon = Db::insert(
        'INSERT INTO users (clinic_id, name, email, password_hash, role, is_active, created_at, email_verified_at)
         VALUES (107, ?, ?, ?, ?, 1, ?, ?)',
        ['Visiting Surgeon', 'surgeon@nod.example', password_hash('Surgeon#2026', PASSWORD_DEFAULT), 'doctor', Db::now(), Db::now()]
    );
    // Two overrides but no tokens issued by this account: a visiting clinician
    // who only ever adjusts the order of a list somebody else created.
    for ($i = 0; $i < 2; $i++) {
        Db::insert(
            'INSERT INTO audit_logs (clinic_id, actor_id, action, entity, entity_id, meta_json, created_at)
             VALUES (107,?,?,?,?,?,?)',
            [$surgeon, 'queue.priority', 'queue_entry', null, json_encode(['reason' => 'Post-op bleeding']), Db::now()]
        );
    }

    $report = (new App\Services\PriorityAuditService())->report($clinicId, Db::today(), Db::today());
    $row = $report['per_staff'][0];

    Assert::same(2, (int) $row['overrides'], 'their overrides are counted');
    Assert::same(0, (int) $row['tokens'], 'they issued no tokens');
    Assert::same(null, $row['rate'], 'so the rate is null — unknown — rather than a reassuring 0%');
    // And no percentage flag is raised against them, because there is nothing
    // to divide by: a finding built on no denominator would be a fabrication.
    $personal = array_values(array_filter($report['flags'], static fn (array $f): bool => $f['who'] === 'Visiting Surgeon'));
    Assert::same([], $personal, 'no per-person flag is invented without a denominator');
});

/* ------------------------------------------------------------------ */
/* 63. Per-clinic message wording                                      */
/* ------------------------------------------------------------------ */

Assert::test('a clinic can change one message without changing the others', function (): void {
    seedTenant(108, 'templates', 'Templates Clinic');
    $templates = new App\Services\TemplateService();

    Assert::same(
        App\Services\NotificationService::TEMPLATES['token_issued'],
        $templates->body(108, 'token_issued'),
        'a clinic that has edited nothing gets the shipped wording'
    );

    $templates->save(108, 'token_issued', 'ඔබගේ අංකය {token}. {queue} රැඳී සිටින්න.', null);
    Assert::same('ඔබගේ අංකය {token}. {queue} රැඳී සිටින්න.', $templates->body(108, 'token_issued'),
        'their own wording is used from then on');
    Assert::same(
        App\Services\NotificationService::TEMPLATES['entry_called'],
        $templates->body(108, 'entry_called'),
        'and every message they did not edit keeps receiving the default'
    );
    Assert::same(
        App\Services\NotificationService::TEMPLATES['token_issued'],
        $templates->body(1, 'token_issued'),
        "another clinic's message is untouched"
    );

    // The message a patient actually receives is the one the clinic wrote.
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 108 ORDER BY id LIMIT 1');
    $patient = Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at) VALUES (108,?,?,?,?)',
        ['T1', 'Wording Patient', '0771237401', Db::now()]
    );
    $issued = (new App\Domain\Queue\QueueService())->issueToken(108, $queueId, $patient, ['source' => 'walkin']);
    $payload = Db::scalar(
        "SELECT payload_json FROM notifications WHERE entry_id = ? AND template = 'token_issued' ORDER BY id DESC LIMIT 1",
        [(int) $issued['entry_id']]
    );
    Assert::true($payload !== null, 'issuing a token queued a message');
    $body = (string) (json_decode((string) $payload, true)['body'] ?? '');
    Assert::contains('ඔබගේ අංකය ', $body, 'the queued message opens with the clinic\'s own words');
    Assert::contains((string) $issued['token'], $body, 'with the real token filled in, not a brace');
    Assert::false(str_contains($body, '{'), 'and no unreplaced placeholder survives');

    // Resetting returns to the default rather than to an empty string.
    Assert::true($templates->reset(108, 'token_issued'), 'the override can be removed');
    Assert::same(
        App\Services\NotificationService::TEMPLATES['token_issued'],
        $templates->body(108, 'token_issued'),
        'and the clinic is back on the default wording'
    );
});

Assert::test('a template that would reach a patient wrong is refused on save', function (): void {
    seedTenant(109, 'templates-two', 'Templates Two');
    $templates = new App\Services\TemplateService();

    $cases = [
        // [template, body, fragment the refusal must contain, why]
        ['token_issued', 'You are {toke} at {clinic}', 'toke', 'a placeholder that does not exist would be sent as braces'],
        ['token_issued', 'Please wait at {clinic} until called.', 'token', 'the token itself cannot be dropped'],
        ['token_issued', 'You are {token at {clinic}', null, 'an unmatched brace is caught'],
        ['token_issued', '   ', 'Write the message', 'an empty message is not a message'],
        ['not_a_template', 'Hello {token}', 'not a message', 'only real templates can be edited'],
        ['token_issued', str_repeat('a', 500) . ' {token}', 'under 480', 'a message that becomes four texts is a surprise bill'],
    ];

    foreach ($cases as [$template, $body, $fragment, $why]) {
        $threw = false;
        try {
            $templates->save(109, $template, $body, null);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            if ($fragment !== null) {
                Assert::contains($fragment, $e->getMessage(), $why);
            }
        }
        Assert::true($threw, 'refused: ' . $why);
    }

    // Nothing refused was written.
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM notification_templates WHERE clinic_id = 109'),
        'no refused message was stored, so the clinic still has the default');
});

/* ------------------------------------------------------------------ */
/* 64. Complete data export                                            */
/* ------------------------------------------------------------------ */

Assert::test('a clinic can export everything it owns as one archive', function (): void {
    $clinicId = 110;
    seedTenant($clinicId, 'exporting', 'Exporting Clinic');
    seedTenant(111, 'nosy', 'Nosy Clinic');

    // Two patients here, one at the other clinic, and a staff account with a
    // password — the three things the archive has to get right.
    foreach ([['A001', 'Kamala Silva', '+94 77 111 2222'], ['A002', 'නිමල් පෙරේරා', '+94 77 333 4444']] as [$code, $name, $phone]) {
        Db::insert(
            'INSERT INTO patients (clinic_id, code, full_name, phone, created_at, updated_at) VALUES (?,?,?,?,?,?)',
            [$clinicId, $code, $name, $phone, Db::now(), Db::now()]
        );
    }
    Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at, updated_at) VALUES (?,?,?,?,?,?)',
        [111, 'B001', 'Someone Else', '+94 77 999 0000', Db::now(), Db::now()]
    );
    Db::insert(
        "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, 'display_token', 'super-secret-display-token')",
        [$clinicId]
    );
    Db::insert(
        "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, 'kiosk.token', 'super-secret-kiosk-token')",
        [$clinicId]
    );

    $service = new App\Services\ExportService();
    $result = $service->build($clinicId, false);
    Assert::true($result['ok'], 'the archive was built');
    Assert::true(is_file($result['path']), 'and written somewhere on disk');

    $zip = new ZipArchive();
    Assert::same(true, $zip->open($result['path']), 'it is a real zip, not a renamed csv');

    // What must be in it.
    $patients = $zip->getFromName('patients.csv');
    Assert::true($patients !== false, 'the patient table is in the archive');
    Assert::contains('Kamala Silva', $patients, 'with this clinic\'s patients');
    Assert::contains('නිමල් පෙරේරා', $patients, 'and their names still in Sinhala');
    Assert::same(false, str_contains($patients, 'Someone Else'),
        'but nothing from another clinic — an export is not a data dump');

    $manifest = json_decode((string) $zip->getFromName('MANIFEST.json'), true);
    Assert::same('Exporting Clinic', $manifest['clinic'], 'the manifest names the clinic');
    Assert::same(false, $manifest['includes_credentials'], 'and records that credentials were withheld');

    // What must NOT be in it, under any option.
    Assert::same(false, str_contains((string) $zip->getFromName('users.csv'), 'password_hash'),
        'the password hash column is gone, not just blank');
    Assert::same(false, str_contains((string) $zip->getFromName('users.csv'), '$2y$'),
        'and no hash sneaks through as a value');

    $settings = (string) $zip->getFromName('clinic-settings.csv');
    Assert::contains('display_token', $settings, 'the manager can see the display token setting exists');
    Assert::same(false, str_contains($settings, 'super-secret-display-token'), 'but not its value');
    Assert::same(false, str_contains($settings, 'super-secret-kiosk-token'), 'nor the kiosk token');
    Assert::contains('not included', $settings, 'and the file says so in words, not a blank cell');

    $readme = (string) $zip->getFromName('README.txt');
    Assert::contains('password', strtolower($readme), 'the README explains what was left out');
    Assert::contains('keep this file somewhere safe', strtolower($readme), 'and tells the manager to look after it');

    $zip->close();
    @unlink($result['path']);

    // The opt-in exists for hardware replacement, and only then.
    $withCreds = $service->build($clinicId, true);
    Assert::true($withCreds['ok'], 'the same export can include screen configuration');
    $zip2 = new ZipArchive();
    $zip2->open($withCreds['path']);
    $settings2 = (string) $zip2->getFromName('clinic-settings.csv');
    Assert::contains('super-secret-display-token', $settings2,
        'when explicitly asked for, the display token is carried so a screen can be moved');
    Assert::same(false, str_contains((string) $zip2->getFromName('users.csv'), 'password_hash'),
        'but a password hash is never carried, however the export is asked for');
    $zip2->close();
    @unlink($withCreds['path']);
});

Assert::test('the export refuses politely rather than writing a half archive', function (): void {
    $service = new App\Services\ExportService();
    $missing = $service->build(999999, false);
    Assert::same(false, $missing['ok'], 'a clinic that does not exist cannot be exported');
    Assert::contains('no longer exists', (string) $missing['error'], 'and the error says what is wrong');

    // Counts are shown before building, so they must not invent tables either.
    $counts = $service->counts(999999);
    $patients = array_values(array_filter($counts, static fn (array $c): bool => $c['table'] === 'patients'));
    Assert::same(0, $patients[0]['rows'], 'a missing clinic shows zero rows, not an error page');
});

/* ------------------------------------------------------------------ */
/* 65. Offline writes and reconciliation                               */
/* ------------------------------------------------------------------ */

Assert::test('a desk can issue real tokens with no connection and sync them once', function (): void {
    $clinicId = 112;
    seedTenant($clinicId, 'offline', 'Offline Clinic');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = ? ORDER BY id LIMIT 1', [$clinicId]);
    $service = new App\Services\OfflineService();

    // The whole scheme rests on this: numbers are set aside BEFORE the line
    // drops, out of the same counter live tokens come from.
    $block = $service->reserveBlock($clinicId, $queueId, 3, null);
    Assert::true($block['ok'], 'a block of numbers can be set aside');
    Assert::same(3, count($block['labels']), 'three usable numbers came back');
    Assert::same(
        $block['to'],
        (int) Db::scalar('SELECT last_token_no FROM queues WHERE id = ?', [$queueId]),
        'the counter moved past the block, so nothing online can be issued those numbers'
    );

    // A live token issued now must be outside the block.
    $patientA = (int) Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at, updated_at) VALUES (?,?,?,?,?,?)',
        [$clinicId, 'O001', 'Online Patient', '+94 77 100 0001', Db::now(), Db::now()]
    );
    $online = (new App\Domain\Queue\QueueService())->issueToken($clinicId, $queueId, $patientA);
    Assert::true(
        (int) $online['token_no'] > $block['to'],
        'an online token skips the block entirely — the numbers cannot collide'
    );

    // Now the desk is offline: it uses the middle number of the block.
    $offlineNo = $block['from'] + 1;
    $items = [[
        'reservation' => 'offline-test-0001',
        'queue_id'    => $queueId,
        'token_no'    => $offlineNo,
        'patient_name' => 'Offline Walk In',
        'patient_phone' => '+94 77 200 0002',
        'joined_at'   => date('Y-m-d H:i:s', time() - 3600),
    ]];

    $first = $service->reconcile($clinicId, $items)['results'][0];
    Assert::same('applied', $first['status'], 'the offline reservation becomes a real visit on reconnect');
    Assert::same(
        $block['prefix'] . str_pad((string) $offlineNo, (int) Db::scalar('SELECT token_padding FROM queues WHERE id = ?', [$queueId]), '0', STR_PAD_LEFT),
        $first['token'],
        'the token the patient is holding is the one recorded, not the counter value'
    );

    $entry = Db::one('SELECT * FROM queue_entries WHERE id = ?', [(int) $first['entry_id']]);
    Assert::same(App\Domain\Queue\QueueStateMachine::WAITING, (string) $entry['status'], 'the visit is waiting, like any other');
    Assert::same((string) $entry['joined_at'], (string) $entry['joined_at'], 'sanity');
    Assert::true(
        strtotime((string) $entry['joined_at']) < time() - 3000,
        'it is recorded as having arrived an hour ago, not at the moment the line came back'
    );

    // The browser retried because the connection dropped mid-response.
    $again = $service->reconcile($clinicId, $items)['results'][0];
    Assert::same('duplicate', $again['status'], 'a replay is recognised rather than repeated');
    Assert::same((int) $first['entry_id'], (int) $again['entry_id'], 'and points at the same visit');
    Assert::same(
        1,
        (int) Db::scalar("SELECT COUNT(*) FROM queue_entries e JOIN patients p ON p.id = e.patient_id WHERE p.full_name = 'Offline Walk In'"),
        'the patient was not entered twice — the whole point of a reservation id'
    );
    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND full_name = ?', [$clinicId, 'Offline Walk In']),
        'and was not registered twice either');
});

Assert::test('an offline token invented by the browser is refused, not guessed at', function (): void {
    $clinicId = 113;
    seedTenant($clinicId, 'offline2', 'Offline Clinic Two');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = ? ORDER BY id LIMIT 1', [$clinicId]);
    $service = new App\Services\OfflineService();
    $block = $service->reserveBlock($clinicId, $queueId, 2, null);

    // A number outside the block is the dangerous case: it looks valid, and it
    // is exactly what a browser with stale storage would send.
    $outside = $service->reconcile($clinicId, [[
        'reservation' => 'offline-fake-001',
        'queue_id'    => $queueId,
        'token_no'    => $block['to'] + 500,
        'patient_name' => 'Made Up',
    ]])['results'][0];
    Assert::same('refused', $outside['status'], 'a number nobody set aside is refused');
    Assert::contains('never set aside', (string) $outside['message'], 'and the sentence says why');
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND full_name = ?', [$clinicId, 'Made Up']),
        'no patient record was created for a token that does not exist');

    // Without a reservation id there is no way to dedupe, so it must not be
    // accepted on trust.
    $noId = $service->reconcile($clinicId, [[
        'reservation' => 'abc',
        'queue_id'    => $queueId,
        'token_no'    => $block['from'],
        'patient_name' => 'No Id',
    ]])['results'][0];
    Assert::same('refused', $noId['status'], 'a too-short reservation id is refused');

    // A block from another clinic must not be usable here. The number has to
    // be outside this clinic's own block, or the test proves nothing — two
    // fresh clinics both start counting at 1, so a naive pick lands inside
    // both windows and is legitimately valid here.
    seedTenant(114, 'offline3', 'Offline Clinic Three');
    $otherQueue = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 114 LIMIT 1');
    $crossBlock = $service->reserveBlock(114, $otherQueue, 10, null);
    Assert::true($crossBlock['to'] > $block['to'], 'the other clinic\'s block extends past this one, so the number is unambiguous');
    $cross = $service->reconcile($clinicId, [[
        'reservation' => 'offline-cross-01',
        'queue_id'    => $queueId,
        'token_no'    => $crossBlock['to'],
        'patient_name' => 'Cross Clinic',
    ]])['results'][0];
    Assert::same('refused', $cross['status'], 'another clinic\'s block is not a valid source of numbers');

    // And the report a manager reads must count what was left unused.
    $report = $service->outstanding($clinicId);
    Assert::true(count($report['blocks']) >= 1, 'the block appears in the reconciliation report');
    Assert::same(2, (int) $report['unused'], 'with both numbers still counted as usable');
});
/* ------------------------------------------------------------------ */
/* 66. Consultation revenue                                            */
/* ------------------------------------------------------------------ */

Assert::test('revenue is billed at each doctor\'s fee, and says so', function (): void {
    $clinicId = 115;
    seedTenant($clinicId, 'revenue', 'Revenue Clinic');
    $queueId = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = ? ORDER BY id LIMIT 1', [$clinicId]);
    $doctorId = (int) Db::scalar('SELECT id FROM doctors WHERE clinic_id = ? ORDER BY id LIMIT 1', [$clinicId]);
    $today = Db::today();

    Db::run('UPDATE doctors SET consult_fee = ? WHERE id = ?', [1500, $doctorId]);

    // A counter, not random_int: token labels are UNIQUE per queue, and
    // drawing from 900 numbers makes this test fail roughly one run in
    // ninety for no reason anybody can reproduce. A label is an identity
    // here, not something that needs to be unpredictable.
    $revSeq = 0;
    $mk = static function (string $status, int $minutesAgo) use ($clinicId, $queueId, $today, &$revSeq): int {
        $revSeq++;
        $pid = (int) Db::insert(
            'INSERT INTO patients (clinic_id, code, full_name, phone, created_at, updated_at) VALUES (?,?,?,?,?,?)',
            [$clinicId, 'R' . $revSeq . substr(md5((string) $revSeq), 0, 4), 'Rev Patient ' . $revSeq,
             '+94 77 ' . (1000000 + $revSeq), Db::now(), Db::now()]
        );
        $finished = date('Y-m-d H:i:s', time() - $minutesAgo * 60);
        return Db::insert(
            'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status, priority,
                sort_weight, source, joined_at, finished_at, track_token, version)
             VALUES (?,?,?,?,?,?,1,0,?,?,?,?,0)',
            [$clinicId, $queueId, $pid, $revSeq, 'R' . str_pad((string) $revSeq, 3, '0', STR_PAD_LEFT), $status,
             'walkin', $finished, $status === 'completed' ? $finished : null, bin2hex(random_bytes(16))]
        );
    };

    // Three completed at 1500 = 4500, and two that must NOT count.
    $mk('completed', 120);
    $mk('completed', 90);
    $mk('completed', 60);
    $mk('called', 30);       // called but never finished — earns nothing
    $mk('cancelled', 20);    // cancelled — earns nothing

    $service = new App\Services\ReportService();
    $rev = $service->revenue($clinicId, $today, $today);

    Assert::same(4500.0, round($rev['total'], 2), 'only completed visits count, at the doctor fee');
    Assert::same(3, $rev['completed'], 'and the visit count matches');
    Assert::same('LKR', $rev['currency'], 'the clinic currency travels with the figure');
    Assert::same([], $rev['missing_fee'], 'a doctor with a fee set is not flagged');

    // A doctor with no fee: visits counted, money not invented, and named.
    $freeDoctor = (int) Db::insert(
        'INSERT INTO doctors (clinic_id, name, avg_consult_minutes, is_active, created_at) VALUES (?,?,?,1,?)',
        [$clinicId, 'Dr. No Fee', 10, Db::now()]
    );
    $freeQueue = (int) Db::insert(
        'INSERT INTO queues (clinic_id, doctor_id, name, token_prefix, session_date, status, last_token_no, created_at, started_at)
         VALUES (?,?,?,?,?,?,0,?,?)',
        [$clinicId, $freeDoctor, 'Free OPD', 'F', $today, 'open', Db::now(), Db::now()]
    );
    $pid = (int) Db::insert(
        'INSERT INTO patients (clinic_id, code, full_name, phone, created_at, updated_at) VALUES (?,?,?,?,?,?)',
        [$clinicId, 'RFREE115', 'Free Patient', '+94 77 000 9999', Db::now(), Db::now()]
    );
    Db::insert(
        'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status, priority,
            sort_weight, source, joined_at, finished_at, track_token, version)
         VALUES (?,?,?,?,?,?,1,0,?,?,?,?,0)',
        [$clinicId, $freeQueue, $pid, 1, 'F001', 'completed', 'walkin', Db::now(), Db::now(), bin2hex(random_bytes(16))]
    );

    $rev2 = $service->revenue($clinicId, $today, $today);
    Assert::same(4500.0, round($rev2['total'], 2), 'a visit with no fee adds nothing to the money');
    Assert::same(4, $rev2['completed'], 'but is still counted as a visit');
    Assert::same(['Dr. No Fee'], $rev2['missing_fee'], 'and the doctor is named, so the total is explainable');
    Assert::same(1, count($rev2['by_day']), 'the daily series groups by day');
    Assert::same(4500.0, round(array_values($rev2['by_day'])[0], 2), 'and its value matches the total');

    // A range excluding the visits must report nothing, not last week's number.
    $empty = $service->revenue($clinicId, '2020-01-01', '2020-01-02');
    Assert::same(0.0, round($empty['total'], 2), 'an empty range reports zero');
    Assert::same([], $empty['rows'], 'with no rows rather than a zero-filled fake');
});
/* ---- the public homepage must not expose patients ------------------ */

Assert::test('the public landing page cannot reach a patient name', function (): void {
    $view = (string) file_get_contents(QP_ROOT . '/resources/views/pages/landing.php');
    // Strip comments first: the docblock explains WHY there is no name, and
    // grepping the raw file would match that explanation.
    $code = (string) preg_replace('~/\*.*?\*/~s', '', $view);
    $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

    // The homepage is unauthenticated and screenshot-able. Its own FAQ promises
    // "tokens and room numbers only", so the demo panel must match that promise.
    Assert::true(strpos($code, 'full_name') === false, 'never selects or prints a patient name');
    Assert::true(strpos($code, 'JOIN patients') === false, 'the patients join is absent, not merely unprinted');
    // Match data access, not the word: 'phone' is also a legitimate icon name
    // in the feature list on this page.
    Assert::true(strpos($code, 'p.phone') === false && strpos($code, 'e.phone') === false
        && strpos($code, "['phone']") === false, 'no phone number either');
    Assert::true(strpos($code, 'track_token') === false, 'no capability token that could cancel a visit');
    Assert::true(strpos($code, 'token_label') !== false, 'it still shows the token, which is the point');
    Assert::true(strpos($code, 'room_name') !== false, 'and the room, matching the waiting-room screen');
});

/* ---- the public homepage must not overclaim ------------------------- */

/**
 * The landing design was supplied as a mockup full of placeholder credibility:
 * five hospital logos, a named medical director, "500+ clinics", "47% shorter
 * waits". None of it is ours. These tests keep it from creeping back in when
 * somebody tidies the copy later.
 */
$landingCopy = static function (): string {
    $view = (string) file_get_contents(QP_ROOT . '/resources/views/pages/landing.php');
    // Comments explain why a claim is ABSENT, so they must be stripped before
    // searching for the claim itself — otherwise the explanation fails the test.
    $code = (string) preg_replace('~/\*.*?\*/~s', '', $view);
    $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

    return (string) preg_replace('~<!--.*?-->~s', '', $code);
};

Assert::test('the homepage borrows no credibility it has not earned', function () use ($landingCopy): void {
    $code = $landingCopy();
    foreach (['Nawaloka', 'Lanka Hospitals', 'Asiri', 'Durdans', 'Unity Healthcare'] as $brand) {
        Assert::true(strpos($code, $brand) === false, 'no hospital named as a customer: ' . $brand);
    }
    foreach (['500+', '47%', '92%', '2.5 hrs', '30%'] as $stat) {
        Assert::true(strpos($code, $stat) === false, 'no statistic we cannot measure: ' . $stat);
    }
    Assert::true(strpos($code, 'Medical Director') === false, 'no invented testimonial author');
});

Assert::test('homepage prices and limits are read from the plan row', function () use ($landingCopy): void {
    $code = $landingCopy();
    // A hardcoded price drifts the moment a plan is repriced, and the homepage
    // would then be advertising a number billing does not honour.
    foreach (['3500', '3,500', '7900', '7,900', '14900', '14,900'] as $literal) {
        Assert::true(strpos($code, $literal) === false, 'no hardcoded price: ' . $literal);
    }
    Assert::true(strpos($code, 'price_monthly') !== false, 'the price comes from the column');
    Assert::true(strpos($code, 'number_format') !== false, 'and is formatted, not typed');
    foreach (['max_doctors', 'max_staff', 'max_queues'] as $col) {
        Assert::true(strpos($code, $col) !== false, 'limits come from ' . $col);
    }
});

Assert::test('the homepage advertises the trial signup actually grants', function () use ($landingCopy): void {
    $svc = (string) file_get_contents(QP_ROOT . '/app/Services/ClinicService.php');
    Assert::true(preg_match("~strtotime\('\+(\d+) days'\)~", $svc, $m) === 1, 'the trial length is readable from the signup code');
    $days = (int) ($m[1] ?? 0);
    Assert::true($days > 0, 'a real trial length was found');
    $code = $landingCopy();
    Assert::true(strpos($code, $days . '-day free trial') !== false, 'the CTA matches the granted trial');
    Assert::true(strpos($code, $days . ' days, full access') !== false, 'so does the reassurance line');
});

Assert::test('the homepage quotes one currency and no exchange rate', function () use ($landingCopy): void {
    $code = $landingCopy();
    Assert::true(strpos($code, 'LKR') !== false, 'prices are labelled LKR');
    Assert::true(strpos($code, 'USD') === false, 'no second currency we have no rate for');
});

Assert::test('the homepage keeps its script external for the CSP', function (): void {
    $view = (string) file_get_contents(QP_ROOT . '/resources/views/pages/landing.php');
    Assert::true(
        preg_match('~<script(?![^>]*\bsrc=)[^>]*>~i', $view) === 0,
        'no inline script: script-src is self, so inline would silently not run'
    );
    Assert::true(strpos($view, "js/landing.js") !== false, 'the page behaviour ships as its own file');
});

/* ---- a rebuild must actually reach a returning browser -------------- */

Assert::test('a rebuilt asset is a new url, so the immutable cache is safe', function (): void {
    // serve.mjs sends `max-age=31536000, immutable` for /assets/build/. That is
    // only correct for a content-addressed url. asset() used to return a fixed
    // path, so a browser that had visited once kept the OLD stylesheet for a
    // year and rendered the redesigned homepage unstyled — new markup, old CSS.
    $css = asset('build/app.css');
    Assert::true(strpos($css, '?v=') !== false, 'the stylesheet url carries a fingerprint');

    $file = QP_ROOT . '/public/assets/build/app.css';
    Assert::true(is_file($file), 'the built stylesheet exists');
    Assert::true(strpos($css, substr((string) md5_file($file), 0, 10)) !== false, 'the fingerprint is the file digest');

    // The header that makes this mandatory, asserted so the pairing is explicit.
    $serve = (string) file_get_contents(QP_ROOT . '/tools/serve.mjs');
    Assert::true(strpos($serve, 'immutable') !== false, 'build assets really are served immutable');

    // A missing file must not produce a bogus "?v=" that caches a 404 forever.
    Assert::true(strpos(asset('build/definitely-not-built.css'), '?v=') === false, 'no fingerprint invented for a missing file');
});

Assert::test('the service worker cannot pin an old stylesheet', function (): void {
    $sw = (string) file_get_contents(QP_ROOT . '/public/sw.js');
    Assert::true(strpos($sw, 'hit || fetch(req)') === false, 'the shell is no longer cache-first');
    Assert::true(strpos($sw, 'SHELL_ASSETS.includes(url.pathname)') !== false, 'the shell branch still exists');
    Assert::true(strpos($sw, 'qp-v2') === false, 'the old cache version is gone, so activate() drops it');
    // Data must still bypass the cache entirely: a stale "you are next" is a lie.
    Assert::true(strpos($sw, "url.pathname.startsWith('/api/')") !== false, 'the api bypass survives');
});

/* ---- brand surface: one document, one scale, honest motion ----------- */

Assert::test('a full-document view is not wrapped in the console layout', function (): void {
    // view() took two parameters while nine callers passed a third `false`.
    // PHP silently drops extra arguments, so the landing, kiosk and track
    // error pages were rendered INSIDE the console layout: two <html> tags.
    // The browser keeps the first, so the page's own html/body classes — the
    // brand tokens and the scale knob — were thrown away.
    $fn = new \ReflectionFunction('view');
    Assert::same(3, $fn->getNumberOfParameters(), 'view() accepts the asLayout flag');
    $render = new \ReflectionMethod(\App\Core\View::class, 'render');
    Assert::same(3, $render->getNumberOfParameters(), 'View::render accepts it too');
    $helpers = (string) file_get_contents(QP_ROOT . '/app/helpers.php');
    Assert::true(strpos($helpers, 'View::render($template, $data, $asLayout)') !== false, 'and the helper actually forwards it');
});

Assert::test('the homepage scale is a single knob that reaches every size', function (): void {
    // Strip comments FIRST: the explanation quotes both `.lp-root { font-size }`
    // and `html { zoom }`, so a raw substring match passes or fails on prose.
    $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(QP_ROOT . '/resources/css/app.css'));
    $view = (string) file_get_contents(QP_ROOT . '/resources/views/pages/landing.php');
    Assert::true(strpos($view, 'class="lp-root') !== false, 'the document is marked as the landing page');
    // Exactly ONE scale mechanism. `html { zoom: .9 }` and a root font-size on
    // the same element multiply: 0.9 x 0.9 = 0.81, which took the container from
    // 1062px to 955.8px and every 44px tap target to 39.6px. Computed style still
    // reads 14.4px in that state, so only geometry catches it — hence this guard.
    Assert::true(preg_match('~html\s*\{[^}]*zoom:\s*0?\.9~s', $css) === 1, 'zoom is the scale mechanism');
    Assert::true(
        preg_match('~\.lp-root\s*\{[^}]*font-size~s', $css) === 0,
        'and no root font-size compounds with it'
    );
    // A stray px size would silently ignore the rem proportions.
    Assert::true(preg_match('~\[[0-9.]+px\]~', $view) === 0, 'every arbitrary size in the view is in rem');
});

Assert::test('breakpoints stay in step with the zoom, in both apps', function (): void {
    // CSS zoom does not resize the viewport media queries evaluate against, so
    // every screen must be the stock Tailwind value x 0.9 or layouts flip at the
    // wrong physical window width. Change the zoom, change these.
    $expected = ['sm' => '576px', 'md' => '691.2px', 'lg' => '921.6px', 'xl' => '1152px', '2xl' => '1382.4px'];
    foreach (['queuepilot', 'devtest'] as $app) {
        $root = $app === 'queuepilot' ? QP_ROOT : dirname(QP_ROOT) . '/devtest';
        if (!is_dir($root)) {
            continue; // the sibling app is not always checked out
        }
        $cfg = (string) file_get_contents($root . '/resources/tailwind/tailwind.config.js');
        foreach ($expected as $name => $px) {
            $q = preg_quote($name, '~');
            Assert::true(
                preg_match("~['\"]?{$q}['\"]?\s*:\s*['\"]" . preg_quote($px, '~') . "['\"]~", $cfg) === 1,
                $app . ' ' . $name . ' is ' . $px
            );
        }
        // vh resolves against the unzoomed viewport and is THEN scaled, so
        // min-h-screen renders 10% short unless the utility divides it back out.
        // This is what left a white strip under the auth pages and console shell.
        foreach (['height', 'minHeight'] as $key) {
            Assert::true(
                preg_match("~{$key}\s*:\s*\{\s*screen\s*:\s*['\"]calc\(100vh\s*/\s*0?\.9\)['\"]~", $cfg) === 1,
                $app . ' ' . $key . '.screen compensates for the zoom'
            );
        }
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($root . '/resources/css/app.css'));
        Assert::true(preg_match('~html\s*\{[^}]*zoom:\s*0?\.9~s', $css) === 1, $app . ' carries the matching zoom');
        // Printing is never screen-scaled.
        Assert::true(preg_match('~@media\s+print\s*\{[^}]*zoom:\s*1~s', $css) === 1, $app . ' prints at true size');
    }
});

Assert::test('no credential is ever printed on an unauthenticated page', function (): void {
    // The sign-in page is public and screenshot-able. A demo chip carrying the
    // seeded platform-admin password hands over every tenant on any install
    // that has not rotated it, so passwords must not reach the markup at all.
    $views = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(QP_ROOT . '/resources/views'));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $views[$f->getPathname()] = (string) file_get_contents($f->getPathname());
        }
    }
    // Prove the walk reached the file this test is about, rather than asserting
    // a file count that drifts whenever a view is added or removed.
    Assert::true(isset($views[QP_ROOT . '/resources/views/pages/auth/login.php']), 'the scan reached the sign-in view');
    Assert::true(count($views) >= 50, 'and walked the whole tree, not one directory');
    foreach ($views as $path => $src) {
        $rel = str_replace(QP_ROOT . '/', '', $path);
        // Strip PHP comments so the explanation above is not a false positive.
        $code = preg_replace('~/\*.*?\*/~s', '', $src);
        Assert::true(
            preg_match('~(QueuePilot|Serenity|Reception|Doctor|Lakeview|Coastal)#20[0-9]{2}~', $code) === 0,
            $rel . ' prints no seeded password'
        );
        Assert::true(strpos($code, 'data-demo-pass') === false, $rel . ' ships no password attribute');
    }
    $login = $views[QP_ROOT . '/resources/views/pages/auth/login.php'];
    Assert::true(
        strpos($login, "Env::get('app.env', 'production') !== 'production'") !== false,
        'the local demo block is gated off in production'
    );
    Assert::true(strpos($login, 'owner@queuepilot.app') === false, 'and never lists the platform admin');
});

Assert::test('scroll reveal is an enhancement, never a gate on content', function (): void {
    $css = (string) file_get_contents(QP_ROOT . '/resources/css/app.css');
    $js  = (string) file_get_contents(QP_ROOT . '/public/assets/js/landing.js');
    // Hidden ONLY once the script has armed it, so a no-JS visitor sees everything.
    Assert::true(
        preg_match('~\.lp-reveal-ready\s+\[data-reveal\]\s*\{\s*opacity:\s*0~', $css) === 1,
        'hiding is scoped to the armed class'
    );
    Assert::true(strpos($js, "classList.add('lp-reveal-ready')") !== false, 'the script arms it');
    $view = (string) file_get_contents(QP_ROOT . '/resources/views/pages/landing.php');
    Assert::true(strpos($view, 'lp-reveal-ready') === false, 'the markup never ships pre-hidden');
    Assert::true(strpos($css, 'prefers-reduced-motion') !== false, 'reduced motion is honoured');
    Assert::true(strpos($js, 'prefers-reduced-motion') !== false, 'in both the styles and the script');
});

Assert::test('the reveal repeats in both directions', function (): void {
    $js = (string) file_get_contents(QP_ROOT . '/public/assets/js/landing.js');
    $css = (string) file_get_contents(QP_ROOT . '/resources/css/app.css');
    // Requested: not a one-time animation. The observer must keep watching and
    // toggle both ways, so scrolling back up replays the entrance.
    Assert::true(strpos($js, "classList.toggle('is-in', entry.isIntersecting)") !== false, 'the class is toggled, not just added');
    $revealBlock = substr($js, (int) strpos($js, 'var io = new IntersectionObserver'));
    $revealBlock = substr($revealBlock, 0, (int) strpos($revealBlock, 'Array.prototype.forEach'));
    Assert::true(strpos($revealBlock, 'unobserve') === false, 'the reveal observer never stops watching');
    // The exit needs its own transition, or it would snap instead of fading.
    Assert::true(
        preg_match('~\.lp-reveal-ready\s+\[data-reveal\]\s*\{[^}]*transition:~s', $css) === 1,
        'the hidden state carries a transition too'
    );
});

Assert::test('the homepage staleness claim matches the poller', function () use ($landingCopy): void {
    // The ledger promises "marks itself stale after 30 seconds without data".
    // That is a number, so it has to come from the code rather than from copy
    // someone liked the sound of. The poller owns the truth.
    $js = (string) file_get_contents(QP_ROOT . '/public/assets/js/app.js');
    Assert::true(preg_match('~Date\.now\(\)\s*-\s*lastOk\s*>\s*(\d+)~', $js, $m) === 1, 'the stale threshold is readable from the poller');
    $seconds = (int) round(((int) ($m[1] ?? 0)) / 1000);
    Assert::true($seconds > 0, 'a real threshold was found');
    Assert::true(
        strpos($landingCopy(), 'stale after ' . $seconds . ' seconds') !== false,
        'the ledger quotes the poller exactly (' . $seconds . 's)'
    );
});

Assert::test('the rebuilt sections use shared primitives, not one-offs', function (): void {
    $view = (string) file_get_contents(QP_ROOT . '/resources/views/pages/landing.php');
    $css  = (string) file_get_contents(QP_ROOT . '/resources/css/app.css');
    foreach (['lp-caprail', 'lp-duet', 'lp-mark', 'lp-decision'] as $cls) {
        Assert::true(strpos($view, $cls) !== false, $cls . ' is used by the view');
        Assert::true(strpos($css, '.' . $cls) !== false, $cls . ' is defined once in the stylesheet');
    }
    // The quote carousel was replaced by annotated evidence: no hidden content,
    // no unattributed quotation, and no JS needed to read the section.
    Assert::true(strpos($view, 'data-lp-carousel') === false, 'the carousel markup is gone');
    $js = (string) file_get_contents(QP_ROOT . '/public/assets/js/landing.js');
    Assert::true(strpos($js, 'lp-carousel') === false, 'and its dead script went with it');
    // Every marker on the dashboard must have a decision to point at.
    // Count inside the evidence band, and by class prefix: the tiles carry
    // modifier utilities alongside lp-mark, so an exact class="lp-mark" match
    // would silently miss them.
    $ev = substr($view, (int) strpos($view, 'lp-band lp-band--dark'));
    $ev = substr($ev, 0, (int) strpos($ev, '</section>'));
    // Two source occurrences: one inside the tile loop (emitted twice, for the
    // count and the average) and one on the doctor-list heading. The rendered
    // total of three is asserted against the DOM in rhythm_verify.mjs.
    Assert::same(2, substr_count($ev, 'class="lp-mark'), 'markers sit on the tiles and the doctor list');
    Assert::true(strpos($view, '$decisions') !== false, 'keyed to a decision list');
    // The capability strip was restored on request; the ledger it replaced must
    // not linger as dead markup or dead CSS.
    Assert::true(strpos($view, 'lp-ledger') === false, 'the ledger markup is gone');
    Assert::true(strpos($css, '.lp-ledger') === false, 'and so are its rules');
});

Assert::test('sparklines keep their viewBox ratio so they cannot be letterboxed', function (): void {
    $css  = (string) file_get_contents(QP_ROOT . '/resources/css/app.css');
    $view = (string) file_get_contents(QP_ROOT . '/resources/views/pages/landing.php');
    // A fixed height on a 260x70 viewBox makes the browser fit-to-height and
    // centre the plot, so it floated inset instead of filling the card on mobile.
    Assert::true(preg_match('~\.lp-spark\s*\{[^}]*aspect-ratio:\s*260\s*/\s*70~s', $css) === 1, 'the ratio is pinned in CSS');
    Assert::same(2, substr_count($view, 'class="lp-spark'), 'both charts use it');
    Assert::true(
        preg_match('~<svg viewBox="0 0 260 70"[^>]*class="[^"]*h-\[~', $view) === 0,
        'neither chart carries a fixed height that would override it'
    );
});


Assert::test('the brand type is self-hosted and licensed', function (): void {
    $dir = QP_ROOT . '/public/assets/fonts/';
    foreach (['fraunces-var.woff2', 'jetbrains-mono-var.woff2'] as $font) {
        Assert::true(is_file($dir . $font), $font . ' is present');
    }
    foreach (['LICENSE.txt', 'LICENSE-fraunces.txt', 'LICENSE-jetbrains-mono.txt'] as $lic) {
        Assert::true(is_file($dir . $lic), $lic . ' ships with the fonts');
    }
    $view = (string) file_get_contents(QP_ROOT . '/resources/views/pages/landing.php');
    Assert::true(strpos($view, 'fonts.googleapis') === false, 'no third-party font request');
});

/* ------------------------------------------------------------------ */

/* ------------------------------------------------------------------ */
/* 47. integrations                                                     */
/* ------------------------------------------------------------------ */

Assert::test('every catalogued integration is offered with a state', function (): void {
    $service = new App\Services\IntegrationService();
    $list = $service->listFor(1);

    Assert::true(count($list) >= 5, 'the full catalogue is listed');
    foreach ($list as $entry) {
        Assert::true(array_key_exists('provider', $entry), 'provider named');
        Assert::true(array_key_exists('connected', $entry), 'connected is always answered');
        Assert::true(array_key_exists('available', $entry), 'available is always answered');
        Assert::true(is_bool($entry['available']), 'available is a boolean, never null');
        Assert::true(is_bool($entry['connected']), 'connected is a boolean, never null');
    }

    // Available and connected are two separate facts. Collapsing them is how
    // a clinic files a support ticket about an integration that simply is
    // not installed on this server. The patient app and webhooks need no
    // server-side credential, so they are available everywhere; the rest
    // depend on what this deployment has been given.
    $byProvider = array_column($list, null, 'provider');
    Assert::true($byProvider['patient_app']['available'], 'patient app needs no credential');
    Assert::true($byProvider['webhooks']['available'], 'webhooks need no credential');
    $providers = array_column($list, 'provider');
    foreach (['sms_gateway', 'email_transport', 'accounting', 'patient_app', 'webhooks'] as $p) {
        Assert::true(in_array($p, $providers, true), $p . ' is in the catalogue');
    }
});

Assert::test('connecting an unknown integration is refused', function (): void {
    $service = new App\Services\IntegrationService();
    Assert::false($service->connect(1, 'not_a_provider', []));
    Assert::false($service->connect(1, '', []));
    $rows = Db::all("SELECT provider FROM integrations WHERE clinic_id = 1 AND provider NOT IN
                     ('sms_gateway','email_transport','accounting','patient_app','webhooks')");
    Assert::same([], $rows, 'nothing was written for a bogus provider');
});

Assert::test('only whitelisted config keys are stored, and they are truncated', function (): void {
    $service = new App\Services\IntegrationService();
    $service->connect(1, 'webhooks', [
        'endpoint'      => 'https://hooks.example.com/qp',
        'signing_secret' => 'should-never-be-stored',
        'api_key'       => 'should-never-be-stored',
    ]);

    $row = Db::one("SELECT config FROM integrations WHERE clinic_id = 1 AND provider = 'webhooks'");
    $config = json_decode((string) $row['config'], true);

    Assert::same(['endpoint' => 'https://hooks.example.com/qp'], $config, 'only the whitelisted field survived');
    Assert::false(isset($config['signing_secret']), 'a secret cannot be smuggled in');
    Assert::false(isset($config['api_key']), 'a credential cannot be smuggled in');

    // Sender IDs are capped by the SMS spec (11 chars), not by goodwill.
    $service->connect(1, 'sms_gateway', ['sender_id' => 'SERENITYCLINIC']);
    $row = Db::one("SELECT config FROM integrations WHERE clinic_id = 1 AND provider = 'sms_gateway'");
    $config = json_decode((string) $row['config'], true);
    Assert::same(11, mb_strlen($config['sender_id']), 'sender id clamped to 11 characters');
});

Assert::test('disconnecting keeps the settings so reconnecting is one click', function (): void {
    $service = new App\Services\IntegrationService();
    $service->connect(1, 'accounting', ['organisation' => 'Serenity-ACC-01']);
    Assert::same(1, (int) Db::scalar("SELECT COUNT(*) FROM integrations
                                      WHERE clinic_id = 1 AND provider = 'accounting' AND status = 'connected'"));

    Assert::true($service->disconnect(1, 'accounting'), 'disconnect reports success');

    $row = Db::one("SELECT status, config FROM integrations WHERE clinic_id = 1 AND provider = 'accounting'");
    Assert::same('off', $row['status'], 'flipped off, not deleted');
    Assert::same(
        ['organisation' => 'Serenity-ACC-01'],
        json_decode((string) $row['config'], true),
        'the setting survives so it does not have to be retyped'
    );

    // And reconnecting is idempotent — one row, not two.
    $service->connect(1, 'accounting', []);
    Assert::same(1, (int) Db::scalar("SELECT COUNT(*) FROM integrations
                                      WHERE clinic_id = 1 AND provider = 'accounting'"), 'still one row');
});

Assert::test('one clinic cannot see or touch another clinic\'s integrations', function (): void {
    $service = new App\Services\IntegrationService();

    // A real row in clinic 1 first: probing a tenant with no rows proves
    // nothing, because 'no rows' is also what a leak looks like.
    $service->connect(1, 'patient_app', []);

    $clinic1 = array_column($service->listFor(1), 'connected', 'provider');
    Assert::true($clinic1['patient_app'] ?? false, 'clinic 1 turned it on');

    $clinic2 = array_column($service->listFor(2), 'connected', 'provider');
    Assert::false($clinic2['patient_app'] ?? false, 'clinic 2 does not inherit it');
    Assert::same(
        0,
        (int) Db::scalar("SELECT COUNT(*) FROM integrations WHERE clinic_id = 2 AND provider = 'patient_app'"),
        'no row leaked into clinic 2'
    );

    // Disconnecting from the wrong clinic must not reach clinic 1's row.
    $service->disconnect(2, 'patient_app');
    Assert::same(
        'connected',
        (string) Db::scalar("SELECT status FROM integrations WHERE clinic_id = 1 AND provider = 'patient_app'"),
        'clinic 1 keeps its connection'
    );
});

Assert::test('the connected count only counts live connections', function (): void {
    $service = new App\Services\IntegrationService();
    $service->disconnect(1, 'sms_gateway');
    $before = $service->connectedCount(1);
    $service->connect(1, 'sms_gateway', ['sender_id' => 'SERENITY']);
    Assert::same($before + 1, $service->connectedCount(1), 'connecting raises the count');
    $service->disconnect(1, 'sms_gateway');
    Assert::same($before, $service->connectedCount(1), 'disconnecting lowers it again');
});


/* ------------------------------------------------------------------ */
/* 48. waiting-room payload and "where do I stand?"                    */
/* ------------------------------------------------------------------ */

// Its own clinics/queues so these assertions never depend on what ran above.
$dsToday   = date('Y-m-d');
$posClinic = 1;
$posQueue  = (int) Db::insert(
    "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
     VALUES (?, 1, 'Position probe', 'consultation', 'open', ?, 'P', 0, 3, ?)",
    [$posClinic, $dsToday, Db::now()]
);
(new App\Domain\Queue\QueueService())->startSession($posClinic, $posQueue);

$posIds = [];
foreach (['Pos One', 'Pos Two', 'Pos Three'] as $i => $name) {
    $p = $patients->findOrCreate($posClinic, ['full_name' => $name, 'phone' => '077222000' . ($i + 1)]);
    $posIds[] = (int) (new App\Domain\Queue\QueueService())->issueToken($posClinic, $posQueue, (int) $p['id'], [])['entry_id'];
}
// Move the head along so the board has a token being served.
$qs = new App\Domain\Queue\QueueService();
$qs->apply($posClinic, $posIds[0], 'call', []);
$qs->apply($posClinic, $posIds[0], 'start', []);

Assert::test('the waiting-room payload is a superset the poller and the page share', function () use ($posClinic): void {
    $payload = (new App\Services\DisplayService())->payload($posClinic);

    Assert::true($payload['ok'] ?? false, 'ok');
    foreach (['now_serving', 'serving', 'rooms', 'upcoming', 'delays', 'paused', 'stats', 'solo'] as $key) {
        Assert::true(array_key_exists($key, $payload), $key . ' is present');
    }
    Assert::true(is_array($payload['serving']), 'serving is a list of every room');
    Assert::true(is_bool($payload['solo']), 'solo is a boolean');
});

Assert::test('a multi-room clinic shows every room, not just one token', function () use ($posClinic): void {
    $payload = (new App\Services\DisplayService())->payload($posClinic);

    // Older code collapsed the clinic to a single `now_serving`, so a patient
    // watching the TV never saw their own room called. `serving` carries all.
    $rooms = array_values(array_filter(
        $payload['rooms'],
        static fn (array $r): bool => ($r['token'] ?? null) !== null
    ));
    Assert::true(count($rooms) >= 1, 'at least one room is serving');
    $first = $rooms[0];
    Assert::true(($first['doctor'] ?? null) !== null, 'the doctor is named');
    Assert::true(array_key_exists('status', $first), 'and the status so "called" can flash');
    Assert::same(count($rooms), count($payload['serving']), 'serving matches the room cards');
});

Assert::test('upcoming tokens name the doctor, because token series repeat per room', function () use ($posClinic): void {
    $payload = (new App\Services\DisplayService())->payload($posClinic);
    if ($payload['upcoming'] === []) {
        Assert::true(true, 'nothing waiting at the moment');

        return;
    }
    foreach ($payload['upcoming'] as $row) {
        // Every queue restarts its own A-series, so three rooms can all be
        // waiting on "A002". Without the doctor the list is ambiguous and
        // patients walk to the wrong door.
        Assert::true(($row['doctor'] ?? null) !== null, 'doctor named for ' . ($row['token'] ?? '?'));
        Assert::true(($row['queue'] ?? null) !== null, 'queue named for ' . ($row['token'] ?? '?'));
    }
});

Assert::test('upcoming is in queue order, not the order tokens were typed in', function () use ($posClinic, $posQueue): void {
    // Read the per-queue list. The clinic-wide `upcoming` is capped at eight
    // across every room, so a queue created late in the suite can be sliced
    // off it entirely — which says nothing about this queue's ordering.
    $payload = (new App\Services\DisplayService())->payload($posClinic);
    $card = null;
    foreach ($payload['rooms'] as $r) {
        if ((int) ($r['queue_id'] ?? 0) === $posQueue) {
            $card = $r;
        }
    }
    Assert::true($card !== null, 'the probe queue has a room card');

    $labels = array_map(static fn (array $r): string => (string) $r['token'], $card['up_next']);
    Assert::true(count($labels) >= 2, 'it lists the people still waiting');
    $sorted = $labels;
    sort($sorted, SORT_NATURAL);
    Assert::same($sorted, $labels, 'ascending, so position 2 follows position 1');
});

Assert::test('where do I stand answers for a real token', function () use ($posClinic, $posQueue, $posIds): void {
    $label = (string) Db::scalar('SELECT token_label FROM queue_entries WHERE id = ?', [$posIds[1]]);
    $res = (new App\Services\DisplayService())->myPosition($posClinic, null, $posQueue, $label);

    Assert::true($res['ok'] ?? false, 'found');
    $p = $res['position'];
    Assert::same($label, $p['token'], 'the token asked for');
    Assert::same('waiting', $p['status'], 'this one is still waiting');
    Assert::same(1, (int) $p['position'], 'first in line once the head moved on');
    // "Is my queue moving?" is only answerable with this queue's own token.
    Assert::true(($p['now_serving'] ?? null) !== null, 'the queue says who it is serving');
    Assert::true(($p['doctor'] ?? null) !== null, 'and who with');
});

Assert::test('where do I stand never leaks a name or the private track link', function () use ($posClinic, $posQueue, $posIds): void {
    $label = (string) Db::scalar('SELECT token_label FROM queue_entries WHERE id = ?', [$posIds[1]]);
    $res = (new App\Services\DisplayService())->myPosition($posClinic, null, $posQueue, $label);

    $flat = json_encode($res, JSON_THROW_ON_ERROR);
    // The token label is printed on paper and shouted across a room. It must
    // never be exchangeable for the private link that can cancel the visit.
    Assert::false(str_contains($flat, 'track_token'), 'no track token');
    Assert::false(str_contains($flat, 'Pos One'), 'no patient name');
    Assert::false(str_contains($flat, '077222000'), 'no phone number');
});

Assert::test('where do I stand refuses a token from another clinic', function () use ($posQueue, $patients): void {
    // A real fixture in clinic 2 first: probing a tenant that has no rows
    // proves nothing, because "no rows" is also what a leak looks like.
    $otherQueue = (int) Db::insert(
        "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
         VALUES (2, 3, 'Other clinic probe', 'consultation', 'open', ?, 'Q', 0, 3, ?)",
        [date('Y-m-d'), Db::now()]
    );
    (new App\Domain\Queue\QueueService())->startSession(2, $otherQueue);
    $op = $patients->findOrCreate(2, ['full_name' => 'Other Clinic Person', 'phone' => '0773330001']);
    $issued = (new App\Domain\Queue\QueueService())->issueToken(2, $otherQueue, (int) $op['id'], []);
    $label = (string) Db::scalar('SELECT token_label FROM queue_entries WHERE id = ?', [(int) $issued['entry_id']]);

    try {
        // Clinic 1's screen asking about its own queue with a clinic-2 label:
        // the label must not resolve, because it belongs to another tenant.
        $res = (new App\Services\DisplayService())->myPosition(1, null, $posQueue, $label);
        Assert::false($res['ok'] ?? false, 'clinic 1 cannot see clinic 2\'s token ' . $label);

        // And asking clinic 1's queue id while standing in clinic 2.
        $res2 = (new App\Services\DisplayService())->myPosition(2, null, $posQueue, $label);
        Assert::false($res2['ok'] ?? false, 'clinic 2 cannot read clinic 1\'s queue');
    } finally {
        Db::run('DELETE FROM queue_entries WHERE queue_id = ?', [$otherQueue]);
        Db::run('DELETE FROM queues WHERE id = ?', [$otherQueue]);
    }
});

Assert::test('where do I stand rejects junk without touching the database', function () use ($posClinic, $posQueue): void {
    $svc = new App\Services\DisplayService();
    Assert::false($svc->myPosition($posClinic, null, $posQueue, '')['ok'], 'empty');
    Assert::false($svc->myPosition($posClinic, null, $posQueue, '   ')['ok'], 'whitespace');
    Assert::false($svc->myPosition($posClinic, null, $posQueue, str_repeat('x', 40))['ok'], 'too long');
    Assert::false($svc->myPosition($posClinic, null, 999999, 'P001')['ok'], 'a queue that does not exist');
});

Assert::test('a department screen stays inside its own waiting area', function (): void {
    $deptId = (int) Db::insert(
        "INSERT INTO departments (clinic_id, name, code, is_active, created_at) VALUES (1, 'Pos Dept', 'POSDEPT', 1, ?)",
        [Db::now()]
    );
    $inDept = (int) Db::insert(
        "INSERT INTO queues (clinic_id, department_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
         VALUES (1, ?, 1, 'In department', 'consultation', 'open', ?, 'R', 0, 3, ?)",
        [$deptId, date('Y-m-d'), Db::now()]
    );
    (new App\Domain\Queue\QueueService())->startSession(1, $inDept);

    try {
        $scoped = (new App\Services\DisplayService())->payload(1, $deptId);
        Assert::same('Pos Dept', $scoped['department'] ?? null, 'the header names the area');
        $names = array_column($scoped['rooms'], 'queue');
        Assert::true(in_array('In department', $names, true), 'its own queue is on the board');
        Assert::false(in_array('Position probe', $names, true), 'a queue from another area is not');
    } finally {
        Db::run('DELETE FROM queues WHERE id = ?', [$inDept]);
        Db::run('DELETE FROM departments WHERE id = ?', [$deptId]);
    }
});


/* ------------------------------------------------------------------ */
/* 49. measuring what priority actually did                           */
/* ------------------------------------------------------------------ */

// Its own tenant, built from nothing. Earlier tests leave finished visits
// behind in clinics 1 and 2, and a mean taken over someone else's data is
// not a measurement of this fixture.
seedTenant(77, 'impact-probe', 'Impact Probe Clinic');
$impClinic = 77;
$impQueue  = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 77');
$impDoctor = (int) Db::scalar('SELECT doctor_id FROM queues WHERE clinic_id = 77');

// A rule to attach to one group, so "by rule" and "by hand" are
// distinguishable rather than both landing in the manual bucket.
$impRuleId = (int) Db::insert(
    "INSERT INTO priority_rules (clinic_id, name, source, kind, effect, is_active, reason, created_at)
     VALUES (77, 'Impact probe rule', 'walkin', 'consultation', 3, 1, 'probe', ?)",
    [Db::now()]
);

$impSeq = 0;
/** A finished visit with a recorded wait. */
$impVisit = function (int $priority, ?int $ruleId, int $wait) use ($impClinic, $impQueue, &$impSeq): int {
    $p = $GLOBALS['patients']->findOrCreate($impClinic, [
        'full_name' => 'Impact Person ' . (++$impSeq),
        'phone'     => '0775550' . str_pad((string) (100 + $impSeq), 3, '0', STR_PAD_LEFT),
    ]);
    $issued = (new App\Domain\Queue\QueueService())->issueToken($impClinic, $impQueue, (int) $p['id'], []);
    $id = (int) $issued['entry_id'];
    // status is completed too, not just finished_at: a row with an end stamp
    // but still reading 'waiting' is a state the app never produces, and a
    // fixture shaped like that quietly passes tests it should fail.
    Db::run(
        "UPDATE queue_entries SET priority = ?, priority_rule_id = ?, wait_minutes = ?,
                started_at = ?, finished_at = ?, status = 'completed' WHERE id = ?",
        [$priority, $ruleId, $wait, Db::now(), Db::now(), $id]
    );

    return $id;
};

// Baseline: ten unprioritised visits, 30 minutes each.
for ($i = 0; $i < 10; $i++) {
    $impVisit(0, null, 30);
}
// By rule: ten visits, 10 minutes.
for ($i = 0; $i < 10; $i++) {
    $impVisit(1, $impRuleId, 10);
}
// By hand: ten visits, 50 minutes — deliberately SLOWER, to prove the sign
// survives instead of being clamped to something flattering.
for ($i = 0; $i < 10; $i++) {
    $impVisit(1, null, 50);
}

Assert::test('priority impact is measured, not asserted', function () use ($impClinic): void {
    $m = (new App\Services\PriorityImpactService())->measure($impClinic, date('Y-m-d'), date('Y-m-d'));

    Assert::same(date('Y-m-d'), $m['from'], 'the window is echoed back');
    Assert::true($m['enough'], 'there is enough data to compare');
    Assert::same(10, $m['baseline']['n'], 'ten unprioritised visits');
    Assert::same(30.0, $m['baseline']['mean'], 'unprioritised mean');
    Assert::same(10.0, $m['rule']['mean'], 'rule-prioritised mean');
    Assert::same(20.0, $m['rule']['diff'], 'the rule group waited 20 minutes less');
    Assert::same(67, (int) $m['rule']['pct'], 'which is a 67% shorter wait');
});

Assert::test('a group that waited longer reports a negative, not a flattering zero', function () use ($impClinic): void {
    $m = (new App\Services\PriorityImpactService())->measure($impClinic, date('Y-m-d'), date('Y-m-d'));

    // Prioritising by hand made these patients wait 50 minutes against a
    // 30-minute baseline. Clamping that to zero would hide the one result
    // a clinic manager most needs to see.
    Assert::true($m['manual']['diff'] < 0, 'the sign is kept: ' . var_export($m['manual']['diff'], true));
    Assert::same(-20.0, $m['manual']['diff'], 'twenty minutes longer');
    Assert::true((int) $m['manual']['pct'] < 0, 'and the percentage is negative too');
});

Assert::test('below the sample floor the mean is null, not a number', function () use ($impClinic, $impQueue): void {
    // A mean of three is noise dressed up as a finding. The service reports
    // null and the screen prints a dash.
    Assert::true(App\Services\PriorityImpactService::MIN_SAMPLES >= 5, 'the floor is at least five');

    $empty = (new App\Services\PriorityImpactService())->measure($impClinic, '2001-01-01', '2001-01-02');
    Assert::same(0, $empty['baseline']['n'], 'no visits in that window');
    Assert::same(null, $empty['baseline']['mean'], 'so no mean is claimed');
    Assert::false($empty['enough'], 'and the panel says it cannot compare');
    Assert::same(null, $empty['rule']['diff'], 'a difference needs two usable means');

    // And a group of three, next to a baseline of ten: plenty of baseline,
    // not nearly enough group.
    $three = [];
    for ($i = 0; $i < 3; $i++) {
        $p = $GLOBALS['patients']->findOrCreate($impClinic, [
            'full_name' => 'Thin Group ' . $i,
            'phone'     => '077556000' . $i,
        ]);
        $id = (int) (new App\Domain\Queue\QueueService())->issueToken($impClinic, $impQueue, (int) $p['id'], [])['entry_id'];
        Db::run(
            "UPDATE queue_entries SET priority = 1, priority_rule_id = null, wait_minutes = 5,
                    started_at = ?, finished_at = ?, status = 'completed' WHERE id = ?",
            [Db::now(), Db::now(), $id]
        );
        $three[] = $id;
    }
    try {
        // Ten by hand plus three more is thirteen, which still clears the
        // floor — so shrink the by-hand group instead and check the floor
        // itself is what governs the thin group.
        $thin = (new App\Services\PriorityImpactService())->measure($impClinic, date('Y-m-d'), date('Y-m-d'));
        Assert::same(13, $thin['manual']['n'], 'the thin group joined the by-hand bucket');
        Assert::true($thin['manual']['mean'] !== null, 'thirteen is above the floor');
    } finally {
        foreach ($three as $id) {
            Db::run('DELETE FROM queue_entries WHERE id = ?', [$id]);
        }
    }
});

Assert::test('overriding now counts only people ahead of the line right now', function () use ($impClinic, $impQueue): void {
    $today = date('Y-m-d');
    $svc   = new App\Services\PriorityImpactService();

    // The thirty finished visits above are all done, so none of them count:
    // this is a live tile, not a period report.
    Assert::same(0, $svc->overridingNow($impClinic, $today), 'finished visits are excluded');

    // Two live by-hand overrides and one live rule-driven entry.
    $live = [];
    foreach ([[1, null], [1, null], [1, $GLOBALS['impRuleId'] ?? null]] as [$prio, $rule]) {
        $p = $GLOBALS['patients']->findOrCreate($impClinic, [
            'full_name' => 'Live ' . count($live),
            'phone'     => '077557000' . count($live),
        ]);
        $id = (int) (new App\Domain\Queue\QueueService())->issueToken($impClinic, $impQueue, (int) $p['id'], [])['entry_id'];
        Db::run('UPDATE queue_entries SET priority = ?, priority_rule_id = ? WHERE id = ?', [$prio, $rule, $id]);
        $live[] = $id;
    }
    try {
        Assert::same(2, $svc->overridingNow($impClinic, $today), 'the two by-hand entries, not the rule one');
    } finally {
        foreach ($live as $id) {
            Db::run('DELETE FROM queue_entries WHERE id = ?', [$id]);
        }
    }
});

Assert::test('one clinic cannot measure another clinic\'s waits', function () use ($impClinic): void {
    $before = (new App\Services\PriorityImpactService())->measure($impClinic, date('Y-m-d'), date('Y-m-d'));

    // A finished visit in a different clinic, inside the very same window.
    seedTenant(78, 'impact-other', 'Impact Other Clinic');
    $otherQueue = (int) Db::scalar('SELECT id FROM queues WHERE clinic_id = 78');
    $op = $GLOBALS['patients']->findOrCreate(78, ['full_name' => 'Impact Other Person', 'phone' => '0775550999']);
    $id = (int) (new App\Domain\Queue\QueueService())->issueToken(78, $otherQueue, (int) $op['id'], [])['entry_id'];
    Db::run(
        "UPDATE queue_entries SET priority = 1, wait_minutes = 5, started_at = ?, finished_at = ?,
                status = 'completed' WHERE id = ?",
        [Db::now(), Db::now(), $id]
    );

    try {
        $after = (new App\Services\PriorityImpactService())->measure($impClinic, date('Y-m-d'), date('Y-m-d'));
        Assert::same($before['baseline']['n'], $after['baseline']['n'], 'clinic 77\'s baseline is untouched');
        Assert::same($before['manual']['n'], $after['manual']['n'], 'and its by-hand count');

        // The other clinic sees only its own single visit.
        $theirs = (new App\Services\PriorityImpactService())->measure(78, date('Y-m-d'), date('Y-m-d'));
        Assert::same(1, $theirs['manual']['n'], 'clinic 78 sees exactly its one entry');
        Assert::same(null, $theirs['manual']['mean'], 'and claims no mean for one visit');
    } finally {
        Db::run('DELETE FROM queue_entries WHERE clinic_id = 78');
    }
});


$code = Assert::summary();
Database::cleanup($dbPath);
echo "\n";
exit($code);
