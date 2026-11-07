<?php
declare(strict_types=1);

/**
 * Tiny assertion harness.
 *
 * The environment has no Composer/PHPUnit (Packagist is unreachable), so this
 * is a deliberately small, readable harness rather than a half-built clone of
 * one. It runs against a throw-away SQLite database so tests never touch the
 * demo data.
 */

namespace Tests {

    final class Assert
    {
        public static int $passed = 0;
        /** @var array<int, string> */
        public static array $failures = [];
        public static string $current = '';

        public static function test(string $name, callable $fn): void
        {
            self::$current = $name;
            $before = count(self::$failures);
            try {
                $fn();
            } catch (\Throwable $e) {
                self::$failures[] = sprintf('%s → threw %s: %s', $name, get_class($e), $e->getMessage());
            }
            if (count(self::$failures) === $before) {
                self::$passed++;
                echo "  \033[32m✓\033[0m {$name}\n";
            } else {
                echo "  \033[31m✗\033[0m {$name}\n";
            }
        }

        public static function true(bool $cond, string $why = ''): void
        {
            if (!$cond) {
                self::$failures[] = self::$current . ' → expected true' . ($why !== '' ? ": {$why}" : '');
            }
        }

        public static function false(bool $cond, string $why = ''): void
        {
            self::true(!$cond, $why);
        }

        public static function same(mixed $expected, mixed $actual, string $why = ''): void
        {
            if ($expected !== $actual) {
                self::$failures[] = sprintf(
                    '%s → expected %s, got %s%s',
                    self::$current,
                    var_export($expected, true),
                    var_export($actual, true),
                    $why !== '' ? " ({$why})" : ''
                );
            }
        }

        public static function contains(string $needle, string $haystack, string $why = ''): void
        {
            if (!str_contains($haystack, $needle)) {
                self::$failures[] = self::$current . " → expected to contain \"{$needle}\"" . ($why !== '' ? ": {$why}" : '');
            }
        }

        public static function throws(callable $fn, string $class, string $why = ''): void
        {
            try {
                $fn();
            } catch (\Throwable $e) {
                if ($e instanceof $class) {
                    return;
                }
                self::$failures[] = self::$current . " → expected {$class}, got " . get_class($e);
                return;
            }
            self::$failures[] = self::$current . " → expected {$class}, nothing thrown" . ($why !== '' ? ": {$why}" : '');
        }

        public static function summary(): int
        {
            $total = self::$passed + count(self::$failures);
            echo "\n", str_repeat('─', 62), "\n";
            if (self::$failures === []) {
                echo " \033[32mAll {$total} checks passed.\033[0m\n";
                return 0;
            }
            echo " \033[31m" . count(self::$failures) . " of {$total} checks failed:\033[0m\n";
            foreach (self::$failures as $f) {
                echo "   • {$f}\n";
            }
            return 1;
        }
    }

    /**
     * Sqlite has no separate per-test database, so the suite builds one
     * throw-away file and deletes it afterwards.
     */
    final class Database
    {
        public static function fresh(): string
        {
            $path = sys_get_temp_dir() . '/queuepilot-test-' . getmypid() . '.sqlite';
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path . $suffix);
            }
            $schema = QP_ROOT . '/database/schema/sqlite.sql';
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec(file_get_contents($schema));
            // apply dialect migrations so the suite runs against the same
            // constraints production will have
            foreach (glob(QP_ROOT . '/database/migrations/*.sqlite.sql') ?: [] as $migration) {
                $pdo->exec(file_get_contents($migration));
            }
            $pdo = null;

            \App\Core\Db::init(['driver' => 'sqlite', 'sqlite' => $path]);
            \App\Core\Db::reset();
            return $path;
        }

        public static function cleanup(string $path): void
        {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path . $suffix);
            }
        }
    }
}
