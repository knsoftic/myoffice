<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\GradeScale;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who maintains the grade ladders (phase-19-23 §9, §4.1).
 *
 * **A module of its own because §82's grade comes from a scale an exam officer maintains**, and giving
 * somebody that right does not imply the right to publish results. The two are separately grantable,
 * which is the whole reason `grade_scales` is not folded into `results`.
 *
 * **A scale has no branch.** It is institute-wide by design: two branches grading the same course on
 * different ladders would make a transferred student's record unreadable. So there is no branch check
 * here, and that absence is deliberate rather than an omission.
 *
 * **`delete` is refused once a scale has graded anybody** — and the model refuses it too, including
 * the soft delete a policy alone would miss when `Gate::before` waves a Super Admin past (D124). The
 * supported act is deactivation (INV-20-4): the scale behind a printed result card has to stay
 * reachable, because the row's own snapshot columns are only half the provenance.
 */
final class GradeScalePolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'grade_scales';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, GradeScale $scale): bool
    {
        return $this->holds($user, self::MODULE, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, GradeScale $scale): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit) && ! $this->isTrashed($scale);
    }

    /** Making it the default, and taking it out of use. */
    public function changeStatus(User $user, GradeScale $scale): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus) && ! $this->isTrashed($scale);
    }

    /**
     * Only a scale nothing has been graded with. Anything else is deactivated — see the class note.
     */
    public function delete(User $user, GradeScale $scale): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($scale)
            && ! (bool) $scale->getAttribute('is_default')
            && ! $scale->hasBeenUsed();
    }

    public function restore(User $user, GradeScale $scale): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($scale);
    }
}
