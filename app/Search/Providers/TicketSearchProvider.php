<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Support tickets (phase-19-23 6.23).
 *
 * `support_tickets.view_any` is the whole queue; `view` is only what the holder raised or was
 * assigned - the `leads` precedent, [D-P5-8], restated for tickets in 4.2. A portal user reaches
 * their own tickets through the same `requester_id` branch, because from the scope's point of view
 * a client who raised a ticket and an agent who raised one are the same case.
 */
final class TicketSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Ticket;
    }

    public function columns(): array
    {
        return ['support_tickets.ticket_number', 'support_tickets.subject'];
    }

    public function exactColumns(): array
    {
        return ['ticket_number'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = SupportTicket::query()
            ->with('department:id,name')
            ->select([
                'id', 'ticket_number', 'subject', 'status', 'priority',
                'ticket_department_id', 'user_id', 'assigned_to',
            ]);

        if (! $this->can($viewer, 'view_any')) {
            $query->where(static function ($q) use ($viewer): void {
                // `user_id`, not `requester_id`: the column is the user who raised it, whichever
                // panel they raised it from.
                $q->where('support_tickets.user_id', $viewer->getKey())
                    ->orWhere('support_tickets.assigned_to', $viewer->getKey());
            });
        }

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var SupportTicket $model */
        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->ticket_number,
            subtitle: $model->subject,
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: ['Queue' => $model->department?->name, 'Priority' => $model->priority?->label()],
            url: $this->urlFor('admin.support-tickets.show', [$model->getKey()], $viewer),
        );
    }
}
