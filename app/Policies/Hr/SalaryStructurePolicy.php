<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Enums\SalaryStructureStatus;
use App\Models\Hr\SalaryStructure;
use App\Models\User;
use App\Policies\Hr\Concerns\ChecksHrPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may read and version a salary structure (phase-07 §2.19, §4.1, §9).
 *
 * **The module deliberately has no `edit` and no `delete`** (§4.1): a rate is never updated and a version
 * is never removed. A raise is `create` — the next version — and that is the only way a salary changes.
 * Refusing the abilities at the registry rather than in a service is what makes the rule visible on the
 * role screen instead of buried in code.
 *
 * A line manager sees none of this even for their own reports: pay is not part of approving absence (§9).
 */
final class SalaryStructurePolicy
{
    use ChecksHrPermissions;

    public const MODULE = 'salary_structures';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->holds($user, self::MODULE, Ability::View);
    }

    public function view(User $user, SalaryStructure $structure): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::View, $this->seesEmployee($user, $structure->employee));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function approve(User $user, SalaryStructure $structure): bool
    {
        return $this->holds($user, self::MODULE, Ability::Approve);
    }

    /**
     * Withdrawing a version that has not started yet. Anything already in force is history.
     */
    public function changeStatus(User $user, SalaryStructure $structure): bool|Response
    {
        if ($structure->status !== SalaryStructureStatus::Scheduled) {
            return Response::deny(sprintf(
                'Version %d is %s. A version that has been in force is history; change the salary by '
                .'adding the next version.',
                (int) $structure->version,
                $structure->status->label()
            ));
        }

        return $this->holds($user, self::MODULE, Ability::ChangeStatus);
    }
}
