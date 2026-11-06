<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Domain\Queue\QueueService;
use App\Domain\Queue\QueueStateMachine;

/**
 * Offline writes: issuing a token when the clinic's internet is down.
 *
 * The scenario list was honest that this is "a different architecture", and it
 * is. The important thing is where the choice is made. Naively holding a token
 * request in the browser and replaying it later produces the worst outcome in
 * a clinic: two patients holding the same number, discovered when both walk to
 * the same door. So the numbering is settled up front, on the server, before
 * the connection is ever needed.
 *
 * The shape of it:
 *
 *  1. The desk asks for a *block* of ticket numbers while online — say A031 to
 *     A040. The server allocates them the same way it allocates a live token
 *     (locking the queue row and advancing `last_token_no`), writes nothing to
 *     `queue_entries`, and hands the block over. No number in the block can
 *     ever be issued online to somebody else, because the counter has already
 *     moved past it.
 *  2. With no connection, the desk issues from that block. The number is
 *     reserved the moment it is used, in the browser's own storage, and the
 *     patient is told it. There is no collision to resolve later, because the
 *     number was already ours.
 *  3. On reconnect the reservations are replayed through the ordinary
 *     `QueueService::issueToken`, which is where every other token comes from.
 *     Each carries a `reservation` id that is UNIQUE in the database, so a
 *     replay that happens twice — the browser retried, the tab was closed and
 *     reopened — still produces one patient and one ticket. That is the whole
 *     reconciliation story: it is an insert with a unique key, not a merge.
 *
 * What the desk is told, plainly: the tokens have no SMS tracking link until
 * they sync. A patient who leaves with a printed number is fine; a patient who
 * expects an SMS will not get one until the connection returns. Saying that at
 * the moment of issue is the difference between a feature and a promise the
 * clinic cannot keep.
 */
final class OfflineService
{
    /** How many numbers one desk may hold at a time. */
    public const MAX_BLOCK = 50;

    /** How long an unused reservation stays valid. */
    public const BLOCK_HOURS = 12;

    /**
     * Reserve a contiguous block of ticket numbers for one queue.
     *
     * Reuses the same row lock `issueToken` uses, so a block and a live token
     * cannot both be handed number 47.
     *
     * @return array{ok: bool, block_id?: int, from?: int, to?: int, prefix?: string, labels?: list<string>, expires_at?: string, error?: string}
     */
    public function reserveBlock(int $clinicId, int $queueId, int $size, ?int $actorId = null): array
    {
        $size = max(1, min(self::MAX_BLOCK, $size));

        return Db::transaction(function () use ($clinicId, $queueId, $size, $actorId) {
            $suffix = \App\Core\Env::isSqlite() ? '' : ' FOR UPDATE';
            $queue = Db::one('SELECT * FROM queues WHERE id = ? AND clinic_id = ?' . $suffix, [$queueId, $clinicId]);
            if ($queue === null) {
                return ['ok' => false, 'error' => 'That queue does not belong to this clinic.'];
            }
            if ((string) $queue['status'] !== 'open') {
                return ['ok' => false, 'error' => 'That queue is ' . $queue['status'] . '. A block of numbers can only be taken for an open queue.'];
            }

            $from = (int) $queue['last_token_no'] + 1;
            $to = $from + $size - 1;
            Db::run('UPDATE queues SET last_token_no = ? WHERE id = ? AND clinic_id = ?', [$to, $queueId, $clinicId]);

            $prefix = (string) $queue['token_prefix'];
            $padding = (int) $queue['token_padding'];
            $labels = [];
            for ($n = $from; $n <= $to; $n++) {
                $labels[] = $prefix . str_pad((string) $n, $padding, '0', STR_PAD_LEFT);
            }

            $expires = date('Y-m-d H:i:s', time() + 3600 * self::BLOCK_HOURS);
            $blockId = Db::insert(
                'INSERT INTO offline_blocks (clinic_id, queue_id, from_no, to_no, issued_by, created_at, expires_at)
                 VALUES (?,?,?,?,?,?,?)',
                [$clinicId, $queueId, $from, $to, $actorId, Db::now(), $expires]
            );

            (new AuditService())->log($clinicId, 'queue.offline_block_reserved', 'queue', $queueId, [
                'block_id' => $blockId,
                'from' => $prefix . str_pad((string) $from, $padding, '0', STR_PAD_LEFT),
                'to' => $prefix . str_pad((string) $to, $padding, '0', STR_PAD_LEFT),
                'size' => $size,
            ]);

            return [
                'ok' => true,
                'block_id' => $blockId,
                'from' => $from,
                'to' => $to,
                'prefix' => $prefix,
                'padding' => $padding,
                'labels' => $labels,
                'expires_at' => $expires,
            ];
        });
    }

    /**
     * Replay reservations made while offline.
     *
     * Every item is replayed through the ordinary issue path, so an offline
     * token is subject to exactly the same rules as an online one — clinic
     * active, queue open, patient belongs here, no duplicate live token. The
     * only extra rule is the number: it must come from a block this clinic
     * reserved, and must not have been used by another reservation already.
     *
     * @param list<array{reservation: string, queue_id: int, token_no: int, patient_id?: int|null, patient_name?: string, patient_phone?: string|null, joined_at?: string|null}> $items
     * @return array{ok: bool, results: list<array<string, mixed>>}
     */
    public function reconcile(int $clinicId, array $items): array
    {
        $service = new QueueService();
        $results = [];

        // Oldest first: a patient who arrived at 09:10 should be ahead of one
        // who arrived at 09:40 in the same priority band, and `sort_weight` is
        // derived from admission order. Replaying in the order the browser
        // happened to store them would reorder the morning.
        usort($items, static fn (array $a, array $b): int => strcmp(
            (string) ($a['joined_at'] ?? ''),
            (string) ($b['joined_at'] ?? '')
        ));

        foreach ($items as $item) {
            $results[] = $this->reconcileOne($clinicId, $service, $item);
        }

        $applied = count(array_filter($results, static fn (array $r): bool => ($r['status'] ?? '') === 'applied'));
        $duplicate = count(array_filter($results, static fn (array $r): bool => ($r['status'] ?? '') === 'duplicate'));
        if ($results !== []) {
            (new AuditService())->log($clinicId, 'queue.offline_reconciled', 'clinic', $clinicId, [
                'received' => count($results),
                'applied' => $applied,
                'already_applied' => $duplicate,
                'refused' => count($results) - $applied - $duplicate,
            ]);
        }

        return ['ok' => true, 'results' => $results];
    }

    /** @param array<string, mixed> $item */
    private function reconcileOne(int $clinicId, QueueService $service, array $item): array
    {
        $reservation = trim((string) ($item['reservation'] ?? ''));
        $queueId = (int) ($item['queue_id'] ?? 0);
        $tokenNo = (int) ($item['token_no'] ?? 0);

        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $reservation)) {
            return ['reservation' => $reservation, 'status' => 'refused', 'message' => 'A reservation needs an id; without one a retry would issue the token twice.'];
        }
        if (strlen($reservation) > 64) {
            return ['reservation' => $reservation, 'status' => 'refused', 'message' => 'That reservation id is too long.'];
        }

        // Already applied? Return the same answer as the first time. The
        // browser may have retried because the connection dropped after the
        // server had already committed.
        $existing = Db::one(
            'SELECT entry_id, token_label FROM offline_reservations WHERE reservation = ? AND clinic_id = ?',
            [$reservation, $clinicId]
        );
        if ($existing !== null) {
            return [
                'reservation' => $reservation,
                'status' => 'duplicate',
                'entry_id' => (int) $existing['entry_id'],
                'token' => (string) $existing['token_label'],
                'message' => 'Already synced; the token was not issued twice.',
            ];
        }

        // The number has to be one this clinic actually set aside. This is the
        // check that makes collision impossible rather than merely unlikely.
        $block = Db::one(
            'SELECT * FROM offline_blocks WHERE clinic_id = ? AND queue_id = ? AND from_no <= ? AND to_no >= ?',
            [$clinicId, $queueId, $tokenNo, $tokenNo]
        );
        if ($block === null) {
            return [
                'reservation' => $reservation,
                'status' => 'refused',
                'token_no' => $tokenNo,
                'message' => 'That number was never set aside for this queue, so it could belong to somebody else. Issue the patient a fresh token at the desk.',
            ];
        }
        if (strtotime((string) $block['expires_at']) < time()) {
            return [
                'reservation' => $reservation,
                'status' => 'refused',
                'token_no' => $tokenNo,
                'message' => 'That block of numbers has expired — it was taken more than ' . self::BLOCK_HOURS . ' hours ago. Issue a fresh token at the desk.',
            ];
        }

        // Patient: either one already on file, or a name typed at the offline
        // desk. Matching on phone within the clinic reuses the record rather
        // than creating a second Kamala every time the line goes down.
        $patientId = isset($item['patient_id']) && (int) $item['patient_id'] > 0 ? (int) $item['patient_id'] : null;
        $typedName = trim((string) ($item['patient_name'] ?? ''));
        $phone = trim((string) ($item['patient_phone'] ?? ''));

        if ($patientId === null && $typedName !== '') {
            // `findOrCreate` already matches on phone within the clinic, which
            // is what stops the same person becoming a second record every
            // time the line goes down. It also allocates the patient code, so
            // there is no second numbering scheme to get wrong.
            $patient = (new PatientService())->findOrCreate($clinicId, [
                'full_name' => mb_substr($typedName, 0, 120),
                'phone' => $phone !== '' ? mb_substr($phone, 0, 32) : null,
                'notes' => 'Registered at the desk while the system was offline.',
            ]);
            $patientId = (int) $patient['id'];
        }
        if ($patientId === null) {
            return [
                'reservation' => $reservation,
                'status' => 'refused',
                'token_no' => $tokenNo,
                'message' => 'A reservation needs either an existing patient or a name to register.',
            ];
        }

        try {
            // `issueToken` allocates the *next* number, and the counter has
            // already moved past this block — so the number is fixed up
            // afterwards inside the same transaction... which means doing it
            // here rather than in the domain service. The alternative, a
            // second allocation path in QueueService, is how two token
            // issuers start disagreeing.
            $entry = Db::transaction(function () use ($clinicId, $service, $queueId, $patientId, $tokenNo, $block, $item, $reservation) {
                $issued = $service->issueToken($clinicId, $queueId, $patientId, [
                    'source' => 'walkin',
                    'note' => 'Issued at the desk while offline.',
                ]);
                $entryId = (int) $issued['entry_id'];

                $queueRow = Db::one('SELECT token_prefix, token_padding FROM queues WHERE id = ?', [$queueId]) ?: [];
                $padding = (int) ($queueRow['token_padding'] ?? 3);
                $prefix = (string) ($queueRow['token_prefix'] ?? '');
                $label = $prefix . str_pad((string) $tokenNo, $padding, '0', STR_PAD_LEFT);

                // Keep the number the patient was actually given on their
                // printout. Anything else — the number the counter reached
                // while we were offline — would contradict the slip in their
                // hand, which is the one record that cannot be corrected.
                Db::run(
                    'UPDATE queue_entries SET token_no = ?, token_label = ? WHERE id = ? AND clinic_id = ?',
                    [$tokenNo, $label, $entryId, $clinicId]
                );

                // Admission order: the time on the paper slip, not the time the
                // connection came back. Otherwise an offline morning replayed
                // at lunchtime queues behind everybody who arrived online.
                $joinedAt = $this->normaliseTime((string) ($item['joined_at'] ?? ''));
                if ($joinedAt !== null) {
                    Db::run('UPDATE queue_entries SET joined_at = ? WHERE id = ? AND clinic_id = ?', [$joinedAt, $entryId, $clinicId]);
                }

                Db::insert(
                    'INSERT INTO offline_reservations (clinic_id, reservation, block_id, queue_id, token_no, token_label, entry_id, created_at)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$clinicId, $reservation, (int) $block['id'], $queueId, $tokenNo, $label, $entryId, Db::now()]
                );

                // The queue event said "token_issued" with the counter's
                // number; correct it so the history matches the slip.
                Db::run(
                    "UPDATE queue_events SET meta_json = ? WHERE clinic_id = ? AND entry_id = ? AND action = 'token_issued'",
                    [json_encode(['token' => $label, 'source' => 'offline', 'reconciled' => true]), $clinicId, $entryId]
                );

                return ['entry_id' => $entryId, 'token' => $label];
            });
        } catch (\Throwable $e) {
            return [
                'reservation' => $reservation,
                'status' => 'refused',
                'token_no' => $tokenNo,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'reservation' => $reservation,
            'status' => 'applied',
            'entry_id' => $entry['entry_id'],
            'token' => $entry['token'],
            'message' => 'Issued.',
        ];
    }

    /** Accept only a timestamp that parses, so a bad client cannot poison ordering. */
    private function normaliseTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false || $ts > time() + 300 || $ts < time() - 86400) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * What is still outstanding: blocks holding unused numbers, and blocks
     * whose window has closed. This is the reconciliation report a manager
     * reads the morning after the line went down.
     *
     * @return array{blocks: list<array<string, mixed>>, unused: int, expired: int}
     */
    public function outstanding(int $clinicId): array
    {
        $blocks = Db::all(
            "SELECT b.*, q.name AS queue_name, q.token_prefix, q.token_padding,
                    (SELECT COUNT(*) FROM offline_reservations r WHERE r.block_id = b.id) AS used
             FROM offline_blocks b
             LEFT JOIN queues q ON q.id = b.queue_id
             WHERE b.clinic_id = ?
             ORDER BY b.id DESC
             LIMIT 50",
            [$clinicId]
        );
        $unused = 0;
        $expired = 0;
        foreach ($blocks as &$b) {
            $size = (int) $b['to_no'] - (int) $b['from_no'] + 1;
            $b['size'] = $size;
            $b['unused'] = $size - (int) $b['used'];
            $b['is_expired'] = strtotime((string) $b['expires_at']) < time();
            if ($b['is_expired']) {
                $expired += (int) $b['unused'];
            } else {
                $unused += (int) $b['unused'];
            }
        }
        return ['blocks' => $blocks, 'unused' => $unused, 'expired' => $expired];
    }
}
