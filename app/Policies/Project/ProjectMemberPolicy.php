<?php

declare(strict_types=1);

namespace App\Policies\Project;

use App\Enums\Ability;
use App\Models\Project\ProjectMember;
use App\Models\User;
use App\Policies\Project\Concerns\ChecksProjectPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may change a project's team (phase-06 §4.2, §9).
 *
 * Reading the team is part of reading the project; changing it is `projects.assign`, which is why every
 * write method here checks the **projects** slug rather than one of its own. A separate
 * `project_members.*` module would have meant granting two permissions to express one trust.
 */
final class ProjectMemberPolicy
{
    use ChecksProjectPermissions;

    public const MODULE = 'projects';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, ProjectMember $member): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::View, $this->seesProject($user, $member->project));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign);
    }

    public function update(User $user, ProjectMember $member): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::Assign, $this->seesProject($user, $member->project));
    }

    public function delete(User $user, ProjectMember $member): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::Assign, $this->seesProject($user, $member->project));
    }
}
