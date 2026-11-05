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
        $cached = $row ?: null;
        return $cached;
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

    public static function clinicId(): ?int
    {
        $u = self::user();
        if (!$u || $u['clinic_id'] === null) {
            return null;
        }
        // super admins operate platform-wide; clinic scope only via impersonation
        return (int) $u['clinic_id'];
    }

    public static function isSuper(): bool
    {
        return self::role() === self::ROLE_SUPER;
    }

    public static function clinic(): ?array
    {
        $cid = self::clinicId();
        if (!$cid) {
            return null;
        }
        static $cache = [];
        return $cache[$cid] ??= Db::one('SELECT * FROM clinics WHERE id = ?', [$cid]);
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
                'clinic.manage', 'staff.manage', 'doctor.manage',
                'queue.operate', 'queue.call', 'queue.consult', 'queue.priority',
                'queue.issue', 'reports.view', 'billing.view', 'patient.manage',
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
                'patient.manage', 'appointment.manage',
                'reports.view', 'reports.view.basic',
            ],
            self::ROLE_DOCTOR => [
                // A doctor works the same board as reception, but the UI hides
                // issuing tokens and other patients' queues (isDoctor branch).
                'queue.operate', 'queue.call', 'queue.consult', 'queue.priority',
                'reports.view.own', 'patient.view',
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

        Db::run('UPDATE users SET last_login_at = ? WHERE id = ?', [Db::now(), $user['id']]);

        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        Csrf::rotate();

        // resolve clinic association for clinic users
        if ($user['clinic_id'] !== null) {
            Session::put('clinic_id', (int) $user['clinic_id']);
        }

        Log::info('auth.login', ['user' => $user['id'], 'role' => $user['role'], 'ip' => $ip]);
        return [true, $user, null];
    }

    public static function logout(): void
    {
        $id = self::id();
        Session::destroy();
        if ($id) {
            Log::info('auth.logout', ['user' => $id]);
        }
    }
}
