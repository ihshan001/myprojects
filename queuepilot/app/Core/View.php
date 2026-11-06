<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], bool $asLayout = true): string
    {
        $data = array_merge(self::$shared, $data);
        $content = self::capture(QP_ROOT . '/resources/views/pages/' . $template . '.php', $data);
        if (!$asLayout) {
            return $content;
        }
        $data['content'] = $content;
        $data['pageTemplate'] = $template;
        return self::capture(QP_ROOT . '/resources/views/layouts/app.php', $data);
    }

    public static function partial(string $name, array $data = []): string
    {
        return self::capture(QP_ROOT . '/resources/views/partials/' . $name . '.php', array_merge(self::$shared, $data));
    }

    public static function component(string $name, array $data = []): string
    {
        return self::capture(QP_ROOT . '/resources/views/components/' . $name . '.php', array_merge(self::$shared, $data));
    }

    private static function capture(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $file);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
