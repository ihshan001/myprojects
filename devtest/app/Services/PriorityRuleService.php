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

        // Optional on the form. The column is NOT NULL because the reason is
        // copied onto every entry the rule decides, so an omitted reason falls
        // back to the rule name rather than storing an empty string.
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) > 160) {
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

        // Validate the CONVERTED hours, so a clock time from the form is
        // judged on the same value that will actually be stored.
        $fromHour = $this->hourFromInput($in['from_hour'] ?? null, false);
        $toHour = $this->hourFromInput($in['to_hour'] ?? null, true);
        if (($in['from_hour'] ?? '') !== '' && $fromHour === null) {
            $errors[] = 'Start time must be a valid time of day.';
        }
        if (($in['to_hour'] ?? '') !== '' && $toHour === null) {
            $errors[] = 'End time must be a valid time of day.';
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

        // Slot a new rule among its peers by effect, so saving a +3 does not
        // silently lose to an existing +1 that happens to sit above it. The
        // manager can then drag it wherever they actually want it.
        $effect = (int) $in['effect'];
        $above = (int) Db::scalar(
            'SELECT COUNT(*) FROM priority_rules WHERE clinic_id = ? AND effect >= ?',
            [$clinicId, $effect]
        );
        $order = $above + 1;

        return Db::transaction(function () use ($clinicId, $queueId, $in, $doctorId, $effect, $actorId, $order): int {
            Db::run(
                'UPDATE priority_rules SET sort_order = sort_order + 1
                  WHERE clinic_id = ? AND sort_order >= ?',
                [$clinicId, $order]
            );
            return Db::insert(
            'INSERT INTO priority_rules
               (clinic_id, queue_id, name, source, kind, doctor_id, min_age, max_age,
                from_hour, to_hour, effect, reason, is_active, created_by, created_at, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)',
            [
                $clinicId, $queueId, trim((string) $in['name']),
                ($in['source'] ?? '') !== '' ? (string) $in['source'] : null,
                ($in['kind'] ?? '') !== '' ? (string) $in['kind'] : null,
                $doctorId,
                $this->nullableInt($in['min_age'] ?? null),
                $this->nullableInt($in['max_age'] ?? null),
                $this->hourFromInput($in['from_hour'] ?? null, false),
                $this->hourFromInput($in['to_hour'] ?? null, true),
                $effect,
                (trim((string) ($in['reason'] ?? '')) !== ''
                    ? trim((string) $in['reason'])
                    : trim((string) $in['name'])),
                $actorId, Db::now(), $order,
            ]
            );
        });
    }

    /**
     * Persist a new evaluation order.
     *
     * The whole list arrives at once and is written in one transaction: a
     * half-applied drag would leave two rules claiming the same position and
     * the engine's "first match" would become a coin toss.
     *
     * Every id must belong to this clinic and every rule must be present, so
     * a stale browser tab cannot quietly drop a rule it has never seen.
     *
     * @param list<int> $orderedIds
     */
    public function reorder(int $clinicId, array $orderedIds): bool
    {
        $ids = array_values(array_unique(array_map('intval', $orderedIds)));
        $owned = array_map('intval', array_column(
            Db::all('SELECT id FROM priority_rules WHERE clinic_id = ?', [$clinicId]),
            'id'
        ));
        if ($ids === [] || count($ids) !== count($owned)) {
            throw new \InvalidArgumentException('Send every rule in this clinic, in the order you want.');
        }
        foreach ($ids as $id) {
            if (!in_array($id, $owned, true)) {
                throw new \InvalidArgumentException('That rule does not belong to this clinic.');
            }
        }

        Db::transaction(function () use ($clinicId, $ids): void {
            $pos = 1;
            foreach ($ids as $id) {
                Db::run(
                    'UPDATE priority_rules SET sort_order = ? WHERE id = ? AND clinic_id = ?',
                    [$pos++, $id, $clinicId]
                );
            }
        });
        return true;
    }

    /** Turn a rule off without deleting the history that refers to it. */
    public function setActive(int $clinicId, int $ruleId, bool $active): bool
    {
        $rule = Db::one('SELECT id FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        if ($rule === null) {
            return false;
        }
        Db::run('UPDATE priority_rules SET is_active = ? WHERE id = ? AND clinic_id = ?', [$active ? 1 : 0, $ruleId, $clinicId]);
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

        // First matching rule in the manager's order decides the arrival.
        // `sort_order` was backfilled to the previous effect-desc ranking, so
        // this returns what strongest-match returned before the change; the
        // difference is that the order is now something a manager can set.
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

    /**
     * The four numbers at the top of the screen.
     *
     * The wait figure is a COMPARISON, not a measurement of cause: patients a
     * rule flags are clinically different from those it does not, so the gap
     * says "these two groups waited differently", never "the rule saved this
     * many minutes". It is null when either group is too small to compare,
     * because a percentage built on three visits is noise presented as insight.
     *
     * @return array{active:int,total:int,overrides_today:int,wait_flagged:?float,wait_plain:?float,wait_gap:?int,updated_at:?string}
     */
    public function stats(int $clinicId, string $from, string $to): array
    {
        $rows = $this->rules($clinicId, false);
        $active = count(array_filter($rows, static fn (array $r): bool => (int) $r['is_active'] === 1));

        $overridesToday = (int) Db::scalar(
            "SELECT COUNT(*) FROM audit_logs
              WHERE clinic_id = ? AND action = 'queue.priority' AND substr(created_at,1,10) = ?",
            [$clinicId, Db::today()]
        );

        $avg = static function (int $clinicId, string $from, string $to, bool $flagged): ?float {
            $v = Db::scalar(
                "SELECT AVG(e.wait_minutes) FROM queue_entries e
                  JOIN queues q ON q.id = e.queue_id
                 WHERE e.clinic_id = ? AND e.status = 'completed'
                   AND e.wait_minutes IS NOT NULL
                   AND q.session_date BETWEEN ? AND ?
                   AND e.priority_rule_id IS " . ($flagged ? 'NOT NULL' : 'NULL'),
                [$clinicId, $from, $to]
            );
            return $v === null ? null : round((float) $v, 1);
        };
        $flagged = $avg($clinicId, $from, $to, true);
        $plain = $avg($clinicId, $from, $to, false);

        $nFlagged = (int) Db::scalar(
            "SELECT COUNT(*) FROM queue_entries e JOIN queues q ON q.id = e.queue_id
              WHERE e.clinic_id = ? AND e.status = 'completed' AND e.wait_minutes IS NOT NULL
                AND q.session_date BETWEEN ? AND ? AND e.priority_rule_id IS NOT NULL",
            [$clinicId, $from, $to]
        );
        $gap = ($flagged !== null && $plain !== null && $plain > 0.0 && $nFlagged >= 5)
            ? (int) round(($plain - $flagged) / $plain * 100)
            : null;

        // "Last updated" must include reordering and toggling, not just creation.
        $lastRule = Db::scalar('SELECT MAX(created_at) FROM priority_rules WHERE clinic_id = ?', [$clinicId]);
        $lastAudit = Db::scalar(
            "SELECT MAX(created_at) FROM audit_logs
              WHERE clinic_id = ? AND action LIKE 'priority_rule.%'",
            [$clinicId]
        );
        $updated = max((string) $lastRule, (string) $lastAudit) ?: null;

        return [
            'active' => $active,
            'total' => count($rows),
            'overrides_today' => $overridesToday,
            'wait_flagged' => $flagged,
            'wait_plain' => $plain,
            'wait_gap' => $gap,
            'updated_at' => $updated,
        ];
    }

    /**
     * What has been changed, from the audit log rather than a second table.
     * The log is already the record of who did what; a parallel history would
     * be one more thing to keep in step.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $clinicId, int $limit = 40): array
    {
        return Db::all(
            "SELECT a.id, a.action, a.meta_json, a.created_at, u.name AS actor_name
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.actor_id
              WHERE a.clinic_id = ? AND a.action LIKE 'priority_rule.%'
              ORDER BY a.id DESC LIMIT " . (int) $limit,
            [$clinicId]
        );
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

    /**
     * The form offers a clock time ("07:00"); the engine stores whole hours.
     *
     * A start rounds DOWN and an end rounds UP, because to_hour is exclusive:
     * a window ending "11:30" must still cover the 11:00 hour, and silently
     * truncating it to 11 would drop the last half hour of the clinic's window.
     * "23:59" therefore becomes 24, i.e. through the end of the day.
     */
    private function hourFromInput(mixed $value, bool $isEnd): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $s = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m) === 1) {
            $h = (int) $m[1];
            $mins = (int) $m[2];
            if ($h > 24 || $mins > 59) {
                return null;
            }
            $h = ($mins > 0 && $isEnd) ? $h + 1 : $h;
            return max($isEnd ? 1 : 0, min(24, $h));
        }
        return $this->nullableHour($value);
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
