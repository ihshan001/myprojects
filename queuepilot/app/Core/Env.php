<?php
declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static array $config = [];

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $value = self::$config;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    public static function debug(): bool
    {
        return (bool) self::get('app.debug', false);
    }

    public static function isSqlite(): bool
    {
        return self::get('db.driver', 'sqlite') === 'sqlite';
    }
}
