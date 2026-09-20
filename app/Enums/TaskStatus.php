<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The six task statuses of requirement §22 (phase-06 §3, `tasks.status`).
 *
 * The case order is the Kanban column order ({@see boardOrder()}); `cancelled` is not a column
 * ({@see isBoardColumn()}) — a cancelled task leaves the board rather than sitting in a "dead" lane.
 * {@see allowedTransitions()} is the §2.13.3 table and `TaskService::changeStatus()` / `move()` accept
 * nothing outside it.
 *
 * {@see progressWeight()} returns **null for `cancelled`**: INV-P9 excludes a cancelled task from every
 * progress denominator, which is not the same as counting it as 0 %. Weights are decimal strings because
 * `ProjectProgressService` averages them through `App\Support\Money` (INV-P14).
 */
enum TaskStatus: string
{
    use HasOptions;

    case Todo = 'todo';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::InReview => 'In review',
            self::Blocked => 'Blocked',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Todo => 'slate',
            self::InProgress => 'sky',
            self::InReview => 'violet',
            self::Blocked => 'rose',
            self::Completed => 'emerald',
            self::Cancelled => 'slate',
        };
    }

    /**
     * Kanban column position, 1-based (§8.4). `cancelled` sorts last and is not rendered as a column.
     */
    public function boardOrder(): int
    {
        return match ($this) {
            self::Todo => 1,
            self::InProgress => 2,
            self::InReview => 3,
            self::Blocked => 4,
            self::Completed => 5,
            self::Cancelled => 6,
        };
    }

    /**
     * Does this status get its own Kanban column? Everything but `cancelled` (§3).
     */
    public function isBoardColumn(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * The work has ended, one way or the other.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /**
     * Still outstanding — the sense `TaskService` uses when it refuses to complete a parent while an open
     * subtask exists.
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * The §6.3 weight, as a decimal string, or **null when this status is excluded from progress**
     * altogether (INV-P9: a cancelled task is not a zero, it is not counted).
     */
    public function progressWeight(): ?string
    {
        return match ($this) {
            self::Todo => '0',
            self::InProgress, self::Blocked => '25',
            self::InReview => '75',
            self::Completed => '100',
            self::Cancelled => null,
        };
    }

    /**
     * Does a task in this status take part in a progress average at all? (INV-P9.)
     */
    public function countsTowardProgress(): bool
    {
        return $this->progressWeight() !== null;
    }

    /**
     * Moving **to** this status needs a reason of its own: `blocked` needs `blocked_reason`, `cancelled`
     * needs the discretionary reason INV-P16 demands.
     */
    public function requiresReason(): bool
    {
        return $this === self::Blocked || $this === self::Cancelled;
    }

    /**
     * The §2.13.3 transition table: the statuses a task in this status may move to.
     *
     * `blocked` lists the three open statuses because "unblocked" returns the task to the status it came
     * from; `TaskService` checks the blocked-from stamp on top of this list.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Todo => [self::InProgress, self::Blocked, self::Cancelled],
            self::InProgress => [self::InReview, self::Blocked, self::Completed, self::Cancelled],
            self::InReview => [self::InProgress, self::Blocked, self::Completed, self::Cancelled],
            self::Blocked => [self::Todo, self::InProgress, self::InReview, self::Cancelled],
            self::Completed => [self::InProgress, self::Cancelled],
            self::Cancelled => [],
        };
    }

    /**
     * Is `$to` listed in {@see allowedTransitions()}? Staying in the same status is never a transition.
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * A move out of a terminal status back into the board (§2.13.3 "re-opened").
     */
    public function isReopening(self $to): bool
    {
        return $this->isTerminal() && $to->isOpen();
    }

    /**
     * Does the move from this status to `$to` need a written reason? Blocking, cancelling and every reopen.
     */
    public function requiresReasonFor(self $to): bool
    {
        return $to->requiresReason() || $this->isReopening($to);
    }

    /**
     * Does moving here stop a running timer on the task? (§2.13.3: `completed` and `cancelled` do.)
     */
    public function stopsRunningTimer(): bool
    {
        return $this->isTerminal();
    }

    /**
     * Every status in board order.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $left, self $right): int => $left->boardOrder() <=> $right->boardOrder());

        return $cases;
    }

    /**
     * The statuses that render as Kanban columns, in order (§8.4).
     *
     * @return list<self>
     */
    public static function boardColumns(): array
    {
        return array_values(array_filter(self::ordered(), static fn (self $status): bool => $status->isBoardColumn()));
    }
}
