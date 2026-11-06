<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Per-doctor weekly schedules.
 *
 * Before this existed, a doctor "worked" whenever the clinic was open. That
 * is fine for a two-room practice and wrong for anyone else: a cardiologist
 * who does Tue/Thu mornings must not appear bookable on a Monday, and must
 * not be hidden on a Thursday.
 *
 * The rule when a doctor has no schedule rows at all is deliberately
 * permissive — fall back to clinic hours — because a clinic that has just
 * upgraded must not find every doctor closed. The UI says which rule is in
 * force, so the fallback is never invisible.
 */
final class ScheduleService
{
    /**
     * @return array<int, array{weekday: int, starts_at: string, ends_at: string, is_active: int}>
     */
    public function forDoctor(int $clinicId, int $doctorId): array
    {
        return Db::all(
            'SELECT * FROM doctor_schedules
              WHERE clinic_id = ? AND doctor_id = ?
              ORDER BY weekday, starts_at',
            [$clinicId, $doctorId]
        );
    }

    /** True when the doctor has at least one row, i.e. a real schedule exists. */
    public function hasSchedule(int $clinicId, int $doctorId): bool
    {
        return Db::scalar(
            'SELECT id FROM doctor_schedules WHERE clinic_id = ? AND doctor_id = ? LIMIT 1',
            [$clinicId, $doctorId]
        ) !== null;
    }

    /**
     * The windows this doctor works on a date, intersected with clinic hours.
     *
     * @param array{open: ?string, close: ?string} $clinicHours
     * @return array<int, array{start: int, end: int}> minutes-from-midnight pairs
     */
    public function windowsFor(int $clinicId, int $doctorId, string $date, array $clinicHours): array
    {
        $clinicOpen = $this->minutes($clinicHours['open'] ?? null);
        $clinicClose = $this->minutes($clinicHours['close'] ?? null);
        if ($clinicOpen === null || $clinicClose === null || $clinicClose <= $clinicOpen) {
            return [];
        }

        if (!$this->hasSchedule($clinicId, $doctorId)) {
            return [['start' => $clinicOpen, 'end' => $clinicClose]];
        }

        $weekday = (int) date('w', strtotime($date));
        $rows = Db::all(
            'SELECT starts_at, ends_at FROM doctor_schedules
              WHERE clinic_id = ? AND doctor_id = ? AND weekday = ? AND is_active = 1
              ORDER BY starts_at',
            [$clinicId, $doctorId, $weekday]
        );

        $windows = [];
        foreach ($rows as $row) {
            // Intersect: a doctor rostered 07:00–17:00 in a clinic open
            // 08:30–12:30 works 08:30–12:30, not seventeen hours.
            $start = max($clinicOpen, $this->minutes((string) $row['starts_at']) ?? $clinicOpen);
            $end = min($clinicClose, $this->minutes((string) $row['ends_at']) ?? $clinicClose);
            if ($end > $start) {
                $windows[] = ['start' => $start, 'end' => $end];
            }
        }
        return $windows;
    }

    /**
     * Replace a doctor's week in one call.
     *
     * @param array<int, array<int, array{0: string, 1: string}>> $week weekday => list of [start, end]
     * @return array{ok: bool, error?: string}
     */
    public function saveWeek(int $clinicId, int $doctorId, array $week): array
    {
        if (!Db::one('SELECT id FROM doctors WHERE id = ? AND clinic_id = ?', [$doctorId, $clinicId])) {
            return ['ok' => false, 'error' => 'That doctor does not belong to this clinic.'];
        }

        // Validate everything before writing anything: a half-applied week is
        // worse than a rejected one, because the clinic cannot tell which half.
        $clean = [];
        foreach ($week as $weekday => $ranges) {
            $weekday = (int) $weekday;
            if ($weekday < 0 || $weekday > 6) {
                continue;
            }
            foreach ((array) $ranges as $range) {
                $start = trim((string) ($range[0] ?? ''));
                $end = trim((string) ($range[1] ?? ''));
                if ($start === '' && $end === '') {
                    continue;
                }
                if (preg_match('/^\d{2}:\d{2}$/', $start) !== 1 || preg_match('/^\d{2}:\d{2}$/', $end) !== 1) {
                    return ['ok' => false, 'error' => 'Use times like 09:00 on every working day.'];
                }
                if ($end <= $start) {
                    return ['ok' => false, 'error' => 'A shift must end after it starts.'];
                }
                $clean[$weekday][] = [$start, $end];
            }
        }

        Db::transaction(function () use ($clinicId, $doctorId, $clean): void {
            Db::run('DELETE FROM doctor_schedules WHERE clinic_id = ? AND doctor_id = ?', [$clinicId, $doctorId]);
            foreach ($clean as $weekday => $ranges) {
                foreach ($ranges as [$start, $end]) {
                    Db::insert(
                        'INSERT INTO doctor_schedules (clinic_id, doctor_id, weekday, starts_at, ends_at, is_active, created_at)
                         VALUES (?,?,?,?,?,1,?)',
                        [$clinicId, $doctorId, $weekday, $start, $end, Db::now()]
                    );
                }
            }
        });

        (new AuditService())->log($clinicId, 'doctor.schedule_updated', 'doctor', $doctorId, ['days' => array_keys($clean)]);
        return ['ok' => true];
    }

    /** @return array<int, array<int, array{0: string, 1: string}>> */
    public function asWeek(int $clinicId, int $doctorId): array
    {
        $week = array_fill(0, 7, []);
        foreach ($this->forDoctor($clinicId, $doctorId) as $row) {
            $week[(int) $row['weekday']][] = [(string) $row['starts_at'], (string) $row['ends_at']];
        }
        return $week;
    }

    private function minutes(?string $hhmm): ?int
    {
        if ($hhmm === null || $hhmm === '') {
            return null;
        }
        [$h, $m] = array_pad(explode(':', $hhmm, 2), 2, '0');
        return ((int) $h) * 60 + (int) $m;
    }
}
