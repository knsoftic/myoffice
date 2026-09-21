<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\StudentApplication;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may triage the §67 inbox (phase-14-17 §4.1, §9).
 *
 * **There is no `delete`, here or in the module.** A public submission is evidence that somebody
 * asked; it is rejected, marked duplicate or withdrawn, each with a reason, and the row stays. A
 * deleted application is a question nobody can answer afterwards — including "did we ever reply".
 *
 * **Converting takes two rights, not one.** The module boundary exists so a receptionist can triage
 * the inbox without holding `students.create`; converting crosses that boundary, so it asks for both.
 * Anything less would make the boundary decorative.
 */
final class StudentApplicationPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'student_applications';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, StudentApplication $application): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $application->branch_id === null ? null : (int) $application->branch_id);
    }

    /** The walk-in typed at the front desk. The public form needs no permission at all. */
    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, StudentApplication $application): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && $application->status->isOpen()
            && $this->sharesBranch($user, $application->branch_id === null ? null : (int) $application->branch_id);
    }

    /** Claiming it for review is the `edit` right, and only on one nobody has decided yet. */
    public function claim(User $user, StudentApplication $application): bool
    {
        return $this->update($user, $application);
    }

    public function markDuplicate(User $user, StudentApplication $application): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $application->status->isOpen();
    }

    /**
     * Rejecting has its own ability: saying no to somebody is a decision a reviewer may be trusted to
     * record without being trusted to make.
     */
    public function reject(User $user, StudentApplication $application): bool
    {
        return $this->holds($user, self::MODULE, Ability::Reject)
            && $application->status->isOpen();
    }

    public function withdraw(User $user, StudentApplication $application): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $application->status->isOpen();
    }

    /**
     * Two modules, two permissions. `students.create` is what actually brings a person into the
     * student directory, and `admissions.create` is what puts money against them.
     */
    public function convert(User $user, StudentApplication $application): bool
    {
        return $application->status->isOpen()
            && $this->holds($user, 'students', Ability::Create)
            && $this->holds($user, 'admissions', Ability::Create)
            && $this->sharesBranch($user, $application->branch_id === null ? null : (int) $application->branch_id);
    }

    /** Deliberately false for everybody: the module declares no `delete` ability at all (§4.1). */
    public function delete(User $user, StudentApplication $application): bool
    {
        return false;
    }

    public function forceDelete(User $user, StudentApplication $application): bool
    {
        return false;
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function viewLogs(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs);
    }
}
