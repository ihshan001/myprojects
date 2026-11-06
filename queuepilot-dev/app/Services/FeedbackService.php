<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Patient satisfaction, collected where the patient already is.
 *
 * The design rule: a rating is only accepted for a visit that has actually
 * finished, from the same tracking link that showed the wait. Anything else
 * is either noise or a way to game the number.
 *
 * Reporting deliberately reports **distribution and response rate**, not just
 * a mean. A clinic with a 4.6 average from four replies out of three hundred
 * patients has learned almost nothing, and a screen that shows only "4.6"
 * invites exactly that self-deception. Every number this class returns is
 * paired with how many answers produced it.
 */
final class FeedbackService
{
    /** The ratings that can be given, with the words patients see. */
    public const SCALE = [
        5 => 'Very good',
        4 => 'Good',
        3 => 'Okay',
        2 => 'Poor',
        1 => 'Very poor',
    ];

    /**
     * Whether this visit may be rated, and why not when it cannot.
     *
     * @return array{can:bool,reason:?string,existing:?array}
     */
    public function statusFor(array $entry): array
    {
        $existing = Db::one(
            'SELECT rating, wait_rating, comment, created_at FROM visit_feedback WHERE entry_id = ?',
            [(int) $entry['id']]
        );
        if ($existing !== null) {
            return ['can' => false, 'reason' => 'already_rated', 'existing' => $existing];
        }
        if ((string) $entry['status'] !== 'completed') {
            // "How was it?" cannot be answered about a consultation that has
            // not happened, and a cancelled visit has nothing to report on.
            return ['can' => false, 'reason' => 'not_finished', 'existing' => null];
        }
        if (!empty($entry['finished_at'])
            && strtotime((string) $entry['finished_at']) < strtotime('-14 days')) {
            // A fortnight-old memory is a memory of the survey, not the visit.
            // Refusing keeps the numbers comparable across time.
            return ['can' => false, 'reason' => 'too_old', 'existing' => null];
        }
        return ['can' => true, 'reason' => null, 'existing' => null];
    }

    /**
     * Record a rating.
     *
     * @throws \InvalidArgumentException for a rating outside the scale
     * @throws \RuntimeException    when the visit cannot be rated
     * @return array{rating:int,wait_rating:?int}
     */
    public function record(int $clinicId, int $entryId, int $rating, ?int $waitRating = null, ?string $comment = null): array
    {
        if (!array_key_exists($rating, self::SCALE)) {
            throw new \InvalidArgumentException('Choose a rating from 1 to 5.');
        }
        if ($waitRating !== null && !array_key_exists($waitRating, self::SCALE)) {
            throw new \InvalidArgumentException('Choose a waiting rating from 1 to 5.');
        }

        $entry = Db::one(
            'SELECT id, clinic_id, status, finished_at, queue_id FROM queue_entries WHERE id = ? AND clinic_id = ?',
            [$entryId, $clinicId]
        );
        if ($entry === null) {
            throw new \RuntimeException('That visit does not exist.');
        }

        $status = $this->statusFor($entry);
        if (!$status['can']) {
            throw new \RuntimeException(match ($status['reason']) {
                'already_rated' => 'You have already rated this visit. Thank you.',
                'too_old' => 'This visit was a while ago — thank you for the thought.',
                default => 'This visit has not finished yet.',
            });
        }

        // The comment is stored as written, trimmed and capped. Escaped at
        // render, never here: storing an escaped string would corrupt the data
        // for any other consumer (a CSV export, an email digest).
        $clean = $comment !== null ? trim($comment) : '';
        if (mb_strlen($clean) > 1000) {
            $clean = mb_substr($clean, 0, 1000);
        }

        $queue = Db::one('SELECT doctor_id, department_id FROM queues WHERE id = ?', [(int) $entry['queue_id']]);

        Db::insert(
            'INSERT INTO visit_feedback (clinic_id, entry_id, doctor_id, department_id, rating, wait_rating, comment, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $clinicId,
                $entryId,
                $queue['doctor_id'] ?? null,
                $queue['department_id'] ?? null,
                $rating,
                $waitRating,
                $clean === '' ? null : $clean,
                Db::now(),
            ]
        );

        (new AuditService())->log($clinicId, 'feedback.recorded', 'queue_entry', $entryId, [
            'rating' => $rating, 'wait_rating' => $waitRating,
        ]);

        return ['rating' => $rating, 'wait_rating' => $waitRating];
    }

    /**
     * The report.
     *
     * `response_rate` is the headline, not the average: it is the number that
     * tells a manager whether to believe the rest.
     *
     * @return array{
     *   responses:int,visits:int,response_rate:float,mean:?float,
     *   distribution:array<int,int>,wait_mean:?float,promoters:int,detractors:int,
     *   comments:list<array<string,mixed>>,by_doctor:list<array<string,mixed>>
     * }
     */
    public function report(int $clinicId, string $from, string $to): array
    {
        $visits = (int) Db::scalar(
            "SELECT COUNT(*) FROM queue_entries
              WHERE clinic_id = ? AND status = 'completed' AND substr(finished_at, 1, 10) BETWEEN ? AND ?",
            [$clinicId, $from, $to]
        );

        $rows = Db::all(
            "SELECT f.rating, f.wait_rating, f.comment, f.created_at, f.doctor_id, f.entry_id,
                    d.name AS doctor_name, e.token_label
               FROM visit_feedback f
               JOIN queue_entries e ON e.id = f.entry_id
               LEFT JOIN doctors d ON d.id = f.doctor_id
              WHERE f.clinic_id = ? AND substr(f.created_at, 1, 10) BETWEEN ? AND ?
              ORDER BY f.created_at DESC",
            [$clinicId, $from, $to]
        );

        $distribution = array_fill_keys(array_keys(self::SCALE), 0);
        $sum = 0;
        $waitSum = 0;
        $waitCount = 0;
        $promoters = 0;
        $detractors = 0;
        $comments = [];
        $byDoctor = [];

        foreach ($rows as $r) {
            $rating = (int) $r['rating'];
            $distribution[$rating] = ($distribution[$rating] ?? 0) + 1;
            $sum += $rating;
            if ($r['wait_rating'] !== null) {
                $waitSum += (int) $r['wait_rating'];
                $waitCount++;
            }
            if ($rating >= 4) {
                $promoters++;
            }
            if ($rating <= 2) {
                $detractors++;
            }
            if ($r['comment'] !== null && trim((string) $r['comment']) !== '') {
                $comments[] = [
                    'comment' => (string) $r['comment'],
                    'rating' => $rating,
                    'at' => (string) $r['created_at'],
                    'doctor' => $r['doctor_name'] !== null ? (string) $r['doctor_name'] : null,
                ];
            }
            if ($r['doctor_id'] !== null) {
                $key = (int) $r['doctor_id'];
                $byDoctor[$key] ??= ['doctor' => (string) ($r['doctor_name'] ?? ''), 'count' => 0, 'sum' => 0];
                $byDoctor[$key]['count']++;
                $byDoctor[$key]['sum'] += $rating;
            }
        }

        $count = count($rows);

        return [
            'responses' => $count,
            'visits' => $visits,
            'response_rate' => $visits > 0 ? round($count / $visits * 100, 1) : 0.0,
            'mean' => $count > 0 ? round($sum / $count, 2) : null,
            'distribution' => $distribution,
            'wait_mean' => $waitCount > 0 ? round($waitSum / $waitCount, 2) : null,
            'promoters' => $promoters,
            'detractors' => $detractors,
            'comments' => array_slice($comments, 0, 50),
            'by_doctor' => array_values(array_map(static fn (array $d): array => [
                'doctor' => $d['doctor'],
                'count' => $d['count'],
                'mean' => round($d['sum'] / $d['count'], 2),
            ], $byDoctor)),
        ];
    }
}
