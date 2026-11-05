<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Clinic closure days.
 *
 * A holiday is checked before opening hours, before doctor schedules and
 * before leave, because it is the strongest statement a clinic makes: not
 * open at all. Getting the precedence wrong in the other direction would let
 * a doctor's Tuesday roster resurrect a slot on a public holiday, and a
 * patient would book a consultation into a locked building.
 */
final class HolidayService
{
    /** @return array<int, array<string, mixed>> */
    public function upcoming(int $clinicId, ?string $from = null, int $limit = 60): array
    {
        return Db::all(
            'SELECT * FROM holidays WHERE clinic_id = ? AND date >= ? ORDER BY date LIMIT ' . max(1, min(200, $limit)),
            [$clinicId, $from ?? Db::today()]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function all(int $clinicId): array
    {
        return Db::all(
            'SELECT * FROM holidays WHERE clinic_id = ? AND date >= date(?) ORDER BY date',
            [$clinicId, Db::today()]
        );
    }

    public function isClosed(int $clinicId, string $date): bool
    {
        return Db::scalar('SELECT id FROM holidays WHERE clinic_id = ? AND date = ?', [$clinicId, $date]) !== null;
    }

    public function reasonFor(int $clinicId, string $date): ?string
    {
        $name = Db::scalar('SELECT name FROM holidays WHERE clinic_id = ? AND date = ?', [$clinicId, $date]);
        return $name !== null ? (string) $name : null;
    }

    /** @return array{ok: bool, id?: int, error?: string} */
    public function add(int $clinicId, string $date, string $name, ?int $userId = null): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return ['ok' => false, 'error' => 'Pick a date.'];
        }
        if (strtotime($date) === false) {
            return ['ok' => false, 'error' => 'That is not a real date.'];
        }
        $name = trim($name);
        if (mb_strlen($name) < 2) {
            return ['ok' => false, 'error' => 'Name the holiday so staff know why the clinic is shut.'];
        }
        if ($this->isClosed($clinicId, $date)) {
            return ['ok' => false, 'error' => 'That date is already marked as closed.'];
        }

        $id = Db::insert(
            'INSERT INTO holidays (clinic_id, date, name, created_by, created_at) VALUES (?,?,?,?,?)',
            [$clinicId, $date, mb_substr($name, 0, 120), $userId, Db::now()]
        );
        (new AuditService())->log($clinicId, 'holiday.added', 'holiday', $id, ['date' => $date, 'name' => $name]);
        return ['ok' => true, 'id' => $id];
    }

    /** @return array{ok: bool, error?: string} */
    public function remove(int $clinicId, int $id): array
    {
        $row = Db::one('SELECT * FROM holidays WHERE id = ? AND clinic_id = ?', [$id, $clinicId]);
        if (!$row) {
            return ['ok' => false, 'error' => 'That holiday does not belong to this clinic.'];
        }
        Db::run('DELETE FROM holidays WHERE id = ? AND clinic_id = ?', [$id, $clinicId]);
        (new AuditService())->log($clinicId, 'holiday.removed', 'holiday', $id, ['date' => $row['date']]);
        return ['ok' => true];
    }

    /**
     * How many booked appointments fall on this date? Shown before deleting a
     * holiday, because reopening a day that has no capacity booked into it is
     * harmless and reopening one that does is a scheduling decision.
     */
    public function appointmentsOn(int $clinicId, string $date): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM appointments
              WHERE clinic_id = ? AND substr(scheduled_for, 1, 10) = ? AND status IN ('booked','checked_in')",
            [$clinicId, $date]
        );
    }
}
