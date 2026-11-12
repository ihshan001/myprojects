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
                self::consultMinutes($data['avg_consult_minutes'] ?? 10),
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
                self::consultMinutes($data['avg_consult_minutes'] ?? 10),
                !empty($data['is_active']) ? 1 : 0,
                $id, $clinicId,
            ]
        );
    }



    /**
     * Clamp an average consultation time to something a human could plausibly
     * experience.
     *
     * Every wait estimate is `patients_ahead × avg_consult_minutes`, so a typo
     * here does not corrupt one number — it makes every estimate in the clinic
     * nonsense ("your wait is 41 days"). The lower bound is 1 because a zero
     * average divides the range into nothing; the upper bound is 240 because a
     * four-hour average is already beyond any clinic we would serve.
     *
     * This is a clamp rather than a rejection because the value is an estimate,
     * and silently storing 99999 is far worse than storing 240.
     */
    public static function consultMinutes(mixed $value): int
    {
        $minutes = (int) $value;
        return max(1, min(240, $minutes));
    }

    /* ------------------------------------------------------------------ */
    /* Queue prefix                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The token prefix this doctor's queue issues: A, B, C … then AA, AB …
     *
     * Prefixes must be unique inside a clinic — two queues both issuing "D004"
     * makes a display board and a printed slip mutually unintelligible — and
     * must be allocated rather than derived. Deriving from the name is what
     * broke: every doctor is entered as "Dr. <name>", so every queue in a
     * clinic resolved to "D".
     *
     * We prefer the doctor's own stored prefix, then the most recent prefix
     * that doctor's queues have used, and only then allocate a fresh letter.
     * The fallback keeps an existing clinic's labels stable the first time
     * this runs instead of renumbering queues under patients who are already
     * holding tickets.
     */
    public function prefixFor(int $clinicId, int $doctorId): string
    {
        $stored = Db::scalar(
            'SELECT queue_prefix FROM doctors WHERE id = ? AND clinic_id = ?',
            [$doctorId, $clinicId]
        );
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $used = Db::scalar(
            'SELECT token_prefix FROM queues
              WHERE clinic_id = ? AND doctor_id = ? AND token_prefix IS NOT NULL AND token_prefix <> \'\'
              ORDER BY id DESC LIMIT 1',
            [$clinicId, $doctorId]
        );
        if (is_string($used) && $used !== '' && !$this->prefixTaken($clinicId, $used, $doctorId)) {
            return $used;
        }

        $fresh = $this->nextFreePrefix($clinicId);
        Db::run('UPDATE doctors SET queue_prefix = ? WHERE id = ? AND clinic_id = ?', [$fresh, $doctorId, $clinicId]);

        return $fresh;
    }

    /**
     * Lowest unused prefix in the sequence A…Z, AA…AZ, BA…ZZ.
     *
     * Single letters first: they read cleanly on a display board and on a
     * thermal slip, and no realistic clinic has more than 26 doctors.
     */
    public function nextFreePrefix(int $clinicId): string
    {
        $taken = [];
        foreach (Db::all('SELECT queue_prefix FROM doctors WHERE clinic_id = ? AND queue_prefix IS NOT NULL', [$clinicId]) as $row) {
            $taken[strtoupper((string) $row['queue_prefix'])] = true;
        }
        foreach (Db::all('SELECT DISTINCT token_prefix FROM queues WHERE clinic_id = ? AND token_prefix IS NOT NULL', [$clinicId]) as $row) {
            $taken[strtoupper((string) $row['token_prefix'])] = true;
        }

        foreach (range('A', 'Z') as $letter) {
            if (!isset($taken[$letter])) {
                return $letter;
            }
        }

        foreach (range('A', 'Z') as $first) {
            foreach (range('A', 'Z') as $second) {
                $candidate = $first . $second;
                if (!isset($taken[$candidate])) {
                    return $candidate;
                }
            }
        }

        // 700+ doctors in one clinic. Any value is now arbitrary; stay unique.
        return 'Q' . $clinicId . substr((string) time(), -4);
    }

    private function prefixTaken(int $clinicId, string $prefix, int $exceptDoctorId): bool
    {
        $owner = Db::scalar(
            'SELECT id FROM doctors WHERE clinic_id = ? AND queue_prefix = ? AND id <> ? LIMIT 1',
            [$clinicId, strtoupper($prefix), $exceptDoctorId]
        );

        return $owner !== null && $owner !== false;
    }

    /* ------------------------------------------------------------------ */
    /* Leave                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Doctor ids on leave for any part of [$from, $to].
     *
     * Leave is inclusive on both ends because that is how a human says it
     * ("away Monday to Wednesday"). An end before the start is rejected at
     * write time, but this query is defensive as well: `ends_on >= ?` on the
     * lower bound fails closed rather than returning a doctor as available.
     *
     * @return array<int, int>
     */
    public function onLeaveIds(int $clinicId, string $from, string $to): array
    {
        $rows = Db::all(
            'SELECT DISTINCT doctor_id FROM doctor_leave
              WHERE clinic_id = ? AND starts_on <= ? AND ends_on >= ?',
            [$clinicId, $to, $from]
        );
        return array_map(static fn (array $r): int => (int) $r['doctor_id'], $rows);
    }

    public function isOnLeave(int $clinicId, int $doctorId, string $date): bool
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM doctor_leave
              WHERE clinic_id = ? AND doctor_id = ? AND starts_on <= ? AND ends_on >= ?',
            [$clinicId, $doctorId, $date, $date]
        ) > 0;
    }

    /**
     * Active doctors, annotated with leave status for $date. The booking form
     * uses this so a doctor on leave is not offered as a choice at all — the
     * patient never sees a slot they cannot have.
     */
    public function bookable(int $clinicId, string $date): array
    {
        $doctors = $this->all($clinicId, true);
        $away = $this->onLeaveIds($clinicId, $date, $date);
        foreach ($doctors as &$doctor) {
            $doctor['on_leave'] = in_array((int) $doctor['id'], $away, true);
        }
        unset($doctor);
        return $doctors;
    }

    public function addLeave(int $clinicId, int $doctorId, string $startsOn, string $endsOn, ?string $reason, ?int $createdBy): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn)) {
            return ['ok' => false, 'error' => 'Pick a start and end date.'];
        }
        if ($endsOn < $startsOn) {
            return ['ok' => false, 'error' => 'The end date cannot be before the start date.'];
        }
        if (!Db::one('SELECT id FROM doctors WHERE id = ? AND clinic_id = ?', [$doctorId, $clinicId])) {
            return ['ok' => false, 'error' => 'That doctor does not belong to this clinic.'];
        }

        $id = Db::insert(
            'INSERT INTO doctor_leave (clinic_id, doctor_id, starts_on, ends_on, reason, created_by, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$clinicId, $doctorId, $startsOn, $endsOn, $reason !== '' ? $reason : null, $createdBy, Db::now()]
        );
        (new AuditService())->log($clinicId, 'doctor.leave_added', 'doctor', $doctorId, [
            'leave_id' => $id, 'from' => $startsOn, 'to' => $endsOn,
        ]);

        // Leave does not silently cancel appointments — that is a human
        // decision — but reception must be told which ones are now affected.
        $affected = Db::all(
            "SELECT a.id, a.scheduled_for, p.full_name, p.phone
               FROM appointments a JOIN patients p ON p.id = a.patient_id
              WHERE a.clinic_id = ? AND a.doctor_id = ? AND a.status IN ('booked','checked_in')
                AND a.scheduled_for >= ? AND a.scheduled_for < ?
              ORDER BY a.scheduled_for",
            [$clinicId, $doctorId, $startsOn . ' 00:00:00', date('Y-m-d', strtotime($endsOn . ' +1 day')) . ' 00:00:00']
        );

        return ['ok' => true, 'id' => $id, 'affected' => $affected];
    }

    public function removeLeave(int $clinicId, int $leaveId): bool
    {
        $row = Db::one('SELECT * FROM doctor_leave WHERE id = ? AND clinic_id = ?', [$leaveId, $clinicId]);
        if (!$row) {
            return false;
        }
        Db::run('DELETE FROM doctor_leave WHERE id = ? AND clinic_id = ?', [$leaveId, $clinicId]);
        (new AuditService())->log($clinicId, 'doctor.leave_removed', 'doctor', (int) $row['doctor_id'], [
            'leave_id' => $leaveId, 'from' => $row['starts_on'], 'to' => $row['ends_on'],
        ]);
        return true;
    }

    public function leave(int $clinicId, ?string $from = null): array
    {
        $sql = 'SELECT l.*, d.name AS doctor_name FROM doctor_leave l
                  JOIN doctors d ON d.id = l.doctor_id
                 WHERE l.clinic_id = ?';
        $params = [$clinicId];
        if ($from !== null) {
            $sql .= ' AND l.ends_on >= ?';
            $params[] = $from;
        }
        $sql .= ' ORDER BY l.starts_on, d.name';
        return Db::all($sql, $params);
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
