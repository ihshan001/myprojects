<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class AppointmentService
{
    public function upcoming(int $clinicId, int $doctorId, int $limit = 20): array
    {
        return Db::all(
            "SELECT a.*, p.full_name, p.phone, p.code AS patient_code
               FROM appointments a JOIN patients p ON p.id = a.patient_id
              WHERE a.clinic_id = ? AND a.doctor_id = ?
                AND a.status IN ('booked','checked_in')
              ORDER BY a.scheduled_for LIMIT " . max(1, min(100, $limit)),
            [$clinicId, $doctorId]
        );
    }

    public function forDay(int $clinicId, ?int $doctorId, ?string $date = null): array
    {
        $date ??= Db::today();
        $sql = "SELECT a.*, p.full_name, p.phone, p.code AS patient_code, d.name AS doctor_name
                  FROM appointments a
                  JOIN patients p ON p.id = a.patient_id
                  JOIN doctors d ON d.id = a.doctor_id
                 WHERE a.clinic_id = ? AND substr(a.scheduled_for, 1, 10) = ?";
        $params = [$clinicId, $date];
        if ($doctorId !== null) {
            $sql .= ' AND a.doctor_id = ?';
            $params[] = $doctorId;
        }
        $sql .= ' ORDER BY a.scheduled_for';
        return Db::all($sql, $params);
    }

    public function create(int $clinicId, int $doctorId, int $patientId, string $when, string $source = 'online', int $duration = 15): int
    {
        return Db::insert(
            'INSERT INTO appointments (clinic_id, doctor_id, patient_id, scheduled_for, duration_minutes, status, source, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$clinicId, $doctorId, $patientId, $when, $duration, 'booked', $source, Db::now()]
        );
    }

    public function markCheckedIn(int $clinicId, int $appointmentId): bool
    {
        $ok = Db::run(
            "UPDATE appointments SET status='checked_in' WHERE id = ? AND clinic_id = ? AND status='booked'",
            [$appointmentId, $clinicId]
        )->rowCount() > 0;
        return $ok;
    }

    public function find(int $clinicId, int $id): ?array
    {
        return Db::one(
            'SELECT a.*, p.full_name, p.phone, d.name AS doctor_name
               FROM appointments a JOIN patients p ON p.id = a.patient_id
               JOIN doctors d ON d.id = a.doctor_id
              WHERE a.id = ? AND a.clinic_id = ?',
            [$id, $clinicId]
        );
    }

    public function updateStatus(int $clinicId, int $id, string $status): void
    {
        Db::run('UPDATE appointments SET status = ? WHERE id = ? AND clinic_id = ?', [$status, $id, $clinicId]);
    }
}
