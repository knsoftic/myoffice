<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Enums\PayrollRunStatus;
use App\Models\Hr\PayrollRun;
use App\Models\User;
use App\Policies\Hr\Concerns\ChecksHrPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may run, lock and pay a payroll (phase-07 §2.23, §6.7, §9).
 *
 * **Whoever locks a run should not be whoever pays it.** When one person holds both `payroll.approve` and
 * `payroll.change_status`, this policy refuses to let *that same person* mark the items of a run they
 * locked as paid (§9). It is a small thing and it is bypassable by two people colluding — the point is
 * that it cannot happen by accident, and that the audit log shows two names instead of one.
 *
 * **There is no unlock.** A locked run cannot be regenerated or cancelled by anybody; the states are the
 * whole security model here, so they are refused as row facts rather than as missing permissions.
 */
final class PayrollRunPolicy
{
    use ChecksHrPermissions;

    public const MODULE = 'payroll';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->holds($user, self::MODULE, Ability::View);
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function viewFinancial(User $user, PayrollRun $run): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Building or rebuilding the items — only while the run is still a draft.
     */
    public function generate(User $user, PayrollRun $run): bool|Response
    {
        if (! $run->status->isEditable()) {
            return Response::deny(sprintf(
                'Run %s is %s. A locked run is history; a mistake becomes a correction run.',
                $run->run_number,
                $run->status->label()
            ));
        }

        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * The irreversible moment (§6.7).
     */
    public function approve(User $user, PayrollRun $run): bool|Response
    {
        if ($run->status->isLocked()) {
            return Response::deny(sprintf('Run %s is already locked.', $run->run_number));
        }

        if ($run->status === PayrollRunStatus::Cancelled) {
            return Response::deny(sprintf('Run %s was cancelled.', $run->run_number));
        }

        return $this->holds($user, self::MODULE, Ability::Approve);
    }

    /**
     * Paying. Refused for the person who locked this run when they hold both abilities (§9).
     */
    public function changeStatus(User $user, PayrollRun $run): bool|Response
    {
        if (! $this->holds($user, self::MODULE, Ability::ChangeStatus)) {
            return false;
        }

        if ($run->locked_by !== null
            && (int) $run->locked_by === (int) $user->getKey()
            && $this->holds($user, self::MODULE, Ability::Approve)) {
            return Response::deny(
                'You locked this run, so somebody else records the payments. Whoever decides a payroll '
                .'is not whoever disburses it.'
            );
        }

        return true;
    }

    public function cancel(User $user, PayrollRun $run): bool|Response
    {
        if (! $run->status->isEditable()) {
            return Response::deny(sprintf(
                'Run %s is %s and can no longer be cancelled.',
                $run->run_number,
                $run->status->label()
            ));
        }

        return $this->holds($user, self::MODULE, Ability::ChangeStatus);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function print(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print);
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }
}
