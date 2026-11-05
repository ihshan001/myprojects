<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Durable, shared rate limiter.
 *
 * Why a table and not APCu/session:
 *   - The display key is a bearer credential on a public URL. An attacker
 *     gets unlimited attempts from a fresh cookie jar otherwise, so keying on
 *     the session is useless.
 *   - A restart must not reset an attack window, so it cannot live in memory.
 *   - Multiple PHP-FPM workers must share the counter, so it cannot be a
 *     per-process static.
 *
 * The counter is bucketed to a fixed window. That is deliberate: it is the
 * simplest thing that is correct under concurrency, and sliding-window
 * precision buys nothing when the limits are tens of requests per minute.
 *
 * Fixed windows admit a burst of up to 2x the limit across a boundary. For
 * credential guessing that is irrelevant (the attacker gains one extra
 * window); it would matter for a billing meter, which is not what this is.
 */
final class RateLimiter
{
    /**
     * Consume one hit for $bucket.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int, hits: int}
     */
    public static function hit(string $bucket, int $limit, int $windowSeconds): array
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $window = intdiv(time(), $windowSeconds);

        // Atomic upsert. Both drivers support this form; the duplicate branch
        // increments in place so two racing requests cannot both read 0.
        $driver = Env::get('db.driver');
        if ($driver === 'mysql') {
            Db::run(
                'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1',
                [$bucket, $window]
            );
        } else {
            Db::run(
                'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
                 ON CONFLICT (bucket, window_start) DO UPDATE SET hits = hits + 1',
                [$bucket, $window]
            );
        }

        $hits = (int) Db::scalar(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?',
            [$bucket, $window]
        );

        return [
            'allowed'     => $hits <= $limit,
            'remaining'   => max(0, $limit - $hits),
            'retry_after' => ($window + 1) * $windowSeconds - time(),
            'hits'        => $hits,
        ];
    }

    /** Read-only check; does not consume a hit. */
    public static function peek(string $bucket, int $windowSeconds): int
    {
        $window = intdiv(time(), max(1, $windowSeconds));
        return (int) Db::scalar(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?',
            [$bucket, $window]
        );
    }

    /**
     * Throw a 429 rather than returning a bool: every caller that exceeds a
     * limit wants exactly the same response, and forgetting to handle `false`
     * is the classic way a limiter silently stops limiting.
     */
    public static function enforce(string $bucket, int $limit, int $windowSeconds): array
    {
        $result = self::hit($bucket, $limit, $windowSeconds);
        if (!$result['allowed']) {
            Log::warn('rate_limit.exceeded', [
                'bucket' => self::redact($bucket),
                'hits'   => $result['hits'],
                'limit'  => $limit,
            ]);
            $retry = max(1, $result['retry_after']);
            header('Retry-After: ' . $retry);
            if (str_starts_with(Request::path(), '/api/') || Request::wantsJson()) {
                json(['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry], 429);
                // json() only *writes* the response — it does not stop the
                // request. Without this exit the calling controller would
                // happily continue and overwrite the 429 with a 200, which is
                // exactly the bug this limiter exists to prevent.
                exit;
            }
            abort(429, 'Too many requests. Please wait ' . $retry . ' seconds and try again.');
        }
        return $result;
    }

    /**
     * Buckets end up in logs and in the table. A bucket built from a display
     * key or a tracking token would put a live credential somewhere it can be
     * read later, so the secret part is hashed before it ever gets stored.
     */
    public static function redact(string $bucket): string
    {
        $parts = explode(':', $bucket);
        foreach ($parts as $i => $part) {
            if ($i === 0) {
                continue;
            }
            if (strlen($part) > 20 && preg_match('/^[A-Za-z0-9_-]+$/', $part)) {
                $parts[$i] = 'sha256.' . substr(hash('sha256', $part), 0, 16);
            }
        }
        return implode(':', $parts);
    }

    /** Drop windows that can no longer matter. Called by the maintenance tool. */
    public static function prune(int $olderThanSeconds = 86400): int
    {
        return Db::run(
            'DELETE FROM rate_limits WHERE window_start < ?',
            [intdiv(time() - $olderThanSeconds, 60)]
        );
    }
}
