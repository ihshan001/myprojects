<?php
declare(strict_types=1);

namespace App\Core;

/**
 * DEVTEST identity — replaces authentication entirely.
 *
 * There is no sign-in, no session user, no password and no 2FA in this build.
 * The port a request arrives on decides who you are, permanently:
 *
 *     8080 clinic owner · 8081 receptionist · 8082 doctor
 *     8083 platform admin · 8084 second clinic owner
 *
 * WHY IT IS BUILT THIS WAY
 * ------------------------
 * The rest of the application is untouched in spirit: every query still scopes
 * by Auth::clinicId(), every action still checks Auth::can(), and the role
 * matrix in Auth::matrix() is still the single source of truth. Only the
 * question "who is asking?" changed — it is answered by the port instead of a
 * session. That is what makes this useful for testing: the doctor on 8082 is
 * genuinely refused `queue.priority` by the domain layer, not by a hidden
 * button, exactly as in the real system.
 *
 * The identity is resolved from dev/roles.json by email, never by a hardcoded
 * user id: ids differ between seeds, and a stale id would silently test the
 * wrong role.
 *
 * THIS FILE MUST NEVER REACH PRODUCTION. It is reachable only because
 * public/index.php requires it, and devtest is not deployed.
 */
final class DevIdentity
{
    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    private static ?array $portEntry = null;
    private static bool $resolved = false;

    /**
     * Test override. The suite exercises several roles in one process, which a
     * port cannot express. Null means "resolve from the port" as normal.
     */
    private static ?int $overrideUserId = null;
    private static bool $overrideSet = false;

    /** Act as a specific user id (tests only). Pass null to clear. */
    public static function actingAs(?int $userId): void
    {
        self::$overrideUserId = $userId;
        self::$overrideSet = $userId !== null;
        self::$resolved = false;
        self::$user = null;
        Auth::forgetBranch();
        Auth::forgetClinicCache();
    }

    /** The roster row for the port this process serves. */
    public static function portEntry(): array
    {
        if (self::$portEntry !== null) {
            return self::$portEntry;
        }
        $port = (int) (getenv('QP_PORT') ?: 0);
        $file = QP_ROOT . '/dev/roles.json';
        $roster = is_file($file)
            ? (json_decode((string) file_get_contents($file), true) ?: [])
            : [];

        foreach ($roster['ports'] ?? [] as $row) {
            if ((int) ($row['port'] ?? 0) === $port) {
                return self::$portEntry = $row;
            }
        }

        // No QP_PORT: a CLI script (migrate, seed, tests) or a plain server.
        // Fall back to the first clinic owner so tooling has an identity, and
        // say which one it picked rather than failing deep inside a query.
        foreach ($roster['ports'] ?? [] as $row) {
            if (($row['role'] ?? '') === 'clinic_admin') {
                return self::$portEntry = $row + ['_fallback' => true];
            }
        }

        throw new \RuntimeException(
            'DEVTEST: dev/roles.json is missing or has no clinic_admin row. Cannot resolve an identity.'
        );
    }

    /** The user row for this port. Null only if the database is unseeded. */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        if (self::$overrideSet) {
            self::$user = null;
            if (self::$overrideUserId !== null) {
                $row = Db::one(
                    'SELECT * FROM users WHERE id = ? AND is_active = 1',
                    [self::$overrideUserId]
                );
                self::$user = $row ?: null;
            }
            return self::$user;
        }

        $entry = self::portEntry();
        self::$user = Db::one(
            'SELECT * FROM users WHERE email = ? AND is_active = 1',
            [strtolower((string) $entry['email'])]
        ) ?: null;
        return self::$user;
    }

    public static function label(): string
    {
        return (string) (self::portEntry()['label'] ?? 'dev');
    }

    public static function port(): int
    {
        return (int) (self::portEntry()['port'] ?? 0);
    }

    /** Forget the memo. Used by long-lived CLI tooling between operations. */
    public static function forget(): void
    {
        self::$user = null;
        self::$resolved = false;
    }
}
