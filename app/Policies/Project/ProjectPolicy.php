<?php

declare(strict_types=1);

namespace App\Policies\Project;

use App\Enums\Ability;
use App\Models\Project\Project;
use App\Models\User;
use App\Policies\Crm\Concerns\ResolvesPortalClient;
use App\Policies\Project\Concerns\ChecksProjectPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and run a project (phase-06 §4.2, §9).
 *
 * **Reaching a row is a 404, not a 403** (INV-P15). A developer who holds `projects.view` but is not on a
 * project gets the same answer for "this project exists but is not yours" as for "no such project" — which
 * is the only way an id cannot be probed.
 *
 * **Money is a second permission, not a second screen.** {@see viewFinancial()} gates `budget_amount`,
 * `project_value`, `discount_amount`, `net_value`, every `commission_*` column and
 * `project_milestones.amount`. §9 requires those columns to be **absent from the query**, not blanked in
 * the view, so the controller asks this policy before it builds the SELECT.
 *
 * **Revising a value needs both `view_financial` and `edit`** ({@see revise()}), which is §4.2's deliberate
 * answer to "who may change the contract value": no new ability was invented, because trusting somebody
 * with money and trusting them to edit are the two things that actually matter.
 */
final class ProjectPolicy
{
    use ChecksProjectPermissions;
    use ResolvesPortalClient;

    public const MODULE = 'projects';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Project $project): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::View, $this->seesProject($user, $project));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Project $project): bool|Response
    {
        if ($this->isTrashed($project)) {
            return false;
        }

        return $this->reaches($user, self::MODULE, Ability::Edit, $this->seesProject($user, $project));
    }

    public function delete(User $user, Project $project): bool|Response
    {
        if ($this->isTrashed($project)) {
            return false;
        }

        return $this->reaches($user, self::MODULE, Ability::Delete, $this->seesProject($user, $project));
    }

    public function restore(User $user, Project $project): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($project);
    }

    public function changeStatus(User $user, Project $project): bool|Response
    {
        if ($this->isTrashed($project)) {
            return false;
        }

        return $this->reaches($user, self::MODULE, Ability::ChangeStatus, $this->seesProject($user, $project));
    }

    /**
     * The team tab's write side (§4.2: `assign` gates team changes).
     */
    public function assign(User $user, Project $project): bool|Response
    {
        if ($this->isTrashed($project)) {
            return false;
        }

        return $this->reaches($user, self::MODULE, Ability::Assign, $this->seesProject($user, $project));
    }

    /**
     * May this user see money on this project at all? Asked **before** the SELECT is built (§9).
     */
    public function viewFinancial(User $user, Project $project): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::ViewFinancial, $this->seesProject($user, $project));
    }

    /**
     * §4.2: changing a contract value needs `view_financial` **and** `edit` — no ability of its own.
     */
    public function revise(User $user, Project $project): bool|Response
    {
        if ($this->isTrashed($project)) {
            return false;
        }

        if (! $this->holds($user, self::MODULE, Ability::ViewFinancial)
            || ! $this->holds($user, self::MODULE, Ability::Edit)) {
            return false;
        }

        return $this->seesProject($user, $project) ? true : Response::denyAsNotFound();
    }

    /**
     * The client panel's own rule — `client.projects.show` checks this (phase-05 §7 wrote the route
     * expecting Phase 6 to answer it; phase-06 §7.7, §9's Client row).
     *
     * A project belonging to a **different** client is a **404, not a 403**: a 403 would confirm that the
     * project exists, which is exactly what an id-probing client is trying to find out (INV-P15).
     */
    public function viewByClient(User $user, Project $project): bool|Response
    {
        if (! $user->can('client_portal.projects')) {
            return false;
        }

        // The client id comes from `ClientContext`, never from the request — the one resolution per
        // request every panel query already shares (phase-05 §9.2).
        $clientId = $this->portalClientId($user);

        return $clientId !== null && (int) $project->client_id === $clientId
            ? true
            : Response::denyAsNotFound();
    }

    public function viewLogs(User $user, Project $project): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::ViewLogs, $this->seesProject($user, $project));
    }

    public function print(User $user, Project $project): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::Print, $this->seesProject($user, $project));
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }
}
