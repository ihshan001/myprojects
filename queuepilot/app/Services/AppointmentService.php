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

    /**
     * Book an appointment.
     *
     * Double-booking protection is layered: the SELECT below produces a
     * sentence the receptionist understands, and the uq_appointment_slot
     * index makes the window between check and insert impossible to exploit.
     *
     * @throws \RuntimeException when the slot is already taken
     */
    public function create(int $clinicId, int $doctorId, int $patientId, string $when, string $source = 'online', int $duration = 15): int
    {
        // Deliberately does not select the patient: the clash message is
        // rendered on the public booking page, and naming the person who
        // already holds the slot tells a stranger who is seeing which doctor
        // and when. Presence of the clash is all the caller needs.
        $clash = Db::scalar(
            "SELECT COUNT(*) FROM appointments
              WHERE clinic_id = ? AND doctor_id = ? AND scheduled_for = ?
                AND status IN ('booked','checked_in')",
            [$clinicId, $doctorId, $when]
        );
        if ((int) $clash > 0) {
            throw new \RuntimeException('That time was just taken. Please choose another slot.');
        }

        return Db::insert(
            'INSERT INTO appointments (clinic_id, doctor_id, patient_id, scheduled_for, duration_minutes, status, source, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$clinicId, $doctorId, $patientId, $when, $duration, 'booked', $source, Db::now()]
        );
    }

    /**
     * How late is this booking, and what should happen?
     *
     * The clinic sets `late_grace_minutes` (Settings → queue policy). Inside
     * the grace period a late patient keeps their appointment's place in the
     * order. Beyond it they are still seen — a patient who travelled across
     * Colombo does not get sent home by software — but they join as a walk-in,
     * behind the people who arrived on time.
     *
     * The software never decides *medical* priority and never turns a patient
     * away; it only decides queue order, and it says which rule it applied.
     *
     * @return array{status: 'on_time'|'late'|'very_late'|'early', minutes: int, weight: int, message: string}
     */
    public function arrivalPolicy(array $appointment, ?array $clinic = null, ?string $now = null): array
    {
        $now ??= Db::now();
        $scheduled = strtotime((string) $appointment['scheduled_for']);
        $actual = strtotime($now);
        $delayMinutes = (int) round(($actual - $scheduled) / 60);

        // Per-service policy first, then the clinic default. A laboratory
        // can accept a sample later than a ten-minute consultation can; one
        // number on the clinic row cannot describe both.
        // Not every caller has a doctor (a bare clinic/scheduled_for pair is
        // enough to ask "is this patient late?"). Without one there is no
        // service to look up, so the clinic default answers — which is what
        // happened before per-service policies existed.
        $doctorId = isset($appointment['doctor_id']) && (int) $appointment['doctor_id'] > 0
            ? (int) $appointment['doctor_id']
            : null;
        $kind = $doctorId !== null
            ? Db::scalar(
                'SELECT kind FROM queues WHERE doctor_id = ? AND clinic_id = ? ORDER BY id DESC LIMIT 1',
                [$doctorId, (int) $appointment['clinic_id']]
            )
            : null;
        $policy = (new AppointmentPolicyService())->resolve(
            (int) $appointment['clinic_id'],
            $kind !== null ? (string) $kind : null,
            $doctorId
        );
        $grace = $policy['late_grace_minutes'];
        if ($clinic === null) {
            $clinic = Db::one('SELECT late_grace_minutes FROM clinics WHERE id = ?', [(int) $appointment['clinic_id']]);
        }
        if ($policy['source'] === 'clinic' && is_array($clinic) && isset($clinic['late_grace_minutes'])) {
            // No override configured: the clinic row remains the authority,
            // exactly as before this service existed.
            $grace = max(0, (int) $clinic['late_grace_minutes']);
        }

        // Arrived ahead of schedule: no penalty, but do not let them jump the
        // queue earlier than their slot either.
        if ($delayMinutes <= -1) {
            return [
                'status' => 'early', 'minutes' => abs($delayMinutes), 'weight' => 1,
                'message' => 'Checked in ' . abs($delayMinutes) . ' min early — keeps the booked order.',
            ];
        }

        if ($delayMinutes <= $grace) {
            return [
                'status' => 'on_time', 'minutes' => max(0, $delayMinutes), 'weight' => 1,
                'message' => 'Checked in on time — keeps the booked order.',
            ];
        }

        return [
            'status' => 'late', 'minutes' => $delayMinutes, 'weight' => 3,
            'message' => sprintf(
                'Arrived %d min late (grace is %d min) — added as a walk-in, behind patients who arrived on time.',
                $delayMinutes,
                $grace
            ),
        ];
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
