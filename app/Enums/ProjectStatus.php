<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The eight delivery statuses of requirement §20 (phase-06 §3, `projects.status`).
 *
 * The case order is the board order ({@see boardOrder()}). {@see allowedTransitions()} is the §2.13.1
 * transition table: `ProjectService::changeStatus()` accepts a move only when the target is listed there and
 * anything else throws `InvalidStatusTransition`. Two cross-cutting rules live here so a Form Request, a
 * policy, the board and the timer read one definition:
 *
 *   · pausing, cancelling and every reopen need a written reason ({@see requiresReasonFor()});
 *   · a terminal project refuses new time entries and new timers ({@see allowsTimeLogging()}, §2.13.1).
 *
 * {@see progressWeight()} is the §6.3 fallback used when a project has neither milestones nor tasks to
 * derive from — a string, never a float, because it is averaged through `App\Support\Money` (INV-P14).
 */
enum ProjectStatus: string
{
    use HasOptions;

    case Planning = 'planning';
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Testing = 'testing';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Review => 'Review',
            self::Testing => 'Testing',
            self::Completed => 'Completed',
            self::OnHold => 'On hold',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planning => 'slate',
            self::Pending => 'amber',
            self::InProgress => 'sky',
            self::Review => 'violet',
            self::Testing => 'cyan',
            self::Completed => 'emerald',
            self::OnHold => 'orange',
            self::Cancelled => 'rose',
        };
    }

    /**
     * Board column position, 1-based (§8.1 the status filter and §8.12 the widget read this order).
     */
    public function boardOrder(): int
    {
        return match ($this) {
            self::Planning => 1,
            self::Pending => 2,
            self::InProgress => 3,
            self::Review => 4,
            self::Testing => 5,
            self::Completed => 6,
            self::OnHold => 7,
            self::Cancelled => 8,
        };
    }

    /**
     * Delivery has ended, one way or the other.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /**
     * Still live work — everything except `completed` and `cancelled`.
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * May a timer be started, or a manual entry logged, against this project's tasks?
     *
     * False on a terminal project: §2.13.1 refuses hours against closed work rather than silently
     * accepting them.
     */
    public function allowsTimeLogging(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * The §6.3 fallback weight, as a decimal string for `App\Support\Money` (INV-P14).
     *
     * Used only when a project has no milestones and no tasks to derive progress from; a project with
     * either derives its percentage from them instead.
     */
    public function progressWeight(): string
    {
        return match ($this) {
            self::Planning, self::Pending, self::OnHold, self::Cancelled => '0',
            self::InProgress => '10',
            self::Review => '80',
            self::Testing => '90',
            self::Completed => '100',
        };
    }

    /**
     * The §2.13.1 transition table: the statuses a project in this status may move to.
     *
     * `on_hold` lists every non-terminal status because the contract's "resumed" row returns the project to
     * the status it was held from; `ProjectService` checks the held-from stamp on top of this list.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planning => [self::Pending, self::InProgress, self::OnHold, self::Cancelled],
            self::Pending => [self::InProgress, self::OnHold, self::Cancelled],
            self::InProgress => [self::Review, self::OnHold, self::Cancelled],
            self::Review => [self::Testing, self::InProgress, self::Completed, self::OnHold, self::Cancelled],
            self::Testing => [self::InProgress, self::Completed, self::OnHold, self::Cancelled],
            self::OnHold => [self::Planning, self::Pending, self::InProgress, self::Review, self::Testing, self::Cancelled],
            self::Completed => [self::InProgress],
            self::Cancelled => [self::Planning],
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
     * A move out of a terminal status back into delivery (§2.13.1 "re-opened").
     */
    public function isReopening(self $to): bool
    {
        return $this->isTerminal() && $to->isOpen();
    }

    /**
     * Does the move from this status to `$to` need a written reason?
     *
     * Pausing, cancelling and every reopen do (§2.13.1, INV-P16).
     */
    public function requiresReasonFor(self $to): bool
    {
        return $to === self::OnHold
            || $to === self::Cancelled
            || $this->isReopening($to);
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
     * The open statuses, in board order (the active-projects widget counts these).
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::ordered(), static fn (self $status): bool => $status->isOpen()));
    }
}
