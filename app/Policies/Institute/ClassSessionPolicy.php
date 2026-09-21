<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\ClassSession;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may cancel, move, hand over and close a class (phase-14-17 §2.30.9, §9).
 *
 * All four live under `timetable.change_status`: each one tells the roster something, and being
 * allowed to create a timetable is not the same as being allowed to call a class off the night
 * before. A terminal class — cancelled or rescheduled — takes no further move.
 */
final class ClassSessionPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'timetable';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, ClassSession $session): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $session->branch_id === null ? null : (int) $session->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, ClassSession $session): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($session)
            && ! $session->status->isTerminal()
            && $this->sharesBranch($user, $session->branch_id === null ? null : (int) $session->branch_id);
    }

    /** Cancel, reschedule, substitute, mark held — §4.2 puts all four here. */
    public function changeStatus(User $user, ClassSession $session): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($session)
            && ! $session->status->isTerminal()
            && $this->sharesBranch($user, $session->branch_id === null ? null : (int) $session->branch_id);
    }

    public function print(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print);
    }

    /**
     * A class is never deleted — it is cancelled, which the roster is told about. This exists so the
     * ability is answered deliberately rather than by an unhandled gate.
     */
    public function delete(User $user, ClassSession $session): bool
    {
        return false;
    }
}
