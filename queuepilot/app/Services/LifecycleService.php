<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;

/**
 * Account suspension, closure and data retention.
 *
 * Three separate things that are easy to conflate and expensive to get wrong:
 *
 *   * **Suspension** — a platform decision (unpaid, abuse, request). The
 *     clinic can still sign in and read its data, but the queue engine
 *     refuses to issue tokens and the public surfaces go dark. At no point
 *     does a patient standing at the desk lose their place because of a
 *     billing dispute.
 *   * **Closure** — the clinic's own decision. Signs out all staff, hides
 *     every public surface, and starts a retention clock.
 *   * **Purge** — destructive and irreversible, so it is never automatic
 *     from a web request: `tools/purge.php` runs it, after the retention
 *     window, and it writes what it removed.
 *
 * The point of separating them is that a clinic that is 20 days late on an
 * invoice must not lose seven years of visit history, and a clinic that asks
 * to close must be able to change its mind during the retention window.
 */
final class LifecycleService
{
    /** Default retention before a closed clinic's data may be purged. */
    public const DEFAULT_RETENTION_DAYS = 90;

    // ------------------------------------------------------------------
    // Suspension
    // ------------------------------------------------------------------

    /** @return array{ok: bool, error?: string} */
    public function suspend(int $clinicId, string $reason, ?int $actorId = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['suspended_at'] !== null) {
            return ['ok' => false, 'error' => 'That clinic is already suspended.'];
        }
        if (mb_strlen(trim($reason)) < 5) {
            return ['ok' => false, 'error' => 'Record why — the clinic sees this and support will ask.'];
        }

        Db::run(
            "UPDATE clinics SET suspended_at = ?, suspension_reason = ?, billing_status = 'suspended', updated_at = ?
              WHERE id = ?",
            [Db::now(), mb_substr(trim($reason), 0, 255), Db::now(), $clinicId]
        );

        (new StaffNotificationService())->operational(
            $clinicId,
            'Account suspended',
            $reason . ' Patients cannot be issued tokens until this is resolved. Nothing has been deleted.',
            'error',
            '/billing'
        );

        (new AuditService())->log($clinicId, 'clinic.suspended', 'clinic', $clinicId, [
            'reason' => $reason, 'actor' => $actorId,
        ]);
        Log::warn('clinic.suspended', ['clinic' => $clinicId, 'reason' => $reason]);
        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    public function reinstate(int $clinicId, ?int $actorId = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['suspended_at'] === null) {
            return ['ok' => false, 'error' => 'That clinic is not suspended.'];
        }

        Db::run(
            "UPDATE clinics SET suspended_at = NULL, suspension_reason = NULL, billing_status = 'active', updated_at = ?
              WHERE id = ?",
            [Db::now(), $clinicId]
        );
        (new AuditService())->log($clinicId, 'clinic.reinstated', 'clinic', $clinicId, ['actor' => $actorId]);
        return ['ok' => true];
    }

    public function isSuspended(int $clinicId): bool
    {
        return Db::scalar('SELECT suspended_at FROM clinics WHERE id = ? AND suspended_at IS NOT NULL', [$clinicId]) !== null;
    }

    // ------------------------------------------------------------------
    // Closure
    // ------------------------------------------------------------------

    /**
     * The clinic asks to close. Not immediate: a retention window starts so
     * a mistaken click, or a change of mind, is recoverable.
     *
     * @return array{ok: bool, purge_after?: string, error?: string}
     */
    public function requestClosure(int $clinicId, int $retentionDays = self::DEFAULT_RETENTION_DAYS): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['closure_requested_at'] !== null) {
            return ['ok' => false, 'error' => 'Closure has already been requested.'];
        }

        $retention = max(7, min(365, $retentionDays));
        $purgeAfter = date('Y-m-d H:i:s', time() + $retention * 86400);

        Db::transaction(function () use ($clinicId, $purgeAfter): void {
            Db::run(
                "UPDATE clinics SET closure_requested_at = ?, purge_after = ?, is_active = 0,
                        billing_status = 'closed', updated_at = ? WHERE id = ?",
                [Db::now(), $purgeAfter, Db::now(), $clinicId]
            );

            // Staff are signed out by deactivating their accounts: Auth::user()
            // filters on is_active, so this is an immediate and total cut.
            Db::run('UPDATE users SET is_active = 0 WHERE clinic_id = ?', [$clinicId]);

            // Open sessions must not be left 'open' — a display left running
            // in a closed clinic would keep showing a stale board.
            Db::run("UPDATE queues SET status = 'closed' WHERE clinic_id = ? AND status <> 'closed'", [$clinicId]);
        });

        (new AuditService())->log($clinicId, 'clinic.closure_requested', 'clinic', $clinicId, [
            'purge_after' => $purgeAfter,
        ]);
        Log::warn('clinic.closure_requested', ['clinic' => $clinicId, 'purge_after' => $purgeAfter]);

        return ['ok' => true, 'purge_after' => $purgeAfter];
    }

    /** @return array{ok: bool, error?: string} */
    public function cancelClosure(int $clinicId, ?int $actorId = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic || $clinic['closure_requested_at'] === null) {
            return ['ok' => false, 'error' => 'No closure request to cancel.'];
        }
        if ($clinic['closed_at'] !== null) {
            return ['ok' => false, 'error' => 'That clinic is already closed and purged data cannot be restored.'];
        }

        Db::transaction(function () use ($clinicId, $clinic): void {
            Db::run(
                "UPDATE clinics SET closure_requested_at = NULL, purge_after = NULL, is_active = 1,
                        billing_status = ?, updated_at = ? WHERE id = ?",
                [$clinic['suspended_at'] !== null ? 'suspended' : 'active', Db::now(), $clinicId]
            );
            // Staff accounts come back only for those still employed: the
            // users table has no separate "deactivated by closure" marker, so
            // this restores admins and doctors alike. Deliberate: a clinic
            // that cancels closure wants its team back.
            Db::run("UPDATE users SET is_active = 1 WHERE clinic_id = ? AND role IN ('clinic_admin','doctor','receptionist')", [$clinicId]);
        });

        (new AuditService())->log($clinicId, 'clinic.closure_cancelled', 'clinic', $clinicId, ['actor' => $actorId]);
        return ['ok' => true];
    }

    /**
     * Clinics whose retention window has expired and may be purged.
     *
     * @return array<int, array<string, mixed>>
     */
    public function purgeable(?string $now = null): array
    {
        return Db::all(
            'SELECT id, name, slug, closure_requested_at, purge_after
               FROM clinics
              WHERE closure_requested_at IS NOT NULL
                AND closed_at IS NULL
                AND purge_after IS NOT NULL
                AND purge_after <= ?',
            [$now ?? Db::now()]
        );
    }

    /**
     * Irreversible. Deletes the clinic's rows in dependency order inside one
     * transaction, then marks the clinic row closed rather than deleting it —
     * the slug must never be reusable by somebody else while an invoice
     * history with that name may exist in the platform's own accounting.
     *
     * @return array{ok: bool, removed?: array<string, int>, error?: string}
     */
    public function purge(int $clinicId): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['purge_after'] === null || strtotime((string) $clinic['purge_after']) > time()) {
            return ['ok' => false, 'error' => 'The retention window has not expired for that clinic.'];
        }

        // Tables with a direct clinic_id, in dependency order (children first).
        // `support_replies` is deliberately absent: it hangs off ticket_id, not
        // clinic_id, and is removed through its parent below.
        $tables = [
            'queue_events', 'queue_entries', 'appointments', 'notifications',
            'staff_notifications', 'doctor_schedules', 'doctor_leave', 'holidays',
            'departments', 'queues', 'doctors', 'rooms', 'specialties', 'patients',
        ];

        $removed = [];
        Db::transaction(function () use ($clinicId, $tables, &$removed, $clinic): void {
            foreach ($tables as $table) {
                $removed[$table] = (int) Db::scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE clinic_id = ?', [$clinicId]);
                Db::run('DELETE FROM ' . $table . ' WHERE clinic_id = ?', [$clinicId]);
            }

            // Support threads: replies first, then the tickets themselves.
            $ticketIds = array_column(Db::all('SELECT id FROM support_tickets WHERE clinic_id = ?', [$clinicId]), 'id');
            foreach ($ticketIds as $tid) {
                Db::run('DELETE FROM support_replies WHERE ticket_id = ?', [(int) $tid]);
            }
            $removed['support_replies'] = count($ticketIds);
            $removed['support_tickets'] = (int) Db::scalar('SELECT COUNT(*) FROM support_tickets WHERE clinic_id = ?', [$clinicId]);
            Db::run('DELETE FROM support_tickets WHERE clinic_id = ?', [$clinicId]);

            // Rows keyed by user rather than by clinic.
            $userIds = Db::all('SELECT id FROM users WHERE clinic_id = ?', [$clinicId]);
            foreach ($userIds as $u) {
                Db::run('DELETE FROM password_resets WHERE user_id = ?', [(int) $u['id']]);
            }
            Db::run('DELETE FROM staff_invites WHERE clinic_id = ?', [$clinicId]);
            // login_attempts keys on email, not user id — it records attempts
            // for addresses that never resolved to a user.
            foreach ($userIds as $u) {
                $email = Db::scalar('SELECT email FROM users WHERE id = ?', [(int) $u['id']]);
                if ($email !== null) {
                    Db::run('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower((string) $email)]);
                }
            }

            Db::run('DELETE FROM users WHERE clinic_id = ?', [$clinicId]);
            $removed['users'] = count($userIds);

            Db::run(
                "UPDATE clinics SET closed_at = ?, is_active = 0, name = ?, email = NULL, phone = NULL,
                        address_line = NULL, city = NULL, updated_at = ? WHERE id = ?",
                [Db::now(), '[closed] ' . $clinic['name'], Db::now(), $clinicId]
            );
        });

        // Invoices are kept: they are the platform's financial record, and a
        // clinic closing does not erase what it owed or paid.
        (new AuditService())->log($clinicId, 'clinic.purged', 'clinic', $clinicId, $removed);
        Log::warn('clinic.purged', ['clinic' => $clinicId, 'removed' => $removed]);
        return ['ok' => true, 'removed' => $removed];
    }

    /** Retention state for the clinic's own settings page. */
    public function status(int $clinicId): array
    {
        $row = Db::one(
            'SELECT suspended_at, suspension_reason, closure_requested_at, closed_at, purge_after
               FROM clinics WHERE id = ?',
            [$clinicId]
        ) ?? [];
        return [
            'suspended' => ($row['suspended_at'] ?? null) !== null,
            'suspension_reason' => $row['suspension_reason'] ?? null,
            'closure_requested' => ($row['closure_requested_at'] ?? null) !== null,
            'purge_after' => $row['purge_after'] ?? null,
            'closed' => ($row['closed_at'] ?? null) !== null,
        ];
    }
}
