<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Identity + authorisation. The clinic_id is resolved from the authenticated
 * user row on every request — never from the URL or a form field.
 */
final class Auth
{
    public const ROLE_SUPER = 'super_admin';
    public const ROLE_ADMIN = 'clinic_admin';
    public const ROLE_RECEPTION = 'receptionist';
    public const ROLE_DOCTOR = 'doctor';

    /**
     * DEVTEST: identity comes from the port, not a session.
     *
     * Everything downstream is unchanged — clinicId(), can(), matrix() and the
     * tenant scoping in every query still behave exactly as in the main
     * system. Only "who is asking?" is answered differently.
     */
    public static function user(): ?array
    {
        return DevIdentity::user();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function role(): ?string
    {
        $u = self::user();
        return $u['role'] ?? null;
    }

    /**
     * The branch this request acts in.
     *
     * `users.clinic_id` is the **home** branch; a user may also hold explicit
     * grants to other branches (see BranchService). This method returns the
     * session's chosen branch, re-validated against the grants on every call,
     * and falls back to home when a grant was revoked or a branch closed.
     *
     * Resolving here rather than in each controller is what makes the feature
     * safe: every query in the application already scopes by this value, so
     * switching branches narrows nothing and opens nothing — it selects one
     * of the branches this user was already allowed to use.
     */
    public static function clinicId(): ?int
    {
        $u = self::user();
        if (!$u || $u['clinic_id'] === null) {
            return null;
        }
        $home = (int) $u['clinic_id'];
        $id = (int) $u['id'];

        // A real static property, not a function-static, for the same reason
        // the clinic cache is: it must be clearable. A long-lived worker that
        // switched branches inside one process would otherwise answer for the
        // wrong clinic on the next iteration.
        if (self::$resolvedBranchFor === $id) {
            return self::$resolvedBranch;
        }

        $active = (new \App\Services\BranchService())->activeBranch($id, $home);
        self::$resolvedBranchFor = $id;
        self::$resolvedBranch = $active ?? $home;
        return self::$resolvedBranch;
    }

    /** @var int|null  user id the memo below belongs to */
    private static ?int $resolvedBranchFor = null;

    /** @var int|null  memoised result of clinicId() for this request */
    private static ?int $resolvedBranch = null;

    /**
     * Forget the branch decision and the clinic row, so a switch takes effect
     * inside the same process (the worker) as well as across requests.
     */
    public static function forgetBranch(): void
    {
        self::$resolvedBranchFor = null;
        self::$resolvedBranch = null;
        self::forgetClinicCache();
    }

    public static function isSuper(): bool
    {
        return self::role() === self::ROLE_SUPER;
    }

    /** @var array<int, array<string, mixed>|null> */
    private static array $clinicCache = [];

    public static function clinic(): ?array
    {
        $cid = self::clinicId();
        if (!$cid) {
            return null;
        }
        // A real static property (not a function-static) so it can be cleared
        // deliberately — see forgetClinicCache(). Function statics are
        // invisible and therefore impossible to invalidate.
        if (!array_key_exists($cid, self::$clinicCache)) {
            self::$clinicCache[$cid] = Db::one('SELECT * FROM clinics WHERE id = ?', [$cid]) ?: null;
        }
        return self::$clinicCache[$cid];
    }

    /**
     * The role → ability matrix. One table, one source of truth.
     *
     * Kept static and pure so it can be unit-tested without a session, and so
     * an auditor can read the whole authorisation model in one screen.
     *
     * @return array<string, array<int, string>>
     */
    public static function matrix(): array
    {
        return [
            self::ROLE_SUPER => ['*'],
            self::ROLE_ADMIN => [
                'clinic.manage', 'staff.manage', 'doctor.manage', 'department.manage',
                'queue.operate', 'queue.call', 'queue.consult', 'queue.priority',
                'queue.issue', 'reports.view', 'billing.view', 'patient.manage',
                'appointment.manage', 'appointment.cancel_day',
                'support.manage', 'notification.manage',
            ],
            self::ROLE_RECEPTION => [
                // Day-to-day desk work plus the operational reports they are
                // actually asked for ("how many did we see today?").
                //
                // `queue.call` and `queue.consult` are desk duties, not clinical
                // authority: the receptionist rings the next patient in and
                // marks the visit finished, which is exactly how a small clinic
                // runs. `queue.priority` is deliberately absent — that decision
                // needs a clinician's judgement and a recorded reason.
                'queue.operate', 'queue.call', 'queue.consult', 'queue.issue',
                'patient.manage', 'appointment.manage', 'appointment.cancel_day',
                'reports.view', 'reports.view.basic', 'support.create',
            ],
            self::ROLE_DOCTOR => [
                // A doctor works the same board as reception, but the UI hides
                // issuing tokens and other patients' queues (isDoctor branch).
                'queue.operate', 'queue.call', 'queue.consult', 'queue.priority',
                // A doctor calling off their own day is clinical authority over
                // their own list, not desk authority over the clinic's bookings:
                // the ability is narrow (`appointment.cancel_day`) and the
                // controller still limits it to the doctor's own record.
                'appointment.cancel_day',
                'reports.view.own', 'patient.view', 'support.create',
            ],
        ];
    }

    /** Abilities granted to a role (empty for an unknown role). */
    public static function abilitiesFor(string $role): array
    {
        return self::matrix()[$role] ?? [];
    }

    /** Pure check: would this role be allowed this ability? */
    public static function canFor(string $role, string $ability): bool
    {
        $abilities = self::abilitiesFor($role);
        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public static function can(string $ability): bool
    {
        return self::canFor((string) self::role(), $ability);
    }

    public static function requireAbility(string $ability): void
    {
        if (!self::can($ability)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* DEVTEST: authentication removed                                     */
    /* ------------------------------------------------------------------ */
    /* attempt(), completeLogin(), pendingTwoFactorId(), clearTwoFactorPending()
       and logout() are gone with the sign-in flow. Identity is fixed per port
       (see DevIdentity), so there is nothing to log in to and nothing to log
       out of. The role/ability matrix above is untouched and still enforced. */

    /**
     * Drop the per-request clinic cache after anything that can change the
     * clinic row (plan change, suspension, rebranding).
     */
    public static function forgetClinicCache(): void
    {
        self::$clinicCache = [];
    }
}
