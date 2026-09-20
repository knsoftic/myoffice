<?php

declare(strict_types=1);

namespace App\Policies\Project;

use App\Models\Project\ProjectValueRevision;
use App\Models\User;
use App\Policies\Project\Concerns\ChecksProjectPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Reading the value history (phase-06 §6.1, INV-P1, INV-P3).
 *
 * **There is no `update` and no `delete` here, and that is the point.** The table is append-only: the
 * model throws, a `BEFORE DELETE` trigger raises underneath it, and this policy declines to offer an
 * ability that nothing should ever grant. A wrong value is corrected by the next revision.
 *
 * Reading needs `projects.view_financial` — the history is a list of contract values, which is money.
 * A collaborator has **no access at all** (§9), which falls out of them never holding that permission.
 */
final class ProjectValueRevisionPolicy
{
    use ChecksProjectPermissions;

    public const MODULE = 'projects';

    public function viewAny(User $user): bool
    {
        return $user->can('projects.view_financial');
    }

    public function view(User $user, ProjectValueRevision $revision): bool|Response
    {
        if (! $user->can('projects.view_financial')) {
            return false;
        }

        return $this->seesProject($user, $revision->project) ? true : Response::denyAsNotFound();
    }
}
