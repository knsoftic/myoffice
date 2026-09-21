<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\Teacher;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may hire, edit, pay and retire a teacher (§72, phase-14-17 §9).
 *
 * **`salary` is behind `teachers.view_financial`**, which the Course Coordinator deliberately does not
 * hold: a coordinator schedules people, and what they are paid is not part of scheduling.
 *
 * **A teacher who has ever taught is never deleted.** Every class, every register and every report
 * points at this row; removing it would take the attribution off all of them. The way out is
 * `resigned`, which the service refuses until their scheduled classes are reassigned.
 */
final class TeacherPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'teachers';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Teacher $teacher): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $teacher->branch_id === null ? null : (int) $teacher->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Teacher $teacher): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($teacher)
            && $this->sharesBranch($user, $teacher->branch_id === null ? null : (int) $teacher->branch_id);
    }

    /** Attaching courses is `assign`, not `edit`: it is who teaches what, not who they are. */
    public function assign(User $user, Teacher $teacher): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign)
            && ! $this->isTrashed($teacher)
            && $this->sharesBranch($user, $teacher->branch_id === null ? null : (int) $teacher->branch_id);
    }

    public function changeStatus(User $user, Teacher $teacher): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($teacher)
            && $this->sharesBranch($user, $teacher->branch_id === null ? null : (int) $teacher->branch_id);
    }

    /** The salary column, wherever a teacher is rendered. */
    public function viewFinancial(User $user, ?Teacher $teacher = null): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewFinancial)
            && ($teacher === null || $this->view($user, $teacher));
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    /**
     * Only somebody who never took a class. Once a register carries their name, the row is what the
     * attribution points at.
     */
    public function delete(User $user, Teacher $teacher): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($teacher)
            && $teacher->sessions()->doesntExist()
            && $teacher->batches()->doesntExist()
            && $this->sharesBranch($user, $teacher->branch_id === null ? null : (int) $teacher->branch_id);
    }

    public function restore(User $user, Teacher $teacher): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($teacher);
    }
}
