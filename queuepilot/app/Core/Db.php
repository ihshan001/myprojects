<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Throwable;

/**
 * Thin PDO wrapper. One connection per request, prepared statements only,
 * explicit transactions for every queue mutation.
 *
 * Driver switch: DB_DRIVER=mysql uses the identical SQL written in ANSI form
 * (see database/schema/mysql.sql) — no query forks in application code.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static array $config = [];
    private static int $txDepth = 0;

    // The PRAGMAs below run only when the driver is SQLite, which is why this
    // file is allowed to mention them.
    // @dialect-check: dual-dialect-guarded
    public static function init(array $config): void
    {
        self::$config = $config;
    }

    /** Drop the connection (used between test databases and after a reset). */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$txDepth = 0;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = self::$config['driver'] ?? 'sqlite';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        try {
            if ($driver === 'mysql') {
                $c = self::$config;
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $c['host'], $c['port'], $c['database'], $c['charset']
                );
                $pdo = new PDO($dsn, $c['username'], $c['password'], $options);
                $pdo->exec("SET time_zone = '+05:30'");
            } else {
                $path = self::$config['sqlite'];
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->exec('PRAGMA busy_timeout = 5000');
                $pdo->exec('PRAGMA synchronous = NORMAL');
            }
        } catch (PDOException $e) {
            Log::error('db.connect.failed', ['driver' => $driver, 'error' => $e->getMessage()]);
            throw $e;
        }

        self::$pdo = $pdo;
        return $pdo;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    public static function exec(string $sql): int
    {
        return (int) self::pdo()->exec($sql);
    }

    /**
     * Transaction with automatic rollback. Nested calls become savepoints so
     * higher-level services can compose safely.
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $isNested = self::$txDepth > 0;
        $savepoint = 'sp_' . self::$txDepth;

        try {
            if ($isNested) {
                $pdo->exec("SAVEPOINT {$savepoint}");
            } else {
                $pdo->beginTransaction();
            }
            self::$txDepth++;

            $result = $work();

            self::$txDepth--;
            if ($isNested) {
                $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            self::$txDepth = max(0, self::$txDepth - 1);
            try {
                if ($isNested) {
                    $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // connection already unwound — nothing else to do
            }
            throw $e;
        }
    }

    /** Which engine is connected: 'mysql' or 'sqlite'. */
    public static function driver(): string
    {
        return (string) (self::$config['driver'] ?? 'sqlite');
    }

    public static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    /**
     * Does this table exist?
     *
     * Two engines, two catalogues. SQLite keeps its schema in sqlite_master;
     * MySQL keeps it in information_schema. Asking the wrong one is not a
     * warning, it is a fatal error, so the question has to go through here
     * rather than being written inline wherever it is needed.
     */
    public static function tableExists(string $table): bool
    {
        if (self::isMysql()) {
            return (int) self::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            ) > 0;
        }
        return self::one("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== null;
    }

    /**
     * The column names of a table, in definition order.
     *
     * @return list<string>
     */
    public static function columns(string $table): array
    {
        if (self::isMysql()) {
            $rows = self::all(
                'SELECT column_name AS name FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ?
                  ORDER BY ordinal_position',
                [$table]
            );
            return array_map(static fn (array $r): string => (string) $r['name'], $rows);
        }
        $rows = self::all('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }
}
