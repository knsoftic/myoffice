<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\TimetableEntry;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may set and change the weekly pattern (§71, phase-14-17 §9).
 *
 * `timetable.change_status` is the ability that covers ending a slot — and, per [D-IN-14], accepting
 * a teacher or room clash with a reason. It is not `edit`, because overriding a clash is a decision
 * somebody answers for, and the two should be grantable apart.
 */
final class TimetableEntryPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'timetable';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, TimetableEntry $entry): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $entry->branch_id === null ? null : (int) $entry->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, TimetableEntry $entry): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($entry)
            && $this->sharesBranch($user, $entry->branch_id === null ? null : (int) $entry->branch_id);
    }

    /** Ending a slot, and overriding a clash with a reason ([D-IN-14]). */
    public function changeStatus(User $user, TimetableEntry $entry): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($entry)
            && $this->sharesBranch($user, $entry->branch_id === null ? null : (int) $entry->branch_id);
    }

    public function print(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    /**
     * Removing a slot cancels the classes it was going to produce, so it asks for `delete` and is
     * refused on a rule that has already produced classes that were held.
     */
    public function delete(User $user, TimetableEntry $entry): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($entry)
            && $entry->sessions()->where('status', 'held')->doesntExist()
            && $this->sharesBranch($user, $entry->branch_id === null ? null : (int) $entry->branch_id);
    }

    public function restore(User $user, TimetableEntry $entry): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($entry);
    }
}
