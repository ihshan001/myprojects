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
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return self::rebase($html);
    }

    /**
     * Add the mount prefix to root-relative URLs, when the app is not at the root.
     *
     * The views are written with `href="/login"` and `action="/queue/new"` —
     * about 290 of them — because that is what a developer means, and it is
     * correct whenever the document root is `public/`. On an install served
     * from a subdirectory (http://localhost/clinicqueue) those URLs point at
     * whatever else lives on that host, which on XAMPP is the dashboard: every
     * link and every form on every page is wrong, and the whole application
     * looks broken while returning 200.
     *
     * Rewriting here rather than in 290 call sites means the views keep saying
     * what they mean, and the mount point is handled in the one place all output
     * passes through. On a root install base_path() is '' and this returns the
     * string untouched — the regex is not even built.
     *
     * Only attribute contexts are rewritten (href, src, action, formaction,
     * poster, data-*), never body text, so a page that documents a URL in prose
     * still reads correctly.
     */
    public static function rebase(string $html): string
    {
        $base = base_path();
        if ($base === '' || !str_contains($html, '="')) {
            return $html;
        }

        static $pattern = null;
        static $lastBase = null;
        if ($pattern === null || $lastBase !== $base) {
            $quoted = preg_quote($base, '/');
            // name="/x"   -> name="/base/x"       (but not name="//host", and not
            //                                        name="/base/..." which is done)
            $pattern = '/(\b(?:href|src|action|formaction|poster|data-[a-z0-9-]+)\s*=\s*")(?!' .
                $quoted . '\/)(\/(?!\/)[^"]*)"/i';
            $lastBase = $base;
        }

        $out = preg_replace_callback($pattern, static function (array $m) use ($base): string {
            $path = $m[2];
            // Exactly the mount point, or already carrying it.
            if ($path === $base || str_starts_with($path, $base . '/')) {
                return $m[1] . $path . '"';
            }
            return $m[1] . $base . $path . '"';
        }, $html);

        return $out ?? $html;
    }
}
