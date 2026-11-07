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
        ['Dr. Kasun Bandara', 'ENT', 'Room 2', 2800, 10],
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
    foreach ($doctors as $i => $docId) {
        $queues[$i] = Db::insert(
            "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
             VALUES (?,?,?,?, 'open', ?, ?, 0, 3, ?)",
            [$c1, $docId, 'OPD — ' . explode(' ', $doctorDefs[$i][0])[1] . ' ' . explode(' ', $doctorDefs[$i][0])[2], 'consultation', $today, 'A', Db::now()]
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
            Db::insert(
                'INSERT INTO queue_entries (clinic_id, queue_id, patient_id, token_no, token_label, status, priority,
                    sort_weight, source, joined_at, called_at, started_at, finished_at, wait_minutes,
                    consult_minutes, track_token, version)
                 VALUES (?,?,?,?,?,?,0,0,?,?,?,?,?,?,?,?,0)',
                [
                    $c1, $q, $pid, 100 + $pi + $d, 'H' . (100 + $pi + $d), $status,
                    $pi % 3 === 0 ? 'appointment' : 'walkin',
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
    $live = [
        // patient idx, doctor idx, status, priority, joined minutes ago
        [0, 0, 'in_consultation', 0, 32],
        [1, 0, 'waiting', 1, 25],
        [2, 0, 'waiting', 0, 21],
        [3, 0, 'waiting', 0, 16],
        [4, 0, 'called', 0, 12],
        [5, 1, 'waiting', 0, 14],
        [6, 1, 'waiting', 0, 9],
        [7, 2, 'in_consultation', 1, 40],
        [8, 2, 'waiting', 0, 6],
        [9, 3, 'waiting', 0, 4],
        [10, 4, 'waiting', 0, 2],
    ];
    $counters = [];
    foreach ($live as [$pi, $qi, $status, $priority, $minsAgo]) {
        $qid = $queues[$qi];
        $counters[$qid] = ($counters[$qid] ?? 0) + 1;
        $n = $counters[$qid];
        $prefix = 'A';
        $joined = date('Y-m-d H:i:s', time() - $minsAgo * 60);
        $called = in_array($status, ['called', 'in_consultation'], true)
            ? date('Y-m-d H:i:s', strtotime($joined) + 6 * 60) : null;
        $started = $status === 'in_consultation' ? date('Y-m-d H:i:s', strtotime($joined) + 9 * 60) : null;
        Db::insert(
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

    /* ---------- Serenity: standing priority rules ----------
       Only conditions the engine can actually evaluate. A "high fever" rule
       would be a lie: temperature is not recorded anywhere, so it would sit
       in the list looking authoritative and never match a single patient. */
    $ruleDefs = [
        ['Under-5 first', 3, null, null, null, 0, 4, null, null, 'Children under 5 get higher priority'],
        ['Elderly support', 2, null, null, null, 65, null, null, null, 'Age 65 and above'],
        ['Morning lab fast-track', 2, null, 'lab', null, null, null, 7, 11, 'Fasting samples before 11:00'],
        ['Post-op review', 1, 'appointment', 'consultation', null, null, null, null, null, 'Patients with post-op visits'],
        ['Routine review behind', -1, 'walkin', 'general', null, null, null, null, null, 'Routine walk-ins can safely wait'],
    ];
    $ruleOrder = 0;
    foreach ($ruleDefs as [$rName, $rEffect, $rSource, $rKind, $rDoc, $rMin, $rMax, $rFrom, $rTo, $rReason]) {
        Db::insert(
            'INSERT INTO priority_rules
               (clinic_id, queue_id, name, source, kind, doctor_id, min_age, max_age,
                from_hour, to_hour, effect, reason, is_active, created_at, sort_order)
             VALUES (?,NULL,?,?,?,?,?,?,?,?,?,?,1,?,?)',
            [$c1, $rName, $rSource, $rKind, $rDoc, $rMin, $rMax, $rFrom, $rTo, $rEffect, $rReason,
             date('Y-m-d H:i:s', strtotime('-6 days') + $ruleOrder * 3600), ++$ruleOrder]
        );
    }

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
    Db::insert(
        "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
         VALUES (?,?,?,?, 'open', ?, 'L', 0, 3, ?)",
        [$c2, $doc2, 'Evening OPD', 'consultation', $today, Db::now()]
    );
    addUser($c2, 'receptionist', 'Nadeesha Kumari', 'reception@lakeview.lk', 'Reception#2026');

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
