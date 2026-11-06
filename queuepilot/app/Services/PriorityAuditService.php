<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Who overrides priority, how often, and for whom.
 *
 * Clinical priority is the one action in this product that lets a person jump
 * a queue. It requires an authorised role and a written reason, and every use
 * is audited — but an audit log nobody reads is not a control. A clinician who
 * marks one in three arrivals urgent has, in practice, abolished triage, and
 * the patients who lose are the ones with no advocate in the room.
 *
 * So this is deliberately framed as **a conversation, not an accusation**.
 * A high rate is often correct: an oncology clinic, a paediatric after-hours
 * service, a dentist treating facial trauma will legitimately override far
 * more than a family OPD. The report shows the rate beside the token count so
 * a reader can judge that for themselves, and it never sorts people into a
 * league table of suspicion.
 *
 * What it measures, and what it refuses to claim:
 *
 *   * `per_staff` is per **actor in the audit log**, so a shared login shows
 *     as one person. That is a real limitation and the screen says so —
 *     inferring individuals from a shared account would be a guess dressed up
 *     as a finding.
 *   * `rate` is overrides ÷ tokens *issued by that person*, not over the
 *     clinic's total. Dividing one person's decisions by everybody's work
 *     would make a busy receptionist look restrained and a quiet one
 *     reckless, which is backwards.
 *   * Automated rule applications (`queue.priority_rule_applied`) are counted
 *     **separately**, because a policy that flags over-70s is not a person
 *     exercising judgement.
 */
final class PriorityAuditService
{
    /**
     * @return array{
     *   overrides:int,tokens:int,rate:float,rule_hits:int,
     *   per_staff:list<array<string,mixed>>,
     *   reasons:list<array{reason:string,count:int}>,
     *   flags:list<array{severity:string,who:string,message:string}>
     * }
     */
    public function report(int $clinicId, string $from, string $to): array
    {
        // Tokens issued per person, as the denominator. A person who issued
        // nothing cannot have a rate, and is listed without one rather than
        // with a misleading 0%.
        $issued = [];
        foreach (Db::all(
            "SELECT actor_id, COUNT(*) AS n FROM audit_logs
              WHERE clinic_id = ? AND action = 'queue.token_issued'
                AND substr(created_at, 1, 10) BETWEEN ? AND ?
              GROUP BY actor_id",
            [$clinicId, $from, $to]
        ) as $row) {
            $issued[(int) ($row['actor_id'] ?? 0)] = (int) $row['n'];
        }

        $perStaff = [];
        $totalOverrides = 0;
        foreach (Db::all(
            "SELECT actor_id, COUNT(*) AS n FROM audit_logs
              WHERE clinic_id = ? AND action = 'queue.priority'
                AND substr(created_at, 1, 10) BETWEEN ? AND ?
              GROUP BY actor_id",
            [$clinicId, $from, $to]
        ) as $row) {
            $actorId = (int) ($row['actor_id'] ?? 0);
            $count = (int) $row['n'];
            $totalOverrides += $count;
            $tokens = $issued[$actorId] ?? 0;
            $perStaff[] = [
                'actor_id' => $actorId,
                'name' => $this->actorName($clinicId, $actorId),
                'overrides' => $count,
                'tokens' => $tokens,
                // Null, not zero: "no denominator" and "looked restrained" are
                // different statements and must not collapse into one number.
                'rate' => $tokens > 0 ? round($count / $tokens * 100, 1) : null,
            ];
        }

        usort($perStaff, static fn (array $a, array $b): int => $b['overrides'] <=> $a['overrides']);

        $reasons = array_map(static fn (array $r): array => [
            'reason' => $r['reason'] !== null && trim((string) $r['reason']) !== ''
                ? (string) $r['reason']
                : '(no reason recorded)',
            'count' => (int) $r['count'],
        ], Db::all(
            "SELECT json_extract(meta_json, '$.reason') AS reason, COUNT(*) AS count
               FROM audit_logs
              WHERE clinic_id = ? AND action = 'queue.priority'
                AND substr(created_at, 1, 10) BETWEEN ? AND ?
              GROUP BY reason ORDER BY count DESC LIMIT 20",
            [$clinicId, $from, $to]
        ));

        $tokens = (int) Db::scalar(
            "SELECT COUNT(*) FROM audit_logs
              WHERE clinic_id = ? AND action = 'queue.token_issued'
                AND substr(created_at, 1, 10) BETWEEN ? AND ?",
            [$clinicId, $from, $to]
        );

        $ruleHits = (int) Db::scalar(
            "SELECT COUNT(*) FROM audit_logs
              WHERE clinic_id = ? AND action = 'queue.priority_rule_applied'
                AND substr(created_at, 1, 10) BETWEEN ? AND ?",
            [$clinicId, $from, $to]
        );

        return [
            'overrides' => $totalOverrides,
            'tokens' => $tokens,
            'rate' => $tokens > 0 ? round($totalOverrides / $tokens * 100, 1) : 0.0,
            'rule_hits' => $ruleHits,
            'per_staff' => $perStaff,
            'reasons' => $reasons,
            'flags' => $this->flags($perStaff, $totalOverrides, $tokens),
        ];
    }

    /**
     * The observations worth putting in words.
     *
     * Kept conservative on purpose. The only claim this makes about a person
     * is a comparison between their own override count and their own token
     * count — never against a colleague — because the latter punishes people
     * for working in harder services.
     *
     * @param list<array<string,mixed>> $perStaff
     * @return list<array{severity:string,who:string,message:string}>
     */
    private function flags(array $perStaff, int $overrides, int $tokens): array
    {
        $flags = [];

        foreach ($perStaff as $row) {
            $rate = $row['rate'];
            if ($rate === null || (int) $row['overrides'] < 3) {
                // Small numbers produce wild percentages. One override out of
                // two tokens is 50% and means nothing.
                continue;
            }
            if ($rate >= 40.0) {
                $flags[] = [
                    'severity' => 'high',
                    'who' => (string) $row['name'],
                    'message' => sprintf(
                        '%d of the %d tokens they issued were given clinical priority (%.0f%%). '
                        . 'At that rate the queue is being reordered more often than not.',
                        (int) $row['overrides'],
                        (int) $row['tokens'],
                        $rate
                    ),
                ];
            } elseif ($rate >= 25.0) {
                $flags[] = [
                    'severity' => 'watch',
                    'who' => (string) $row['name'],
                    'message' => sprintf(
                        '%d of %d tokens prioritised (%.0f%%). Worth a look, especially if this '
                        . 'is a general service rather than an urgent one.',
                        (int) $row['overrides'],
                        (int) $row['tokens'],
                        $rate
                    ),
                ];
            }
        }

        if ($tokens > 0 && $overrides / $tokens >= 0.3) {
            $flags[] = [
                'severity' => 'clinic',
                'who' => 'Whole clinic',
                'message' => sprintf(
                    '%.0f%% of every token issued in this period was prioritised. Either the service '
                    . 'genuinely takes urgent cases, or the priority rule has stopped meaning anything.',
                    $overrides / $tokens * 100
                ),
            ];
        }

        return $flags;
    }

    private function actorName(int $clinicId, int $actorId): string
    {
        if ($actorId === 0) {
            // A null actor is not a person: it is the worker, a migration, or
            // an import. Naming it honestly is better than blaming "Unknown".
            return 'System / no signed-in user';
        }
        $name = Db::scalar('SELECT name FROM users WHERE id = ? AND clinic_id = ?', [$actorId, $clinicId]);
        return $name !== null ? (string) $name : 'Removed user #' . $actorId;
    }
}
