<?php
declare(strict_types=1);

namespace App\Core;

final class Lang
{
    private static array $strings = [];

    public static function load(string $locale): void
    {
        $locale = in_array($locale, ['en', 'si', 'ta'], true) ? $locale : 'en';
        $file = QP_ROOT . '/resources/lang/' . $locale . '.php';
        self::$strings = is_file($file) ? require $file : [];
    }

    public static function get(string $key, string $fallback = ''): string
    {
        return self::$strings[$key] ?? ($fallback !== '' ? $fallback : $key);
    }
}
