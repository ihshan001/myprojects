<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Command-line arguments, from wherever they arrive.
 *
 * Under a normal PHP CLI binary, `$argv` is a global created by the `register_argc_argv`
 * ini setting. The php-wasm harness this project uses to run PHP does not create
 * it — it only populates `$_SERVER['argv']`.
 *
 * That difference had a real consequence: every tool that read `$argv` saw an
 * empty list, so `--yes` and `--today` were silently ignored. A purge tool that
 * cannot be told to purge looks exactly like a purge tool nobody has finished.
 *
 * One helper, checked in this order, so a tool behaves the same in both
 * environments and nobody has to remember which one they are in.
 */
final class Argv
{
    /** @return list<string> */
    public static function all(): array
    {
        if (isset($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
            return array_values(array_map('strval', $GLOBALS['argv']));
        }
        if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            return array_values(array_map('strval', $_SERVER['argv']));
        }
        return [];
    }

    public static function has(string $flag): bool
    {
        return in_array($flag, self::all(), true);
    }

    /** The value after a flag, or null when the flag is absent or last. */
    public static function value(string $flag, ?string $default = null): ?string
    {
        $args = self::all();
        foreach ($args as $i => $arg) {
            if ($arg === $flag) {
                return $args[$i + 1] ?? $default;
            }
        }
        return $default;
    }
}
