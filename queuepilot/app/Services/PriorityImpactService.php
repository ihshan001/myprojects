<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * What priority actually did to waiting times, measured rather than asserted.
 *
 * The screen this feeds is tempting to fake. "Waits improved 18%" is the kind
 * of number a dashboard wants, and nothing in the product had ever computed
 * it. This does compute it, from finished visits only, and it is careful about
 * what the number is allowed to mean.
 *
 * THE COMPARISON. Every finished visit in the window is placed in one of three
 * groups, and the mean wait of each priority group is compared against the mean
 * wait of the unprioritised visits in the same window:
 *
 *   * baseline — priority 0, no rule. The queue as it would have run.
 *   * rule     — a standing rule decided it (priority_rule_id set).
 *   * manual   — a person at the desk decided it (priority non-zero, no rule).
 *
 * WHAT IT IS NOT. This is an observational difference, not a controlled trial.
 * A clinic that prioritises children is also a clinic whose children arrive at
 * different hours, and the sicker patient may have been going to be called
 * sooner anyway. So the figure is labelled as a comparison against
 * unprioritised visits in the same period, never as time "saved", and the
 * per-group sample sizes travel with it so a reader can see how thin it is.
 *
 * THE SAMPLE FLOOR. Below five finished visits a mean is noise, so the group
 * reports null and the screen prints a dash. A dash is the honest answer and
 * the product already behaves this way on the public homepage.
 *
 * Direction is reported, not assumed: prioritising a group can come out
 * *slower* than baseline in a given week, and the number is allowed to be
 * negative rather than being clamped to a flattering zero.
 */
final class PriorityImpactService
{
    /** Below this many finished visits, a mean is not worth printing. */
    public const MIN_SAMPLES = 5;

    /**
     * @return array{
     *   from:string, to:string,
     *   baseline:array{n:int, mean:?float},
     *   rule:array{n:int, mean:?float, diff:?float, pct:?float},
     *   manual:array{n:int, mean:?float, diff:?float, pct:?float},
     *   enough:bool
     * }
     */
    public function measure(int $clinicId, string $from, string $to): array
    {
        // finished_at is the clinical end of the visit; wait_minutes is written
        // when the consultation starts, so only rows that have both are usable.
        $rows = Db::all(
            "SELECT priority, priority_rule_id, wait_minutes
               FROM queue_entries
              WHERE clinic_id = ?
                AND wait_minutes IS NOT NULL
                AND finished_at IS NOT NULL
                AND substr(finished_at, 1, 10) BETWEEN ? AND ?",
            [$clinicId, $from, $to]
        );

        $groups = ['baseline' => [], 'rule' => [], 'manual' => []];
        foreach ($rows as $row) {
            $wait = (int) $row['wait_minutes'];
            if ($wait < 0) {
                continue;   // a clock that went backwards is not a measurement
            }
            $priority = (int) $row['priority'];
            if ($row['priority_rule_id'] !== null) {
                $groups['rule'][] = $wait;
            } elseif ($priority !== 0) {
                $groups['manual'][] = $wait;
            } else {
                $groups['baseline'][] = $wait;
            }
        }

        $mean = static function (array $values): ?float {
            return count($values) >= self::MIN_SAMPLES
                ? round(array_sum($values) / count($values), 1)
                : null;
        };

        $baseMean = $mean($groups['baseline']);
        $out = [
            'from' => $from,
            'to'   => $to,
            'baseline' => ['n' => count($groups['baseline']), 'mean' => $baseMean],
        ];

        foreach (['rule', 'manual'] as $key) {
            $m = $mean($groups[$key]);
            // A difference needs two usable means. Without a baseline there is
            // nothing to compare against, so the difference stays null even
            // when the group itself has plenty of visits.
            $diff = ($m !== null && $baseMean !== null) ? round($baseMean - $m, 1) : null;
            $pct  = ($diff !== null && $baseMean > 0.0)
                ? round($diff / $baseMean * 100, 0)
                : null;
            $out[$key] = [
                'n' => count($groups[$key]), 'mean' => $m, 'diff' => $diff, 'pct' => $pct,
            ];
        }

        $out['enough'] = $baseMean !== null
            && ($out['rule']['mean'] !== null || $out['manual']['mean'] !== null);

        return $out;
    }

    /**
     * How many entries are being held ahead of the queue by a person right now.
     * Live count, not a period report: this is the "right now" tile.
     */
    public function overridingNow(int $clinicId, string $sessionDate): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*)
               FROM queue_entries e
               JOIN queues q ON q.id = e.queue_id
              WHERE e.clinic_id = ?
                AND q.session_date = ?
                AND e.priority <> 0
                AND e.priority_rule_id IS NULL
                AND e.status IN ('waiting','called','in_consultation')",
            [$clinicId, $sessionDate]
        );
    }
}