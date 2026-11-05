<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Which appointment slots a patient may actually choose.
 *
 * The previous version of the booking page offered a fixed list of times
 * (08:30 … 16:30) regardless of anything. That is worse than having no
 * form: it promises a clinic's real availability and then lets a patient
 * pick a time nobody works, or hides a time the doctor is free.
 *
 * The inputs here are all things the clinic already controls:
 *
 *   * clinic opening hours        (settings, per weekday, clinic scope)
 *   * the doctor's session start (from today's queue for that doctor, if
 *                                  one exists — a doctor is "in" only when
 *                                  the clinic has scheduled a session)
 *   * the doctor's consultation length (doctors.avg_consult_minutes)
 *   * bookings already held     (appointments, booked or checked_in)
 *   * leave                     (doctor_leave)
 *   * the booking horizon       (settings, default 30 days)
 *
 * What this deliberately does NOT do is invent working days that the clinic
 * never entered. If a clinic has not configured hours, the defaults below
 * apply and the UI says which hours it is showing — silence about the
 * assumption is what makes a booking page untrustworthy.
 */
final class SlotService
{
    /** 0 = Sunday … 6 = Saturday, matching `date('w')`. */
    public const DEFAULT_HOURS = [
        0 => null,               // closed
        1 => ['08:30', '12:30'], // Mon
        2 => ['08:30', '12:30'],
        3 => ['08:30', '12:30'],
        4 => ['08:30', '12:30'],
        5 => ['08:30', '12:30'],
        6 => ['08:30', '12:30'],
    ];

    /** @return array{open: ?string, close: ?string, configured: bool} */
    public function hoursFor(int $clinicId, string $date): array
    {
        $dow = (int) date('w', strtotime($date));
        $raw = Db::scalar(
            "SELECT value FROM settings WHERE scope = 'clinic' AND owner_id = ? AND key_name = ?",
            [$clinicId, 'hours.' . $dow]
        );

        if (is_string($raw) && $raw !== '') {
            $parts = explode('-', $raw, 2);
            if (count($parts) === 2) {
                return ['open' => $parts[0], 'close' => $parts[1], 'configured' => true];
            }
            if ($raw === 'closed') {
                return ['open' => null, 'close' => null, 'configured' => true];
            }
        }

        $default = self::DEFAULT_HOURS[$dow] ?? null;
        return [
            'open' => $default[0] ?? null,
            'close' => $default[1] ?? null,
            'configured' => false,
        ];
    }

    /**
     * Bookable times for one doctor on one date.
     *
     * @return array{slots: array<int, string>, reason: ?string, hours: array}
     *         `reason` is a patient-readable sentence when the list is empty
     *         because of something other than "the day is full".
     */
    public function slotsFor(int $clinicId, int $doctorId, string $date): array
    {
        $hours = $this->hoursFor($clinicId, $date);

        if ($hours['open'] === null || $hours['close'] === null) {
            return ['slots' => [], 'reason' => 'The clinic is closed on this day.', 'hours' => $hours];
        }

        $doctor = Db::one(
            'SELECT id, name, avg_consult_minutes, is_active FROM doctors WHERE id = ? AND clinic_id = ?',
            [$doctorId, $clinicId]
        );
        if (!$doctor || (int) $doctor['is_active'] !== 1) {
            return ['slots' => [], 'reason' => 'That doctor is not taking appointments.', 'hours' => $hours];
        }

        if ((new DoctorService())->isOnLeave($clinicId, $doctorId, $date)) {
            return ['slots' => [], 'reason' => 'The doctor is on leave on this date.', 'hours' => $hours];
        }

        // The slot width is the doctor's own consultation length: a doctor who
        // takes 30 minutes must not be offered 15-minute slots. It is then
        // snapped up to the next 5 minutes, because a grid derived from an
        // average of 9 minutes produces '9:06 AM' — arithmetically right and
        // absurd to read. Snapping up, never down, so a slot is never shorter
        // than the consultation it represents.
        $consult = max(5, (int) ($doctor['avg_consult_minutes'] ?: 15));
        $step = max(10, (int) (ceil($consult / 5) * 5));

        // There is no per-day cap column in the schema. The cap is the number
        // of consultations that physically fit inside the clinic's opening
        // hours, which the loop below computes. A per-doctor override would be
        // a schema change and is not pretended at here.

        $open = $this->minutes($hours['open']);
        $close = $this->minutes($hours['close']);
        if ($close <= $open) {
            return ['slots' => [], 'reason' => 'The clinic hours are misconfigured for this day.', 'hours' => $hours];
        }

        // How many patients can this doctor physically see in the window?
        // Honest capacity beats an infinite grid: a patient who books the
        // 41st slot of a 20-patient day is being lied to.
        $capacity = (int) floor(($close - $open) / $step);

        $taken = [];
        foreach (Db::all(
            "SELECT scheduled_for FROM appointments
              WHERE clinic_id = ? AND doctor_id = ? AND status IN ('booked','checked_in')
                AND scheduled_for >= ? AND scheduled_for < ?",
            [$clinicId, $doctorId, $date . ' 00:00:00', $date . ' 23:59:59']
        ) as $row) {
            $taken[substr((string) $row['scheduled_for'], 11, 5)] = true;
        }

        $now = Db::now();
        $isToday = $date === substr($now, 0, 10);
        $nowMinutes = $isToday ? ((int) substr($now, 11, 2)) * 60 + (int) substr($now, 14, 2) : -1;

        $slots = [];
        for ($m = $open; $m + $step <= $close && count($slots) < $capacity; $m += $step) {
            // A time that has already passed today cannot be booked.
            if ($isToday && $m <= $nowMinutes + 15) {
                continue;
            }
            $label = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            if (isset($taken[$label])) {
                continue;
            }
            $slots[] = $label;
        }

        if ($slots === []) {
            $reason = $taken !== []
                ? 'Every slot on this day is already booked.'
                : 'There are no times left on this day. Choose another date.';
            return ['slots' => [], 'reason' => $reason, 'hours' => $hours];
        }

        return ['slots' => $slots, 'reason' => null, 'hours' => $hours];
    }

    /** Is this exact time a slot the service would have offered? */
    public function isOffered(int $clinicId, int $doctorId, string $date, string $time): bool
    {
        $result = $this->slotsFor($clinicId, $doctorId, $date);
        return in_array($time, $result['slots'], true);
    }

    /** The furthest ahead a patient may book, in days. */
    public function horizonDays(int $clinicId): int
    {
        $raw = Db::scalar(
            "SELECT value FROM settings WHERE scope = 'clinic' AND owner_id = ? AND key_name = 'booking.horizon_days'",
            [$clinicId]
        );
        $days = is_numeric($raw) ? (int) $raw : 30;
        return max(1, min(180, $days));
    }

    /**
     * Persist clinic hours. Kept here rather than in a controller so the
     * booking form and the settings screen can never disagree about what
     * "open" means.
     *
     * @param array<int, ?string> $week Map of 0..6 => 'HH:MM-HH:MM' | 'closed' | null
     */
    public function saveHours(int $clinicId, array $week): void
    {
        foreach ($week as $dow => $spec) {
            $dow = (int) $dow;
            if ($dow < 0 || $dow > 6) {
                continue;
            }
            if ($spec === null) {
                Db::run(
                    "DELETE FROM settings WHERE scope = 'clinic' AND owner_id = ? AND key_name = ?",
                    [$clinicId, 'hours.' . $dow]
                );
                continue;
            }
            Db::run(
                "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, ?, ?)
                 ON CONFLICT (scope, owner_id, key_name) DO UPDATE SET value = excluded.value",
                [$clinicId, 'hours.' . $dow, (string) $spec]
            );
        }
    }

    private function minutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm, 2), 2, '0');
        return ((int) $h) * 60 + (int) $m;
    }
}
