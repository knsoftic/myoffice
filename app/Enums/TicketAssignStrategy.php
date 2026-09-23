<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a department hands a new ticket to somebody (phase-19-23 §3.4, §6.16, requirement §93).
 *
 * **Every strategy may answer "nobody", and that is a feature.** `TicketAssignmentService` returns
 * null rather than guessing when no eligible agent exists — the ticket stays unassigned and the
 * holders of `support_tickets.assign` are told. A strategy that always produced a name would
 * eventually produce the wrong one, silently, and the ticket would sit in an inbox nobody reads.
 *
 * **Eligibility is the same for all four** and is not part of the strategy: an agent holds
 * `support_tickets.view_any`, is `UserStatus::Active`, and — when the ticket has a branch — is in
 * that branch or in none. The strategy only decides *which* of the eligible, so a change to who
 * counts as an agent is one edit rather than four.
 */
enum TicketAssignStrategy: string
{
    use HasOptions;

    /** Leave it on the queue for somebody to pick up. */
    case None = 'none';

    /** The department's named default assignee. */
    case DefaultAssignee = 'default_assignee';

    /** The next eligible agent, by who has waited longest. */
    case RoundRobin = 'round_robin';

    /** The eligible agent with the fewest open tickets. */
    case LeastOpen = 'least_open';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Leave it unassigned',
            self::DefaultAssignee => 'The department’s default person',
            self::RoundRobin => 'Take it in turns',
            self::LeastOpen => 'Whoever has the fewest open',
        };
    }

    /**
     * A strategy is configuration rather than a state, so the badge is quiet by design — only
     * "nobody is assigned automatically" is worth a colour that draws the eye.
     */
    public function color(): string
    {
        return match ($this) {
            self::None => 'amber',
            self::DefaultAssignee => 'sky',
            self::RoundRobin => 'indigo',
            self::LeastOpen => 'emerald',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::None => 'Tickets land on the queue and an agent picks one up.',
            self::DefaultAssignee => 'Everything goes to one person, who can hand it on.',
            self::RoundRobin => 'Each new ticket goes to the eligible agent who was assigned longest ago. Ties go to the lower id, so the order is stable.',
            self::LeastOpen => 'Each new ticket goes to the eligible agent carrying the smallest open load. Ties go to the lower id.',
        };
    }

    /** Does this strategy need the department to name somebody? */
    public function needsDefaultAssignee(): bool
    {
        return $this === self::DefaultAssignee;
    }

    /** Does this strategy pick anybody at all? */
    public function assigns(): bool
    {
        return $this !== self::None;
    }
}
