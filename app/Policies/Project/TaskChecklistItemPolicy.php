<?php

declare(strict_types=1);

namespace App\Policies\Project;

use App\Models\Project\TaskChecklistItem;
use App\Models\User;
use App\Policies\Project\Concerns\ChecksProjectPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Ticking, renaming and removing a checklist line (phase-06 §2.6, §7.3).
 *
 * A checklist line has no permissions of its own: it is part of its task, so every question here forwards
 * to {@see TaskPolicy::update()}. Ticking a box moves the task's progress, so whoever may work the task
 * may tick it, and nobody else.
 */
final class TaskChecklistItemPolicy
{
    use ChecksProjectPermissions;

    public function __construct(private readonly TaskPolicy $tasks) {}

    public function view(User $user, TaskChecklistItem $item): bool|Response
    {
        return $this->tasks->view($user, $item->task);
    }

    public function create(User $user): bool
    {
        return $this->tasks->create($user);
    }

    public function update(User $user, TaskChecklistItem $item): bool|Response
    {
        return $this->tasks->update($user, $item->task);
    }

    public function delete(User $user, TaskChecklistItem $item): bool|Response
    {
        return $this->tasks->update($user, $item->task);
    }
}
