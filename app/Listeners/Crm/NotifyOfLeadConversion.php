<?php

declare(strict_types=1);

namespace App\Listeners\Crm;

use App\Events\Crm\LeadConverted as LeadConvertedEvent;
use App\Listeners\Cms\Concerns\NotifiesStaff;
use App\Models\Crm\Client;
use App\Models\User;
use App\Notifications\Crm\LeadConverted as LeadConvertedNotification;
use App\Support\Modules;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

/**
 * The `LeadConverted` notification (phase-05 §10.3) — to the new client's account manager and to the converter,
 * each once. Queued, after commit.
 */
final class NotifyOfLeadConversion implements ShouldQueue
{
    use NotifiesStaff;

    public bool $deleteWhenMissingModels = true;

    public function handle(LeadConvertedEvent $event): void
    {
        if (! Modules::enabled('clients')) {
            return;
        }

        $conversion = $event->conversion;
        $clientId = $conversion->getAttribute('client_id');
        $managerId = $clientId === null ? null : Client::query()->withTrashed()->whereKey((int) $clientId)->value('account_manager_id');

        $ids = array_values(array_unique(array_filter([
            $managerId === null ? null : (int) $managerId,
            $event->actorId,
            $conversion->getAttribute('converted_by') === null ? null : (int) $conversion->getAttribute('converted_by'),
        ])));

        if ($ids === []) {
            return;
        }

        /** @var Collection<int, User> $users */
        $users = User::query()->active()->whereKey($ids)->get();

        $this->deliver($users, [], new LeadConvertedNotification($conversion));
    }
}
