<?php
declare(strict_types=1);

namespace App\Domain\Queue;

/**
 * Queue state machine.
 *
 * The single source of truth for which entry transitions are legal.
 * Every mutation in QueueService must pass through `assert()`; illegal
 * transitions (including replayed or stale clicks) are rejected rather than
 * silently applied.
 *
 *   waiting ──call──▶ called ──start──▶ in_consultation ──complete──▶ completed
 *      │               │  ▲                  │
 *      │               │  └──recall──┐        ├──transfer──▶ transferred
 *      │               │             │        └──cancel───▶ cancelled
 *      │               ├──skip──▶ skipped ──requeue──▶ waiting
 *      │               └──no_show──▶ no_show
 *      └──cancel──▶ cancelled
 *   skipped / no_show / cancelled ──requeue──▶ waiting
 *
 *   A hold is not a failure: the patient is here, keeps their place and
 *   their token, but cannot be called forward right now (sent for an X-ray,
 *   step outside for air, fetching cash). So `waiting ⇄ on_hold` is a real
 *   transition and `on_hold` counts as a LIVE status for the duplicate-token
 *   guard. Modelling it as `skipped` would silently move them to the back of
 *   the queue; modelling it as `no_show` would be a false record.
 */
final class QueueStateMachine
{
    public const WAITING         = 'waiting';
    public const CALLED          = 'called';
    public const IN_CONSULTATION = 'in_consultation';
    public const COMPLETED       = 'completed';
    public const SKIPPED         = 'skipped';
    public const NO_SHOW         = 'no_show';
    public const CANCELLED       = 'cancelled';
    public const TRANSFERRED     = 'transferred';
    public const ON_HOLD         = 'on_hold';

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        self::WAITING => [
            self::CALLED, self::SKIPPED, self::CANCELLED, self::TRANSFERRED, self::NO_SHOW, self::ON_HOLD,
            // A self-transition is only legal here because `priority` changes an
            // attribute without moving the patient through the funnel. It is
            // still a recorded event, still audited, and still version-guarded.
            // Terminal states deliberately have no self-transition.
            self::WAITING,
        ],
        self::CALLED => [
            self::IN_CONSULTATION, self::SKIPPED, self::NO_SHOW, self::CANCELLED, self::TRANSFERRED, self::CALLED,
            self::ON_HOLD,
        ],
        self::IN_CONSULTATION => [
            self::COMPLETED, self::CANCELLED, self::TRANSFERRED,
        ],
        self::SKIPPED => [
            self::WAITING, self::CANCELLED, self::TRANSFERRED, self::ON_HOLD,
        ],
        // Back to waiting is the normal exit; cancelling abandons the visit.
        // Deliberately NOT allowed to go straight to `called`: coming off a
        // hold returns the patient to the line, it does not jump them.
        self::ON_HOLD => [
            self::WAITING, self::CANCELLED, self::NO_SHOW,
        ],
        self::NO_SHOW => [
            self::WAITING, self::CANCELLED,
        ],
        self::COMPLETED => [],
        self::CANCELLED => [],
        self::TRANSFERRED => [
            self::WAITING, // re-queue back into this column
        ],
    ];

    public const ACTIVE_STATUSES = [self::WAITING, self::CALLED, self::IN_CONSULTATION, self::ON_HOLD];
    public const RECALLABLE      = [self::CALLED, self::SKIPPED];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assert(string $from, string $to): void
    {
        if (!self::can($from, $to)) {
            throw new InvalidTransition(
                sprintf('Illegal queue transition: %s → %s', $from, $to),
                $from,
                $to
            );
        }
    }

    /** Statuses that still occupy the live board. */
    public static function isOpen(string $status): bool
    {
        return in_array($status, self::ACTIVE_STATUSES, true);
    }

    /** Human label + UI tone per status (single mapping shared by all views). */
    public static function meta(string $status): array
    {
        return match ($status) {
            self::WAITING         => ['label' => 'Waiting',        'tone' => 'amber', 'icon' => 'clock'],
            self::CALLED          => ['label' => 'Called',         'tone' => 'sky',   'icon' => 'megaphone'],
            self::IN_CONSULTATION => ['label' => 'In consultation','tone' => 'teal',  'icon' => 'stethoscope'],
            self::COMPLETED       => ['label' => 'Completed',      'tone' => 'emerald','icon' => 'check'],
            self::SKIPPED         => ['label' => 'Skipped',        'tone' => 'orange', 'icon' => 'skip'],
            self::NO_SHOW         => ['label' => 'No show',        'tone' => 'rose',  'icon' => 'user-x'],
            self::CANCELLED       => ['label' => 'Cancelled',      'tone' => 'slate', 'icon' => 'x'],
            self::TRANSFERRED     => ['label' => 'Transferred',    'tone' => 'violet','icon' => 'arrow-right'],
            self::ON_HOLD         => ['label' => 'On hold',        'tone' => 'slate', 'icon' => 'pause'],
            default               => ['label' => ucfirst($status), 'tone' => 'slate', 'icon' => 'clock'],
        };
    }

    /** Actions an operator may take from a given status (drives the UI buttons). */
    public static function actions(string $status): array
    {
        return match ($status) {
            self::WAITING => ['call', 'skip', 'no_show', 'cancel', 'priority', 'transfer', 'hold'],
            self::CALLED => ['start', 'recall', 'skip', 'no_show', 'cancel', 'transfer', 'hold'],
            self::IN_CONSULTATION => ['complete', 'transfer', 'cancel'],
            self::SKIPPED => ['requeue', 'cancel'],
            self::NO_SHOW => ['requeue', 'cancel'],
            self::TRANSFERRED => ['requeue'],
            self::ON_HOLD => ['release_hold', 'cancel'],
            default => [],
        };
    }
}
