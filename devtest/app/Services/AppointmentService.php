<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class AppointmentService
{
    /**
     * A signed link a booked patient can use before they ever hold a queue
     * token: `GET /a/{id}.{sig}`.
     *
     * The signature covers the appointment id **and the scheduled time**, so
     * the link stops working the moment the booking is moved or cancelled.
     * That is deliberate: a link forwarded to a friend last week cannot be
     * used to move tomorrow's appointment, and no extra column or expiry
     * sweep is needed — the booking itself is the state.
     *
     * Signed with APP_KEY, so rotating the key invalidates every such link,
     * which is the correct emergency response if one leaks.
     */
    public static function manageSignature(int $appointmentId, string $scheduledFor): string
    {
        return substr(hash_hmac('sha256', 'appointment-manage|' . $appointmentId . '|' . $scheduledFor, self::signingKey()), 0, 32);
    }

    public static function manageUrl(int $appointmentId, string $scheduledFor): string
    {
        return '/a/' . $appointmentId . '.' . self::manageSignature($appointmentId, $scheduledFor);
    }

    /** Constant-time check; a wrong id and a wrong signature fail identically. */
    public static function manageTokenValid(int $appointmentId, string $scheduledFor, string $signature): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/', $signature) !== 1) {
            return false;
        }
        return hash_equals(self::manageSignature($appointmentId, $scheduledFor), $signature);
    }

    private static function signingKey(): string
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        return (string) ($config['app']['key'] ?? '');
    }

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
     * Move an appointment to a different time, keeping its identity.
     *
     * Rescheduling is not "cancel and rebook": the appointment id is what a
     * patient quotes at the desk, what a reminder already sent refers to, and
     * what an audit trail hangs off. Cancelling and rebooking throws all three
     * away and leaves two rows where one event happened.
     *
     * The clash check deliberately excludes this appointment's own slot —
     * otherwise moving a booking by five minutes would collide with itself.
     * The unique index is the backstop for the race between check and update.
     *
     * @throws \RuntimeException when the slot is taken, the appointment is not
     *                            movable, or the new time is in the past.
     */
    public function reschedule(int $clinicId, int $appointmentId, string $when, ?string $reason = null): array
    {
        $appointment = $this->find($clinicId, $appointmentId);
        if ($appointment === null) {
            throw new \RuntimeException('That appointment does not belong to this clinic.');
        }
        if (!in_array((string) $appointment['status'], ['booked', 'checked_in'], true)) {
            throw new \RuntimeException(
                'This appointment is ' . str_replace('_', ' ', (string) $appointment['status'])
                . ' — only a booked appointment can be moved.'
            );
        }
        if (strtotime($when) === false) {
            throw new \RuntimeException('That is not a time.');
        }
        if (strtotime($when) < strtotime(Db::today())) {
            throw new \RuntimeException('An appointment cannot be moved into the past.');
        }

        $clash = (int) Db::scalar(
            "SELECT COUNT(*) FROM appointments
              WHERE clinic_id = ? AND doctor_id = ? AND scheduled_for = ? AND id <> ?
                AND status IN ('booked','checked_in')",
            [$clinicId, (int) $appointment['doctor_id'], $when, $appointmentId]
        );
        if ($clash > 0) {
            throw new \RuntimeException('That time was just taken. Please choose another slot.');
        }

        $was = (string) $appointment['scheduled_for'];
        Db::run(
            'UPDATE appointments SET scheduled_for = ?, notes = ? WHERE id = ? AND clinic_id = ?',
            [$when, trim((string) $appointment['notes'] . ' Moved from ' . $was . ($reason !== null && $reason !== '' ? ' (' . $reason . ')' : '')), $appointmentId, $clinicId]
        );

        // A reminder already queued for the old time would tell the patient to
        // turn up when nobody is expecting them. Cancel it rather than send it.
        Db::run(
            "UPDATE notifications SET status = 'cancelled', last_error = 'appointment moved'
              WHERE clinic_id = ? AND dedupe_key = ? AND status = 'queued'",
            [$clinicId, 'appointment_reminder:' . $appointmentId]
        );

        return ['from' => $was, 'to' => $when, 'appointment_id' => $appointmentId];
    }

    /**
     * Cancel one booking.
     *
     * Separate from `cancelDay` because the reasons differ: a day is cancelled
     * because the doctor cannot come in, one booking because the patient
     * cannot. Both resolve to the same status so the desk reads one list.
     *
     * @throws \RuntimeException when it is not a live booking
     */
    public function cancel(int $clinicId, int $appointmentId, ?string $reason = null): array
    {
        $appointment = Db::one(
            'SELECT * FROM appointments WHERE id = ? AND clinic_id = ?',
            [$appointmentId, $clinicId]
        );
        if ($appointment === null) {
            throw new \RuntimeException('That appointment does not exist.');
        }
        if (!in_array((string) $appointment['status'], ['booked', 'checked_in'], true)) {
            throw new \RuntimeException('This appointment is ' . $appointment['status'] . ' — only a live booking can be cancelled.');
        }

        $notes = trim((string) ($appointment['notes'] ?? ''));
        $stamp = 'Cancelled ' . Db::now();
        if ($reason !== null && $reason !== '') {
            $stamp .= ' (' . $reason . ')';
        }
        Db::run(
            'UPDATE appointments SET status = ?, notes = ? WHERE id = ? AND clinic_id = ?',
            ['cancelled', $notes === '' ? $stamp : $notes . "\n" . $stamp, $appointmentId, $clinicId]
        );

        return ['id' => $appointmentId, 'scheduled_for' => (string) $appointment['scheduled_for']];
    }

    /**
     * Cancel every booked appointment for one doctor on one day.
     *
     * A doctor going off sick at 07:00 should not mean twenty individual
     * cancellations, and it should not mean twenty patients arriving to find
     * out. The return value names every patient so somebody can ring them —
     * the software can cancel the appointment, it cannot apologise.
     *
     * @return array{cancelled:int,patients:array<int,array{id:int,name:string,phone:?string,time:string}>}
     */
    public function cancelDay(int $clinicId, int $doctorId, string $date, ?string $reason = null, ?int $actorId = null): array
    {
        $doctor = Db::one('SELECT id, name FROM doctors WHERE id = ? AND clinic_id = ?', [$doctorId, $clinicId]);
        if ($doctor === null) {
            throw new \RuntimeException('That doctor does not belong to this clinic.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new \RuntimeException('Choose a day.');
        }

        $rows = Db::all(
            "SELECT a.id, a.scheduled_for, p.id AS patient_id, p.full_name, p.phone
               FROM appointments a JOIN patients p ON p.id = a.patient_id
              WHERE a.clinic_id = ? AND a.doctor_id = ? AND DATE(a.scheduled_for) = ?
                AND a.status IN ('booked','checked_in')
              ORDER BY a.scheduled_for",
            [$clinicId, $doctorId, $date]
        );

        // Nothing to do is not an event. Logging a zero-count cancellation
        // would put a line in the audit trail for something that never
        // happened, and the next person reading it would wonder what changed.
        if ($rows === []) {
            return ['cancelled' => 0, 'patients' => []];
        }

        $note = 'Doctor unavailable' . ($reason !== null && trim($reason) !== '' ? ': ' . trim($reason) : '');
        $stamp = 'Cancelled ' . Db::now() . ' (' . $note . ')';
        Db::transaction(function () use ($rows, $clinicId, $doctorId, $date, $stamp, $note, $actorId): void {
            foreach ($rows as $row) {
                // Append rather than replace: a booking that was already moved
                // once keeps that history, so the record still explains itself.
                $existing = (string) Db::scalar('SELECT notes FROM appointments WHERE id = ?', [(int) $row['id']]);
                Db::run(
                    'UPDATE appointments SET status = ?, notes = ? WHERE id = ? AND clinic_id = ?',
                    ['cancelled', trim($existing . "\n" . $stamp), (int) $row['id'], $clinicId]
                );
                Db::run(
                    "UPDATE notifications SET status = 'cancelled', last_error = 'appointment cancelled'
                      WHERE clinic_id = ? AND dedupe_key = ? AND status = 'queued'",
                    [$clinicId, 'appointment_reminder:' . (int) $row['id']]
                );
            }
            Db::insert(
                'INSERT INTO audit_logs (clinic_id, actor_id, action, entity, entity_id, meta_json, created_at)
                 VALUES (?,?,?,?,?,?,?)',
                [$clinicId, $actorId, 'appointment.day_cancelled', 'doctor', $doctorId,
                 json_encode(['date' => $date, 'count' => count($rows), 'reason' => $note]), Db::now()]
            );
        });

        return [
            'cancelled' => count($rows),
            'patients'  => array_map(static fn (array $r): array => [
                'id'    => (int) $r['patient_id'],
                'name'  => (string) $r['full_name'],
                'phone' => $r['phone'] !== null ? (string) $r['phone'] : null,
                'time'  => substr((string) $r['scheduled_for'], 11, 5),
            ], $rows),
        ];
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
