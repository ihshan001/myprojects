<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * In-system notifications for staff.
 *
 * Distinct from `NotificationService`, which sends SMS to patients. This one
 * writes rows that a signed-in staff member sees in the bell menu. Keeping
 * them apart matters: a patient message costs money and has a delivery
 * lifecycle, a staff notice is a row and is free. Mixing them would make
 * "notification history" mean two different things on one screen.
 *
 * A notice addressed to `user_id = NULL` is for everybody in the clinic,
 * which is how a "doctor is running 40 minutes late" message reaches the
 * whole desk without fanning out rows nobody deletes.
 */
final class StaffNotificationService
{
    public function notify(
        int $clinicId,
        ?int $userId,
        string $title,
        string $body = '',
        string $kind = 'info',
        ?string $link = null
    ): int {
        return Db::insert(
            'INSERT INTO staff_notifications (clinic_id, user_id, kind, title, body, link, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$clinicId, $userId, $kind, mb_substr($title, 0, 200), mb_substr($body, 0, 500), $link, Db::now()]
        );
    }

    /**
     * Notices for this user: either addressed to them or to the whole clinic.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inbox(int $clinicId, int $userId, int $limit = 25, bool $unreadOnly = false): array
    {
        $sql = 'SELECT * FROM staff_notifications
                 WHERE clinic_id = ? AND (user_id = ? OR user_id IS NULL)';
        $params = [$clinicId, $userId];
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(200, $limit));
        return Db::all($sql, $params);
    }

    public function unreadCount(int $clinicId, int $userId): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM staff_notifications
              WHERE clinic_id = ? AND (user_id = ? OR user_id IS NULL) AND read_at IS NULL',
            [$clinicId, $userId]
        );
    }

    /**
     * Mark one notice read.
     *
     * The clinic scope is part of the WHERE clause, not a separate check, so
     * a forged id from another tenant matches nothing rather than being
     * caught later by a guard somebody might forget to add.
     */
    public function markRead(int $clinicId, int $userId, int $id): bool
    {
        $row = Db::one(
            'SELECT id FROM staff_notifications
              WHERE id = ? AND clinic_id = ? AND (user_id = ? OR user_id IS NULL)',
            [$id, $clinicId, $userId]
        );
        if (!$row) {
            return false;
        }
        Db::run('UPDATE staff_notifications SET read_at = ? WHERE id = ? AND clinic_id = ?', [Db::now(), $id, $clinicId]);
        return true;
    }

    public function markAllRead(int $clinicId, int $userId): int
    {
        Db::run(
            'UPDATE staff_notifications SET read_at = ?
              WHERE clinic_id = ? AND (user_id = ? OR user_id IS NULL) AND read_at IS NULL',
            [Db::now(), $clinicId, $userId]
        );
        return 1;
    }

    /**
     * A clinic-wide notice that something operational changed.
     *
     * Used by the queue engine for the events staff actually need to know
     * about without watching the board: a doctor running late, a queue
     * paused, a session closed with unseen patients.
     */
    public function operational(int $clinicId, string $title, string $body, string $kind = 'warning', ?string $link = null): int
    {
        return $this->notify($clinicId, null, $title, $body, $kind, $link);
    }
}
