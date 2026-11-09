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
        'INSERT INTO users (clinic_id, role, name, email, phone, password_hash, doctor_id, locale, is_active, email_verified_at, created_at)
         VALUES (?,?,?,?,?,?,?,?,1,?,?)',
        // Demo accounts are seeded pre-verified. clinicMayIssueTokens() blocks
        // token issuance while any active clinic_admin is unverified, so a
        // seeded clinic whose owner has never clicked a verification mail can
        // never issue a token — the boards would open empty for no visible
        // reason. Real signups still have to verify.
        [$clinicId, $role, $name, mb_strtolower($email), '+94770000000', password_hash($password, PASSWORD_DEFAULT), $doctorId, 'en', Db::now(), Db::now()]
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
    $doctorService = new App\Services\DoctorService();
    foreach ($doctors as $i => $docId) {
        $queues[$i] = Db::insert(
            "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
             VALUES (?,?,?,?, 'open', ?, ?, 0, 3, ?)",
            // One prefix per doctor (A, B, C …) so the display board and the
            // printed slip can tell the consulting rooms apart.
            [$c1, $docId, 'OPD — ' . explode(' ', $doctorDefs[$i][0])[1] . ' ' . explode(' ', $doctorDefs[$i][0])[2], 'consultation', $today, $doctorService->prefixFor($c1, $docId), Db::now()]
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

/* ------------------------------------------------------- reference data */
/*
 * Idempotent, and deliberately outside the transaction above.
 *
 * The main block seeds one working clinic and stops if it already exists;
 * this one fills in the reference data that makes the rest of the product
 * demonstrable — doctor schedules (without which appointment availability
 * falls back to clinic hours and the "real availability" rule is never
 * exercised), departments, holidays, notification templates, priority rules,
 * appointments, feedback, an organisation and a support ticket.
 *
 * Every insert is guarded so `npm run seed` can be re-run against a live
 * database without duplicating anything.
 */
(function (): void {
    $c1 = (int) (Db::scalar("SELECT id FROM clinics WHERE slug = 'serenity'") ?: 0);
    if ($c1 === 0) {
        return;
    }

    $doctors = array_column(
        Db::all('SELECT id, name FROM doctors WHERE clinic_id = ? AND is_active = 1 ORDER BY id', [$c1]),
        'name', 'id'
    );
    $doctorIds = array_map('intval', array_keys($doctors));
    if ($doctorIds === []) {
        return;
    }

    /* ---- doctor schedules: the source of truth for availability -------- */
    // A schedule is what makes "real availability" real. Two windows a day
    // (a morning and an evening clinic) with a gap in the middle, which is
    // how most Sri Lankan OPDs actually run.
    $hasSched = (int) Db::scalar('SELECT COUNT(*) FROM doctor_schedules WHERE clinic_id = ?', [$c1]);
    if ($hasSched === 0) {
        foreach ($doctorIds as $i => $docId) {
            // Stagger the roster so the "who is working today" question has a
            // real answer rather than everybody working every day.
            $weekend = $i % 2 === 0 ? [6] : [];
            foreach (array_merge([1, 2, 3, 4, 5], $weekend) as $wd) {
                foreach ([['08:30', '12:30'], ['16:00', '19:30']] as [$from, $to]) {
                    Db::insert(
                        'INSERT INTO doctor_schedules (clinic_id, doctor_id, weekday, starts_at, ends_at, is_active, created_at)
                         VALUES (?,?,?,?,?,1,?)',
                        [$c1, $docId, $wd, $from, $to, Db::now()]
                    );
                }
            }
        }
    }

    /* ---- departments -------------------------------------------------- */
    $hasDept = (int) Db::scalar('SELECT COUNT(*) FROM departments WHERE clinic_id = ?', [$c1]);
    if ($hasDept === 0) {
        $deptDefs = [
            ['General OPD', 'OPD', 'Walk-in and booked general consultations', 'Ground', 1],
            ['Family medicine', 'FAM', 'Family and long-term care', 'First', 2],
        ];
        $i = 0;
        foreach ($deptDefs as [$name, $code, $desc, $floor, $sort]) {
            $deptId = Db::insert(
                'INSERT INTO departments (clinic_id, name, code, description, floor, lead_doctor_id, sort_order, is_active, created_at)
                 VALUES (?,?,?,?,?,?,?,1,?)',
                [$c1, $name, $code, $desc, $floor, $doctorIds[$i % count($doctorIds)], $sort, Db::now()]
            );
            // Half the doctors in each department: enough to show the
            // department board, not so many that the assignment is noise.
            foreach ($doctorIds as $k => $docId) {
                if ($k % 2 === $i % 2) {
                    Db::run('UPDATE doctors SET department_id = ? WHERE id = ? AND clinic_id = ?', [$deptId, $docId, $c1]);
                }
            }
            $i++;
        }
    }

    /* ---- clinic holidays ---------------------------------------------- */
    $hasHol = (int) Db::scalar('SELECT COUNT(*) FROM holidays WHERE clinic_id = ?', [$c1]);
    if ($hasHol === 0) {
        Db::insert(
            'INSERT INTO holidays (clinic_id, date, name, created_by, created_at) VALUES (?,?,?,NULL,?)',
            [$c1, date('Y-m-d', strtotime('+18 days')), 'Poya Day', Db::now()]
        );
        Db::insert(
            'INSERT INTO holidays (clinic_id, date, name, created_by, created_at) VALUES (?,?,?,NULL,?)',
            [$c1, date('Y-m-d', strtotime('+47 days')), 'Annual maintenance day', Db::now()]
        );
    }

    /* ---- notification templates --------------------------------------- */
    // Seeded as *overrides* so the templates screen opens with content and a
    // clinic can see what it is editing. They deliberately carry the same
    // placeholders as the defaults.
    $hasTpl = (int) Db::scalar('SELECT COUNT(*) FROM notification_templates WHERE clinic_id = ?', [$c1]);
    if ($hasTpl === 0) {
        $custom = [
            'token_issued' => 'You are {token} in the {queue} queue at {clinic}. Track your place: {link}',
            'entry_called' => 'Your turn now — please go to {room}. Token {token}, {clinic}.',
        ];
        foreach ($custom as $key => $body) {
            Db::insert(
                'INSERT INTO notification_templates (clinic_id, template, body, created_at, updated_at)
                 VALUES (?,?,?,?,?)',
                [$c1, $key, $body, Db::now(), Db::now()]
            );
        }
    }

    /* ---- priority rules ----------------------------------------------- */
    $hasPrio = (int) Db::scalar('SELECT COUNT(*) FROM priority_rules WHERE clinic_id = ?', [$c1]);
    if ($hasPrio === 0) {
        Db::insert(
            "INSERT INTO priority_rules (clinic_id, queue_id, name, source, kind, min_age, max_age, effect, reason, is_active, created_at)
             VALUES (?,NULL,?,NULL,?,?,?,?,?,1,?)",
            [$c1, 'Elderly patients', 'consultation', 65, null, 3, 'Patients over 65 move ahead of the line.', Db::now()]
        );
        Db::insert(
            "INSERT INTO priority_rules (clinic_id, queue_id, name, source, kind, min_age, max_age, effect, reason, is_active, created_at)
             VALUES (?,NULL,?,?,?,?,?,?,?,1,?)",
            [$c1, 'Infants under two', NULL, 'consultation', null, 2, 4, 'Infants move ahead of the line.', Db::now()]
        );
    }

    /* ---- appointments -------------------------------------------------- */
    // Without bookings the appointment list, check-in, reschedule and
    // reminder paths are all dead ends on a fresh install.
    $hasAppt = (int) Db::scalar('SELECT COUNT(*) FROM appointments WHERE clinic_id = ?', [$c1]);
    if ($hasAppt === 0) {
        $patients = array_column(
            Db::all('SELECT id FROM patients WHERE clinic_id = ? ORDER BY id', [$c1]), 'id'
        );
        if ($patients !== []) {
            $slots = [
                ['+1 day 09:00', 'booked'], ['+1 day 09:20', 'booked'],
                ['+1 day 10:00', 'booked'], ['+2 days 16:30', 'booked'],
                ['+0 days 11:00', 'booked'],
            ];
            foreach ($slots as $i => [$when, $status]) {
                Db::insert(
                    'INSERT INTO appointments (clinic_id, doctor_id, patient_id, scheduled_for, duration_minutes, status, source, notes, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [
                        $c1,
                        $doctorIds[$i % count($doctorIds)],
                        (int) $patients[$i % count($patients)],
                        date('Y-m-d H:i:s', strtotime($when)),
                        15, $status, $i % 2 === 0 ? 'online' : 'reception', null, Db::now(),
                    ]
                );
            }
        }
    }

    /* ---- visit feedback ------------------------------------------------ */
    $hasFb = (int) Db::scalar('SELECT COUNT(*) FROM visit_feedback WHERE clinic_id = ?', [$c1]);
    if ($hasFb === 0) {
        $done = Db::all(
            "SELECT e.id, q.doctor_id FROM queue_entries e
               JOIN queues q ON q.id = e.queue_id
              WHERE e.clinic_id = ? AND e.status = 'completed'
              ORDER BY e.id DESC LIMIT 6",
            [$c1]
        );
        $ratings = [5, 4, 5, 3, 4, 5];
        foreach ($done as $i => $row) {
            Db::insert(
                'INSERT INTO visit_feedback (clinic_id, entry_id, doctor_id, rating, wait_rating, comment, created_at)
                 VALUES (?,?,?,?,?,NULL,?)',
                [$c1, (int) $row['id'], (int) $row['doctor_id'], $ratings[$i % 6], max(2, $ratings[$i % 6] - 1), Db::now()]
            );
        }
    }

    /* ---- organisation (so the Group screen has something to show) ------ */
    $hasOrg = (int) Db::scalar('SELECT COUNT(*) FROM organisations');
    if ($hasOrg === 0) {
        $orgId = Db::insert(
            'INSERT INTO organisations (name, slug, owner_email, notes, is_active, created_at, updated_at)
             VALUES (?,?,?,?,1,?,?)',
            ['Western Medical Group', 'western-medical', 'group@western.lk',
             'Two clinics sharing a group: Serenity OPD and Lakeview Medical Centre.', Db::now(), Db::now()]
        );
        Db::run(
            "UPDATE clinics SET organisation_id = ? WHERE slug IN ('serenity','lakeview')",
            [$orgId]
        );
    }

    /* ---- one support ticket, so the queue is not an empty state -------- */
    $hasTicket = (int) Db::scalar('SELECT COUNT(*) FROM support_tickets');
    if ($hasTicket === 0) {
        $owner = (int) (Db::scalar('SELECT id FROM users WHERE email = ?', ['admin@serenity.lk']) ?: 0);
        Db::insert(
            "INSERT INTO support_tickets (clinic_id, user_id, subject, body, category, priority, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [$c1, $owner ?: null, 'Add a third doctor to our plan',
             'We are taking on Dr. Perera from next month. What does moving from Professional to Clinic Plus involve?',
             'billing', 'normal', 'open', Db::now(), Db::now()]
        );
    }

    /* ---- clinic working hours ------------------------------------------ */
    // SlotService falls back to built-in defaults when these are absent, and
    // says so on the booking page ("the clinic has not set its own hours").
    // Seeding them means the demo clinic looks configured, and the
    // schedule-vs-hours intersection — a doctor rostered beyond opening time
    // is clipped to opening time — is actually exercised.
    $hasHours = (int) Db::scalar(
        "SELECT COUNT(*) FROM settings WHERE scope = 'clinic' AND owner_id = ? AND key_name LIKE 'hours.%'",
        [$c1]
    );
    if ($hasHours === 0) {
        $hours = [
            1 => '08:30-19:30', 2 => '08:30-19:30', 3 => '08:30-19:30',
            4 => '08:30-19:30', 5 => '08:30-19:30', 6 => '09:00-13:00',
            0 => 'closed',
        ];
        foreach ($hours as $dow => $value) {
            Db::insert(
                "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, ?, ?)",
                [$c1, 'hours.' . $dow, $value]
            );
        }
    }

    /* ---- public booking ------------------------------------------------ */
    // Off by default, and it was left off in the seed, which meant the
    // patient-facing booking page 404'd for every demo clinic. Serenity is
    // the "everything switched on" tenant, so switch it on here.
    Db::run('UPDATE clinics SET allow_online_booking = 1 WHERE id = ?', [$c1]);
})();

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
