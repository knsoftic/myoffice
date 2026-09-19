<?php

declare(strict_types=1);

namespace App\Policies\Crm;

use App\Enums\Ability;
use App\Models\Crm\LeadFollowUp;
use App\Models\User;
use App\Policies\Crm\Concerns\ChecksCrmPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and work a scheduled follow-up (phase-05 §9.1, §9.3).
 *
 * **Isolation follows the lead:** the parent lead is resolved (no visibility scope, trash included) and
 * `LeadPolicy::view()` decides — a lead outside the user's §9 scope answers **404** for its follow-ups too
 * (§11 test 9).
 *
 * **Complete / reschedule / cancel:** the follow-up's assignee, the lead's owner (assigned to or created by the
 * user), or a `leads.edit` holder (§9.3), on a live lead and a live follow-up. Whether the follow-up is still
 * `pending` is the service's rule (a 422 with a reason, not a 403).
 */
final class LeadFollowUpPolicy
{
    use ChecksCrmPermissions;

    public function __construct(
        private readonly LeadPolicy $leads,
    ) {}

    public function view(User $user, LeadFollowUp $followUp): Response|bool
    {
        $lead = $followUp->resolveLead();

        if ($lead === null) {
            return Response::denyAsNotFound();
        }

        return $this->leads->view($user, $lead);
    }

    public function complete(User $user, LeadFollowUp $followUp): Response|bool
    {
        return $this->work($user, $followUp);
    }

    public function reschedule(User $user, LeadFollowUp $followUp): Response|bool
    {
        return $this->work($user, $followUp);
    }

    public function cancel(User $user, LeadFollowUp $followUp): Response|bool
    {
        return $this->work($user, $followUp);
    }

    private function work(User $user, LeadFollowUp $followUp): Response|bool
    {
        $visible = $this->view($user, $followUp);

        if ($visible !== true) {
            return $visible;
        }

        $lead = $followUp->resolveLead();

        if ($lead === null || $this->isTrashed($lead) || $this->isTrashed($followUp)) {
            return false;
        }

        return $followUp->isAssignedTo($user)
            || $lead->isOwnedBy($user)
            || $this->holds($user, LeadPolicy::MODULE, Ability::Edit);
    }
}
