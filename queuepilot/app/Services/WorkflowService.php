<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Service workflows — the steps a visit has to pass before it is complete.
 *
 * A doctor's visit is one act: call the patient, see them, done. A laboratory
 * visit is not. The sample has to be taken before the result can be verified
 * before the report can be handed over, and a receptionist marking the token
 * "completed" at the first of those three produces a record that says the
 * visit finished when nothing had actually happened yet.
 *
 * A workflow is therefore a list of steps hung off a queue kind (or one queue),
 * and completing an entry is refused until every required step is ticked. The
 * steps are **not** new statuses. Adding `sample_taken` to the state machine
 * would mean every board, display, tracker and report in the product had a
 * state it had never seen; keeping the entry `in_consultation` and recording
 * the steps beside it means an existing install keeps working, and a clinic
 * that wants no workflow simply has no steps.
 *
 * Ticking is attributed: who ticked it and when. A step nobody ticked is a
 * reason to refuse, not a silent default.
 */
final class WorkflowService
{
    public const KINDS = [
        'consultation' => 'Consultation',
        'lab'          => 'Laboratory',
        'pharmacy'     => 'Pharmacy',
        'general'      => 'General',
    ];

    /** Steps configured for a queue, already resolved through its kind. */
    public function stepsFor(int $clinicId, ?int $queueId, ?string $kind): array
    {
        $rows = Db::all(
            "SELECT * FROM workflow_steps
              WHERE clinic_id = ? AND is_active = 1
                AND (queue_id = ? OR (queue_id IS NULL AND (kind IS NULL OR kind = ?)))
              ORDER BY position ASC, id ASC",
            [$clinicId, $queueId, $kind]
        );

        // A step pinned to this very queue replaces the kind-wide step with the
        // same name, so a lab can add "second sample" to one bench without
        // changing every lab queue in the clinic.
        $byName = [];
        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row['name']));
            if (!isset($byName[$key]) || $row['queue_id'] !== null) {
                $byName[$key] = $row;
            }
        }

        return array_values($byName);
    }

    public function all(int $clinicId, bool $activeOnly = true): array
    {
        $sql = 'SELECT s.*, q.name AS queue_name
                  FROM workflow_steps s
                  LEFT JOIN queues q ON q.id = s.queue_id
                 WHERE s.clinic_id = ?';
        if ($activeOnly) {
            $sql .= ' AND s.is_active = 1';
        }
        $sql .= ' ORDER BY s.position ASC, s.id ASC';

        return Db::all($sql, [$clinicId]);
    }

    /** Validate a posted step. Returns a list of human-readable problems. */
    public function validate(array $in): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Name the step the way staff say it out loud — “Sample taken”, not “Step 1”.';
        } elseif (mb_strlen($name) > 60) {
            $errors[] = 'A step name is limited to 60 characters.';
        }

        $kind = ($in['kind'] ?? '') !== '' ? (string) $in['kind'] : null;
        if ($kind !== null && !isset(self::KINDS[$kind])) {
            $errors[] = 'Unknown queue kind.';
        }

        $position = filter_var($in['position'] ?? 1, FILTER_VALIDATE_INT);
        if ($position === false || $position < 1 || $position > 20) {
            $errors[] = 'The order is between 1 and 20.';
        }

        return $errors;
    }

    public function create(int $clinicId, array $in, ?int $actorId = null): int
    {
        $errors = $this->validate($in);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $queueId = null;
        if (($in['queue_id'] ?? '') !== '') {
            $queueId = (int) $in['queue_id'];
            if (Db::scalar('SELECT id FROM queues WHERE id = ? AND clinic_id = ?', [$queueId, $clinicId]) === null) {
                throw new \InvalidArgumentException('That queue does not belong to this clinic.');
            }
        }

        return Db::insert(
            'INSERT INTO workflow_steps (clinic_id, queue_id, kind, name, position, is_required, is_active, created_at)
             VALUES (?,?,?,?,?,?,1,?)',
            [
                $clinicId, $queueId,
                ($in['kind'] ?? '') !== '' ? (string) $in['kind'] : null,
                trim((string) $in['name']),
                (int) ($in['position'] ?? 1),
                isset($in['is_required']) && (string) $in['is_required'] === '0' ? 0 : 1,
                Db::now(),
            ]
        );
    }

    /** Retire a step. Visits that already passed it keep their record. */
    public function deactivate(int $clinicId, int $stepId): bool
    {
        if (Db::scalar('SELECT id FROM workflow_steps WHERE id = ? AND clinic_id = ?', [$stepId, $clinicId]) === null) {
            return false;
        }
        Db::run('UPDATE workflow_steps SET is_active = 0 WHERE id = ? AND clinic_id = ?', [$stepId, $clinicId]);
        return true;
    }

    public function delete(int $clinicId, int $stepId): bool
    {
        if (Db::scalar('SELECT id FROM workflow_steps WHERE id = ? AND clinic_id = ?', [$stepId, $clinicId]) === null) {
            return false;
        }
        Db::run('DELETE FROM entry_step_done WHERE step_id = ?', [$stepId]);
        Db::run('DELETE FROM workflow_steps WHERE id = ? AND clinic_id = ?', [$stepId, $clinicId]);
        return true;
    }

    /**
     * The steps for one entry, with whether each is done.
     *
     * @return array<int, array{id:int,name:string,position:int,is_required:int,done:bool,done_at:?string,done_by:?string}>
     */
    public function progress(int $clinicId, array $entry): array
    {
        $kind = null;
        if (isset($entry['queue_id'])) {
            $kind = Db::scalar('SELECT kind FROM queues WHERE id = ? AND clinic_id = ?', [(int) $entry['queue_id'], $clinicId]);
        }
        $steps = $this->stepsFor($clinicId, isset($entry['queue_id']) ? (int) $entry['queue_id'] : null, $kind !== null ? (string) $kind : null);
        if ($steps === []) {
            return [];
        }

        $done = [];
        foreach (Db::all(
            'SELECT d.step_id, d.done_at, u.name AS done_by
               FROM entry_step_done d LEFT JOIN users u ON u.id = d.done_by
              WHERE d.clinic_id = ? AND d.entry_id = ?',
            [$clinicId, (int) $entry['id']]
        ) as $row) {
            $done[(int) $row['step_id']] = $row;
        }

        $out = [];
        foreach ($steps as $step) {
            $id = (int) $step['id'];
            $out[] = [
                'id'          => $id,
                'name'        => (string) $step['name'],
                'position'    => (int) $step['position'],
                'is_required' => (int) $step['is_required'],
                'done'        => isset($done[$id]),
                'done_at'     => $done[$id]['done_at'] ?? null,
                'done_by'     => $done[$id]['done_by'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Steps still outstanding for an entry. Empty means completion is allowed.
     *
     * @return array<int, string> names of the missing required steps
     */
    public function missing(int $clinicId, array $entry): array
    {
        $missing = [];
        foreach ($this->progress($clinicId, $entry) as $step) {
            if ($step['is_required'] === 1 && !$step['done']) {
                $missing[] = $step['name'];
            }
        }
        return $missing;
    }

    /** Tick a step on an entry. Returns false when it was already ticked. */
    public function markDone(int $clinicId, int $entryId, int $stepId, ?int $actorId = null): bool
    {
        $step = Db::one('SELECT * FROM workflow_steps WHERE id = ? AND clinic_id = ? AND is_active = 1', [$stepId, $clinicId]);
        if ($step === null) {
            throw new \InvalidArgumentException('That step does not belong to this clinic, or has been retired.');
        }
        $entry = Db::one('SELECT id FROM queue_entries WHERE id = ? AND clinic_id = ?', [$entryId, $clinicId]);
        if ($entry === null) {
            throw new \InvalidArgumentException('That visit does not belong to this clinic.');
        }
        if (Db::scalar('SELECT id FROM entry_step_done WHERE entry_id = ? AND step_id = ?', [$entryId, $stepId]) !== null) {
            return false;
        }

        // A tick is attributed or it is worthless: six months later "done" with
        // nobody attached cannot be discussed with the person who did it.
        Db::insert(
            'INSERT INTO entry_step_done (clinic_id, entry_id, step_id, done_by, done_at) VALUES (?,?,?,?,?)',
            [$clinicId, $entryId, $stepId, $actorId, Db::now()]
        );
        return true;
    }

    public function undoStep(int $clinicId, int $entryId, int $stepId): bool
    {
        $deleted = Db::run('DELETE FROM entry_step_done WHERE clinic_id = ? AND entry_id = ? AND step_id = ?', [$clinicId, $entryId, $stepId]);
        return $deleted > 0;
    }
}
