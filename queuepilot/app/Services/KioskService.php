<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * The waiting-room kiosk: a tablet on a stand that issues its own tokens.
 *
 * Why a separate credential from the display key
 * ----------------------------------------------
 * The display key is *read-only*: someone who photographs the lobby screen
 * learns today's token numbers, which is public information on that very
 * screen. A kiosk key **creates bookings and consumes queue numbers**. If the
 * two shared a key, a leaked display would let a stranger mint tokens until
 * the doctor's day was full.
 *
 * So they are different credentials with different scopes, stored under
 * different setting names, and rotating one leaves the other alone.
 *
 * What the kiosk deliberately cannot do
 * -------------------------------------
 *   * It cannot see other patients. The screen shows the token it just
 *     issued, and nothing else — no list, no names, no queue depth.
 *   * It cannot pick a priority. A self-service kiosk that can mark an
 *     arrival "urgent" is a queue that fills with urgent people. Priority is
 *     a clinician's judgement (see the priority rules), so the kiosk always
 *     issues at the walk-in weight.
 *   * It cannot issue for a date other than today, or for a doctor with no
 *     open session.
 *
 * Rate limiting is per kiosk *and* per IP: one kiosk cannot be scripted into
 * exhausting a clinic's numbering, and a shared clinic IP cannot be used to
 * spin many kiosks.
 */
final class KioskService
{
    /** The settings key holding a clinic's kiosk credential. */
    public const SETTING = 'kiosk.token';

    public function keyFor(int $clinicId): ?string
    {
        $value = Db::scalar(
            "SELECT value FROM settings WHERE scope = 'clinic' AND owner_id = ? AND key_name = ?",
            [$clinicId, self::SETTING]
        );
        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * Issue a new key, replacing any old one.
     *
     * Rotation is the whole point: the key is printed on a device in a public
     * room, so replacing the tablet, or suspecting the key was photographed,
     * must be one action with no migration.
     */
    public function rotate(int $clinicId): string
    {
        $key = bin2hex(random_bytes(24)); // 48 hex chars, 192 bits
        // `settings` is a plain key/value table with no updated_at, so the row
        // is replaced rather than updated. The audit entry written by the
        // caller is what records when this happened.
        Db::run(
            "DELETE FROM settings WHERE scope = 'clinic' AND owner_id = ? AND key_name = ?",
            [$clinicId, self::SETTING]
        );
        Db::insert(
            "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, ?, ?)",
            [$clinicId, self::SETTING, $key]
        );
        return $key;
    }

    public function revoke(int $clinicId): void
    {
        Db::run(
            "DELETE FROM settings WHERE scope = 'clinic' AND owner_id = ? AND key_name = ?",
            [$clinicId, self::SETTING]
        );
    }

    /**
     * Resolve a key to its clinic, or null.
     *
     * Compared with `hash_equals` against each row rather than a SQL `=`, so
     * response time does not reveal how much of a guessed key was correct.
     * The row count is the number of clinics, not a secret.
     */
    public function clinicForKey(string $key): ?int
    {
        if (preg_match('/^[a-f0-9]{48}$/', $key) !== 1) {
            return null;
        }
        foreach (Db::all("SELECT owner_id, value FROM settings WHERE scope = 'clinic' AND key_name = ?", [self::SETTING]) as $row) {
            if (hash_equals((string) $row['value'], $key)) {
                return (int) $row['owner_id'];
            }
        }
        return null;
    }

    /**
     * The queues a kiosk may offer today: open sessions only.
     *
     * A closed session is not a choice the kiosk should present — the patient
     * would take a number for a doctor who has gone home. Paused is offered,
     * because a paused queue still accepts arrivals and the patient is told
     * the wait is longer.
     */
    public function openQueues(int $clinicId): array
    {
        return Db::all(
            "SELECT q.id, q.name, q.token_prefix, q.status, d.name AS doctor_name, d.room_id,
                    s.name AS specialty,
                    (SELECT COUNT(*) FROM queue_entries e
                      WHERE e.queue_id = q.id AND e.status = 'waiting') AS waiting_count
               FROM queues q
               LEFT JOIN doctors d ON d.id = q.doctor_id
               LEFT JOIN specialties s ON s.id = d.specialty_id
              WHERE q.clinic_id = ? AND q.session_date = ? AND q.status IN ('open','paused')
              ORDER BY q.status = 'open' DESC, d.name, q.id",
            [$clinicId, Db::today()]
        );
    }

    /**
     * Turn a kiosk submission into a token.
     *
     * This is a thin wrapper on purpose: it calls the same `QueueService` the
     * reception desk calls, so a kiosk token and a desk token are the same
     * kind of object. Duplicating the issue logic here is how a kiosk ends up
     * with tokens the board does not draw.
     *
     * @return array{ok:bool,message:string,token?:string,queue?:string,doctor?:string,ahead?:int,entry_id?:int}
     */
    public function issue(int $clinicId, int $queueId, string $name, string $phone, ?string $note = null): array
    {
        $queue = Db::one(
            "SELECT q.*, d.name AS doctor_name FROM queues q
               LEFT JOIN doctors d ON d.id = q.doctor_id
              WHERE q.id = ? AND q.clinic_id = ? AND q.session_date = ? AND q.status IN ('open','paused')",
            [$queueId, $clinicId, Db::today()]
        );
        if ($queue === null) {
            return ['ok' => false, 'message' => 'That session is not open today. Please ask at the desk.'];
        }

        $patient = (new PatientService())->findOrCreate($clinicId, [
            'full_name' => $name,
            'phone' => $phone,
        ]);

        // No priority, no weight, no appointment link: a kiosk arrival is a
        // walk-in, full stop. `issueToken` throws when the clinic is suspended
        // or the queue is gone, and that exception is the honest answer — a
        // kiosk that swallowed it would hand out a number for a session that
        // is not running.
        try {
            $result = (new \App\Domain\Queue\QueueService())->issueToken(
                $clinicId,
                (int) $queue['id'],
                (int) $patient['id'],
                [
                    'source' => 'kiosk',
                    'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                ]
            );
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        // How many are ahead, counted from the live board rather than returned
        // by the service: the kiosk shows one number and the patient can be
        // told whether to sit down. Never a name.
        $ahead = (int) Db::scalar(
            "SELECT COUNT(*) FROM queue_entries
              WHERE queue_id = ? AND status = 'waiting' AND id <> ?",
            [(int) $queue['id'], (int) $result['entry_id']]
        );

        return [
            'ok' => true,
            'message' => 'Take a seat — the screen will call your number.',
            'token' => (string) $result['token'],
            'queue' => (string) $queue['name'],
            'doctor' => (string) ($queue['doctor_name'] ?? ''),
            'ahead' => $ahead,
            'entry_id' => (int) $result['entry_id'],
            'track_url' => (string) ($result['track_url'] ?? ''),
        ];
    }
}
