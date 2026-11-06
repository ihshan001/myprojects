<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Which branches a signed-in person may work in, and which one they are
 * working in right now.
 *
 * The rule that keeps this safe is narrow on purpose: **a user may act in
 * their home branch, plus any branch explicitly granted.** There is no
 * "organisation member sees the group", because that would change what every
 * existing query returns for people who never asked for it. A grant is a
 * deliberate act with an actor recorded.
 *
 * Two failure modes deserve naming, because both are the kind that looks like
 * a feature until it is a leak:
 *
 *   1. Trusting a branch id from the URL or a cookie. The switcher posts a
 *      branch, and `switchTo()` re-validates it against `allowedFor()` before
 *      the session stores it. A crafted cookie therefore cannot widen scope.
 *   2. Letting the active branch outlive the grant. If access is revoked, the
 *      next request must not keep working in that branch just because the
 *      session remembers it. `activeBranch()` checks the grant every time.
 */
final class BranchService
{
    public const SESSION_KEY = 'active_branch';

    /**
     * Every branch this user may act in: home first, then grants, in the
     * order a person would recognise them.
     *
     * @return list<array{id:int,name:string,slug:string,is_home:bool,organisation:?string}>
     */
    public function allowedFor(int $userId, ?int $homeClinicId): array
    {
        $rows = Db::all(
            "SELECT c.id, c.name, c.slug, o.name AS organisation
               FROM clinics c
               LEFT JOIN organisations o ON o.id = c.organisation_id
              WHERE c.is_active = 1 AND (
                    c.id = ?
                    OR c.id IN (SELECT clinic_id FROM branch_grants WHERE user_id = ?)
              )
              ORDER BY (c.id = ?) DESC, o.name, c.name",
            [$homeClinicId, $userId, $homeClinicId]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'slug' => (string) $r['slug'],
            'is_home' => (int) $r['id'] === $homeClinicId,
            'organisation' => $r['organisation'] !== null ? (string) $r['organisation'] : null,
        ], $rows);
    }

    /** True when this user may act in this clinic. The single authority. */
    public function canActIn(int $userId, ?int $homeClinicId, int $clinicId): bool
    {
        if ($homeClinicId !== null && $clinicId === $homeClinicId) {
            return true;
        }
        return Db::scalar(
            'SELECT id FROM branch_grants WHERE user_id = ? AND clinic_id = ?',
            [$userId, $clinicId]
        ) !== null;
    }

    /**
     * The branch to use for this request.
     *
     * Falls back to the home branch whenever the stored one is no longer
     * allowed — a revoked grant, a suspended clinic, a stale session. Falling
     * back rather than erroring means a receptionist whose extra branch was
     * removed keeps working in their own clinic instead of hitting a 403 they
     * cannot explain.
     */
    public function activeBranch(int $userId, ?int $homeClinicId): ?int
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        if ($stored !== null) {
            $clinicId = (int) $stored;
            if ($clinicId > 0 && $this->canActIn($userId, $homeClinicId, $clinicId)) {
                // Also require the branch to still be live: a closed branch
                // must not keep serving tokens because a session remembers it.
                $live = Db::scalar('SELECT id FROM clinics WHERE id = ? AND is_active = 1', [$clinicId]);
                if ($live !== null) {
                    return $clinicId;
                }
            }
            unset($_SESSION[self::SESSION_KEY]);
        }
        return $homeClinicId;
    }

    /**
     * Move this session to another branch, or refuse.
     *
     * @throws \RuntimeException when the branch was never granted
     */
    public function switchTo(int $userId, ?int $homeClinicId, int $clinicId): void
    {
        if (!$this->canActIn($userId, $homeClinicId, $clinicId)) {
            throw new \RuntimeException('You do not have access to that branch.');
        }
        $live = Db::scalar('SELECT id FROM clinics WHERE id = ? AND is_active = 1', [$clinicId]);
        if ($live === null) {
            throw new \RuntimeException('That branch is not active.');
        }
        $_SESSION[self::SESSION_KEY] = $clinicId;
        \App\Core\Auth::forgetBranch();
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        \App\Core\Auth::forgetBranch();
    }

    /** Grant (or refresh) one branch for one user. Idempotent. */
    public function grant(int $userId, int $clinicId, ?int $grantedBy = null): void
    {
        if (Db::scalar('SELECT id FROM clinics WHERE id = ? AND is_active = 1', [$clinicId]) === null) {
            throw new \RuntimeException('That branch does not exist or is not active.');
        }
        if (Db::scalar('SELECT id FROM branch_grants WHERE user_id = ? AND clinic_id = ?', [$userId, $clinicId]) !== null) {
            return;
        }
        Db::insert(
            'INSERT INTO branch_grants (user_id, clinic_id, granted_by, created_at) VALUES (?,?,?,?)',
            [$userId, $clinicId, $grantedBy, Db::now()]
        );
    }

    /**
     * Revoke one branch.
     *
     * The home branch cannot be revoked: it is the column, not a grant, and
     * "remove this user's own clinic" is a different operation (deactivate
     * the account) with different consequences.
     */
    public function revoke(int $userId, int $clinicId, ?int $homeClinicId = null): bool
    {
        if ($homeClinicId !== null && $clinicId === $homeClinicId) {
            throw new \RuntimeException('The home branch cannot be revoked — deactivate the account instead.');
        }
        Db::run('DELETE FROM branch_grants WHERE user_id = ? AND clinic_id = ?', [$userId, $clinicId]);
        return true;
    }

    /**
     * Staff who can reach a branch, for the owner's screen: everyone whose
     * home is the branch, plus everyone granted it.
     *
     * @return list<array{id:int,name:string,email:string,role:string,via:string}>
     */
    public function staffFor(int $clinicId): array
    {
        $rows = Db::all(
            "SELECT u.id, u.name, u.email, u.role,
                    CASE WHEN u.clinic_id = ? THEN 'home' ELSE 'granted' END AS via
               FROM users u
              WHERE u.is_active = 1
                AND (u.clinic_id = ? OR u.id IN (SELECT user_id FROM branch_grants WHERE clinic_id = ?))
              ORDER BY (u.clinic_id = ?) DESC, u.name",
            [$clinicId, $clinicId, $clinicId, $clinicId]
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'email' => (string) $r['email'],
            'role' => (string) $r['role'],
            'via' => (string) $r['via'],
        ], $rows);
    }
}
