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

// App\Support\Argv, not $argv. The php-wasm harness this project runs PHP on
// does not populate $argv at all — it only sets $_SERVER['argv'] — so reading
// $argv directly made `--loop` silently do nothing: the worker ran one pass
// and exited. On a cron-scheduled host that is fine by accident; for anybody
// running this under a supervisor it looked like the worker kept dying.
$loop = App\Support\Argv::has('--loop') ? (int) (App\Support\Argv::value('--loop') ?? 30) : null;

$service = new NotificationService();

do {
    // "Turn approaching" is generated here rather than in the request cycle:
    // a receptionist clicking Call must not wait on SMS work, and the message
    // only makes sense against the current line order.
    $approaching = 0;
    foreach (App\Core\Db::all("SELECT id FROM queues WHERE session_date = ? AND status <> 'closed'", [App\Core\Db::today()]) as $q) {
        $clinicId = (int) App\Core\Db::scalar('SELECT clinic_id FROM queues WHERE id = ?', [(int) $q['id']]);
        $approaching += $service->notifyApproaching($clinicId, (int) $q['id']);
    }

    // Appointment reminders for tomorrow. Idempotent through the dedupe key,
    // so running this on every pass costs one indexed lookup per appointment
    // and never sends a second message.
    $reminders = $service->notifyAppointmentReminders();

    $result = $service->processDue(50);
    $stamp = date('Y-m-d H:i:s');
    if ($approaching > 0) {
        echo "[{$stamp}] queued {$approaching} approaching notice(s)\n";
    }
    if ($reminders > 0) {
        echo "[{$stamp}] queued {$reminders} appointment reminder(s) for tomorrow\n";
    }
    if ($result['processed'] === 0) {
        echo "[{$stamp}] queue empty\n";
    } else {
        echo "[{$stamp}] processed {$result['processed']}, sent {$result['sent']}, failed {$result['failed']}\n";
    }
    if ($loop !== null) {
        sleep(max(5, $loop));
    }
} while ($loop !== null);
