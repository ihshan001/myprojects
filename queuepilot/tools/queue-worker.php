<?php
declare(strict_types=1);

/**
 * Notification worker.
 *
 * Run on a schedule (cron) or as a long-lived loop:
 *   php tools/queue-worker.php            # one pass
 *   php tools/queue-worker.php --loop 30   # every 30 s
 *
 * On Laravel this maps to `php artisan queue:work`; the outbox table and
 * idempotency key are the same design either way.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\NotificationService;

$loop = null;
foreach ($argv ?? [] as $i => $arg) {
    if ($arg === '--loop') {
        $loop = (int) ($argv[$i + 1] ?? 30);
    }
}

$service = new NotificationService();

do {
    $result = $service->processDue(50);
    $stamp = date('Y-m-d H:i:s');
    if ($result['processed'] === 0) {
        echo "[{$stamp}] queue empty\n";
    } else {
        echo "[{$stamp}] processed {$result['processed']}, sent {$result['sent']}, failed {$result['failed']}\n";
    }
    if ($loop !== null) {
        sleep(max(5, $loop));
    }
} while ($loop !== null);
