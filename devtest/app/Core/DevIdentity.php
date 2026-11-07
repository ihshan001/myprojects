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

    /**
     * Every port in the roster, for the role switcher in the UI.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function roster(): array
    {
        $file = QP_ROOT . '/dev/roles.json';
        $roster = is_file($file)
            ? (json_decode((string) file_get_contents($file), true) ?: [])
            : [];
        return array_values($roster['ports'] ?? []);
    }

    /**
     * Absolute URL for another role's port, derived from the host the browser
     * actually used.
     *
     * This matters more than it looks. In the Arena/E2B preview the sandbox
     * ports are not reachable as host:port — each one gets its own hostname,
     * `8081-<sandbox>.e2b.app`. A link to `localhost:8081` or `host:8081` is
     * dead from the user's browser. So:
     *
     *   8080-abc.e2b.app  ->  https://8081-abc.e2b.app   (swap port prefix)
     *   localhost:8080    ->  http://localhost:8081      (swap :port)
     *
     * The scheme is taken from the current request, so HTTPS previews never
     * get downgraded to a mixed-content http:// link.
     */
    public static function siblingUrl(int $port, string $path = '/'): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');

        // Proxied preview: the port is the first label of the hostname.
        if (preg_match('/^(\d+)-(.+)$/', $host, $m)) {
            return 'https://' . $port . '-' . $m[2] . $path;
        }

        // Plain host:port (local development).
        $bare = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
        $scheme = $https ? 'https' : 'http';
        return $scheme . '://' . $bare . ':' . $port . $path;
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
