<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\StudentFeeReminder;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may chase a student for money (phase-18 §4.1, §9).
 *
 * **The module exists so that chasing and charging are different rights.** A Receptionist can tell a
 * student their installment is due; the same Receptionist cannot edit the fee, add a discount or take
 * a refund. Folding "send a reminder" into `student_fees.edit` would have meant granting the second to
 * get the first.
 *
 * **There is no `update` and no `delete`, here or in the registry.** A reminder records that a message
 * left the building. It cannot be edited into having said something else, and deleting it would remove
 * the one piece of evidence a student disputing being chased would want. The model throws on both, and
 * the table carries no `deleted_at` (D19).
 */
final class StudentFeeReminderPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'fee_reminders';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, StudentFeeReminder $reminder): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $reminder->branch_id === null ? null : (int) $reminder->branch_id);
    }

    /**
     * "Send reminder now". The unique guard makes a second press within the same day a no-op that
     * says so, rather than a second message — that is the service's job, not this one's.
     */
    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function viewLogs(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs);
    }
}
