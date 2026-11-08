<?php
declare(strict_types=1);

/**
 * Seeds subscription plans plus a realistic multi-tenant demo:
 *   1. Serenity OPD & Family Clinic (Colombo)  — the flagship demo
 *   2. Lakeview Medical Centre (Negombo)
 *   3. A suspended tenant, to exercise the billing gate
 * All demo credentials are printed at the end.
 */
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use App\Core\Db;
use App\Core\Str;

$today = Db::today();

// `--plans-only` makes this safe on a production database: subscription plans
// are required for the app to function, demo tenants are not. Deploys run
// `migrate.php && seed.php --plans-only`; nobody runs the demo on real data.
$plansOnly = in_array('--plans-only', $_SERVER['argv'] ?? [], true);

/* ---------------------------------------------------------------- plans */
$plans = [
    ['starter', 'Starter', 3500, 1, 2, 2, 10, 'Single doctor, one queue, live tracking links', 1],
    ['professional', 'Professional', 7900, 5, 8, 6, 20, 'Multi-doctor, appointments, SMS credits, reports', 2],
    ['clinic_plus', 'Clinic Plus', 14900, 15, 25, 20, 30, 'Priority support, WhatsApp, custom branding, display screens', 3],
    ['enterprise', 'Enterprise', 0, 999, 999, 999, 40, 'Multi-branch, SSO, dedicated onboarding, API access', 4],
];
foreach ($plans as [$code, $name, $price, $docs, $staff, $qs, $sort, $features]) {
    if (!Db::scalar('SELECT id FROM plans WHERE code = ?', [$code])) {
        Db::insert(
            'INSERT INTO plans (code, name, price_monthly, currency, max_doctors, max_staff, max_queues, features, sort_order, is_active, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,1,?)',
            [$code, $name, $price, 'LKR', $docs, $staff, $qs, $features, $sort, Db::now()]
        );
    }
}
$planIds = [];
foreach (Db::all('SELECT id, code FROM plans') as $p) {
    $planIds[$p['code']] = (int) $p['id'];
}

if ($plansOnly) {
    echo str_repeat('─', 58), "\n";
    echo " Subscription plans ready (" . count($planIds) . " plans)\n";
    echo " Demo tenants skipped (--plans-only).\n";
    echo str_repeat('─', 58), "\n";
    foreach (Db::all('SELECT code, name, price_monthly, max_doctors, max_staff, max_queues FROM plans ORDER BY sort_order') as $p) {
        printf("  %-14s %-14s LKR %6.0f   %d doctors / %d staff / %d queues\n",
            $p['code'], $p['name'], $p['price_monthly'], $p['max_doctors'], $p['max_staff'], $p['max_queues']);
    }
    exit(0);
}

/* ------------------------------------------------------------- tenants */
function makeClinic(array $c, int $planId): int
{
    return Db::insert(
        'INSERT INTO clinics (slug, name, phone, email, address_line, city, district, timezone, locale,
            brand_color, currency, token_prefix, token_reset_policy, late_grace_minutes,
            plan_id, billing_status, trial_ends_on, renews_on, is_active, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)',
        [
            $c['slug'], $c['name'], $c['phone'], $c['email'], $c['address'], $c['city'], $c['district'],
            'Asia/Colombo', 'en', $c['color'], 'LKR', $c['prefix'], 'daily', 15,
            $planId, $c['billing'], date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('+27 days')),
            Db::now(), Db::now(),
        ]
    );
}

function addUser(int $clinicId, string $role, string $name, string $email, string $password, ?int $doctorId = null): int
{
    return Db::insert(
        'INSERT INTO users (clinic_id, role, name, email, phone, password_hash, doctor_id, locale, is_active, created_at)
         VALUES (?,?,?,?,?,?,?,?,1,?)',
        [$clinicId, $role, $name, mb_strtolower($email), '+94770000000', password_hash($password, PASSWORD_DEFAULT), $doctorId, 'en', Db::now()]
    );
}

Db::transaction(function () use ($planIds, $today) {
    if (Db::scalar("SELECT id FROM users WHERE email = 'admin@serenity.lk'")) {
        echo "Demo data already present — skipping tenants.\n";
        return;
    }

    /* ---------- tenant 1: Serenity OPD (full demo) ---------- */
    $c1 = makeClinic([
        'slug' => 'serenity', 'name' => 'Serenity OPD & Family Clinic',
        'phone' => '+94 11 269 4400', 'email' => 'hello@serenity.lk',
        'address' => '118 Galle Road, Colombo 03', 'city' => 'Colombo', 'district' => 'Colombo',
        'color' => '#0F766E', 'prefix' => 'S', 'billing' => 'active',
    ], $planIds['professional']);

    $admin1 = addUser($c1, 'clinic_admin', 'Dr. Nuwan Perera', 'admin@serenity.lk', 'Serenity#2026');
    $recep1 = addUser($c1, 'receptionist', 'Ishara Fernando', 'reception@serenity.lk', 'Reception#2026');

    $specs = [];
    foreach (['General Medicine', 'Paediatrics', 'Cardiology', 'Dermatology', 'ENT'] as $s) {
        $specs[$s] = Db::insert('INSERT INTO specialties (clinic_id, name) VALUES (?,?)', [$c1, $s]);
    }
    $rooms = [];
    foreach (['Room 1', 'Room 2', 'Room 3', 'Room 4', 'Procedure Room'] as $i => $r) {
        $rooms[$r] = Db::insert('INSERT INTO rooms (clinic_id, name, floor) VALUES (?,?,?)', [$c1, $r, $i < 3 ? 'Ground' : 'First']);
    }

    $doctorDefs = [
        ['Dr. Nuwan Perera', 'General Medicine', 'Room 1', 2000, 9],
        ['Dr. Amara Silva', 'Paediatrics', 'Room 2', 2500, 12],
        ['Dr. Ruwan Jayasinghe', 'Cardiology', 'Room 3', 4000, 15],
        ['Dr. Fathima Rizwan', 'Dermatology', 'Room 4', 3000, 11],
        ['Dr. Kasun Bandara', 'ENT', 'Procedure Room', 2800, 10],
    ];
    $doctors = [];
    foreach ($doctorDefs as [$name, $spec, $room, $fee, $avg]) {
        $doctors[] = Db::insert(
            'INSERT INTO doctors (clinic_id, specialty_id, room_id, name, consult_fee, avg_consult_minutes, is_active, created_at)
             VALUES (?,?,?,?,?,?,1,?)',
            [$c1, $specs[$spec], $rooms[$room], $name, $fee, $avg, Db::now()]
        );
    }
    addUser($c1, 'doctor', 'Dr. Amara Silva', 'amara@serenity.lk', 'Doctor#2026', $doctors[1]);
    addUser($c1, 'doctor', 'Dr. Ruwan Jayasinghe', 'ruwan@serenity.lk', 'Doctor#2026', $doctors[2]);

    $queues = [];
    $series = new App\Domain\Queue\QueueService();
    foreach ($doctors as $i => $docId) {
        // Use the real allocator rather than hardcoding a letter, so the demo
        // shows the same A/B/C/D/E series a clinic actually gets: one per room.
        $prefix = $series->suggestPrefix($c1, $rooms[$doctorDefs[$i][2]]);
        $queues[$i] = Db::insert(
            "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
             VALUES (?,?,?,?, 'open', ?, ?, 0, 3, ?)",
            [$c1, $docId, 'OPD — ' . explode(' ', $doctorDefs[$i][0])[1] . ' ' . explode(' ', $doctorDefs[$i][0])[2], 'consultation', $today, $prefix, Db::now()]
        );
    }

    /* ---------- patients + live queue on doctor 1 ---------- */
    $patientDefs = [
        ['Nimali Wickramasinghe', '+94 77 123 4567', 'female', 1988],
        ['Sunil Rathnayake', '+94 71 884 2210', 'male', 1962],
        ['Ayesha Rahman', '+94 76 550 9182', 'female', 1995],
        ['Tharindu Silva', '+94 70 233 7711', 'male', 2001],
        ['Dilani Fernando', '+94 77 908 6612', 'female', 1979],
        ['Mohamed Aslam', '+94 72 445 9930', 'male', 1985],
        ['Kavya Nair', '+94 75 332 1180', 'female', 2010],
        ['Chaminda Perera', '+94 77 660 4472', 'male', 1971],
        ['Sanduni Jayawardena', '+94 71 118 2299', 'female', 1993],
        ['Ravi Kumar', '+94 76 887 3301', 'male', 1958],
        ['Hasini Weerasinghe', '+94 70 991 2244', 'female', 1999],
        ['Anura Dissanayake', '+94 77 445 6677', 'male', 1966],
    ];
    $patients = [];
    foreach ($patientDefs as $i => [$name, $phone, $gender, $year]) {
        $patients[] = Db::insert(
            'INSERT INTO patients (clinic_id, code, full_name, phone, gender, birth_year, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$c1, 'P-' . Str::pad($i + 1, 5), $name, $phone, $gender, $year, Db::now(), Db::now()]
        );
    }

    // Standing priority rules. Every one of these is expressible by the
    // engine: age, a recorded temperature, source and doctor. Nothing here is
    // a label the evaluator cannot act on — "post-op" is not a fact the desk
    // records, so the booked-review rule is named for what it actually matches.
    //
    // Order matters now: the first matching rule in this list decides. A child
    // with a fever is decided by "Under-5 first" because it sits above the
    // fever rule, which is the ordering a manager would expect to see and drag.
    $ruleService = new \App\Services\PriorityRuleService();
    foreach ([
        ['Under-5 first', 6, ['max_age' => 4],
            'Child under five — see before adults'],
        ['High fever', 6, ['min_temp' => '39.0'],
            'Febrile at 39.0 °C or above — assess promptly'],
        ['Booked reviews ahead', 2, ['source' => 'appointment', 'doctor_id' => $doctors[0]],
            'Booked review — keeps the appointment list moving'],
        ['Elderly support', 2, ['min_age' => 65],
            'Age 65 or over — limit time spent waiting'],
    ] as [$ruleName, $effect, $conditions, $reason]) {
        $ruleService->create($c1, $conditions + [
            'name' => $ruleName, 'effect' => $effect, 'reason' => $reason,
        ], $admin1);
    }

    // historical completed visits (last 14 days) → makes wait estimates real
    $histStatuses = ['completed', 'completed', 'completed', 'no_show', 'completed', 'skipped'];
    foreach ($patients as $pi => $pid) {
        for ($d = 1; $d <= 14; $d += 3) {
            $day = date('Y-m-d', strtotime("-{$d} days"));
            $q = $queues[$pi % count($queues)];
            $joined = $day . ' ' . sprintf('%02d:%02d:00', 8 + ($pi % 6), ($pi * 7) % 60);
            $started = date('Y-m-d H:i:s', strtotime($joined) + (12 + $pi % 9) * 60);
            $done = date('Y-m-d H:i:s', strtotime($started) + (7 + ($pi * 3) % 12) * 60);
            $status = $histStatuses[$pi % count($histStatuses)];

            // Attributed by the real evaluator, not labelled by hand: the
            // same first-match-wins pass the desk runs decides whether this
            // visit was prioritised and which rule did it. The wait formula
            // below is left exactly as it was — the demo does not shorten
            // prioritised waits to manufacture an improvement, so the page
            // reports whatever this cohort actually measures.
            $histSource = $pi % 3 === 0 ? 'appointment' : 'walkin';
            $histQueue = Db::one('SELECT kind, doctor_id FROM queues WHERE id = ?', [$q]);
            $histBirth = Db::scalar('SELECT birth_year FROM patients WHERE id = ?', [$pid]);
            $histMatch = $ruleService->evaluate($c1, [
                'queue_id'  => $q,
                'kind'      => $histQueue['kind'] ?? null,
                'doctor_id' => $histQueue['doctor_id'] ?? null,
                'source'    => $histSource,
                'age'       => $ruleService->ageOf($histBirth !== null ? (int) $histBirth : null),
                'hour'      => (int) substr($joined, 11, 2),
            ]);
            Db::insert(
                'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status, priority,
                    priority_rule_id, priority_reason, sort_weight, source, joined_at, called_at, started_at,
                    finished_at, wait_minutes, consult_minutes, track_token, version)
                 VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?,0)',
                [
                    $c1, $q, $pid, 100 + $pi + $d, 'H' . (100 + $pi + $d), $status,
                    $histMatch['priority'], $histMatch['rule_id'],
                    $histMatch['rule_id'] !== null ? $histMatch['reason'] : null,
                    $histSource,
                    $joined, date('Y-m-d H:i:s', strtotime($joined) + 4 * 60),
                    $status === 'completed' ? $started : null,
                    $status === 'completed' ? $done : (in_array($status, ['no_show', 'skipped']) ? $done : null),
                    $status === 'completed' ? 12 + $pi % 9 : null,
                    $status === 'completed' ? 7 + ($pi * 3) % 12 : null,
                    Str::urlToken(24),
                ]
            );
        }
    }

    // today's live board
    // Each room reads in ascending order: the token being seen is the lowest
    // live one, and the waiting list runs upward from it. The previous fixture
    // had A005 called while A002-A004 still waited, which looked like the board
    // had the order wrong even though the query was fine.
    $live = [
        // patient idx, doctor idx, status, priority, joined minutes ago
        [0, 0, 'in_consultation', 0, 32],  // A001 with the doctor
        [1, 0, 'waiting', 1, 25],          // A002 clinician-approved priority
        [2, 0, 'waiting', 0, 21],          // A003
        [3, 0, 'waiting', 0, 16],          // A004
        [4, 0, 'waiting', 0, 12],          // A005
        [5, 1, 'in_consultation', 0, 14],  // B001
        [6, 1, 'waiting', 0, 9],           // B002
        [7, 2, 'called', 1, 40],           // C001 just called
        [8, 2, 'waiting', 0, 6],           // C002
        [9, 3, 'waiting', 0, 4],           // D001
        [10, 4, 'waiting', 0, 2],          // E001
    ];
    $counters = [];
    foreach ($live as [$pi, $qi, $status, $priority, $minsAgo]) {
        $qid = $queues[$qi];
        $counters[$qid] = ($counters[$qid] ?? 0) + 1;
        $n = $counters[$qid];
        $prefix = (string) Db::scalar('SELECT token_prefix FROM queues WHERE id = ?', [$qid]);
        $joined = date('Y-m-d H:i:s', time() - $minsAgo * 60);
        $called = in_array($status, ['called', 'in_consultation'], true)
            ? date('Y-m-d H:i:s', strtotime($joined) + 6 * 60) : null;
        $started = $status === 'in_consultation' ? date('Y-m-d H:i:s', strtotime($joined) + 9 * 60) : null;
        $liveEntryId = Db::insert(
            'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status, priority,
                priority_reason, sort_weight, source, joined_at, called_at, started_at, call_count,
                track_token, version, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)',
            [
                $c1, $qid, $patients[$pi], $n, $prefix . Str::pad($n, 3), $status, $priority,
                $priority ? 'Chest pain — clinician approved priority' : null,
                0, $pi % 2 === 0 ? 'walkin' : 'appointment', $joined, $called, $started,
                $status === 'called' ? 1 : 0, Str::urlToken(24), $recep1,
            ]
        );
        Db::run('UPDATE queues SET last_token_no = ? WHERE id = ?', [$n, $qid]);

        // The override report is audit-driven, so the demo has to write the
        // same rows the desk writes — otherwise the tile counts two live
        // overrides while the report underneath it claims none happened.
        Db::insert(
            'INSERT INTO audit_logs (clinic_id, actor_id, action, entity, entity_id, ip, meta_json, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$c1, $recep1, 'queue.token_issued', 'queue_entry', $liveEntryId, '127.0.0.1',
                json_encode(['token' => $prefix . Str::pad($n, 3), 'queue_id' => $qid]), $joined]
        );
        if ($priority !== 0) {
            Db::insert(
                'INSERT INTO audit_logs (clinic_id, actor_id, action, entity, entity_id, ip, meta_json, created_at)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$c1, $recep1, 'queue.priority', 'queue_entry', $liveEntryId, '127.0.0.1',
                    json_encode(['reason' => 'Chest pain — clinician approved priority', 'to' => $priority]), $joined]
            );
        }
    }

    // audit + display screen token
    Db::insert(
        'INSERT INTO audit_logs (clinic_id, actor_id, action, entity, entity_id, ip, meta_json, created_at)
         VALUES (?,?,?,?,?,?,?,?)',
        [$c1, $admin1, 'clinic.seeded', 'clinic', $c1, '127.0.0.1', json_encode(['source' => 'demo']), Db::now()]
    );
    Db::insert(
        "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, 'display_token', ?)",
        [$c1, Str::urlToken(20)]
    );

    /* ---------- tenant 2: Lakeview (small, starter) ---------- */
    $c2 = makeClinic([
        'slug' => 'lakeview', 'name' => 'Lakeview Medical Centre',
        'phone' => '+94 31 223 7788', 'email' => 'front@lakeview.lk',
        'address' => '42 Lewis Place, Negombo', 'city' => 'Negombo', 'district' => 'Gampaha',
        'color' => '#1D4ED8', 'prefix' => 'L', 'billing' => 'active',
    ], $planIds['starter']);
    addUser($c2, 'clinic_admin', 'Dr. Malini Dias', 'admin@lakeview.lk', 'Lakeview#2026');
    $spec2 = Db::insert('INSERT INTO specialties (clinic_id, name) VALUES (?,?)', [$c2, 'General Medicine']);
    $room2 = Db::insert('INSERT INTO rooms (clinic_id, name, floor) VALUES (?,?,?)', [$c2, 'Room 1', 'Ground']);
    $doc2 = Db::insert(
        'INSERT INTO doctors (clinic_id, specialty_id, room_id, name, consult_fee, avg_consult_minutes, is_active, created_at)
         VALUES (?,?,?,?,?,?,1,?)',
        [$c2, $spec2, $room2, 'Dr. Malini Dias', 1500, 10, Db::now()]
    );
    $q2 = Db::insert(
        "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
         VALUES (?,?,?,?, 'open', ?, 'L', 0, 3, ?)",
        [$c2, $doc2, 'Evening OPD', 'consultation', $today, Db::now()]
    );
    $recep2 = addUser($c2, 'receptionist', 'Nadeesha Kumari', 'reception@lakeview.lk', 'Reception#2026');

    // A solo practice is the common case, not an edge case: most clinics are one
    // doctor. Give Lakeview a real session so the single-doctor board, the
    // one-doctor console and "where do I stand?" are exercised by the demo
    // rather than only ever rendering their empty states.
    $soloDefs = [
        ['Sunil Bandara', '+94 71 204 3388', 'male', 1968],
        ['Nimali Ekanayake', '+94 77 512 6644', 'female', 1984],
        ['Jeevan Rasiah', '+94 76 330 9021', 'male', 1957],
        ['Fathima Nazeera', '+94 70 887 1245', 'female', 1992],
        ['Kamal Gunathilake', '+94 75 661 7730', 'male', 1975],
    ];
    $soloPatients = [];
    foreach ($soloDefs as $i => [$name, $phone, $gender, $year]) {
        $soloPatients[] = Db::insert(
            'INSERT INTO patients (clinic_id, code, full_name, phone, gender, birth_year, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$c2, 'P-' . Str::pad($i + 1, 5), $name, $phone, $gender, $year, Db::now(), Db::now()]
        );
    }
    // One being seen, four waiting: enough to show a real position and estimate.
    $soloLive = [['called', 14], ['waiting', 11], ['waiting', 8], ['waiting', 5], ['waiting', 2]];
    foreach ($soloLive as $i => [$status, $minsAgo]) {
        $n = $i + 1;
        $joined = date('Y-m-d H:i:s', time() - $minsAgo * 60);
        Db::insert(
            'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status, priority,
                sort_weight, source, joined_at, called_at, call_count, track_token, version, created_by)
             VALUES (?,?,?,?,?,?,0,0,?,?,?,?,?,0,?)',
            [
                $c2, $q2, $soloPatients[$i], $n, 'L' . Str::pad($n, 3), $status, 'walkin', $joined,
                $status === 'called' ? date('Y-m-d H:i:s', strtotime($joined) + 5 * 60) : null,
                $status === 'called' ? 1 : 0, Str::urlToken(24), $recep2,
            ]
        );
    }
    Db::run('UPDATE queues SET last_token_no = ? WHERE id = ?', [count($soloLive), $q2]);
    Db::insert(
        "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, 'display_token', ?)",
        [$c2, Str::urlToken(20)]
    );

    /* ---------- tenant 3: suspended (billing gate demo) ---------- */
    $c3 = makeClinic([
        'slug' => 'coastal', 'name' => 'Coastal Health Collective',
        'phone' => '+94 91 222 3344', 'email' => 'admin@coastal.lk',
        'address' => '7 Beach Road, Galle', 'city' => 'Galle', 'district' => 'Galle',
        'color' => '#B45309', 'prefix' => 'C', 'billing' => 'suspended',
    ], $planIds['starter']);
    addUser($c3, 'clinic_admin', 'Dr. Suresh Mendis', 'admin@coastal.lk', 'Coastal#2026');

    /* ---------- platform super admin ---------- */
    Db::insert(
        'INSERT INTO users (clinic_id, role, name, email, password_hash, locale, is_active, created_at)
         VALUES (NULL, ?, ?, ?, ?, ?, 1, ?)',
        ['super_admin', 'Platform Admin', 'owner@queuepilot.app', password_hash('QueuePilot#2026', PASSWORD_DEFAULT), 'en', Db::now()]
    );

    /* ---------- invoices ---------- */
    foreach ([[$c1, 'INV-2026-0001', 7900, 'paid'], [$c2, 'INV-2026-0002', 3500, 'unpaid'], [$c3, 'INV-2026-0003', 3500, 'unpaid']] as [$cid, $no, $amt, $st]) {
        Db::insert(
            'INSERT INTO invoices (clinic_id, number, period_from, period_to, amount, currency, status, paid_at, method, reference, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $cid, $no, date('Y-m-01', strtotime('-1 month')), date('Y-m-t', strtotime('-1 month')),
                $amt, 'LKR', $st, $st === 'paid' ? Db::now() : null, $st === 'paid' ? 'bank_transfer' : null,
                $st === 'paid' ? 'BOC-88213' : null, Db::now(),
            ]
        );
    }
});

echo "\n──────────────────────────────────────────────────────────\n";
echo " QueuePilot demo data ready\n";
echo "──────────────────────────────────────────────────────────\n";
printf(" %-22s %-26s %s\n", 'ROLE', 'EMAIL', 'PASSWORD');
printf(" %-22s %-26s %s\n", 'Platform owner', 'owner@queuepilot.app', 'QueuePilot#2026');
printf(" %-22s %-26s %s\n", 'Clinic admin', 'admin@serenity.lk', 'Serenity#2026');
printf(" %-22s %-26s %s\n", 'Receptionist', 'reception@serenity.lk', 'Reception#2026');
printf(" %-22s %-26s %s\n", 'Doctor', 'amara@serenity.lk', 'Doctor#2026');
printf(" %-22s %-26s %s\n", 'Second clinic', 'admin@lakeview.lk', 'Lakeview#2026');
printf(" %-22s %-26s %s\n", 'Suspended clinic', 'admin@coastal.lk', 'Coastal#2026');
echo "──────────────────────────────────────────────────────────\n\n";
