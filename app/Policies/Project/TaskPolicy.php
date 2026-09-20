<?php

declare(strict_types=1);

namespace App\Policies\Project;

use App\Enums\Ability;
use App\Models\Project\Task;
use App\Models\User;
use App\Policies\Project\Concerns\ChecksProjectPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and work a task (phase-06 §4.2, §9).
 *
 * **Seeing** is `Task::visibleTo()` asked for one row: inside a project the user can see, and — without
 * `tasks.view_any` — only their own assigned or reported work, or everything inside a project their
 * membership role manages.
 *
 * **Working on it is narrower than seeing it.** {@see update()} requires the user to be the assignee or to
 * manage the project: a developer who can read a colleague's card must not be able to rewrite it. Status
 * is the same rule with its own ability, because §4.2 keeps `change_status` and `assign` apart — moving a
 * card across the board is an everyday act, handing the work to somebody else is not.
 *
 * Reaching a row you may not see is a **404** (INV-P15).
 */
final class TaskPolicy
{
    use ChecksProjectPermissions;

    public const MODULE = 'tasks';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Task $task): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::View, $this->sees($user, $task));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Task $task): bool|Response
    {
        if ($this->isTrashed($task)) {
            return false;
        }

        if (! $this->holds($user, self::MODULE, Ability::Edit)) {
            return false;
        }

        if (! $this->sees($user, $task)) {
            return Response::denyAsNotFound();
        }

        return $this->worksOn($user, $task);
    }

    public function delete(User $user, Task $task): bool|Response
    {
        if ($this->isTrashed($task)) {
            return false;
        }

        if (! $this->holds($user, self::MODULE, Ability::Delete)) {
            return false;
        }

        return $this->sees($user, $task) ? true : Response::denyAsNotFound();
    }

    public function restore(User $user, Task $task): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($task);
    }

    /**
     * A board drag, a status select, or completing the card.
     */
    public function changeStatus(User $user, Task $task): bool|Response
    {
        if ($this->isTrashed($task)) {
            return false;
        }

        if (! $this->holds($user, self::MODULE, Ability::ChangeStatus)) {
            return false;
        }

        if (! $this->sees($user, $task)) {
            return Response::denyAsNotFound();
        }

        return $this->worksOn($user, $task);
    }

    /**
     * Handing the work to somebody else — deliberately a different ability from moving the card (§4.2).
     */
    public function assign(User $user, Task $task): bool|Response
    {
        if ($this->isTrashed($task)) {
            return false;
        }

        return $this->reaches($user, self::MODULE, Ability::Assign, $this->sees($user, $task));
    }

    public function viewLogs(User $user, Task $task): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::ViewLogs, $this->sees($user, $task));
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    /**
     * The client-panel rule (§7.7, §9, F-3.1): the **AND** of the global setting and the per-row flag.
     *
     * Both halves are checked here as well as in the query, because a policy that trusted the query would
     * be one refactor away from leaking an internal task.
     */
    public function viewByClient(User $user, Task $task): bool|Response
    {
        if (! (bool) setting('projects.client_can_see_tasks', true) || ! $task->is_client_visible) {
            return Response::denyAsNotFound();
        }

        if (! $user->can('client_portal.tasks')) {
            return false;
        }

        $clientId = $user->client?->getKey();

        return $clientId !== null && (int) $task->project?->client_id === (int) $clientId
            ? true
            : Response::denyAsNotFound();
    }

    private function sees(User $user, Task $task): bool
    {
        if ($user->can('tasks.view_any') && $this->seesProject($user, $task->project)) {
            return true;
        }

        return Task::query()
            ->withoutGlobalScopes()
            ->whereKey($task->getKey())
            ->visibleTo($user)
            ->exists();
    }

    /**
     * Writing needs more than reading: the assignee, or somebody who manages the project.
     */
    private function worksOn(User $user, Task $task): bool
    {
        if ((int) $task->assigned_user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->managesProject($user, $task->project);
    }
}
