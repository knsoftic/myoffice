<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Workspace;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\TicketStatus;
use App\Models\Support\SupportTicket;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The support queue as the desk actually reads it: open, unclaimed, and late.
 *
 * **A queue is shared, so this card is deliberately not scoped to the viewer.** Its three
 * companions in this group answer "what is on my plate"; this one answers "what is on ours", and a
 * ticket nobody has picked up is precisely the row that belongs to no individual. `admin.tickets.index`
 * already applies `TicketService`'s §9.4 scope when the agent follows the link, so the card names a
 * figure and the screen behind it decides what that person may open.
 *
 * **A state, not a period.** The dashboard's date range is ignored on purpose: a ticket raised three
 * weeks ago and still unanswered is *more* urgent than this morning's, and a card filtered to
 * "today" would report an empty queue on the exact morning the backlog most needed reading.
 *
 * **The breach is computed live rather than read from `first_response_breached`.** Those two boolean
 * columns are written when a sweeper notices, so between sweeps they say "no" about a ticket that
 * went past its target twenty minutes ago. The predicate here is `scopeBreaching()`'s, expressed as
 * a conditional sum so it costs nothing extra: past a target, still unanswered or unresolved, and
 * matching nothing at all when `support.sla_enabled` is off and both due columns are null.
 *
 * **One query.** Every figure is an aggregate over the same open rows, counted in a single pass —
 * five cards asking the obvious way is how a dashboard drifts past its query budget one reasonable
 * addition at a time.
 */
final class SupportTicketQueueWidget extends Widget
{
    public function key(): string
    {
        return 'support_ticket_queue';
    }

    public function title(): string
    {
        return 'Support queue';
    }

    public function icon(): string
    {
        return 'lifebuoy';
    }

    public function permission(): ?string
    {
        return 'support_tickets.view_any';
    }

    public function module(): ?string
    {
        return 'support_tickets';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 10;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.tickets.index');
    }

    public function emptyMessage(): ?string
    {
        return 'The queue is clear.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $open = array_map(
                static fn (TicketStatus $status): string => $status->value,
                array_filter(
                    TicketStatus::cases(),
                    static fn (TicketStatus $status): bool => $status->isOpen(),
                ),
            );

            // `now()` in the timezone timestamps are stored in — the same moment the model's own
            // `scopeBreaching()` compares against, so the card and the filtered list agree.
            $now = Carbon::now()->toDateTimeString();

            $row = SupportTicket::query()
                ->toBase()
                ->whereIn('status', $open)
                ->selectRaw(
                    'COUNT(*) as open_total,'
                    .' SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned,'
                    .' SUM(CASE WHEN first_response_at IS NULL THEN 1 ELSE 0 END) as unanswered,'
                    .' SUM(CASE WHEN ('
                    .'   first_response_at IS NULL'
                    .'   AND first_response_due_at IS NOT NULL'
                    .'   AND first_response_due_at < ?'
                    .' ) OR ('
                    .'   resolution_due_at IS NOT NULL'
                    .'   AND resolution_due_at < ?'
                    .' ) THEN 1 ELSE 0 END) as breached,'
                    .' MIN(created_at) as oldest',
                    [$now, $now],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'open' => (int) ($row->open_total ?? 0),
            'unassigned' => (int) ($row->unassigned ?? 0),
            'unanswered' => (int) ($row->unanswered ?? 0),
            'breached' => (int) ($row->breached ?? 0),
            'oldest_at' => ($row->oldest ?? null) === null ? null : Carbon::parse($row->oldest),
            // Null when the route is not registered; the view falls back to plain text.
            'unassigned_link' => $this->routeUrlWithQuery('admin.tickets.index', ['assignee' => 'none']),
            'breached_link' => $this->routeUrlWithQuery('admin.tickets.index', ['breaching' => 1]),
        ];
    }
}
