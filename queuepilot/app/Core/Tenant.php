<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Which clinic is this request for, and which of its two sites is it?
 *
 * Before this class, tenancy came from the signed-in user (`users.clinic_id`)
 * and nothing else. That is safe for one host per deployment and useless once
 * twenty clinics each have two subdomains, because the hostname has to decide
 * *before* anybody signs in: the patient board, the tracking page and the login
 * form all belong to a clinic that no session has named yet.
 *
 * The rule this class exists to enforce:
 *
 *   **An unknown host resolves to nothing. It never falls back.**
 *
 * A resolver that guesses — "no match, use the first clinic" — is how one
 * clinic's display board ends up showing another's patients. Every lookup here
 * is an exact match on a unique column, and a miss is a 404.
 *
 * Two surfaces, deliberately different:
 *
 *   site   <slug>.queue.lk          public: display board, queue position,
 *                                   tracking links, the patient-facing pages.
 *                                   No login. Patients may reach anything here.
 *   ops    <ops_slug>.queue.lk      the backend. Staff sign in here. Ops hosts
 *                                   are unguessable so the admin surface is not
 *                                   discoverable by trying clinic names.
 *   root   queue.lk, admin…         the platform itself: signup, the platform
 *                                   console. No clinic context.
 *
 * A clinic request is additionally *bound* to its clinic: see bind(). A
 * signed-in user whose clinic_id does not match the host's clinic is refused,
 * because a cookie replayed against another clinic's subdomain must not work.
 */
final class Tenant
{
    public const KIND_ROOT = 'root';
    public const KIND_SITE = 'site';
    public const KIND_OPS  = 'ops';

    private static ?array $clinic = null;
    private static string $kind = self::KIND_ROOT;
    private static ?string $host = null;
    private static bool $resolved = false;

    /**
     * Work out the tenant from the Host header.
     *
     * Called once, early, before routing. The result is cached for the request
     * because every subsequent question — which clinic, which surface — must
     * be answered from the same decision.
     */
    public static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;

        $host = self::normalise((string) ($_SERVER['HTTP_HOST'] ?? ''));
        self::$host = $host;

        if ($host === '') {
            self::$kind = self::KIND_ROOT;
            return;
        }

        // The configured platform hosts. Anything here is the platform's own
        // surface and carries no clinic.
        $rootHosts = array_filter(array_map(
            static fn ($h) => self::normalise((string) $h),
            explode(',', (string) Env::get('app.root_hosts', ''))
        ));
        if (in_array($host, $rootHosts, true)) {
            self::$kind = self::KIND_ROOT;
            return;
        }

        // Dev and CLI: with no root hosts configured and no subdomain split to
        // make, everything is the platform host. This keeps `npm run dev` and
        // the whole existing suite working on 127.0.0.1 without a hosts file.
        $suffix = self::normalise((string) Env::get('app.domain', ''));
        if ($suffix === '') {
            self::$kind = self::KIND_ROOT;
            return;
        }

        if ($host === $suffix) {
            self::$kind = self::KIND_ROOT;
            return;
        }
        if (!str_ends_with($host, '.' . $suffix)) {
            // A host we were never told about. Not a clinic, not the platform:
            // refuse it rather than serve the wrong tenant.
            self::$kind = self::KIND_ROOT;
            return;
        }

        $label = substr($host, 0, -(strlen($suffix) + 1));
        if ($label === '' || str_contains($label, '.')) {
            self::$kind = self::KIND_ROOT;
            return;
        }

        // Match the FULL hostname, which is what the columns store. Comparing
        // the label alone looks right and always misses, because the database
        // holds `serenity.queue.lk` while the label is just `serenity` — the
        // resolver then reports "unknown host" for every clinic, which fails
        // closed and is therefore invisible: every subdomain simply behaves
        // like the platform root.
        $clinic = Db::one(
            'SELECT * FROM clinics WHERE ops_host = ? OR site_host = ? LIMIT 1',
            [$host, $host]
        );
        if ($clinic === null) {
            self::$clinic = null;
            self::$kind = self::KIND_ROOT;
            return;
        }

        self::$clinic = $clinic;
        // Which surface, decided by which column matched — not by testing the
        // label prefix, which would misread an ops host whose code happens to
        // start with the clinic name.
        self::$kind = (($clinic['ops_host'] ?? null) === $host) ? self::KIND_OPS : self::KIND_SITE;
    }

    /**
     * Strip the things that make two spellings of one host look different.
     *
     * A trailing dot is legal in a Host header, case is not significant, and a
     * port is not part of the name. Without this, `Serenity.queue.lk.` and
     * `serenity.queue.lk` are two lookups, and only one of them matches.
     */
    private static function normalise(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('~:\d+$~', '', $host) ?? $host;
        return rtrim($host, '.');
    }

    /** The clinic row this request belongs to, or null on the platform host. */
    public static function clinic(): ?array
    {
        self::resolve();
        return self::$clinic;
    }

    public static function clinicId(): ?int
    {
        $c = self::clinic();
        return $c === null ? null : (int) $c['id'];
    }

    public static function kind(): string
    {
        self::resolve();
        return self::$kind;
    }

    public static function isRoot(): bool
    {
        return self::kind() === self::KIND_ROOT;
    }

    public static function isSite(): bool
    {
        return self::kind() === self::KIND_SITE;
    }

    public static function isOps(): bool
    {
        return self::kind() === self::KIND_OPS;
    }

    /** The host this request arrived on, normalised. */
    public static function host(): ?string
    {
        self::resolve();
        return self::$host;
    }

    /**
     * Refuse a signed-in user whose clinic is not the host's clinic.
     *
     * This is the replay guard. A session cookie issued for serenity's ops host
     * is still a valid session record in the shared database; what stops it
     * working on lakeview's host is this check, not the cookie's scope. Both
     * exist deliberately — the cookie scope cannot be relied on behind proxies
     * that rewrite Host, and this check cannot be relied on if a route forgets
     * to run.
     *
     * Platform (super admin) users have no clinic and are unaffected; they
     * belong to the root host, which never reaches here with a clinic context.
     */
    public static function bind(?int $userId, ?int $userClinicId): bool
    {
        if ($userId === null) {
            return true; // not signed in: nothing to bind
        }
        $tenantClinic = self::clinicId();
        if ($tenantClinic === null) {
            return true; // platform host
        }
        return $userClinicId !== null && (int) $userClinicId === $tenantClinic;
    }

    /** Absolute base URL for the clinic's public site, for outbound links. */
    public static function siteUrl(array $clinic): string
    {
        $scheme = self::scheme();
        $host = (string) ($clinic['site_host'] ?? '');
        if ($host === '') {
            return rtrim((string) Env::get('app.url', ''), '/');
        }
        return $scheme . '://' . $host;
    }

    /**
     * The platform's own console address.
     *
     * Deliberately separate from siteUrl()/opsUrl(): those answer "where does
     * this clinic live", and this answers "where does the operator live". A
     * platform alert must point at the console, and for a platform admin there
     * is no clinic to ask about — the test that forbids `APP_URL . path`
     * everywhere else is right, but a rule with no sanctioned exception just
     * gets worked around.
     */
    public static function platformUrl(string $path = ''): string
    {
        $base = rtrim((string) Env::get('app.url', ''), '/');
        if ($base === '') {
            return $path === '' ? '' : '/' . ltrim($path, '/');
        }
        return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /** Absolute base URL for the clinic's staff backend. */
    public static function opsUrl(array $clinic): string
    {
        $scheme = self::scheme();
        $host = (string) ($clinic['ops_host'] ?? '');
        if ($host === '') {
            return rtrim((string) Env::get('app.url', ''), '/');
        }
        return $scheme . '://' . $host;
    }

    private static function scheme(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return $https ? 'https' : 'http';
    }

    /** Test seam: forget the resolved tenant. */
    public static function reset(): void
    {
        self::$clinic = null;
        self::$kind = self::KIND_ROOT;
        self::$host = null;
        self::$resolved = false;
    }
}
