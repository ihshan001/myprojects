<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Env;
use App\Core\Log;

/**
 * Notification architecture (outbox pattern).
 *
 * Enqueue inside the same transaction as the queue mutation, so a message is
 * never lost if the worker is down and never sent twice for one event.
 * `dedupe_key` is UNIQUE — that is the idempotency guard.
 *
 * Delivery is pluggable: SmsGateway implements the provider call. Without
 * credentials the worker marks messages 'simulated' so the whole pipeline stays
 * observable in development. SMS/WhatsApp usage is metered separately from the
 * subscription (see invoices / usage), per the billing rules.
 */
final class NotificationService
{
    /** Templates are intentionally short: gateways charge per 160-char SMS. */
    public const TEMPLATES = [
        'token_issued'   => 'You are {token} in the {queue} queue at {clinic}. Track live: {link}',
        'entry_called'   => 'Your turn is now. Please go to {room}. Token {token} — {clinic}.',
        'visit_completed'=> 'Thank you for visiting {clinic}. Token {token} is complete. Get well soon.',
        'appointment_reminder' => 'Reminder: appointment at {clinic} on {when}. Reply to reschedule.',
    ];

    public function queue(int $clinicId, int $entryId, string $template, array $vars = []): void
    {
        if (!isset(self::TEMPLATES[$template])) {
            return;
        }
        $entry = Db::one(
            'SELECT e.id, e.token_label, p.phone, c.name AS clinic_name, q.name AS queue_name
               FROM queue_entries e
               JOIN patients p ON p.id = e.patient_id
               JOIN clinics c ON c.id = e.clinic_id
               JOIN queues q ON q.id = e.queue_id
              WHERE e.id = ? AND e.clinic_id = ?',
            [$entryId, $clinicId]
        );
        if (!$entry || empty($entry['phone'])) {
            return; // no consent-able number on file → in-app/link only
        }

        $dedupe = $template . ':' . $entryId;
        $exists = Db::scalar('SELECT id FROM notifications WHERE dedupe_key = ?', [$dedupe]);
        if ($exists) {
            return; // already enqueued for this event
        }

        $link = rtrim((string) Env::get('app.url', ''), '/') . '/t/' . (string) Db::scalar(
            'SELECT track_token FROM queue_entries WHERE id = ?', [$entryId]
        );

        $body = strtr(self::TEMPLATES[$template], [
            '{token}'  => (string) $entry['token_label'],
            '{queue}'  => (string) $entry['queue_name'],
            '{clinic}' => (string) $entry['clinic_name'],
            '{room}'   => (string) ($vars['room'] ?? 'the consultation room'),
            '{when}'   => (string) ($vars['when'] ?? ''),
            '{link}'   => $link,
        ]);

        try {
            Db::insert(
                'INSERT INTO notifications (clinic_id, entry_id, channel, template, to_number, payload_json,
                    dedupe_key, status, attempts, scheduled_at, created_at)
                 VALUES (?,?,?,?,?,?,?,?,0,?,?)',
                [
                    $clinicId, $entryId, 'sms', $template, $entry['phone'],
                    json_encode(['body' => $body, 'vars' => $vars], JSON_UNESCAPED_UNICODE),
                    $dedupe, 'queued', Db::now(), Db::now(),
                ]
            );
        } catch (\PDOException $e) {
            // unique violation = duplicate suppressed, which is the goal
            if (!str_contains($e->getMessage(), 'UNIQUE') && !str_contains($e->getMessage(), 'Duplicate')) {
                throw $e;
            }
        }
    }

    /** Called by the worker (tools/queue-worker.php). Returns counts. */
    public function processDue(int $limit = 50): array
    {
        $due = Db::all(
            "SELECT * FROM notifications WHERE status = 'queued' AND scheduled_at <= ? ORDER BY id LIMIT " . max(1, $limit),
            [Db::now()]
        );

        $sent = 0;
        $failed = 0;
        foreach ($due as $job) {
            $attempts = (int) $job['attempts'] + 1;
            $payload = json_decode((string) $job['payload_json'], true) ?: [];
            $body = $payload['body'] ?? '';

            try {
                $result = $this->gateway($job['channel'])->send((string) $job['to_number'], $body);
                if ($result === 'sent') {
                    Db::run(
                        "UPDATE notifications SET status='sent', attempts=?, sent_at=? WHERE id=?",
                        [$attempts, Db::now(), $job['id']]
                    );
                    $sent++;
                } else {
                    // The gateway accepted the job but no provider is wired up.
                    // Marking this "sent" would tell the clinic a patient was
                    // notified when nothing left the building, so it stays
                    // queued with an explicit reason and backs off like a
                    // failure. Billing/usage meters only count real sends.
                    $status = $attempts >= 3 ? 'abandoned' : 'queued';
                    Db::run(
                        'UPDATE notifications SET status=?, attempts=?, last_error=?, scheduled_at=? WHERE id=?',
                        [$status, $attempts, 'no gateway provider configured', date('Y-m-d H:i:s', time() + 60 * (2 ** $attempts)), $job['id']]
                    );
                    $failed++;
                }
            } catch (\Throwable $e) {
                $status = $attempts >= 3 ? 'abandoned' : 'queued';
                $retryAt = date('Y-m-d H:i:s', time() + 60 * (2 ** $attempts));
                Db::run(
                    'UPDATE notifications SET status=?, attempts=?, last_error=?, scheduled_at=? WHERE id=?',
                    [$status, $attempts, mb_substr($e->getMessage(), 0, 290), $retryAt, $job['id']]
                );
                $failed++;
                Log::warn('notification.send.failed', ['id' => $job['id'], 'error' => $e->getMessage()]);
            }
        }

        return ['processed' => count($due), 'sent' => $sent, 'failed' => $failed];
    }

    private function gateway(string $channel): Gateway
    {
        return match ($channel) {
            'whatsapp' => new WhatsAppGateway(),
            default    => new SmsGateway(),
        };
    }

    public function stats(int $clinicId): array
    {
        return Db::one(
            "SELECT
                SUM(CASE WHEN status='queued' THEN 1 ELSE 0 END) AS queued,
                SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status IN ('failed','abandoned') THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS total
             FROM notifications WHERE clinic_id = ?",
            [$clinicId]
        ) ?? ['queued' => 0, 'sent' => 0, 'failed' => 0, 'total' => 0];
    }
}
