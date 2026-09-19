<?php

declare(strict_types=1);

namespace App\Listeners\Crm;

use App\Events\Crm\LeadCreated;
use App\Listeners\Cms\Concerns\NotifiesStaff;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Notifications\Crm\NewLeadFromWebsite;
use App\Support\Modules;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

/**
 * `NewLeadFromWebsite` for a lead created from a contact inquiry (phase-05 §10.3): to its assignee, or — while it is
 * unassigned — to the active users holding `leads.assign`. Recipients are resolved from permissions at send time,
 * never from a role name (CLAUDE.md rule 8). Queued; nothing is sent while the `leads` module is off.
 */
final class NotifyStaffOfWebsiteLead implements ShouldQueue
{
    use NotifiesStaff;

    public bool $deleteWhenMissingModels = true;

    public function handle(LeadCreated $event): void
    {
        if (! Modules::enabled('leads')) {
            return;
        }

        $lead = Lead::query()->withoutGlobalScope(LeadVisibilityScope::class)->find($event->lead->getKey());

        if (! $lead instanceof Lead || $lead->getAttribute('contact_inquiry_id') === null) {
            return;
        }

        $assignee = $lead->getAttribute('assigned_to');

        $recipients = $assignee === null
            ? $this->usersHolding('leads.assign')
            : new Collection(array_filter([User::query()->active()->find((int) $assignee)]));

        $this->deliver($recipients, [], new NewLeadFromWebsite($lead));
    }
}
