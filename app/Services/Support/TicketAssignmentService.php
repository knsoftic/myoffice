<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketAssignStrategy;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Models\Support\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who picks up a new ticket (phase-19-23 §6.16, requirement §93).
 *
 * **Every strategy may answer "nobody", and that is the feature.** When no eligible agent exists this
 * returns null and the ticket stays on the queue — the holders of `support_tickets.assign` are told,
 * and somebody picks it up. A strategy that always produced a name would eventually produce the
 * wrong one silently, and the ticket would sit in an inbox nobody reads, which is worse than an
 * unassigned ticket somebody can see.
 *
 * **Eligibility is one definition, shared by all four strategies**: holds `support_tickets.view_any`,
 * is `UserStatus::Active`, and — when the ticket has a branch — is in that branch or in none. The
 * strategy only decides *which* of the eligible, so a change to who counts as an agent is one edit
 * rather than four.
 *
 * **Ties break on the lowest id, everywhere.** Without it `round_robin` and `least_open` would each
 * be "some agent", and two runs over the same data could disagree — which makes a bug report about
 * assignment impossible to reproduce.
 */
final class TicketAssignmentService
{
    /**
     * Pick somebody, or nobody.
     *
     * Reads the department's strategy, falling back to `support.ticket_auto_assign` — so a
     * department that has expressed no preference follows the institute, and one that has says so.
     */
    public function assignAutomatically(SupportTicket $ticket): ?User
    {
        $strategy = $this->strategyFor($ticket);

        if (! $strategy->assigns()) {
            return null;
        }

        $eligible = $this->eligibleFor($ticket);

        if ($eligible->isEmpty()) {
            return null;
        }

        return match ($strategy) {
            TicketAssignStrategy::DefaultAssignee => $this->defaultAssignee($ticket, $eligible),
            TicketAssignStrategy::RoundRobin => $this->longestWaiting($eligible),
            TicketAssignStrategy::LeastOpen => $this->lightestLoad($eligible),
            TicketAssignStrategy::None => null,
        };
    }

    /**
     * Everybody who could take this ticket.
     *
     * Public because the assign screen offers the same list the automatic path draws from — two
     * definitions of "eligible" would mean a coordinator could hand a ticket to somebody the system
     * would never have chosen, and neither would be wrong on its own terms.
     *
     * @return Collection<int, User>
     */
    public function eligibleFor(SupportTicket $ticket): Collection
    {
        $branchId = $ticket->getAttribute('branch_id');

        return User::query()
            ->where('status', UserStatus::Active->value)
            ->when($branchId !== null, function ($query) use ($branchId): void {
                // A branch's tickets go to that branch's agents, or to somebody who belongs to no
                // branch — head office, typically, who covers all of them.
                $query->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'));
            })
            ->get()
            ->filter(static fn (User $user): bool => $user->can('support_tickets.view_any'))
            ->values();
    }

    /** The strategy this ticket's department asks for, or the institute's. */
    public function strategyFor(SupportTicket $ticket): TicketAssignStrategy
    {
        $department = $ticket->department;
        $strategy = $department?->auto_assign_strategy;

        if ($strategy instanceof TicketAssignStrategy && $strategy->assigns()) {
            return $strategy;
        }

        // `none` on the department is not "no opinion" — but §6.16 says the institute's setting is
        // the fallback when the department says `none`, so an institute can switch automatic
        // assignment on globally without editing every queue.
        return TicketAssignStrategy::tryFrom((string) setting('support.ticket_auto_assign', 'least_open'))
            ?? TicketAssignStrategy::LeastOpen;
    }

    // ===============================================================================================

    /**
     * The department's named person — but only if they are still eligible.
     *
     * A default assignee who has left, been suspended or lost the permission is not a default
     * assignee; falling through to nobody puts the ticket on the queue where somebody will see it,
     * rather than in a departed colleague's name.
     *
     * @param  Collection<int, User>  $eligible
     */
    private function defaultAssignee(SupportTicket $ticket, Collection $eligible): ?User
    {
        $id = $ticket->department?->getAttribute('default_assignee_id');

        if ($id === null) {
            return null;
        }

        return $eligible->first(static fn (User $user): bool => (int) $user->getKey() === (int) $id);
    }

    /**
     * Whoever was assigned longest ago — and anybody never assigned comes first.
     *
     * One query for the last assignment per agent rather than a query per agent: a desk with thirty
     * agents would otherwise pay thirty round trips to answer one question.
     *
     * @param  Collection<int, User>  $eligible
     */
    private function longestWaiting(Collection $eligible): ?User
    {
        $ids = $eligible->map(static fn (User $user): int => (int) $user->getKey())->all();

        $lastAssigned = DB::table('support_tickets')
            ->select('assigned_to', DB::raw('MAX(assigned_at) AS last_at'))
            ->whereIn('assigned_to', $ids)
            ->whereNull('deleted_at')
            ->groupBy('assigned_to')
            ->pluck('last_at', 'assigned_to');

        return $eligible
            ->sortBy(static fn (User $user): array => [
                // Never assigned sorts first: an empty string precedes any timestamp.
                (string) ($lastAssigned[$user->getKey()] ?? ''),
                (int) $user->getKey(),
            ])
            ->first();
    }

    /**
     * Whoever is carrying the fewest open tickets.
     *
     * "Open" is `TicketStatus::isOpen()` — the three states that are still on somebody's queue. A
     * count that included resolved tickets would measure how much work an agent had *done*, and
     * hand the next one to whoever had done the least.
     *
     * @param  Collection<int, User>  $eligible
     */
    private function lightestLoad(Collection $eligible): ?User
    {
        $ids = $eligible->map(static fn (User $user): int => (int) $user->getKey())->all();

        $open = DB::table('support_tickets')
            ->select('assigned_to', DB::raw('COUNT(*) AS open_count'))
            ->whereIn('assigned_to', $ids)
            ->whereNull('deleted_at')
            ->whereIn('status', array_map(
                static fn (TicketStatus $status): string => $status->value,
                array_values(array_filter(TicketStatus::cases(), static fn (TicketStatus $s): bool => $s->isOpen())),
            ))
            ->groupBy('assigned_to')
            ->pluck('open_count', 'assigned_to');

        return $eligible
            ->sortBy(static fn (User $user): array => [
                (int) ($open[$user->getKey()] ?? 0),
                (int) $user->getKey(),
            ])
            ->first();
    }
}
