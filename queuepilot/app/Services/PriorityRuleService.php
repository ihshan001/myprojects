<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Priority rules — policy written once, applied to every arrival.
 *
 * Until now priority was a manual act: a clinician flagged one patient and
 * typed why. That is the correct escape hatch and the wrong way to run a
 * clinic that always calls children under five first, or always holds
 * routine reviews until after the morning rush. Staff were retyping the same
 * clinical judgement fifty times a day, and every entry where they forgot
 * was an unexplained ordering.
 *
 * A rule is a small declaration: match on the facts already known when a
 * token is issued (source, patient age, queue kind, doctor, hour), and name
 * the priority and the reason. Two design decisions worth stating:
 *
 *   * **The highest matching boost wins; boosts do not stack.** "Child" and
 *     "appointment" and "morning" describe one patient, not three units of
 *     urgency. Adding them turns a policy into an accident of overlapping
 *     filters. A tie on the boost is broken by the older rule, then by id,
 *     so the same patient always sorts the same way.
 *
 *   * **The reason is written onto the entry.** A priority nobody typed has
 *     to be explainable afterwards, and explaining it by re-reading a rule
 *     that may have changed since is how an audit trail becomes a guess.
 *
 * Penalties exist for the same reason boosts do: a routine review that can
 * wait is a clinical decision too, and modelling it as "no rule" leaves it
 * at the walk-in weight where it competes with everyone.
 */
final class PriorityRuleService
{
    public const SOURCES = ['appointment' => 'Appointment', 'walkin' => 'Walk-in'];

    public const KINDS = [
        'consultation' => 'Consultation',
        'lab'          => 'Laboratory',
        'pharmacy'     => 'Pharmacy',
        'general'      => 'General',
    ];

    public function rules(int $clinicId, bool $activeOnly = true): array
    {
        $sql = 'SELECT r.*, d.name AS doctor_name, q.name AS queue_name
                  FROM priority_rules r
                  LEFT JOIN doctors d ON d.id = r.doctor_id
                  LEFT JOIN queues q ON q.id = r.queue_id
                 WHERE r.clinic_id = ?';
        if ($activeOnly) {
            $sql .= ' AND r.is_active = 1';
        }
        // The clinic's own ranked list. It used to be `effect DESC`, which
        // meant the only way to say "this policy outranks that one" was to
        // inflate a number until it won. The list is now the truth.
        $sql .= ' ORDER BY r.sort_order ASC, r.id ASC';

        return Db::all($sql, [$clinicId]);
    }

    /** Validate a posted rule. Returns a list of human-readable problems. */
    public function validate(array $in): array
    {
        $errors = [];

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Give the rule a name the next manager will understand.';
        } elseif (mb_strlen($name) > 80) {
            $errors[] = 'The rule name is limited to 80 characters.';
        }

        $reason = trim((string) ($in['reason'] ?? ''));
        if ($reason === '') {
            $errors[] = 'A rule must carry the clinical reason it applies — it is copied onto every entry it decides.';
        } elseif (mb_strlen($reason) > 160) {
            $errors[] = 'The reason is limited to 160 characters.';
        }

        $effect = filter_var($in['effect'] ?? null, FILTER_VALIDATE_INT);
        if ($effect === false || $effect === 0 || $effect < -5 || $effect > 9) {
            $errors[] = 'Choose a priority between -5 and 9, but not 0 — a rule that changes nothing is a comment, not a rule.';
        }

        // 34.0–43.0 °C. Outside that range a patient is not in a waiting
        // room, and the number is a typo that would silently reorder people.
        foreach (['min_temp_x10' => 'Minimum', 'max_temp_x10' => 'Maximum'] as $field => $label) {
            $raw = $in[$field] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $tenths = filter_var($raw, FILTER_VALIDATE_INT);
            if ($tenths === false || $tenths < 340 || $tenths > 430) {
                $errors[] = $label . ' temperature must be between 34.0 and 43.0 °C.';
            }
        }
        $minT = $this->nullableInt($in['min_temp_x10'] ?? null);
        $maxT = $this->nullableInt($in['max_temp_x10'] ?? null);
        if ($minT !== null && $maxT !== null && $minT >= $maxT) {
            $errors[] = 'The temperature window must start below where it ends.';
        }

        $source = ($in['source'] ?? '') !== '' ? (string) $in['source'] : null;
        if ($source !== null && !isset(self::SOURCES[$source])) {
            $errors[] = 'Unknown source. Choose appointment or walk-in, or leave it blank for both.';
        }

        $kind = ($in['kind'] ?? '') !== '' ? (string) $in['kind'] : null;
        if ($kind !== null && !isset(self::KINDS[$kind])) {
            $errors[] = 'Unknown queue kind.';
        }

        $fromHour = $this->nullableHour($in['from_hour'] ?? null);
        $toHour = $this->nullableHour($in['to_hour'] ?? null);
        if (($in['from_hour'] ?? '') !== '' && $fromHour === null) {
            $errors[] = 'From hour must be 0 to 23.';
        }
        if (($in['to_hour'] ?? '') !== '' && $toHour === null) {
            $errors[] = 'To hour must be 1 to 24.';
        }
        if ($fromHour !== null && $toHour !== null) {
            if ($fromHour >= $toHour) {
                $errors[] = 'The hour window must start before it ends. A window that wraps past midnight is two rules.';
            }
        } elseif ($fromHour !== null || $toHour !== null) {
            if ($fromHour === null || $toHour === null) {
                $errors[] = 'Give both an hour a window starts and the hour it ends, or neither.';
            }
        }

        $minAge = $this->nullableInt($in['min_age'] ?? null);
        $maxAge = $this->nullableInt($in['max_age'] ?? null);
        foreach (['min_age' => $minAge, 'max_age' => $maxAge] as $field => $value) {
            if (($in[$field] ?? '') !== '' && ($value === null || $value < 0 || $value > 130)) {
                $errors[] = 'Ages must be between 0 and 130.';
            }
        }
        if ($minAge !== null && $maxAge !== null && $minAge > $maxAge) {
            $errors[] = 'The youngest age cannot be above the oldest age.';
        }

        return $errors;
    }

    /**
     * Create a rule. Everything except name/effect/reason is optional, so
     * "under fives first" is five fields and "Routine reviews last for
     * Dr. Silva on Tuesdays" is the same five fields filled in differently.
     *
     * @throws \InvalidArgumentException when the input does not validate.
     */
    public function create(int $clinicId, array $in, ?int $actorId = null): int
    {
        $errors = $this->validate($in);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $queueId = $this->ownedQueueId($clinicId, $in['queue_id'] ?? null);
        $doctorId = $this->ownedDoctorId($clinicId, $in['doctor_id'] ?? null);
        if ($doctorId === false) {
            throw new \InvalidArgumentException('That doctor does not belong to this clinic.');
        }
        if ($queueId === false) {
            throw new \InvalidArgumentException('That queue does not belong to this clinic.');
        }

        // New rules append at the bottom of the list, where a manager can see
        // them and move them up. Inserting at the top would make an unranked
        // rule quietly outrank every policy the clinic already wrote.
        $nextOrder = (int) Db::scalar(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM priority_rules WHERE clinic_id = ?',
            [$clinicId]
        );

        $id = Db::insert(
            'INSERT INTO priority_rules
               (clinic_id, queue_id, name, source, kind, doctor_id, min_age, max_age,
                from_hour, to_hour, min_temp_x10, max_temp_x10,
                effect, reason, is_active, sort_order, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?)',
            [
                $clinicId, $queueId, trim((string) $in['name']),
                ($in['source'] ?? '') !== '' ? (string) $in['source'] : null,
                ($in['kind'] ?? '') !== '' ? (string) $in['kind'] : null,
                $doctorId,
                $this->nullableInt($in['min_age'] ?? null),
                $this->nullableInt($in['max_age'] ?? null),
                $this->nullableHour($in['from_hour'] ?? null),
                $this->nullableHour($in['to_hour'] ?? null),
                $this->nullableInt($in['min_temp_x10'] ?? null),
                $this->nullableInt($in['max_temp_x10'] ?? null),
                (int) $in['effect'],
                trim((string) $in['reason']),
                $nextOrder, $actorId, Db::now(), Db::now(),
            ]
        );

        // Describe the row that was actually stored, not the POST: the saved
        // rule has the defaults filled in and the queue and doctor resolved
        // to names, which is what someone reading the history needs.
        $saved = Db::one('SELECT * FROM priority_rules WHERE id = ? AND clinic_id = ?', [$id, $clinicId]);
        $this->record($clinicId, $id, trim((string) $in['name']), 'created', $actorId,
            'Rule created: ' . ($saved === null ? '' : $this->describeMatch($saved)),
            null, $saved);

        return $id;
    }

    /**
     * Put the rules in the order given.
     *
     * Only ids belonging to this clinic are touched, so a hand-crafted POST
     * cannot renumber another tenant's list. Ids that are not mentioned keep
     * their place rather than being dropped to the end.
     */
    public function reorder(int $clinicId, array $orderedIds, ?int $actorId = null): int
    {
        $ids = [];
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $owned = Db::all(
            "SELECT id FROM priority_rules WHERE clinic_id = ? AND id IN ($placeholders)",
            array_merge([$clinicId], $ids)
        );
        $ownedIds = array_map(static fn (array $r): int => (int) $r['id'], $owned);
        // Keep the caller's order, drop anything that is not ours.
        $final = array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $ownedIds, true)));
        if ($final === []) {
            return 0;
        }

        Db::transaction(function () use ($clinicId, $final): void {
            foreach ($final as $position => $ruleId) {
                Db::run(
                    'UPDATE priority_rules SET sort_order = ?, updated_at = ? WHERE id = ? AND clinic_id = ?',
                    [$position + 1, Db::now(), $ruleId, $clinicId]
                );
            }
        });

        $this->record($clinicId, null, count($final) . ' rules', 'reordered', $actorId,
            'Order changed: ' . implode(' → ', array_map(
                static fn (int $id): string => (string) Db::scalar(
                    'SELECT name FROM priority_rules WHERE id = ? AND clinic_id = ?', [$id, $clinicId]
                ), $final
            )));

        return count($final);
    }

    /**
     * Write a row to the rule's own history.
     *
     * The audit log records that something happened to a rule. This records
     * WHAT the rule said at the time, because a priority nobody typed has to
     * be explainable after the rule has been edited or deleted — and "the
     * rule may have changed since" is how an audit trail becomes a guess.
     */
    public function record(
        int $clinicId,
        ?int $ruleId,
        string $ruleName,
        string $action,
        ?int $actorId = null,
        string $detail = '',
        ?array $before = null,
        ?array $after = null
    ): void {
        try {
            Db::insert(
                'INSERT INTO priority_rule_history
                   (clinic_id, rule_id, rule_name, action, actor_id, detail, before_json, after_json, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    $clinicId, $ruleId, mb_substr($ruleName, 0, 120), $action, $actorId,
                    mb_substr($detail, 0, 500),
                    $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                    $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                    Db::now(),
                ]
            );
        } catch (\Throwable $e) {
            // History is evidence, not the transaction. A clinic must never
            // lose the rule they just wrote because its footnote failed.
            \App\Core\Log::warn('priority_rule.history_failed', ['error' => $e->getMessage()]);
        }
    }

    /** Most recent changes to this clinic's rules, newest first. */
    public function history(int $clinicId, int $limit = 12): array
    {
        try {
            return Db::all(
                'SELECT h.*, u.name AS actor_name
                   FROM priority_rule_history h
                   LEFT JOIN users u ON u.id = h.actor_id
                  WHERE h.clinic_id = ?
                  ORDER BY h.id DESC
                  LIMIT ?',
                [$clinicId, $limit]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Turn a rule off without deleting the history that refers to it. */
    public function setActive(int $clinicId, int $ruleId, bool $active): bool
    {
        $rule = Db::one('SELECT id FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        if ($rule === null) {
            return false;
        }
        $name = (string) Db::scalar('SELECT name FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        Db::run(
            'UPDATE priority_rules SET is_active = ?, updated_at = ? WHERE id = ? AND clinic_id = ?',
            [$active ? 1 : 0, Db::now(), $ruleId, $clinicId]
        );
        $this->record($clinicId, $ruleId, $name, $active ? 'enabled' : 'disabled', null,
            $active ? 'Rule turned back on.' : 'Rule turned off. Entries it already decided keep their reason.');
        return true;
    }

    public function delete(int $clinicId, int $ruleId): bool
    {
        $rule = Db::one('SELECT id FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        if ($rule === null) {
            return false;
        }
        // Entries that already carry the rule keep their reason string; only
        // the foreign reference is cleared, so the audit trail still reads.
        Db::run('UPDATE queue_entries SET priority_rule_id = NULL WHERE priority_rule_id = ?', [$ruleId]);
        $name = (string) Db::scalar('SELECT name FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        $snapshot = Db::one('SELECT * FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        // History first: the row is about to go, and the rule_id column
        // deliberately has no foreign key so this outlives it.
        $this->record($clinicId, $ruleId, $name, 'deleted', null,
            'Rule deleted. Patients it already reordered keep their recorded reason.', $snapshot);
        Db::run('DELETE FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        return true;
    }

    /**
     * Which rule, if any, decides this arrival.
     *
     * @param array $ctx clinic_id, queue_id, kind, doctor_id, source, age, hour
     * @return array{priority:int, reason:string, rule_id:?int, rule_name:?string}
     *               priority 0 and rule_id null when nothing matches.
     */
    public function evaluate(int $clinicId, array $ctx): array
    {
        $none = ['priority' => 0, 'reason' => '', 'rule_id' => null, 'rule_name' => null];

        $age = isset($ctx['age']) && $ctx['age'] !== null ? (int) $ctx['age'] : null;
        $hour = isset($ctx['hour']) && $ctx['hour'] !== null ? (int) $ctx['hour'] : null;
        $source = $ctx['source'] ?? null;
        $kind = $ctx['kind'] ?? null;
        $doctorId = isset($ctx['doctor_id']) ? (int) $ctx['doctor_id'] : null;
        $queueId = isset($ctx['queue_id']) ? (int) $ctx['queue_id'] : null;
        // Tenths of a degree. Null means "not measured", which is not the
        // same as "afebrile" — an unmeasured patient must never be caught by
        // a fever rule, in either direction.
        $temp = isset($ctx['temp_c_x10']) && $ctx['temp_c_x10'] !== null
            ? (int) $ctx['temp_c_x10']
            : null;

        // The FIRST matching rule in the clinic's ranked list decides. It
        // used to be "highest effect wins", which is the same thing for a
        // list nobody has reordered — and different the moment they do,
        // which is the point of letting them.
        $best = null;
        foreach ($this->rules($clinicId) as $rule) {
            if ($rule['queue_id'] !== null && (int) $rule['queue_id'] !== $queueId) {
                continue;
            }
            if ($rule['doctor_id'] !== null && (int) $rule['doctor_id'] !== $doctorId) {
                continue;
            }
            if ($rule['source'] !== null && $rule['source'] !== $source) {
                continue;
            }
            if ($rule['kind'] !== null && $rule['kind'] !== $kind) {
                continue;
            }
            if ($rule['min_age'] !== null && ($age === null || $age < (int) $rule['min_age'])) {
                continue;
            }
            if ($rule['max_age'] !== null && ($age === null || $age > (int) $rule['max_age'])) {
                continue;
            }
            if ($rule['min_temp_x10'] !== null && ($temp === null || $temp < (int) $rule['min_temp_x10'])) {
                continue;
            }
            if ($rule['max_temp_x10'] !== null && ($temp === null || $temp > (int) $rule['max_temp_x10'])) {
                continue;
            }
            if ($rule['from_hour'] !== null || $rule['to_hour'] !== null) {
                if ($hour === null) {
                    continue;
                }
                $from = $rule['from_hour'] !== null ? (int) $rule['from_hour'] : 0;
                $to = $rule['to_hour'] !== null ? (int) $rule['to_hour'] : 24;
                if ($hour < $from || $hour >= $to) {
                    continue;
                }
            }

            $best = $rule;
            break;
        }

        if ($best === null) {
            return $none;
        }

        return [
            'priority'  => (int) $best['effect'],
            'reason'    => (string) $best['reason'],
            'rule_id'   => (int) $best['id'],
            'rule_name' => (string) $best['name'],
        ];
    }

    /** Age in whole years from a stored birth year; null when unrecorded. */
    public function ageOf(?int $birthYear, ?string $today = null): ?int
    {
        if ($birthYear === null || $birthYear < 1900) {
            return null;
        }
        $year = (int) substr($today ?? Db::today(), 0, 4);
        $age = $year - $birthYear;
        return $age >= 0 && $age <= 130 ? $age : null;
    }

    /** A plain-language summary of what a rule matches, for the list screen. */
    public function describeMatch(array $rule): string
    {
        $parts = [];
        if ($rule['source'] !== null) {
            $parts[] = self::SOURCES[$rule['source']] ?? (string) $rule['source'];
        }
        if ($rule['kind'] !== null) {
            $parts[] = (self::KINDS[$rule['kind']] ?? $rule['kind']) . ' queues';
        }
        if ($rule['queue_id'] !== null) {
            $parts[] = 'queue ' . ($rule['queue_name'] ?? ('#' . $rule['queue_id']));
        }
        if ($rule['doctor_id'] !== null) {
            $parts[] = (string) ($rule['doctor_name'] ?? ('doctor #' . $rule['doctor_id']));
        }
        if ($rule['min_age'] !== null && $rule['max_age'] !== null) {
            $parts[] = 'age ' . $rule['min_age'] . '–' . $rule['max_age'];
        } elseif ($rule['min_age'] !== null) {
            $parts[] = 'age ' . $rule['min_age'] . '+';
        } elseif ($rule['max_age'] !== null) {
            $parts[] = 'age ' . $rule['max_age'] . ' and under';
        }
        if ($rule['from_hour'] !== null || $rule['to_hour'] !== null) {
            $from = (int) ($rule['from_hour'] ?? 0);
            $to = (int) ($rule['to_hour'] ?? 24);
            $parts[] = sprintf('%02d:00–%02d:00', $from, $to);
        }

        return $parts === [] ? 'every arrival' : implode(', ', $parts);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_INT) === false ? null : (int) $value;
    }

    private function nullableHour(mixed $value): ?int
    {
        $int = $this->nullableInt($value);
        if ($int === null) {
            return null;
        }
        return $int >= 0 && $int <= 24 ? $int : null;
    }

    /** @return int|false|null null when absent, false when not this clinic's */
    private function ownedQueueId(int $clinicId, mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;
        return Db::scalar('SELECT id FROM queues WHERE id = ? AND clinic_id = ?', [$id, $clinicId]) !== null ? $id : false;
    }

    /** @return int|false|null */
    private function ownedDoctorId(int $clinicId, mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;
        return Db::scalar('SELECT id FROM doctors WHERE id = ? AND clinic_id = ?', [$id, $clinicId]) !== null ? $id : false;
    }
}
