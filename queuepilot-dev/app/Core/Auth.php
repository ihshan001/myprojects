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

    public static function user(): ?array
    {
        $id = Session::get('user_id');
        if (!$id) {
            return null;
        }
        static $cached = null;
        if ($cached !== null && (int) $cached['id'] === (int) $id) {
            return $cached;
        }
        $row = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $id]);
        if ($row !== null && self::sessionStale((int) $id)) {
            // A password reset completed after this session was established.
            // The most common reason to reset a password is that somebody
            // else had it, so the old session must not survive. Treated as a
            // logout rather than an error so the user lands on the sign-in
            // page with an explanation instead of a blank 403.
            Session::destroy();
            return null;
        }
        $cached = $row ?: null;
        return $cached;
    }

    /**
     * True when the user's password was reset after this session began.
     * The marker lives in `settings` (scope=user) because `users` has no
     * column for it and the baseline schema must not be hand-edited.
     */
    private static function sessionStale(int $userId): bool
    {
        $validAfter = Db::scalar(
            "SELECT value FROM settings WHERE scope = 'user' AND owner_id = ? AND key_name = 'sessions_valid_after'",
            [$userId]
        );
        if ($validAfter === null || $validAfter === '') {
            return false;
        }
        $started = Session::get('started_at');
        if ($started === null) {
            // Sessions created before this feature shipped carry no marker;
            // they are trusted until they expire rather than all logged out.
            return false;
        }
        return (string) $started < (string) $validAfter;
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

    /**
     * Attempt login with rate limiting and uniform error messages.
     * Returns [ok, user|null].
     */
    public static function attempt(string $email, string $password, string $ip): array
    {
        $max = (int) Env::get('security.login_max_attempts', 6);
        $window = (int) Env::get('security.login_window_min', 10);

        $recent = (int) Db::scalar(
            "SELECT COUNT(*) FROM login_attempts
              WHERE successful = 0 AND created_at >= ?
                AND (email = ? OR ip = ?)",
            [date('Y-m-d H:i:s', time() - $window * 60), mb_strtolower($email), $ip]
        );

        if ($recent >= $max) {
            Log::warn('auth.rate_limited', ['email' => $email, 'ip' => $ip]);
            return [false, null, 'too_many'];
        }

        $user = Db::one('SELECT * FROM users WHERE email = ? AND is_active = 1', [mb_strtolower($email)]);
        $ok = $user !== null && password_verify($password, $user['password_hash']);

        Db::insert(
            'INSERT INTO login_attempts (email, ip, successful, created_at) VALUES (?, ?, ?, ?)',
            [mb_strtolower($email), $ip, $ok ? 1 : 0, Db::now()]
        );

        if (!$ok) {
            return [false, null, 'invalid'];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [
                password_hash($password, PASSWORD_DEFAULT), $user['id'],
            ]);
        }

        // Step-up: a correct password is not yet a session when the account
        // has two-factor enabled. The half-authenticated state is a *pending*
        // marker in the session — deliberately NOT `user_id`, so every
        // Auth::check() in the app keeps failing until the second factor
        // arrives. That is what makes this safe to add retroactively.
        if (!empty($user['totp_enabled_at'])) {
            Session::regenerate();
            Session::put('2fa_pending_user_id', (int) $user['id']);
            Session::put('2fa_pending_at', time());
            Log::info('auth.2fa_challenge', ['user' => $user['id'], 'ip' => $ip]);
            return [true, $user, '2fa_required'];
        }

        self::completeLogin($user, $ip);
        return [true, $user, null];
    }

    /** Finish authentication after either a password-only or a 2FA login. */
    public static function completeLogin(array $user, string $ip = ''): void
    {
        Db::run('UPDATE users SET last_login_at = ? WHERE id = ?', [Db::now(), $user['id']]);

        Session::regenerate();
        Session::forget('2fa_pending_user_id');
        Session::forget('2fa_pending_at');
        Session::put('user_id', (int) $user['id']);
        // Stamped so a later password reset can tell which sessions predate it.
        Session::put('started_at', Db::now());
        Csrf::rotate();

        // resolve clinic association for clinic users
        if ($user['clinic_id'] !== null) {
            Session::put('clinic_id', (int) $user['clinic_id']);
        }

        // The clinic row can change under a long-lived session (plan change,
        // suspension, rebranding), and Auth::clinic() caches it statically.
        self::forgetClinicCache();

        Log::info('auth.login', ['user' => $user['id'], 'role' => $user['role'], 'ip' => $ip]);
    }

    /** The user id awaiting a second factor, if any. */
    public static function pendingTwoFactorId(): ?int
    {
        $id = Session::get('2fa_pending_user_id');
        if (!$id) {
            return null;
        }
        // A password prompt left open overnight must not stay valid.
        $started = (int) (Session::get('2fa_pending_at') ?? 0);
        if ($started > 0 && time() - $started > 600) {
            Session::forget('2fa_pending_user_id');
            Session::forget('2fa_pending_at');
            return null;
        }
        return (int) $id;
    }

    public static function clearTwoFactorPending(): void
    {
        Session::forget('2fa_pending_user_id');
        Session::forget('2fa_pending_at');
    }

    /**
     * Drop the per-request clinic cache after anything that can change the
     * clinic row. Static caches are correct for one HTTP request but wrong
     * for a long-lived worker process (tools/queue-worker.php), which is
     * exactly where a stale row would surface as "plan limit already reached".
     */
    public static function forgetClinicCache(): void
    {
        self::$clinicCache = [];
    }

    public static function logout(): void
    {
        $id = self::id();
        self::forgetBranch();
        Session::destroy();
        if ($id) {
            Log::info('auth.logout', ['user' => $id]);
        }
    }
}
