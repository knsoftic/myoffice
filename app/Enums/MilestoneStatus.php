<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The five milestone statuses of phase-06 §3 (`project_milestones.status`).
 *
 * {@see allowedTransitions()} is the §2.13.2 table. {@see progressWeight()} returns **null for
 * `cancelled`** — INV-P9 excludes a cancelled milestone from the progress denominator rather than
 * counting it as 0 %. Weights are decimal strings; `ProjectProgressService` averages them through
 * `App\Support\Money` (INV-P14).
 *
 * **Deviation from §2.13.2, recorded as D66.** The contract's table lists `pending` / `in_progress` ->
 * `on_hold` but no row back out of `on_hold` except `cancelled`, which would leave a held milestone with
 * no way to resume. The project lifecycle §2.13.1 has exactly that row ("`on_hold` -> the status it was
 * held from"), so the omission reads as an editing slip, not a rule. `on_hold` therefore returns to
 * `pending` or `in_progress` here, and `MilestoneService` checks the held-from stamp on top of the list.
 */
enum MilestoneStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::OnHold => 'On hold',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::InProgress => 'sky',
            self::Completed => 'emerald',
            self::OnHold => 'orange',
            self::Cancelled => 'slate',
        };
    }

    /**
     * Delivery of this milestone has ended, one way or the other.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /**
     * Still outstanding.
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * The §6.3 weight as a decimal string, or **null when excluded from progress** (INV-P9).
     */
    public function progressWeight(): ?string
    {
        return match ($this) {
            self::Pending, self::OnHold => '0',
            self::InProgress => '50',
            self::Completed => '100',
            self::Cancelled => null,
        };
    }

    /**
     * Does a milestone in this status take part in a progress average at all? (INV-P9.)
     */
    public function countsTowardProgress(): bool
    {
        return $this->progressWeight() !== null;
    }

    /**
     * Moving **to** this status needs a written reason (§2.13.2, INV-P16).
     */
    public function requiresReason(): bool
    {
        return $this === self::OnHold || $this === self::Cancelled;
    }

    /**
     * The §2.13.2 transition table, with the resume rows of D66 (see the class docblock).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress, self::OnHold, self::Cancelled],
            self::InProgress => [self::Completed, self::OnHold, self::Cancelled],
            self::OnHold => [self::Pending, self::InProgress, self::Cancelled],
            self::Completed => [self::InProgress],
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
     * A move out of a terminal status back into delivery (§2.13.2 "a task re-opens").
     */
    public function isReopening(self $to): bool
    {
        return $this->isTerminal() && $to->isOpen();
    }

    /**
     * Does the move from this status to `$to` need a written reason? Holding, cancelling and every reopen.
     */
    public function requiresReasonFor(self $to): bool
    {
        return $to->requiresReason() || $this->isReopening($to);
    }
}
