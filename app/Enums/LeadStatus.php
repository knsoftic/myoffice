<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The seven pipeline stages of requirement §18 (phase-05 §3, `leads.status`).
 *
 * The case order is the Kanban column order ({@see sortOrder()}). {@see allowedTransitions()} is the server's
 * answer to a board drag (§2.11): `LeadService::changeStatus()` accepts a move only when the target is listed
 * there, and anything else is a 422 carrying that list. The cross-cutting rules the service enforces on top of
 * the table are exposed here too, so a Form Request, a policy and the board UI read one definition:
 *
 *   · moving **to** `lost` needs a `lost_reason` ({@see requiresReason()});
 *   · reopening a terminal lead (`won` -> `negotiation`, `lost` -> `new` / `contacted`) needs a reason
 *     ({@see requiresReasonFor()}), and leaving `won` is refused while a live conversion exists;
 *   · `crm.require_follow_up_on_contacted` applies to the four working stages ({@see requiresFollowUp()}).
 */
enum LeadStatus: string
{
    use HasOptions;

    case New = 'new';
    case Contacted = 'contacted';
    case Interested = 'interested';
    case Negotiation = 'negotiation';
    case ProposalSent = 'proposal_sent';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Interested => 'Interested',
            self::Negotiation => 'Negotiation',
            self::ProposalSent => 'Proposal sent',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::Contacted => 'indigo',
            self::Interested => 'violet',
            self::Negotiation => 'amber',
            self::ProposalSent => 'cyan',
            self::Won => 'emerald',
            self::Lost => 'rose',
        };
    }

    /**
     * Board column position, 1-based (§8.2: one column per status in this order).
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::New => 1,
            self::Contacted => 2,
            self::Interested => 3,
            self::Negotiation => 4,
            self::ProposalSent => 5,
            self::Won => 6,
            self::Lost => 7,
        };
    }

    /**
     * Still being worked: everything except `won` and `lost`.
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * The pipeline has ended for this lead, one way or the other.
     */
    public function isTerminal(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }

    /**
     * Moving to this status needs a reason of its own (`lost_reason`).
     */
    public function requiresReason(): bool
    {
        return $this === self::Lost;
    }

    /**
     * Only a won lead may be converted into a client (§6.4 step 1; "mark as won and convert" runs the status
     * move first, inside the same transaction).
     */
    public function canConvert(): bool
    {
        return $this === self::Won;
    }

    /**
     * The §2.11 transition table: the statuses a lead in this status may move to.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::Interested, self::Lost],
            self::Contacted => [self::Interested, self::Negotiation, self::ProposalSent, self::Lost],
            self::Interested => [self::Contacted, self::Negotiation, self::ProposalSent, self::Lost],
            self::Negotiation => [self::Interested, self::ProposalSent, self::Won, self::Lost],
            self::ProposalSent => [self::Negotiation, self::Won, self::Lost],
            self::Won => [self::Negotiation],
            self::Lost => [self::New, self::Contacted],
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
     * A move out of a terminal status back into the pipeline (§2.11 "reopen").
     */
    public function isReopening(self $to): bool
    {
        return $this->isTerminal() && $to->isOpen();
    }

    /**
     * Does the move from this status to `$to` need a written reason? Moving to `lost` (the `lost_reason`) and
     * every reopen (§2.11 "reason mandatory").
     */
    public function requiresReasonFor(self $to): bool
    {
        return $to->requiresReason() || $this->isReopening($to);
    }

    /**
     * The working stages `crm.require_follow_up_on_contacted` guards: a move here needs a follow-up payload
     * or an already open follow-up (§2.11).
     */
    public function requiresFollowUp(): bool
    {
        return in_array($this, [self::Contacted, self::Interested, self::Negotiation, self::ProposalSent], true);
    }

    /**
     * Every status in board column order.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $left, self $right): int => $left->sortOrder() <=> $right->sortOrder());

        return $cases;
    }

    /**
     * The open statuses, in board order (the pipeline-value widget sums these).
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::ordered(), static fn (self $status): bool => $status->isOpen()));
    }
}
