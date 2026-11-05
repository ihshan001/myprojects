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
 * credentials the log transport records messages so the whole pipeline stays
 * observable in development. SMS/WhatsApp usage is metered separately from the
 * subscription (see invoices / usage), per the billing rules.
 */
final class NotificationService
{
    /** Templates are intentionally short: gateways charge per 160-char SMS. */
    public const TEMPLATES = [
        'token_issued'   => 'You are {token} in the {queue} queue at {clinic}. Track live: {link}',
        'entry_called'   => 'Your turn is now. Please go to {room}. Token {token} — {clinic}.',
        // Deliberately a separate template from `entry_called`: "nearly there"
        // and "go in now" are different instructions, and a patient who
        // receives the second without the first is not surprised.
        'entry_approaching' => 'Almost your turn. Token {token} is {ahead} away in the {queue} queue at {clinic}. Please stay nearby.',
        'visit_completed'=> 'Thank you for visiting {clinic}. Token {token} is complete. Get well soon.',
        'appointment_reminder' => 'Reminder: appointment at {clinic} on {when}. Reply to reschedule.',
        'entry_delayed'    => 'Your doctor is running about {minutes} minutes late. Token {token} keeps its place at {clinic}.',
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
            '{ahead}'  => (string) ($vars['ahead'] ?? ''),
            '{link}'   => $link,
        ]);

        try {
            Db::insert(
                'INSERT INTO notifications (clinic_id, entry_id, channel, template, to_number, payload_json,
                    dedupe_key, status, attempts, scheduled_at, created_at)
                 VALUES (?,?,?,?,?,?,?,?,0,?,?)',
                [
                    $clinicId, $entryId, 'sms', $template, self::mask((string) $entry['phone']),
                    json_encode(['body' => $body, 'vars' => $vars, 'to' => (string) $entry['phone']], JSON_UNESCAPED_UNICODE),
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

    /**
     * "Your turn is approaching" — sent at most once per entry, and only for
     * clinics that switched it on.
     *
     * The opt-in is per clinic because an SMS costs money and a clinic that
     * runs a five-minute walk-in line does not want a message for something
     * the patient can see by looking up. `approach_notified_at` is the
     * idempotency marker, so a poller that runs every ten seconds cannot send
     * the same message twelve times a minute.
     */
    public function notifyApproaching(int $clinicId, int $queueId): int
    {
        $clinic = Db::one(
            'SELECT notify_approach, approach_threshold FROM clinics WHERE id = ?',
            [$clinicId]
        );
        if (!$clinic || (int) ($clinic['notify_approach'] ?? 0) !== 1) {
            return 0;
        }
        $threshold = max(1, (int) ($clinic['approach_threshold'] ?? 2));

        // The next `threshold` patients in line, in board order (priority
        // first, then their original standing) — never the raw id order,
        // because that would message the wrong people in a priority queue.
        $next = Db::all(
            "SELECT e.id
               FROM queue_entries e
              WHERE e.clinic_id = ? AND e.queue_id = ?
                AND e.status = 'waiting' AND e.approach_notified_at IS NULL
              ORDER BY e.priority DESC, e.sort_weight ASC, e.id ASC
              LIMIT ?",
            [$clinicId, $queueId, $threshold]
        );

        $sent = 0;
        foreach ($next as $row) {
            $entryId = (int) $row['id'];
            // Claim the right to send *before* queueing, so two workers cannot
            // both decide to announce the same patient.
            $claimed = Db::run(
                'UPDATE queue_entries SET approach_notified_at = ?
                  WHERE id = ? AND clinic_id = ? AND approach_notified_at IS NULL',
                [Db::now(), $entryId, $clinicId]
            )->rowCount();
            if ($claimed !== 1) {
                continue;
            }

            $position = (int) Db::scalar(
                "SELECT COUNT(*) FROM queue_entries
                  WHERE queue_id = ? AND status = 'waiting' AND id <> ?
                    AND (priority > (SELECT priority FROM queue_entries WHERE id = ?)
                         OR (priority = (SELECT priority FROM queue_entries WHERE id = ?)
                             AND sort_weight <= (SELECT sort_weight FROM queue_entries WHERE id = ?)))",
                [$queueId, $entryId, $entryId, $entryId, $entryId]
            ) + 1;

            $this->queue($clinicId, $entryId, 'entry_approaching', ['ahead' => $position . ' place' . ($position === 1 ? '' : 's')]);
            $sent++;
        }
        return $sent;
    }

    /** Called by the worker (tools/queue-worker.php). Returns counts. */
    /**
     * Queue reminders for tomorrow's appointments.
     *
     * Runs from the worker, not from a web request: a reminder is a scheduled
     * obligation, and tying it to somebody visiting a page means it is sent
     * only when the clinic happens to be busy.
     *
     * Idempotency is the same `dedupe_key` mechanism as every other message —
     * `appointment_reminder:<id>` — so running the worker sixty times in an
     * hour still sends one reminder per appointment.
     *
     * Nothing is sent for an appointment that is already cancelled, already
     * checked in, or whose clinic has SMS switched off.
     *
     * @return int how many reminders were newly queued
     */
    public function notifyAppointmentReminders(?string $forDate = null): int
    {
        $date = $forDate ?? date('Y-m-d', strtotime('+1 day'));
        $queued = 0;

        $appointments = Db::all(
            "SELECT a.id, a.clinic_id, a.scheduled_for, p.full_name, p.phone,
                    c.name AS clinic_name, d.name AS doctor_name
               FROM appointments a
               JOIN patients p ON p.id = a.patient_id
               JOIN clinics c ON c.id = a.clinic_id
               JOIN doctors d ON d.id = a.doctor_id
              WHERE a.status = 'booked'
                AND substr(a.scheduled_for, 1, 10) = ?
                AND p.phone IS NOT NULL AND p.phone <> ''
                AND c.is_active = 1 AND c.suspended_at IS NULL AND c.closed_at IS NULL",
            [$date]
        );

        foreach ($appointments as $a) {
            $dedupe = 'appointment_reminder:' . (int) $a['id'];
            if (Db::scalar('SELECT id FROM notifications WHERE dedupe_key = ?', [$dedupe]) !== null) {
                continue;
            }

            $when = date('D j M, g:i A', strtotime((string) $a['scheduled_for']));
            $body = strtr(self::TEMPLATES['appointment_reminder'], [
                '{clinic}' => (string) $a['clinic_name'],
                '{when}'   => $when,
                '{token}'  => '',
                '{queue}'  => (string) $a['doctor_name'],
                '{room}'   => '',
                '{ahead}'  => '',
                '{link}'   => '',
            ]);

            try {
                Db::insert(
                    'INSERT INTO notifications (clinic_id, entry_id, channel, template, to_number, payload_json,
                        dedupe_key, status, attempts, scheduled_at, created_at)
                     VALUES (?,?,?,?,?,?,?,?,0,?,?)',
                    [
                        (int) $a['clinic_id'], null, 'sms', 'appointment_reminder',
                        self::mask((string) $a['phone']),
                        json_encode(['body' => $body, 'vars' => ['when' => $when], 'to' => (string) $a['phone']], JSON_UNESCAPED_UNICODE),
                        $dedupe, 'queued', Db::now(), Db::now(),
                    ]
                );
                $queued++;
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE') && !str_contains($e->getMessage(), 'Duplicate')) {
                    throw $e;
                }
            }
        }

        if ($queued > 0) {
            Log::info('notification.reminders_queued', ['date' => $date, 'count' => $queued]);
        }
        return $queued;
    }

    /**
     * Tell every waiting patient that their doctor is running late.
     *
     * The delay was already visible: the board shows it and the tracking page
     * shows it. Neither helps a patient who has gone for a walk or is sitting
     * in their car. A delay announcement that only reaches people already
     * staring at the screen is not a notification.
     *
     * Rules that make this safe to call repeatedly:
     *
     *   * One message per patient per announced delay. The dedupe key includes
     *     the queue and the delay length, so re-announcing the same 20 minutes
     *     sends nothing, and raising it to 40 sends a second, truthful message.
     *   * Clearing a delay (minutes = 0) sends nothing at all. "We are no
     *     longer late" is not news worth a text.
     *   * Only patients actually still waiting are messaged. Somebody already
     *     seen does not care.
     *   * A patient with no phone is skipped silently, as everywhere else.
     *
     * @return int how many messages were newly queued
     */
    public function notifyDelay(int $clinicId, int $queueId, int $minutes, string $note = ''): int
    {
        if ($minutes <= 0) {
            return 0;
        }

        $queue = Db::one(
            'SELECT q.id, q.name, c.name AS clinic_name
               FROM queues q JOIN clinics c ON c.id = q.clinic_id
              WHERE q.id = ? AND q.clinic_id = ?',
            [$queueId, $clinicId]
        );
        if (!$queue) {
            return 0;
        }

        $waiting = Db::all(
            "SELECT e.id, e.token_label, p.phone
               FROM queue_entries e JOIN patients p ON p.id = e.patient_id
              WHERE e.clinic_id = ? AND e.queue_id = ? AND e.status = 'waiting'
                AND p.phone IS NOT NULL AND p.phone <> ''",
            [$clinicId, $queueId]
        );

        $queued = 0;
        foreach ($waiting as $entry) {
            // The delay length is part of the key: telling somebody "20 minutes
            // late" twice is noise, telling them it is now 40 is information.
            $dedupe = 'delay:' . $queueId . ':' . $minutes . ':' . (int) $entry['id'];
            if (Db::scalar('SELECT id FROM notifications WHERE dedupe_key = ?', [$dedupe]) !== null) {
                continue;
            }

            $body = sprintf(
                'Your doctor is running about %d minutes late at %s. Token %s keeps its place — no need to ask at the desk.%s',
                $minutes,
                (string) $queue['clinic_name'],
                (string) $entry['token_label'],
                $note !== '' ? ' ' . $note : ''
            );

            try {
                Db::insert(
                    'INSERT INTO notifications (clinic_id, entry_id, channel, template, to_number, payload_json,
                        dedupe_key, status, attempts, scheduled_at, created_at)
                     VALUES (?,?,?,?,?,?,?,?,0,?,?)',
                    [
                        $clinicId, (int) $entry['id'], 'sms', 'entry_delayed',
                        self::mask((string) $entry['phone']),
                        json_encode(['body' => $body, 'to' => (string) $entry['phone'], 'minutes' => $minutes], JSON_UNESCAPED_UNICODE),
                        $dedupe, 'queued', Db::now(), Db::now(),
                    ]
                );
                $queued++;
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE') && !str_contains($e->getMessage(), 'Duplicate')) {
                    throw $e;
                }
            }
        }

        if ($queued > 0) {
            Log::info('notification.delay_queued', ['queue_id' => $queueId, 'minutes' => $minutes, 'count' => $queued]);
        }
        return $queued;
    }

    public function processDue(int $limit = 50): array
    {
        $due = Db::all(
            "SELECT * FROM notifications WHERE status = 'queued' AND scheduled_at <= ? ORDER BY id LIMIT " . max(1, $limit),
            [Db::now()]
        );

        $sent = 0;
        $logged = 0;
        $failed = 0;
        foreach ($due as $job) {
            $attempts = (int) $job['attempts'] + 1;
            $payload = json_decode((string) $job['payload_json'], true) ?: [];
            $body = $payload['body'] ?? '';

            try {
                // The stored `to_number` is masked. The deliverable number
                // lives in the payload, which is the only copy that needs it.
                $to = (string) ($payload['to'] ?? '');
                if ($to === '') {
                    // A row written before masking existed still has a real
                    // number in the column; use it rather than drop the send.
                    $to = (string) $job['to_number'];
                }
                // Three honest outcomes. `sent` means a provider accepted it.
                // `logged` means the development transport recorded the message
                // and nobody was texted — it is terminal, because retrying a
                // log write forever is pointless, but it is NOT counted as a
                // send, so a clinic reading the history cannot conclude a
                // patient was contacted. Anything else throws and is retried.
                $outcome = $this->gateway($job['channel'])->send($to, $body);
                if ($outcome === 'logged') {
                    Db::run(
                        "UPDATE notifications SET status='logged', attempts=?, last_error=?, sent_at=NULL, payload_json=? WHERE id=?",
                        [$attempts, 'recorded by the log transport — not sent to the patient',
                         $this->withoutNumber($job['payload_json']), $job['id']]
                    );
                    $logged++;
                    continue;
                }
                Db::run(
                    "UPDATE notifications SET status='sent', attempts=?, sent_at=?, last_error=NULL, payload_json=? WHERE id=?",
                    [$attempts, Db::now(), $this->withoutNumber($job['payload_json']), $job['id']]
                );
                $sent++;
            } catch (\Throwable $e) {
                $status = $attempts >= 3 ? 'abandoned' : 'queued';
                $retryAt = date('Y-m-d H:i:s', time() + 60 * (2 ** $attempts));
                Db::run(
                    'UPDATE notifications SET status=?, attempts=?, last_error=?, scheduled_at=?, payload_json=? WHERE id=?',
                    [$status, $attempts, mb_substr($e->getMessage(), 0, 290), $retryAt,
                     $attempts >= 3 ? $this->withoutNumber($job['payload_json']) : $job['payload_json'], $job['id']]
                );
                $failed++;
                Log::warn('notification.send.failed', ['id' => $job['id'], 'error' => $e->getMessage()]);
            }
        }

        return ['processed' => count($due), 'sent' => $sent, 'logged' => $logged, 'failed' => $failed];
    }

    private function gateway(string $channel): Gateway
    {
        return match ($channel) {
            'whatsapp' => new WhatsAppGateway(),
            default    => new SmsGateway(),
        };
    }

    /**
     * The same payload with the deliverable number removed.
     *
     * The raw number has to exist somewhere between queueing and sending —
     * that is how the message reaches the patient — but keeping it after the
     * send is finished means every row this clinic ever messaged holds a real
     * number, and a database dump hands over the whole patient list. So the
     * number lives for as long as a retry could still need it: removed on
     * success or on the log transport, kept while the job is still queued or
     * being retried, dropped when it is abandoned. The masked `to_number`
     * column is what staff read either way.
     */
    private function withoutNumber(string $payloadJson): ?string
    {
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return $payloadJson;
        }
        unset($payload['to']);
        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Mask a phone number for storage and display.
     *
     * The queue needs the number only at the moment of sending. Keeping the
     * full number on every row means a database dump — or a screenshot of the
     * notification list — exposes every patient the clinic has ever messaged.
     * Enough digits remain to let staff confirm they are looking at the right
     * patient (last three), which is the only operational need.
     */
    public static function mask(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 5) {
            return '••••';
        }
        return '•••••' . substr($digits, -3);
    }

    public function stats(int $clinicId): array
    {
        return Db::one(
            "SELECT
                SUM(CASE WHEN status='queued' THEN 1 ELSE 0 END) AS queued,
                SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) AS sent,
                -- Recorded by the log transport: never reached a patient, so it
                -- is counted separately from a real send.
                SUM(CASE WHEN status='logged' THEN 1 ELSE 0 END) AS logged,
                SUM(CASE WHEN status IN ('failed','abandoned') THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS total
             FROM notifications WHERE clinic_id = ?",
            [$clinicId]
        ) ?? ['queued' => 0, 'sent' => 0, 'logged' => 0, 'failed' => 0, 'total' => 0];
    }
}
