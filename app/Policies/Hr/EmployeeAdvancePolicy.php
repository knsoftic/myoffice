<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Enums\AdvanceStatus;
use App\Models\Hr\EmployeeAdvance;
use App\Models\User;
use App\Policies\Hr\Concerns\ChecksHrPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may request, approve and recover a salary advance (phase-07 §2.21, §4.1, §9).
 *
 * **The module has no `edit` and no `delete`** (§4.1). Once money has been handed over, the amount is
 * what was handed over; a mistake is corrected with a repayment row or a waiver, both of which carry a
 * reason and an actor. Cancellation and write-off are statuses, never deletions.
 */
final class EmployeeAdvancePolicy
{
    use ChecksHrPermissions;

    public const MODULE = 'employee_advances';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->holds($user, self::MODULE, Ability::View);
    }

    public function view(User $user, EmployeeAdvance $advance): bool|Response
    {
        if ($this->isSelf($user, $advance->employee)) {
            return true;
        }

        return $this->reaches($user, self::MODULE, Ability::View, $this->seesEmployee($user, $advance->employee));
    }

    public function viewFinancial(User $user, EmployeeAdvance $advance): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::ViewFinancial, $this->seesEmployee($user, $advance->employee));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function approve(User $user, EmployeeAdvance $advance): bool|Response
    {
        if ($advance->status !== AdvanceStatus::Requested) {
            return Response::deny(sprintf('This advance is already %s.', $advance->status->label()));
        }

        if ($this->isSelf($user, $advance->employee)) {
            return Response::deny('Nobody approves their own advance.');
        }

        return $this->holds($user, self::MODULE, Ability::Approve);
    }

    public function reject(User $user, EmployeeAdvance $advance): bool|Response
    {
        if ($advance->status !== AdvanceStatus::Requested) {
            return Response::deny(sprintf('This advance is already %s.', $advance->status->label()));
        }

        return $this->holds($user, self::MODULE, Ability::Reject);
    }

    /**
     * Disbursing, recording a recovery, cancelling — the states of §2.26.
     */
    public function changeStatus(User $user, EmployeeAdvance $advance): bool|Response
    {
        if ($advance->status->isTerminal()) {
            return Response::deny(sprintf('This advance is %s.', $advance->status->label()));
        }

        return $this->holds($user, self::MODULE, Ability::ChangeStatus);
    }

    /**
     * Forgiving part of what is owed — always the approver's decision, never the requester's.
     */
    public function waive(User $user, EmployeeAdvance $advance): bool|Response
    {
        if ($this->isSelf($user, $advance->employee)) {
            return Response::deny('Nobody waives their own advance.');
        }

        return $this->holds($user, self::MODULE, Ability::Approve);
    }
}
