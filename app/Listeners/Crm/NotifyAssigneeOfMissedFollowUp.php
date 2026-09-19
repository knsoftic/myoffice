<?php

declare(strict_types=1);

namespace App\Listeners\Crm;

use App\Events\Crm\LeadFollowUpMissed;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Notifications\Crm\LeadFollowUpOverdue;
use App\Support\Modules;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * One `LeadFollowUpOverdue` to the assignee of a follow-up that was just marked missed (phase-05 §10.5, test 30).
 * "Once" is guaranteed upstream: only a `pending` row can move to `missed`, so the event fires once per follow-up.
 */
final class NotifyAssigneeOfMissedFollowUp implements ShouldQueue
{
    public bool $deleteWhenMissingModels = true;

    public function handle(LeadFollowUpMissed $event): void
    {
        if (! Modules::enabled('leads')) {
            return;
        }

        $followUp = $event->followUp;
        $recipientId = $followUp->getAttribute('assigned_to');

        if ($recipientId === null) {
            $recipientId = Lead::query()
                ->withoutGlobalScope(LeadVisibilityScope::class)
                ->withTrashed()
                ->whereKey($followUp->getAttribute('lead_id'))
                ->value('assigned_to');
        }

        if ($recipientId === null) {
            return;
        }

        $user = User::query()->active()->find((int) $recipientId);

        if (! $user instanceof User) {
            return;
        }

        try {
            $user->notify(new LeadFollowUpOverdue($followUp));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
