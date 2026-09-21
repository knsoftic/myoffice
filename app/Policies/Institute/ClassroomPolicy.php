<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\Classroom;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may add, edit, close and remove a room (phase-14-17 §4.1, §9).
 *
 * A module of its own so a branch administrator can be given the rooms without being given the
 * batches that fill them — two different jobs that happen to touch the same calendar.
 */
final class ClassroomPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'classrooms';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Classroom $room): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $room->branch_id === null ? null : (int) $room->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Classroom $room): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($room)
            && $this->sharesBranch($user, $room->branch_id === null ? null : (int) $room->branch_id);
    }

    /** Opening and closing a room is a status change, not an edit of what the room is. */
    public function changeStatus(User $user, Classroom $room): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($room)
            && $this->sharesBranch($user, $room->branch_id === null ? null : (int) $room->branch_id);
    }

    /**
     * The service refuses a room that ever held a class; this refuses one that is booked now. Both
     * checks exist because they answer different questions, and the stricter one is the service's.
     */
    public function delete(User $user, Classroom $room): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($room)
            && $room->sessions()->doesntExist()
            && $this->sharesBranch($user, $room->branch_id === null ? null : (int) $room->branch_id);
    }

    public function restore(User $user, Classroom $room): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($room);
    }
}
