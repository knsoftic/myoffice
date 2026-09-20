<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Models\Hr\Employee;
use App\Models\User;
use App\Policies\Hr\Concerns\ChecksHrPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and work an employee record (phase-07 §4.1, §9).
 *
 * The §9 window decides the row: **all** for HR and Admin, **team** for a line manager (themselves plus
 * their reporting tree), **own** for self-service, **none** for anybody with neither a record nor a
 * permission. Falling outside the window is a **404**, never a 403 — otherwise somebody could walk the ids
 * and learn how many people work here and where the gaps are.
 *
 * **Money is a second question.** `current_gross_salary` and everything a structure or a slip holds needs
 * `employees.view_financial` on top of `view`. A line manager approves absence, not pay, and this is the
 * line that keeps those two apart.
 */
final class EmployeePolicy
{
    use ChecksHrPermissions;

    public const MODULE = 'employees';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->holds($user, self::MODULE, Ability::View);
    }

    public function view(User $user, Employee $employee): bool|Response
    {
        return $this->reaches(
            $user,
            self::MODULE,
            Ability::View,
            $this->seesEmployee($user, $employee) || $this->isSelf($user, $employee)
        );
    }

    /**
     * May this user see what this person is paid?
     */
    public function viewFinancial(User $user, Employee $employee): bool|Response
    {
        return $this->reaches(
            $user,
            self::MODULE,
            Ability::ViewFinancial,
            $this->seesEmployee($user, $employee)
        );
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Employee $employee): bool|Response
    {
        if ($this->isTrashed($employee)) {
            return false;
        }

        return $this->reaches($user, self::MODULE, Ability::Edit, $this->seesEmployee($user, $employee));
    }

    public function delete(User $user, Employee $employee): bool|Response
    {
        if ($this->isTrashed($employee)) {
            return false;
        }

        return $this->reaches($user, self::MODULE, Ability::Delete, $this->seesEmployee($user, $employee));
    }

    public function restore(User $user, Employee $employee): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    /**
     * Moving somebody between the states of §2.26, including the exit sequence.
     */
    public function changeStatus(User $user, Employee $employee): bool|Response
    {
        return $this->reaches(
            $user,
            self::MODULE,
            Ability::ChangeStatus,
            $this->seesEmployee($user, $employee)
        );
    }

    /**
     * Department, designation, shift and reporting line — the org chart, which is its own decision.
     */
    public function assign(User $user, Employee $employee): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::Assign, $this->seesEmployee($user, $employee));
    }

    public function print(User $user, Employee $employee): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::Print, $this->seesEmployee($user, $employee));
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function import(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Import);
    }
}
