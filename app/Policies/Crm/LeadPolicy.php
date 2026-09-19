<?php

declare(strict_types=1);

namespace App\Policies\Crm;

use App\Enums\Ability;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Policies\Crm\Concerns\ChecksCrmPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may work a lead (phase-05 §4.2, §9.1, §9.3).
 *
 * **Pipeline visibility ([D-P5-8], D30).** `leads.view_any` reaches every lead; `leads.view` alone reaches only
 * leads assigned to or created by the user — the rule of {@see LeadVisibilityScope}, restated here so a check
 * never depends on how the model was loaded. Holding the ability without reaching the lead answers **404**
 * (§11 test 9); lacking the ability is a 403 (test 10).
 *
 * **Row writes** also need the lead to be live: a trashed lead is read-only until it is restored.
 *
 * **Conversion** needs no ability of its own: `leads.edit` **and** `clients.create` (§4.1, test 47); the project
 * hand-off additionally needs `projects.create`, checked where the hand-off is offered ({@see convertToProject()}).
 *
 * `Gate::before` waves Super Admin through every method while the module is enabled, which is why every state
 * rule (legal transitions, live-conversion refusals) is repeated in the owning service.
 */
final class LeadPolicy
{
    use ChecksCrmPermissions;

    public const MODULE = 'leads';

    /**
     * The index, the board and the worklist (routes gate on `leads.view`); the rows are narrowed by the scope.
     */
    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Lead $lead): Response|bool
    {
        if (! $this->holds($user, self::MODULE, Ability::View)) {
            return false;
        }

        return $this->reaches($user, $lead);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Lead $lead): Response|bool
    {
        return $this->rowAbility($user, $lead, Ability::Edit);
    }

    public function delete(User $user, Lead $lead): Response|bool
    {
        return $this->rowAbility($user, $lead, Ability::Delete);
    }

    public function restore(User $user, Lead $lead): Response|bool
    {
        if (! $this->holds($user, self::MODULE, Ability::Restore) || ! $this->isTrashed($lead)) {
            return false;
        }

        return $this->reaches($user, $lead);
    }

    /**
     * Super Admin only (§2.2) — `Gate::before` grants it; nobody else may. A converted lead cannot be removed at
     * all (`lead_conversions.lead_id` is `restrictOnDelete`).
     */
    public function forceDelete(User $user, Lead $lead): bool
    {
        return false;
    }

    public function changeStatus(User $user, Lead $lead): Response|bool
    {
        return $this->rowAbility($user, $lead, Ability::ChangeStatus);
    }

    public function assign(User $user, Lead $lead): Response|bool
    {
        return $this->rowAbility($user, $lead, Ability::Assign);
    }

    /**
     * `leads.edit` **and** `clients.create` (§4.1). The second check re-enters `Gate::before`, so a disabled
     * `clients` module closes conversion too.
     */
    public function convert(User $user, Lead $lead): Response|bool
    {
        if (! $this->holds($user, self::MODULE, Ability::Edit)
            || ! $this->holds($user, ClientPolicy::MODULE, Ability::Create)
            || $this->isTrashed($lead)) {
            return false;
        }

        return $this->reaches($user, $lead);
    }

    /**
     * The optional project hand-off of a conversion: `convert` plus `projects.create` (§6.4 step 8). Whether the
     * capability exists at all (`ProjectCreator::isAvailable()`) is the controller's 404, not a permission.
     */
    public function convertToProject(User $user, Lead $lead): Response|bool
    {
        $convert = $this->convert($user, $lead);

        if ($convert !== true) {
            return $convert;
        }

        return $this->holds($user, 'projects', Ability::Create);
    }

    public function import(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Import);
    }

    /**
     * The export is still narrowed to the §9 scope by `LeadExporter` (§11 test 83).
     */
    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function print(User $user, ?Lead $lead = null): Response|bool
    {
        if (! $this->holds($user, self::MODULE, Ability::Print)) {
            return false;
        }

        return $lead === null ? true : $this->reaches($user, $lead);
    }

    /**
     * A timeline entry is a change to the lead (`admin.leads.activities.store` gates on `update`).
     */
    public function logActivity(User $user, Lead $lead): Response|bool
    {
        return $this->update($user, $lead);
    }

    /**
     * Scheduling a follow-up is a change to the lead (`admin.leads.follow-ups.store` gates on `update`).
     */
    public function scheduleFollowUp(User $user, Lead $lead): Response|bool
    {
        return $this->update($user, $lead);
    }

    /**
     * §9.1: `leads.view_any`, or the lead is assigned to or created by the user. Otherwise 404.
     */
    public function reaches(User $user, Lead $lead): Response|bool
    {
        if (LeadVisibilityScope::seesWholePipeline($user) || $lead->isOwnedBy($user)) {
            return true;
        }

        return Response::denyAsNotFound();
    }

    private function rowAbility(User $user, Lead $lead, Ability $ability): Response|bool
    {
        if (! $this->holds($user, self::MODULE, $ability) || $this->isTrashed($lead)) {
            return false;
        }

        return $this->reaches($user, $lead);
    }
}
