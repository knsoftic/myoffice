<?php

declare(strict_types=1);

namespace App\Policies\Project;

use App\Enums\Ability;
use App\Models\Project\ProjectMilestone;
use App\Models\User;
use App\Policies\Project\Concerns\ChecksProjectPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may work a milestone (phase-06 §4.2, §9).
 *
 * A milestone inherits its visibility from its project — there is no separate "milestone I can see".
 *
 * **`amount` has no ability of its own** (§4.2): it is money, so it is gated by
 * `projects.view_financial` through {@see ProjectPolicy::viewFinancial()}. Giving milestones their own
 * money ability would let a role see a payment schedule while being unable to see the contract it adds up
 * to.
 *
 * Deleting is refused by `MilestoneService` while a payment references the row; this policy answers the
 * permission question, the service answers the business one.
 */
final class ProjectMilestonePolicy
{
    use ChecksProjectPermissions;

    public const MODULE = 'project_milestones';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, ProjectMilestone $milestone): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::View, $this->seesProject($user, $milestone->project));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, ProjectMilestone $milestone): bool|Response
    {
        if ($this->isTrashed($milestone)) {
            return false;
        }

        return $this->reaches($user, self::MODULE, Ability::Edit, $this->seesProject($user, $milestone->project));
    }

    public function delete(User $user, ProjectMilestone $milestone): bool|Response
    {
        if ($this->isTrashed($milestone)) {
            return false;
        }

        return $this->reaches($user, self::MODULE, Ability::Delete, $this->seesProject($user, $milestone->project));
    }

    public function changeStatus(User $user, ProjectMilestone $milestone): bool|Response
    {
        if ($this->isTrashed($milestone)) {
            return false;
        }

        return $this->reaches($user, self::MODULE, Ability::ChangeStatus, $this->seesProject($user, $milestone->project));
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }
}
