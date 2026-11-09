<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Str;

/** Tenant lifecycle: self-service signup, plan limits, suspension. */
final class ClinicService
{
    public function findBySlug(string $slug): ?array
    {
        return Db::one('SELECT * FROM clinics WHERE slug = ?', [$slug]);
    }

    public function find(int $id): ?array
    {
        return Db::one('SELECT * FROM clinics WHERE id = ?', [$id]);
    }

    public function plan(?int $planId): ?array
    {
        if (!$planId) {
            return null;
        }
        return Db::one('SELECT * FROM plans WHERE id = ?', [$planId]);
    }

    public function plans(): array
    {
        return Db::all('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_monthly');
    }

    /** Plan limit check used before creating a resource. */
    public function withinLimit(int $clinicId, string $resource): bool
    {
        $clinic = $this->find($clinicId);
        if (!$clinic) {
            return false;
        }
        $plan = $this->plan($clinic['plan_id'] !== null ? (int) $clinic['plan_id'] : null);
        if (!$plan) {
            return false;
        }
        $counts = [
            'doctors' => (int) Db::scalar('SELECT COUNT(*) FROM doctors WHERE clinic_id = ? AND is_active = 1', [$clinicId]),
            'staff'   => (int) Db::scalar("SELECT COUNT(*) FROM users WHERE clinic_id = ? AND role <> 'doctor'", [$clinicId]),
            'queues'  => (int) Db::scalar("SELECT COUNT(*) FROM queues WHERE clinic_id = ? AND session_date = ?", [$clinicId, Db::today()]),
        ];
        $limitKey = match ($resource) {
            'doctors' => 'max_doctors',
            'staff'   => 'max_staff',
            'queues'  => 'max_queues',
            default   => null,
        };
        if ($limitKey === null) {
            return true;
        }
        return $counts[$resource] < (int) $plan[$limitKey];
    }

    /**
     * Self-service signup: creates clinic + owner + default rooms/specs + queue.
     * Runs in one transaction so a partial tenant can never exist.
     */
    public function register(array $data, string $planCode = 'starter'): array
    {
        return Db::transaction(function () use ($data, $planCode) {
            $slug = Str::slug($data['name']);
            if ($slug === '') {
                throw new \RuntimeException('Please use letters or numbers in the clinic name.');
            }
            $base = $slug;
            $i = 1;
            while ($this->findBySlug($slug) !== null) {
                $slug = $base . '-' . (++$i);
            }

            $plan = Db::one('SELECT * FROM plans WHERE code = ?', [$planCode]);
            $trialEnds = date('Y-m-d', strtotime('+14 days'));

            $clinicId = Db::insert(
                'INSERT INTO clinics (slug, name, phone, email, city, district, timezone, locale, plan_id,
                    billing_status, trial_ends_on, token_prefix, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $slug, $data['name'], $data['phone'] ?? null, $data['email'] ?? null,
                    $data['city'] ?? null, $data['district'] ?? null,
                    'Asia/Colombo', 'en', $plan['id'] ?? null,
                    'trial', $trialEnds, strtoupper(substr($slug, 0, 1)),
                    Db::now(), Db::now(),
                ]
            );

            Db::insert(
                'INSERT INTO users (clinic_id, role, name, email, phone, password_hash, locale, is_active, created_at)
                 VALUES (?,?,?,?,?,?,?,1,?)',
                [
                    $clinicId, 'clinic_admin', $data['owner_name'], mb_strtolower($data['email']),
                    $data['phone'] ?? null,
                    password_hash($data['password'], PASSWORD_DEFAULT), 'en', Db::now(),
                ]
            );

            // sensible starting configuration so the clinic can run on day one
            $specs = ['General Medicine', 'Paediatrics', 'Dental'];
            $specIds = [];
            foreach ($specs as $s) {
                $specIds[$s] = Db::insert('INSERT INTO specialties (clinic_id, name) VALUES (?,?)', [$clinicId, $s]);
            }
            $rooms = ['Room 1', 'Room 2', 'Room 3'];
            $roomIds = [];
            foreach ($rooms as $r) {
                $roomIds[$r] = Db::insert('INSERT INTO rooms (clinic_id, name, floor) VALUES (?,?,?)', [$clinicId, $r, 'Ground']);
            }

            $doctorId = Db::insert(
                'INSERT INTO doctors (clinic_id, specialty_id, room_id, name, consult_fee, avg_consult_minutes, is_active, created_at)
                 VALUES (?,?,?,?,?,?,1,?)',
                [$clinicId, $specIds['General Medicine'], $roomIds['Room 1'], $data['owner_name'], 2000, 10, Db::now()]
            );

            Db::insert(
                "INSERT INTO queues (clinic_id, doctor_id, name, kind, status, session_date, token_prefix, last_token_no, token_padding, created_at)
                 VALUES (?,?,?,?, 'open', ?, ?, 0, 3, ?)",
                [$clinicId, $doctorId, 'OPD — Consultation', 'consultation', Db::today(),
                 (new DoctorService())->prefixFor($clinicId, $doctorId), Db::now()]
            );

            Db::insert(
                "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, 'onboarding', 'done')",
                [$clinicId]
            );

            return ['clinic_id' => $clinicId, 'slug' => $slug, 'owner_email' => mb_strtolower($data['email'])];
        });
    }

    public function updateBranding(int $clinicId, array $data): void
    {
        Db::run(
            'UPDATE clinics SET name = ?, phone = ?, email = ?, address_line = ?, city = ?, district = ?,
                    brand_color = ?, locale = ?, token_prefix = ?, late_grace_minutes = ?,
                    notify_approach = ?, approach_threshold = ?, updated_at = ?
              WHERE id = ?',
            [
                $data['name'], $data['phone'] ?? null, $data['email'] ?? null,
                $data['address_line'] ?? null, $data['city'] ?? null, $data['district'] ?? null,
                $data['brand_color'] ?: '#0F766E', $data['locale'] ?: 'en',
                strtoupper(substr(trim((string) ($data['token_prefix'] ?? '')), 0, 2)),
                (int) ($data['late_grace_minutes'] ?? 15),
                !empty($data['notify_approach']) ? 1 : 0,
                max(1, min(10, (int) ($data['approach_threshold'] ?? 2))),
                Db::now(), $clinicId,
            ]
        );
        // The settings save can change the clinic row the current request has
        // already cached (Auth::clinic()).
        \App\Core\Auth::forgetClinicCache();
    }

    /** Suspension keeps all data; it only blocks new sessions. */
    public function setBillingStatus(int $clinicId, string $status): void
    {
        Db::run('UPDATE clinics SET billing_status = ?, updated_at = ? WHERE id = ?', [$status, Db::now(), $clinicId]);
    }
}
