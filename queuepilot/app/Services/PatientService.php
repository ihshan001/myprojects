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

        $code = $this->nextCode($clinicId);
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
    }

    private function nextCode(int $clinicId): string
    {
        $count = (int) Db::scalar('SELECT COUNT(*) FROM patients WHERE clinic_id = ?', [$clinicId]);
        return 'P-' . Str::pad($count + 1, 5);
    }
}
