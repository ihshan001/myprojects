<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class DoctorService
{
    public function all(int $clinicId, bool $activeOnly = true): array
    {
        $sql = 'SELECT d.*, s.name AS specialty, r.name AS room_name
                  FROM doctors d
                  LEFT JOIN specialties s ON s.id = d.specialty_id
                  LEFT JOIN rooms r ON r.id = d.room_id
                 WHERE d.clinic_id = ?';
        if ($activeOnly) {
            $sql .= ' AND d.is_active = 1';
        }
        $sql .= ' ORDER BY d.name';
        return Db::all($sql, [$clinicId]);
    }

    public function find(int $clinicId, int $id): ?array
    {
        return Db::one(
            'SELECT d.*, s.name AS specialty, r.name AS room_name FROM doctors d
               LEFT JOIN specialties s ON s.id = d.specialty_id
               LEFT JOIN rooms r ON r.id = d.room_id
              WHERE d.id = ? AND d.clinic_id = ?',
            [$id, $clinicId]
        );
    }

    public function create(int $clinicId, array $data): int
    {
        return Db::insert(
            'INSERT INTO doctors (clinic_id, specialty_id, room_id, name, reg_no, consult_fee, avg_consult_minutes, is_active, created_at)
             VALUES (?,?,?,?,?,?,?,1,?)',
            [
                $clinicId,
                $data['specialty_id'] ?: null,
                $data['room_id'] ?: null,
                $data['name'],
                $data['reg_no'] ?? null,
                (float) ($data['consult_fee'] ?? 0),
                (int) ($data['avg_consult_minutes'] ?? 10),
                Db::now(),
            ]
        );
    }

    public function update(int $clinicId, int $id, array $data): void
    {
        Db::run(
            'UPDATE doctors SET name = ?, specialty_id = ?, room_id = ?, consult_fee = ?, avg_consult_minutes = ?, is_active = ?
              WHERE id = ? AND clinic_id = ?',
            [
                $data['name'],
                $data['specialty_id'] ?: null,
                $data['room_id'] ?: null,
                (float) ($data['consult_fee'] ?? 0),
                (int) ($data['avg_consult_minutes'] ?? 10),
                !empty($data['is_active']) ? 1 : 0,
                $id, $clinicId,
            ]
        );
    }

    public function specialties(int $clinicId): array
    {
        return Db::all('SELECT * FROM specialties WHERE clinic_id = ? ORDER BY name', [$clinicId]);
    }

    public function rooms(int $clinicId): array
    {
        return Db::all('SELECT * FROM rooms WHERE clinic_id = ? ORDER BY name', [$clinicId]);
    }
}
