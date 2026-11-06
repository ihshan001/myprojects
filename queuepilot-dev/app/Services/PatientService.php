<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Str;

/** Patient records are clinic-scoped: the same person at two clinics is two rows. */
final class PatientService
{
    public function find(int $clinicId, int $id): ?array
    {
        return Db::one('SELECT * FROM patients WHERE id = ? AND clinic_id = ?', [$id, $clinicId]);
    }

    public function findByPhone(int $clinicId, string $phone): ?array
    {
        $digits = Str::phoneDigits($phone);
        if ($digits === '') {
            return null;
        }
        return Db::one(
            "SELECT * FROM patients WHERE clinic_id = ? AND replace(replace(replace(phone,' ',''),'-',''),'+','') LIKE ?
              ORDER BY id DESC LIMIT 1",
            [$clinicId, '%' . substr($digits, -9)]
        );
    }

    public function search(int $clinicId, string $term, int $limit = 12): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $like = '%' . $term . '%';
        return Db::all(
            'SELECT * FROM patients
              WHERE clinic_id = ? AND (full_name LIKE ? OR phone LIKE ? OR code LIKE ?)
              ORDER BY full_name LIMIT ' . max(1, min(50, $limit)),
            [$clinicId, $like, $like, $like]
        );
    }

    /** Find-or-create by phone so walk-ins don't create duplicates. */
    public function findOrCreate(int $clinicId, array $data): array
    {
        $phone = trim((string) ($data['phone'] ?? ''));
        $name = trim((string) ($data['full_name'] ?? ''));

        if ($phone !== '') {
            $existing = $this->findByPhone($clinicId, $phone);
            if ($existing) {
                if ($name !== '' && $existing['full_name'] !== $name) {
                    Db::run('UPDATE patients SET full_name = ?, updated_at = ? WHERE id = ? AND clinic_id = ?',
                        [$name, Db::now(), $existing['id'], $clinicId]);
                    $existing['full_name'] = $name;
                }
                return $existing;
            }
        }

        // Two front-desk devices can compute the same next code at the same
        // moment (especially on MySQL, where the read and the insert are not
        // one statement). The unique index is the real guard; this loop lets
        // the loser pick up the next number instead of showing staff a raw
        // constraint error.
        $attempts = 0;
        while (true) {
            $code = $this->nextCode($clinicId);
            try {
                $id = Db::insert(
                    'INSERT INTO patients (clinic_id, code, full_name, phone, gender, birth_year, notes, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [
                        $clinicId, $code, $name, $phone ?: null,
                        $data['gender'] ?? null,
                        isset($data['birth_year']) && $data['birth_year'] ? (int) $data['birth_year'] : null,
                        $data['notes'] ?? null,
                        Db::now(), Db::now(),
                    ]
                );

                return $this->find($clinicId, $id);
            } catch (\PDOException $e) {
                $attempts++;
                $isCollision = str_contains(strtoupper($e->getMessage()), 'UNIQUE')
                    || str_contains(strtoupper($e->getMessage()), 'DUPLICATE');
                if (!$isCollision || $attempts >= 5) {
                    throw $e;
                }
                usleep(15000);
            }
        }
    }

    /**
     * Next patient code for a clinic.
     *
     * Counting rows and adding one is wrong the moment a record is ever
     * removed or merged: the count goes down while the highest code stays, so
     * the next insert collides with UNIQUE (clinic_id, code). That is exactly
     * what happened after a data cleanup, and every new patient registration
     * failed.
     *
     * Read the highest existing number instead, and still retry on a collision
     * so a concurrent insert cannot break registration either.
     */
    private function nextCode(int $clinicId): string
    {
        $highest = 0;
        $codes = Db::all('SELECT code FROM patients WHERE clinic_id = ?', [$clinicId]);
        foreach ($codes as $row) {
            if (preg_match('/(\d+)\s*$/', (string) $row['code'], $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }
        return 'P-' . Str::pad($highest + 1, 5);
    }
}
