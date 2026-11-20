<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * The `settings` key/value table, written once instead of eleven times.
 *
 * Why this class exists. Four call sites wrote a setting with
 *
 *     INSERT ... ON CONFLICT (scope, owner_id, key_name) DO UPDATE ...
 *
 * which is SQLite's upsert syntax. MySQL does not have it — `ON CONFLICT` is a
 * syntax error there, not a no-op — so on the host that actually matters,
 * saving opening hours, generating a department's display key, saving queue
 * settings and saving slot settings would all have thrown `SQLSTATE[42000]`.
 * The test suite runs SQLite, so nothing caught it. Two other places had worked
 * around the same problem by hand with a read-then-write, and one of them left
 * a comment explaining that the duplication was the price of supporting both
 * engines.
 *
 * It is not a price worth paying four more times. This is the single place that
 * knows how to write a setting, so a new caller cannot reintroduce the bug by
 * copying a neighbouring UPDATE.
 *
 * `settings` carries `UNIQUE KEY uq_setting (scope, owner_id, key_name)` in both
 * dialects, so the two branches below are equivalent:
 *
 *   SQLite:  INSERT ... ON CONFLICT (scope, owner_id, key_name) DO UPDATE
 *   MySQL:   INSERT ... ON DUPLICATE KEY UPDATE value = VALUES(value)
 *
 * MySQL 8.0.20 deprecates `VALUES()` in favour of a row alias, but the old form
 * is still accepted in every 8.x and in MariaDB, which is what XAMPP ships. A
 * deprecated-but-working form is the right choice for a file that has to run on
 * servers the author cannot see.
 */
final class Settings
{
    // @dialect-check: dual-dialect-guarded — this class exists *because* the two
    // engines disagree about upsert syntax; put() chooses by Db::isMysql().
    /**
     * Write a setting, inserting it or replacing the existing value.
     *
     * `owner_id` may be NULL for platform-wide settings, and **neither engine
     * treats NULL as equal to NULL in a unique index**. So `ON DUPLICATE KEY`
     * and `ON CONFLICT` both fail to match an existing row whose `owner_id` is
     * NULL: writing the same platform setting twice leaves two rows, and
     * whichever one a later SELECT happens to return wins. That is a
     * read-your-own-writes bug that only shows up intermittently, so the NULL
     * case takes an explicit read-then-write path instead of an upsert.
     *
     * @param  string   $scope   'clinic' or 'platform'
     * @param  int|null $ownerId clinic id for clinic scope, null for platform
     */
    public static function put(string $scope, ?int $ownerId, string $key, ?string $value): void
    {
        if ($ownerId === null) {
            $exists = Db::scalar(
                'SELECT id FROM settings WHERE scope = ? AND owner_id IS NULL AND key_name = ?',
                [$scope, $key]
            );

            if ($exists) {
                Db::run(
                    'UPDATE settings SET value = ? WHERE id = ?',
                    [$value, (int) $exists]
                );
                return;
            }

            Db::run(
                'INSERT INTO settings (scope, owner_id, key_name, value) VALUES (?, NULL, ?, ?)',
                [$scope, $key, $value]
            );
            return;
        }

        $sql = Db::isMysql()
            ? "INSERT INTO settings (scope, owner_id, key_name, value) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)"
            : "INSERT INTO settings (scope, owner_id, key_name, value) VALUES (?, ?, ?, ?)
                 ON CONFLICT (scope, owner_id, key_name) DO UPDATE SET value = excluded.value";

        Db::run($sql, [$scope, $ownerId, $key, $value]);
    }

    /** Write a clinic-scoped setting — the common case. */
    public static function putClinic(int $clinicId, string $key, ?string $value): void
    {
        self::put('clinic', $clinicId, $key, $value);
    }

    /**
     * Read one setting, or $default when it has never been written.
     *
     * `owner_id IS NULL` needs its own comparison: `owner_id = NULL` matches
     * nothing in either engine, so a platform setting written by put() would
     * never be found again by get().
     */
    public static function get(string $scope, ?int $ownerId, string $key, ?string $default = null): ?string
    {
        $value = $ownerId === null
            ? Db::scalar(
                'SELECT value FROM settings WHERE scope = ? AND owner_id IS NULL AND key_name = ?',
                [$scope, $key]
            )
            : Db::scalar(
                'SELECT value FROM settings WHERE scope = ? AND owner_id = ? AND key_name = ?',
                [$scope, $ownerId, $key]
            );

        return $value === null || $value === false ? $default : (string) $value;
    }

    /** Read a clinic-scoped setting. */
    public static function getClinic(int $clinicId, string $key, ?string $default = null): ?string
    {
        return self::get('clinic', $clinicId, $key, $default);
    }

    /** Remove a setting entirely. Same NULL caveat as get(). */
    public static function forget(string $scope, ?int $ownerId, string $key): void
    {
        if ($ownerId === null) {
            Db::run(
                'DELETE FROM settings WHERE scope = ? AND owner_id IS NULL AND key_name = ?',
                [$scope, $key]
            );
            return;
        }

        Db::run(
            'DELETE FROM settings WHERE scope = ? AND owner_id = ? AND key_name = ?',
            [$scope, $ownerId, $key]
        );
    }
}
