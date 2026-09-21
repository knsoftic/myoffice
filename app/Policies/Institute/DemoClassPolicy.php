<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\DemoClass;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may book, move and mark a demo class (§87, phase-14-17 §9).
 *
 * **A demo that has happened is not edited.** Once it is attended, missed or cancelled, the slot it
 * held is history — the teacher's week and the §88 demo-to-admission rate are built from it, and a
 * retrospective edit would change a number somebody has already read.
 *
 * **`print` is its own ability** because the slip is what an attendee is handed at reception, and
 * being allowed to print one is not the same as being allowed to book one.
 */
final class DemoClassPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'demo_classes';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, DemoClass $demo): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $demo->branch_id === null ? null : (int) $demo->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, DemoClass $demo): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($demo)
            && $demo->status->holdsASlot()
            && $this->sharesBranch($user, $demo->branch_id === null ? null : (int) $demo->branch_id);
    }

    /** Moving it is an edit, and only while it is still ahead of somebody. */
    public function reschedule(User $user, DemoClass $demo): bool
    {
        return $this->update($user, $demo);
    }

    public function changeStatus(User $user, DemoClass $demo): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $demo->status->isTerminal()
            && $this->sharesBranch($user, $demo->branch_id === null ? null : (int) $demo->branch_id);
    }

    /** Converting creates a student and an admission, so it asks those modules. */
    public function convert(User $user, DemoClass $demo): bool
    {
        return $this->view($user, $demo)
            && ! $demo->status->isTerminal()
            && $this->holds($user, 'students', Ability::Create)
            && $this->holds($user, 'admissions', Ability::Create);
    }

    /**
     * Only a booking nothing came of. A demo that was attended is the evidence somebody turned up,
     * and a converted one is the first link in an admission's chain.
     */
    public function delete(User $user, DemoClass $demo): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && $demo->status->holdsASlot()
            && $demo->converted_admission_id === null;
    }

    public function restore(User $user, DemoClass $demo): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    public function print(User $user, DemoClass $demo): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print) && $this->view($user, $demo);
    }

    public function assign(User $user, DemoClass $demo): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign);
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }
}
