<?php

declare(strict_types=1);

namespace App\Listeners\Crm;

use App\Events\Crm\LeadAssigned;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Notifications\Crm\LeadAssignedToYou;
use App\Support\Modules;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * `LeadAssignedToYou` to the **new** assignee only (phase-05 §6.1 `assign()`, §10.2) — never the previous owner,
 * never a user assigning a lead to themselves, nothing on an unassign, nothing while the `leads` module is off.
 * Queued; the event is dispatched after commit, so a rolled-back assignment notifies nobody.
 */
final class NotifyLeadAssignee implements ShouldQueue
{
    public bool $deleteWhenMissingModels = true;

    public function handle(LeadAssigned $event): void
    {
        if ($event->toUserId === null || $event->toUserId === $event->actorId || ! Modules::enabled('leads')) {
            return;
        }

        $lead = Lead::query()->withoutGlobalScope(LeadVisibilityScope::class)->find($event->lead->getKey());

        // A later reassignment already moved the lead on: only its current owner hears about it.
        if (! $lead instanceof Lead || (int) $lead->getAttribute('assigned_to') !== $event->toUserId) {
            return;
        }

        $user = User::query()->active()->find($event->toUserId);

        if (! $user instanceof User) {
            return;
        }

        $byName = $event->actorId === null ? null : User::query()->whereKey($event->actorId)->value('name');

        try {
            $user->notify(new LeadAssignedToYou($lead, is_string($byName) ? $byName : null, $event->reason));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
