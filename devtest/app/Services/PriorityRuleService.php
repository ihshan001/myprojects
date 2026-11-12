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
 *   * **The first matching rule in the manager's list wins; nothing stacks.**
 *     "Child" and "appointment" and "morning" describe one patient, not
 *     three units of urgency, so adding them turns a policy into an accident
 *     of overlapping filters. Which one wins used to be decided by the
 *     largest `effect`, which meant the only way to promote a policy was to
 *     inflate a number until it beat the others. `sort_order` is now the
 *     ranking the manager actually sees and drags, and the first match in it
 *     decides. Ties on position fall back to id so the same patient always
 *     sorts the same way.
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

    /**
     * A temperature outside this band is a mistyped reading, not a patient.
     * Stored in tenths of a degree so a 39.0 threshold compares exactly.
     */
    public const TEMP_MIN_C = 30.0;
    public const TEMP_MAX_C = 45.0;

    /**
     * "38.7" -> 387. Returns null for blank, and false for anything that is
     * not a plausible reading, so the caller can tell "not measured" apart
     * from "nonsense" instead of silently treating both as no measurement.
     */
    public static function tempToTenths(mixed $value): int|false|null
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (!is_numeric($value)) {
            return false;
        }
        $c = (float) $value;
        if ($c < self::TEMP_MIN_C || $c > self::TEMP_MAX_C) {
            return false;
        }
        return (int) round($c * 10);
    }

    /** 387 -> "38.7". */
    public static function tenthsToText(?int $tenths): string
    {
        return $tenths === null ? '' : number_format($tenths / 10, 1, '.', '');
    }

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

        $minTemp = self::tempToTenths($in['min_temp'] ?? null);
        $maxTemp = self::tempToTenths($in['max_temp'] ?? null);
        foreach (['min_temp' => $minTemp, 'max_temp' => $maxTemp] as $field => $value) {
            if (($in[$field] ?? '') !== '' && $value === false) {
                $errors[] = sprintf(
                    'A temperature must be a reading between %s and %s °C.',
                    number_format(self::TEMP_MIN_C, 1), number_format(self::TEMP_MAX_C, 1)
                );
            }
        }
        if (is_int($minTemp) && is_int($maxTemp) && $minTemp > $maxTemp) {
            $errors[] = 'The lower temperature cannot be above the upper one.';
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

        // A new policy goes to the bottom of the list, where the manager can
        // see it and drag it. Defaulting it to the top would silently overrule
        // every existing rule the moment it was saved.
        $next = (int) Db::scalar('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM priority_rules WHERE clinic_id = ?', [$clinicId]);
        $now = Db::now();

        $id = Db::insert(
            'INSERT INTO priority_rules
               (clinic_id, queue_id, name, source, kind, doctor_id, min_age, max_age,
                from_hour, to_hour, min_temp_x10, max_temp_x10, effect, reason,
                is_active, created_by, created_at, updated_at, sort_order)
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
                self::tempToTenths($in['min_temp'] ?? null),
                self::tempToTenths($in['max_temp'] ?? null),
                (int) $in['effect'],
                trim((string) $in['reason']),
                $actorId, $now, $now, $next,
            ]
        );

        $rule = Db::one('SELECT * FROM priority_rules WHERE id = ? AND clinic_id = ?', [$id, $clinicId]);
        $this->record($clinicId, $id, (string) $in['name'], 'created', $actorId,
            'Added at position ' . $next . '.', null, $rule);

        return $id;
    }

    /**
     * Move rules into the order the manager dragged them into.
     *
     * Every id must belong to this clinic and every rule must appear exactly
     * once: a partial list would leave two rules sharing a position, and the
     * tie would then be settled by id rather than by the manager.
     *
     * @param list<int> $orderedIds
     */
    public function reorder(int $clinicId, array $orderedIds, ?int $actorId = null): bool
    {
        $ids = [];
        foreach ($orderedIds as $raw) {
            $id = filter_var($raw, FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0 || in_array($id, $ids, true)) {
                return false;
            }
            $ids[] = (int) $id;
        }
        if ($ids === []) {
            return false;
        }

        $owned = array_map('intval', array_column(
            Db::all('SELECT id FROM priority_rules WHERE clinic_id = ?', [$clinicId]), 'id'
        ));
        sort($owned);
        $given = $ids;
        sort($given);
        if ($given !== $owned) {
            return false;   // another clinic's rule, a stale id, or a missing one
        }

        $before = Db::all('SELECT id, name, sort_order FROM priority_rules WHERE clinic_id = ? ORDER BY sort_order, id', [$clinicId]);

        Db::transaction(function () use ($ids, $clinicId): void {
            $pos = 1;
            foreach ($ids as $id) {
                Db::run('UPDATE priority_rules SET sort_order = ?, updated_at = ? WHERE id = ? AND clinic_id = ?',
                    [$pos++, Db::now(), $id, $clinicId]);
            }
        });

        $after = Db::all('SELECT id, name, sort_order FROM priority_rules WHERE clinic_id = ? ORDER BY sort_order, id', [$clinicId]);
        $this->record($clinicId, null, 'Rule order', 'reordered', $actorId,
            'Order changed to: ' . implode(' → ', array_column($after, 'name')) . '.', $before, $after);

        return true;
    }

    /** Most recent changes first. */
    public function history(int $clinicId, int $limit = 50): array
    {
        return Db::all(
            'SELECT h.*, u.name AS actor_name
               FROM priority_rule_history h
               LEFT JOIN users u ON u.id = h.actor_id
              WHERE h.clinic_id = ?
              ORDER BY h.id DESC LIMIT ' . max(1, min(200, $limit)),
            [$clinicId]
        );
    }

    /** When any rule last changed; null when the clinic has never had one. */
    public function lastChangedAt(int $clinicId): ?string
    {
        $at = Db::scalar('SELECT MAX(updated_at) FROM priority_rules WHERE clinic_id = ?', [$clinicId]);
        return $at !== null ? (string) $at : null;
    }

    private function record(int $clinicId, ?int $ruleId, string $name, string $action,
                            ?int $actorId, string $detail, mixed $before, mixed $after): void
    {
        Db::insert(
            'INSERT INTO priority_rule_history
               (clinic_id, rule_id, rule_name, action, actor_id, detail, before_json, after_json, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$clinicId, $ruleId, $name, $action, $actorId, $detail,
             $before === null ? null : json_encode($before),
             $after === null ? null : json_encode($after),
             Db::now()]
        );
    }

    /** Turn a rule off without deleting the history that refers to it. */
    public function setActive(int $clinicId, int $ruleId, bool $active): bool
    {
        $rule = Db::one('SELECT * FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        if ($rule === null) {
            return false;
        }
        Db::run('UPDATE priority_rules SET is_active = ?, updated_at = ? WHERE id = ? AND clinic_id = ?',
            [$active ? 1 : 0, Db::now(), $ruleId, $clinicId]);
        $this->record($clinicId, $ruleId, (string) $rule['name'], $active ? 'enabled' : 'disabled', null,
            $active ? 'Turned on.' : 'Turned off; it no longer decides arrivals.',
            ['is_active' => (int) $rule['is_active']], ['is_active' => $active ? 1 : 0]);
        return true;
    }

    public function delete(int $clinicId, int $ruleId): bool
    {
        $rule = Db::one('SELECT * FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        if ($rule === null) {
            return false;
        }
        // Entries that already carry the rule keep their reason string; only
        // the foreign reference is cleared, so the audit trail still reads.
        Db::run('UPDATE queue_entries SET priority_rule_id = NULL WHERE priority_rule_id = ?', [$ruleId]);
        Db::run('DELETE FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        // History keeps the name and the full rule, so a deleted policy is
        // still readable months later.
        $this->record($clinicId, $ruleId, (string) $rule['name'], 'deleted', null,
            'Deleted. Entries it already decided keep their reason.', $rule, null);
        return true;
    }

    /**
     * Which rule, if any, decides this arrival.
     *
     * @param array $ctx clinic_id, queue_id, kind, doctor_id, source, age, hour, temp_c_x10
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
        $temp = isset($ctx['temp_c_x10']) && $ctx['temp_c_x10'] !== null ? (int) $ctx['temp_c_x10'] : null;

        // rules() returns the manager's ranked list, so the FIRST match decides
        // and the loop stops there. Position is the policy; `effect` is only
        // what the winning rule writes onto the entry.
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
            // An unmeasured patient is not an afebrile one: no reading means a
            // temperature rule cannot match, exactly as no birth year means an
            // age rule cannot.
            if ($rule['min_temp_x10'] !== null && ($temp === null || $temp < (int) $rule['min_temp_x10'])) {
                continue;
            }
            if ($rule['max_temp_x10'] !== null && ($temp === null || $temp > (int) $rule['max_temp_x10'])) {
                continue;
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
        $minTemp = $rule['min_temp_x10'] ?? null;
        $maxTemp = $rule['max_temp_x10'] ?? null;
        if ($minTemp !== null && $maxTemp !== null) {
            $parts[] = self::tenthsToText((int) $minTemp) . '–' . self::tenthsToText((int) $maxTemp) . ' °C';
        } elseif ($minTemp !== null) {
            $parts[] = 'temperature ' . self::tenthsToText((int) $minTemp) . ' °C and above';
        } elseif ($maxTemp !== null) {
            $parts[] = 'temperature up to ' . self::tenthsToText((int) $maxTemp) . ' °C';
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
