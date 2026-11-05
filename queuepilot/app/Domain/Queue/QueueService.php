<?php
declare(strict_types=1);

namespace App\Domain\Queue;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Env;
use App\Core\Log;
use App\Core\Str;
use App\Services\AuditService;
use App\Services\NotificationService;

/**
 * QueueService — all queue mutations live here.
 *
 * Guarantees:
 *  1. Every write happens inside one transaction.
 *  2. The entry row is locked (SELECT ... FOR UPDATE on MySQL) and its
 *     `version` column is compared, so double-clicks and two receptionists
 *     pressing "Call" simultaneously cannot both win.
 *  3. Status changes go through QueueStateMachine::assert().
 *  4. Every mutation writes queue_events + audit_logs (who/what/when).
 *  5. Token numbers are allocated under the same lock as the insert so the
 *     UNIQUE(queue_id, token_label) constraint can never be violated.
 */
final class QueueService
{
    public function __construct(
        private readonly QueueRepository $repo = new QueueRepository(),
        private readonly AuditService $audit = new AuditService(),
        private readonly NotificationService $notifications = new NotificationService(),
    ) {}

    /* ------------------------------------------------------------------ */
    /* Token issuing                                                       */
    /* ------------------------------------------------------------------ */

    public function issueToken(int $clinicId, int $queueId, int $patientId, array $opts = []): array
    {
        return Db::transaction(function () use ($clinicId, $queueId, $patientId, $opts) {
            $queue = $this->repo->queue($clinicId, $queueId);
            if (!$queue) {
                throw new \RuntimeException('Queue not found for this clinic.');
            }
            // Paused means "stop adding to this line" — a receptionist who
            // pauses a queue because the doctor is behind expects the flow of
            // new tokens to stop. Closed is the end-of-day state.
            if ($queue['status'] === 'paused') {
                throw new \RuntimeException('This queue is paused. Resume it before issuing new tokens.');
            }
            if ($queue['status'] === 'closed') {
                throw new \RuntimeException('This queue is closed for the day.');
            }

            // A patient must not hold two live tokens in the same queue. The
            // database enforces this too (unique active-entry index), but the
            // check happens first so reception sees a sentence rather than a
            // constraint violation. Both are needed: this one gives the clear
            // message, the index closes the race between two devices.
            $already = Db::one(
                "SELECT token_label, status FROM queue_entries
                  WHERE clinic_id = ? AND queue_id = ? AND patient_id = ?
                    AND status IN ('waiting','called','in_consultation','on_hold')
                  LIMIT 1",
                [$clinicId, $queueId, $patientId]
            );
            if ($already) {
                throw new \RuntimeException(
                    'This patient already holds token ' . $already['token_label']
                    . ' in this queue'
                    . ($already['status'] === 'on_hold' ? ' (currently on hold)' : '')
                    . '. Recall or complete that entry first.'
                );
            }

            // Lock the queue row, then allocate the next number.
            $suffix = Env::isSqlite() ? '' : ' FOR UPDATE';
            $locked = Db::one('SELECT * FROM queues WHERE id = ? AND clinic_id = ?' . $suffix, [$queueId, $clinicId]);
            $next = (int) $locked['last_token_no'] + 1;
            $label = ($queue['token_prefix'] !== '' ? $queue['token_prefix'] : '') . Str::pad($next, (int) $queue['token_padding']);

            Db::run('UPDATE queues SET last_token_no = ? WHERE id = ? AND clinic_id = ?', [$next, $queueId, $clinicId]);

            $priority = (int) ($opts['priority'] ?? 0);
            $source = (string) ($opts['source'] ?? 'walkin');
            // Ordering policy is clinic-defined (Settings -> queue policy) and
            // lives on the clinic row. Callers may override per call; otherwise
            // the clinic's own weights apply, so a clinic that treats walk-ins
            // equally is not forced into our default 1-vs-3 ordering.
            $clinicPolicy = Db::one(
                'SELECT appointment_weight, walkin_weight FROM clinics WHERE id = ?',
                [$clinicId]
            ) ?: [];
            $apptWeight = (int) ($opts['appointment_weight'] ?? $clinicPolicy['appointment_weight'] ?? 1);
            $walkinWeight = (int) ($opts['walkin_weight'] ?? $clinicPolicy['walkin_weight'] ?? 3);
            $weight = $source === 'appointment' ? $apptWeight : $walkinWeight;

            $token = Str::urlToken((int) Env::get('security.track_token_bytes', 24));
            $expires = date('Y-m-d H:i:s', time() + 60 * 60 * (int) Env::get('security.track_token_hours', 18));

            $entryId = Db::insert(
                'INSERT INTO queue_entries
                   (clinic_id, queue_id, patient_id, appointment_id, token_no, token_label, status,
                    priority, priority_reason, sort_weight, source, joined_at, note, created_by,
                    track_token, track_expires_at, version)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)',
                [
                    $clinicId, $queueId, $patientId,
                    $opts['appointment_id'] ?? null,
                    $next, $label, QueueStateMachine::WAITING,
                    $priority, $opts['priority_reason'] ?? null,
                    $weight, $source, Db::now(),
                    $opts['note'] ?? null, Auth::id(),
                    $token, $expires,
                ]
            );

            $this->recordEvent($clinicId, $queueId, $entryId, 'token_issued', null, QueueStateMachine::WAITING, null, [
                'token' => $label, 'source' => $source,
            ]);
            $this->audit->log($clinicId, 'queue.token_issued', 'queue_entry', $entryId, [
                'token' => $label, 'queue_id' => $queueId,
            ]);

            // Queue the "you're in the queue" message, deduped by entry id.
            $this->notifications->queue($clinicId, $entryId, 'token_issued', [
                'token' => $label,
                'queue' => $queue['name'],
            ]);

            return [
                'entry_id' => $entryId,
                'token'    => $label,
                'token_no' => $next,
                'track_token' => $token,
                'track_url'   => '/t/' . $token,
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /* Transitions                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Apply a named action. This is the only public mutation entry point for
     * status changes, so the API, the dashboard and the doctor screen all
     * enforce identical rules.
     *
     * @return array{ok: bool, status: string, message: string, entry?: array}
     */
    public function apply(int $clinicId, int $entryId, string $action, array $ctx = []): array
    {
        try {
            return Db::transaction(function () use ($clinicId, $entryId, $action, $ctx) {
                $entry = $this->repo->lockEntry($clinicId, $entryId);
                if (!$entry) {
                    return ['ok' => false, 'status' => 'unknown', 'message' => 'Queue entry not found.'];
                }

                // Optimistic concurrency: reject stale UI actions.
                if (isset($ctx['version']) && (int) $ctx['version'] !== (int) $entry['version']) {
                    return [
                        'ok' => false,
                        'status' => $entry['status'],
                        'message' => 'This entry changed on another device — the board was refreshed.',
                        'stale' => true,
                    ];
                }

                // Clinical priority is the one action with an authorisation
                // rule of its own. Enforcing it here — not only in the UI —
                // means every entry point (API, form post, worker, future
                // integrations) obeys it identically.
                if ($action === 'priority') {
                    if (!Auth::can('queue.priority')) {
                        return [
                            'ok' => false,
                            'status' => $entry['status'],
                            'message' => 'Only authorised clinical staff can set priority.',
                            'forbidden' => true,
                        ];
                    }
                    if (trim((string) ($ctx['reason'] ?? '')) === '') {
                        return [
                            'ok' => false,
                            'status' => $entry['status'],
                            'message' => 'A clinical reason is required for priority — it is recorded in the audit log.',
                            'reason_required' => true,
                        ];
                    }
                }

                [$to, $patch, $message] = $this->plan($entry, $action, $ctx);

                QueueStateMachine::assert($entry['status'], $to);

                // A transfer must know where it is going. Resolve the target
                // queue up front so a bad id fails before anything is written.
                $transferTarget = null;
                if ($action === 'transfer') {
                    $targetId = (int) ($ctx['to_queue_id'] ?? 0);
                    if ($targetId <= 0 || $targetId === (int) $entry['queue_id']) {
                        return [
                            'ok' => false,
                            'status' => $entry['status'],
                            'message' => 'Choose a different queue to transfer this patient to.',
                        ];
                    }
                    $transferTarget = $this->repo->queue($clinicId, $targetId);
                    if (!$transferTarget) {
                        return [
                            'ok' => false,
                            'status' => $entry['status'],
                            'message' => 'That queue does not belong to this clinic.',
                        ];
                    }
                }

                // The transition target is the single authority for the new
                // status; plan() must never be trusted to restate it. Doing it
                // here means a new action cannot forget to move the row.
                $patch['status'] = $to;
                $patch['version'] = (int) $entry['version'] + 1;

                if ($this->updateEntry($clinicId, $entryId, $patch, (int) $entry['version']) !== 1) {
                    // Someone else won the race between our read and this write.
                    return [
                        'ok' => false,
                        'status' => $entry['status'],
                        'message' => 'This entry changed on another device — the board was refreshed.',
                        'stale' => true,
                    ];
                }

                $this->recordEvent(
                    $clinicId,
                    (int) $entry['queue_id'],
                    $entryId,
                    'entry.' . $action,
                    $entry['status'],
                    $to,
                    $ctx['reason'] ?? null,
                    ['by' => Auth::id()]
                );
                $this->audit->log($clinicId, 'queue.' . $action, 'queue_entry', $entryId, [
                    'from' => $entry['status'], 'to' => $to,
                    'reason' => $ctx['reason'] ?? null,
                ]);

                // Issue the replacement token in the same transaction. If this
                // fails the transfer rolls back, so a patient can never end up
                // 'transferred' with no live token anywhere.
                if ($action === 'transfer' && $transferTarget !== null) {
                    $issued = $this->issueToken($clinicId, (int) $transferTarget['id'], (int) $entry['patient_id'], [
                        'note' => 'Transferred from ' . $entry['token_label'],
                        'source' => 'walkin',
                    ]);
                    $message = sprintf(
                        '%s transferred to %s as %s.',
                        $entry['token_label'],
                        $transferTarget['name'],
                        $issued['token']
                    );
                    $this->audit->log($clinicId, 'queue.transferred_out', 'queue_entry', $entryId, [
                        'to_queue' => (int) $transferTarget['id'],
                        'new_token' => $issued['token'],
                    ]);
                }

                if (in_array($to, [QueueStateMachine::CALLED], true)) {
                    $this->notifications->queue($clinicId, $entryId, 'entry_called', [
                        'token' => $entry['token_label'],
                        'room'  => $ctx['room'] ?? null,
                    ]);
                }
                if ($to === QueueStateMachine::COMPLETED) {
                    $this->notifications->queue($clinicId, $entryId, 'visit_completed', [
                        'token' => $entry['token_label'],
                    ]);
                }

                $result = [
                    'ok' => true,
                    'status' => $to,
                    'message' => $message,
                    'entry' => $this->repo->entry($clinicId, $entryId),
                ];
                if (isset($issued)) {
                    $result['transferred_to'] = [
                        'queue_id' => (int) $transferTarget['id'],
                        'queue_name' => $transferTarget['name'],
                        'token' => $issued['token'],
                        'track_url' => $issued['track_url'],
                    ];
                }
                return $result;
            });
        } catch (InvalidTransition $e) {
            Log::warn('queue.invalid_transition', [
                'clinic' => $clinicId, 'entry' => $entryId, 'action' => $action,
                'from' => $e->from, 'to' => $e->to, 'user' => Auth::id(),
            ]);
            return [
                'ok' => false,
                'status' => $e->from,
                'message' => 'That action is not available any more — the board was refreshed.',
            ];
        } catch (\Throwable $e) {
            Log::error('queue.apply.failed', ['entry' => $entryId, 'action' => $action, 'error' => $e->getMessage()]);
            return ['ok' => false, 'status' => 'error', 'message' => 'Could not update the queue. Please retry.'];
        }
    }

    /**
     * Resolve an action into [targetStatus, columnPatch, humanMessage].
     * Kept pure so it is unit-testable without a database.
     */
    private function plan(array $entry, string $action, array $ctx): array
    {
        $now = Db::now();
        $repeat = (int) $entry['call_count'];

        return match ($action) {
            // Calling advances the entry's status, so `status` must be part of the
            // patch — forgetting it left rows "waiting" while version/called_at
            // moved on, which broke the whole board.
            'call' => [
                QueueStateMachine::CALLED,
                ['status' => QueueStateMachine::CALLED, 'called_at' => $now, 'call_count' => $repeat + 1],
                'Called ' . $entry['token_label'] . '.',
            ],

            'recall' => [
                QueueStateMachine::CALLED,
                ['status' => QueueStateMachine::CALLED, 'called_at' => $now, 'call_count' => $repeat + 1],
                'Called ' . $entry['token_label'] . ' again (' . ($repeat + 1) . ').',
            ],

            'start' => [
                QueueStateMachine::IN_CONSULTATION,
                ['started_at' => $now],
                $entry['token_label'] . ' is now in consultation.',
            ],

            'complete' => [
                QueueStateMachine::COMPLETED,
                [
                    'finished_at' => $now,
                    'wait_minutes' => $this->minsBetween($entry['joined_at'], $entry['started_at'] ?? $now),
                    'consult_minutes' => $this->minsBetween($entry['started_at'] ?? $now, $now),
                ],
                'Consultation completed for ' . $entry['token_label'] . '.',
            ],

            'skip' => [
                QueueStateMachine::SKIPPED,
                ['note' => trim((string) ($ctx['reason'] ?? '')) ?: 'Skipped — patient not present'],
                'Skipped ' . $entry['token_label'] . '. You can call them back any time.',
            ],

            'no_show' => [
                QueueStateMachine::NO_SHOW,
                ['finished_at' => $now, 'note' => 'Marked no-show'],
                'Marked ' . $entry['token_label'] . ' as no-show.',
            ],

            'cancel' => [
                QueueStateMachine::CANCELLED,
                ['finished_at' => $now, 'note' => (string) ($ctx['reason'] ?? 'Cancelled')],
                'Entry cancelled.',
            ],

            'requeue' => [
                QueueStateMachine::WAITING,
                ['called_at' => null, 'started_at' => null, 'finished_at' => null, 'sort_weight' => 0],
                $entry['token_label'] . ' is back in the waiting list.',
            ],

            // Transfer is a real move, not a dead end: the original entry is
            // closed as `transferred` (history preserved, no duplicate active
            // token) and a fresh token is issued in the target queue. The
            // target must be resolved by the caller and passed in.
            'transfer' => [
                QueueStateMachine::TRANSFERRED,
                ['finished_at' => $now, 'note' => (string) ($ctx['reason'] ?? 'Transferred')],
                'Entry transferred.',
            ],

            'priority' => [
                QueueStateMachine::WAITING,
                [
                    'priority' => 1,
                    'priority_reason' => (string) ($ctx['reason'] ?? 'Clinical priority'),
                    'sort_weight' => 0,
                ],
                'Clinical priority set for ' . $entry['token_label'] . '.',
            ],

            // A hold keeps the token and the place. `sort_weight` is left
            // alone on purpose: the patient rejoins the line at their original
            // standing, not at the back.
            'hold' => [
                QueueStateMachine::ON_HOLD,
                [
                    'hold_reason' => trim((string) ($ctx['reason'] ?? '')) ?: 'Stepped away',
                    'hold_since'  => $now,
                    'called_at'   => null,
                ],
                $entry['token_label'] . ' put on hold — ' . (trim((string) ($ctx['reason'] ?? '')) ?: 'stepped away') . '.',
            ],

            'release_hold' => [
                QueueStateMachine::WAITING,
                ['hold_reason' => null, 'hold_since' => null],
                $entry['token_label'] . ' is back in the waiting list.',
            ],

            default => throw new InvalidTransition('Unknown action: ' . $action, (string) $entry['status'], '?'),
        };
    }

    /**
     * Writes the patch and returns affected rows.
     *
     * The WHERE clause carries the version we read, so a concurrent operator
     * can never be silently overwritten — the database, not PHP, is the final
     * arbiter. Returns 0 when the row moved underneath us.
     */
    private function updateEntry(int $clinicId, int $entryId, array $patch, int $expectedVersion): int
    {
        $cols = [];
        $params = [];
        foreach ($patch as $column => $value) {
            $cols[] = $column . ' = ?';
            $params[] = $value;
        }
        $params[] = $entryId;
        $params[] = $clinicId;
        $params[] = $expectedVersion;
        return Db::run(
            'UPDATE queue_entries SET ' . implode(', ', $cols) . ' WHERE id = ? AND clinic_id = ? AND version = ?',
            $params
        )->rowCount();
    }

    /* ------------------------------------------------------------------ */
    /* Board helpers                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Wait-time estimate as a RANGE, never a false-precision number.
     * Source of truth: completed consultations for this doctor (last 30 days),
     * falling back to the configured average, then to a cautious default.
     */
    public function estimate(array $queue, int $clinicId, int $ahead): array
    {
        $samples = $this->repo->averageConsultMinutes($clinicId, $queue['doctor_id'] ?? null);
        $configured = (float) ($queue['avg_consult_minutes'] ?? 10);
        $minConfidence = (int) Env::get('queue.estimate_min_confidence', 4);

        $completed = (int) Db::scalar(
            'SELECT COUNT(*) FROM queue_entries WHERE clinic_id = ? AND status = ? AND finished_at >= ?'
                . (!empty($queue['doctor_id']) ? ' AND queue_id IN (SELECT id FROM queues WHERE doctor_id = ?)' : ''),
            !empty($queue['doctor_id'])
                ? [$clinicId, QueueStateMachine::COMPLETED, date('Y-m-d 00:00:00', strtotime('-30 days')), (int) $queue['doctor_id']]
                : [$clinicId, QueueStateMachine::COMPLETED, date('Y-m-d 00:00:00', strtotime('-30 days'))]
        );

        $perPatient = $samples ?? $configured;
        $confidence = $completed >= $minConfidence ? 'data' : 'estimating';

        // A delay the clinic announced is a known fact, so it shifts the range
        // rather than making the estimate fuzzier.
        $delay = max(0, (int) ($queue['delay_minutes'] ?? 0));

        $low = max(2, (int) round($ahead * $perPatient * 0.7)) + $delay;
        $high = max($low + 4, (int) round($ahead * $perPatient * 1.4) + 3) + $delay;

        if ($ahead === 0) {
            if ($delay > 0) {
                return [
                    'low' => $delay,
                    'high' => $delay + 8,
                    'label' => sprintf('About %d min', $delay),
                    'confidence' => $confidence,
                    'delay_minutes' => $delay,
                ];
            }
            return ['low' => 0, 'high' => 8, 'label' => 'Next up', 'confidence' => $confidence, 'delay_minutes' => 0];
        }

        return [
            'low' => $low,
            'high' => $high,
            'label' => $confidence === 'data'
                ? sprintf('%d–%d min', $low, $high)
                : sprintf('About %d–%d min', $low, $high),
            'confidence' => $confidence,
            'delay_minutes' => $delay,
        ];
    }

    public function positionFor(array $entry, int $clinicId): int
    {
        if ($entry['status'] !== QueueStateMachine::WAITING) {
            return 0;
        }
        return $this->repo->position(
            (int) $entry['queue_id'],
            (int) $entry['id'],
            (int) $entry['priority'],
            (int) $entry['sort_weight']
        );
    }

    public function nowServing(int $clinicId, int $queueId): ?array
    {
        return Db::one(
            "SELECT e.*, p.full_name FROM queue_entries e
               JOIN patients p ON p.id = e.patient_id
              WHERE e.clinic_id = ? AND e.queue_id = ?
                AND e.status IN ('in_consultation','called')
              ORDER BY CASE e.status WHEN 'in_consultation' THEN 0 ELSE 1 END, e.id DESC
              LIMIT 1",
            [$clinicId, $queueId]
        );
    }

    /** Public-safe snapshot: no phone, no surname, no notes. */
    public function publicSnapshot(array $entry): array
    {
        $position = $this->positionFor($entry, (int) $entry['clinic_id']);

        // Use the real queue row rather than a hand-built array: the estimate
        // depends on columns (delay, weights) that a literal would silently
        // drop, which is exactly how an announced delay went missing before.
        $queue = $this->repo->queue((int) $entry['clinic_id'], (int) $entry['queue_id']) ?? [];
        $queue['doctor_id'] = $entry['doctor_id'] ?? ($queue['doctor_id'] ?? null);
        $queue['avg_consult_minutes'] = $entry['avg_consult_minutes'] ?? ($queue['avg_consult_minutes'] ?? 10);

        $estimate = $this->estimate($queue, (int) $entry['clinic_id'], max(0, $position - 1));
        $meta = QueueStateMachine::meta((string) $entry['status']);

        return [
            'token'        => $entry['token_label'],
            'status'       => $entry['status'],
            'status_label' => $meta['label'],
            'position'     => $position,
            'estimate'     => $estimate,
            'doctor'       => $entry['doctor_name'],
            'specialty'    => $entry['specialty'],
            'room'         => $entry['room_name'],
            'clinic'       => $entry['clinic_name'],
            'clinic_phone' => $entry['clinic_phone'],
            'queue'        => $entry['queue_name'],
            'joined_at'    => $entry['joined_at'],
            'updated_at'   => Db::now(),
        ];
    }

    private function recordEvent(int $clinicId, int $queueId, int $entryId, string $action, ?string $from, ?string $to, ?string $reason, array $meta = []): void
    {
        Db::insert(
            'INSERT INTO queue_events (clinic_id, queue_id, entry_id, actor_id, action, from_status, to_status, reason, meta_json, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $clinicId, $queueId, $entryId, Auth::id(), $action, $from, $to,
                $reason, $meta ? json_encode($meta) : null, Db::now(),
            ]
        );
    }

    private function minsBetween(?string $from, ?string $to): ?int
    {
        if (!$from) return null;
        $a = strtotime($from);
        $b = strtotime($to ?? 'now');
        if ($a === false || $b === false) return null;
        return max(0, (int) round(($b - $a) / 60));
    }
}
