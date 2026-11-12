<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Str;

/**
 * Keys that open the public waiting-room and department screens.
 *
 * These are credentials. They are handed to a television, written down on a
 * sticky note, and then trusted for years — which is exactly why they need to
 * be individual, named, and revocable. A single shared key in `settings`
 * meant taking one screen offline meant taking every screen offline, and an
 * abandoned screen was indistinguishable from a live one.
 *
 * Resolution still falls back to the legacy `settings` values so an existing
 * clinic's screens keep working the moment this ships; the fallback is read
 * only, and issuing or revoking always goes through this table.
 */
final class DisplayKeyService
{
    /* ------------------------------------------------------------------ */
    /* Issuing                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Issue a key for one screen.
     *
     * @param int         $clinicId
     * @param string      $name          what the screen is called, for humans
     * @param string      $kind          waiting_room | department
     * @param int|null    $departmentId  required for a department screen
     */
    public function issue(int $clinicId, string $name, string $kind = 'waiting_room', ?int $departmentId = null): array
    {
        $name = trim(mb_substr($name, 0, 80));
        if ($name === '') {
            throw new \RuntimeException('Give the screen a name so you can tell it apart later.');
        }
        if ($kind === 'department' && $departmentId === null) {
            throw new \RuntimeException('A department screen needs a department.');
        }

        $key = Str::urlToken(24);
        $id = Db::insert(
            'INSERT INTO public_display_keys (clinic_id, department_id, name, display_key, kind, created_by, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$clinicId, $departmentId, $name, $key, $kind, Auth::id(), Db::now()]
        );

        (new AuditService())->log($clinicId, 'display.key_issued', 'public_display_key', $id, [
            'name' => $name, 'kind' => $kind, 'department_id' => $departmentId,
        ]);

        return [
            'id' => $id,
            'name' => $name,
            'key' => $key,
            'kind' => $kind,
            'url' => $kind === 'department' ? '/board/' . $key : '/board/' . $key,
        ];
    }

    /**
     * Revoke one screen without disturbing the others.
     *
     * The row is kept rather than deleted: a revoked key that is still being
     * requested is a screen somebody forgot to update, and that is worth being
     * able to see.
     */
    public function revoke(int $clinicId, int $id): bool
    {
        $row = Db::one(
            'SELECT id, name FROM public_display_keys WHERE id = ? AND clinic_id = ? AND revoked_at IS NULL',
            [$id, $clinicId]
        );
        if ($row === null) {
            return false;
        }

        Db::run('UPDATE public_display_keys SET revoked_at = ? WHERE id = ?', [Db::now(), $id]);
        (new AuditService())->log($clinicId, 'display.key_revoked', 'public_display_key', $id, [
            'name' => (string) $row['name'],
        ]);

        return true;
    }

    /** Every key this clinic has, live ones first. */
    public function forClinic(int $clinicId): array
    {
        return Db::all(
            'SELECT k.*, d.name AS department_name
               FROM public_display_keys k
               LEFT JOIN departments d ON d.id = k.department_id
              WHERE k.clinic_id = ?
              ORDER BY k.revoked_at IS NOT NULL, k.id DESC',
            [$clinicId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Resolution                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * What a presented key opens.
     *
     * @return array{clinic_id:int, department_id:?int, key_id:?int}|null
     */
    public function resolve(string $key): ?array
    {
        if ($key === '' || strlen($key) > 64) {
            return null;
        }

        $row = Db::one(
            'SELECT id, clinic_id, department_id, revoked_at
               FROM public_display_keys WHERE display_key = ?',
            [$key]
        );

        if ($row !== null) {
            if ($row['revoked_at'] !== null) {
                return null;
            }
            // Touch, but not on every poll — a screen refreshes every few
            // seconds and there is no reason to write that often.
            if (random_int(1, 30) === 1) {
                Db::run('UPDATE public_display_keys SET last_used_at = ? WHERE id = ?', [Db::now(), (int) $row['id']]);
            }

            return [
                'clinic_id' => (int) $row['clinic_id'],
                'department_id' => $row['department_id'] !== null ? (int) $row['department_id'] : null,
                'key_id' => (int) $row['id'],
            ];
        }

        return $this->resolveLegacy($key);
    }

    /**
     * Keys issued before this table existed.
     *
     * A waiting-room screen that has been running for a year must not go blank
     * because of a migration, so the old `settings` values are still honoured.
     * They cannot be renamed or individually revoked — issuing a new key is
     * how a clinic moves onto the new model.
     */
    private function resolveLegacy(string $key): ?array
    {
        $clinicId = Db::scalar(
            "SELECT owner_id FROM settings WHERE scope='clinic' AND key_name='display_token' AND value = ?",
            [$key]
        );

        if ($clinicId !== null) {
            return ['clinic_id' => (int) $clinicId, 'department_id' => null, 'key_id' => null];
        }

        $row = Db::one(
            "SELECT owner_id, key_name FROM settings
              WHERE scope='clinic' AND key_name LIKE 'display.department.%' AND value = ?",
            [$key]
        );
        if ($row !== null) {
            $departmentId = (int) substr((string) $row['key_name'], strlen('display.department.'));
            $exists = Db::scalar(
                'SELECT id FROM departments WHERE id = ? AND clinic_id = ?',
                [$departmentId, (int) $row['owner_id']]
            );

            return [
                'clinic_id' => (int) $row['owner_id'],
                'department_id' => $exists ? $departmentId : null,
                'key_id' => null,
            ];
        }

        return null;
    }
}
