<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Enums\PayrollItemStatus;
use App\Models\Hr\PayrollRunItem;
use App\Models\User;
use App\Policies\Hr\Concerns\ChecksHrPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may see, pay, hold and correct one salary slip (phase-07 §2.24, §6.7, §6.8, §9).
 *
 * The slip module (`salary_slips`) is separate from `payroll` on purpose (§4.1): an Accountant can read
 * and print slips without holding the right to lock a run, and a line manager holds neither — a manager
 * approves absence, not pay.
 *
 * **An employee reaches their own slip through self-service**, and the answer for anybody else's is a
 * 404, not a 403: with ids in the URL, a 403 would confirm that a slip exists for that id.
 */
final class PayrollRunItemPolicy
{
    use ChecksHrPermissions;

    public const MODULE = 'salary_slips';

    public const RUN_MODULE = 'payroll';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->holds($user, self::MODULE, Ability::View);
    }

    public function view(User $user, PayrollRunItem $item): bool|Response
    {
        if ($this->isSelf($user, $item->employee)) {
            return $this->holds($user, 'employee_self_service', Ability::ViewFinancial)
                || $this->holds($user, self::MODULE, Ability::View);
        }

        return $this->reaches($user, self::MODULE, Ability::View, $this->seesEmployee($user, $item->employee));
    }

    public function print(User $user, PayrollRunItem $item): bool|Response
    {
        if ($this->isSelf($user, $item->employee)) {
            return $this->holds($user, 'employee_self_service', Ability::Print);
        }

        return $this->reaches($user, self::MODULE, Ability::Print, $this->seesEmployee($user, $item->employee));
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    /**
     * Recording that this slip was paid. Only from `locked` — a draft can still change.
     */
    public function pay(User $user, PayrollRunItem $item): bool|Response
    {
        if (! $item->status->isPayable()) {
            return Response::deny(sprintf(
                'Slip %s is %s. Only a locked slip can be paid.',
                $item->slip_number,
                $item->status->label()
            ));
        }

        return $user->can('changeStatus', $item->run);
    }

    /**
     * Holding a slip back, and releasing it again.
     */
    public function hold(User $user, PayrollRunItem $item): bool|Response
    {
        if ($item->status === PayrollItemStatus::Paid) {
            return Response::deny(
                'This salary has already been paid. Money that has moved is corrected with a correction '
                .'run, not with a hold.'
            );
        }

        return $this->holds($user, self::RUN_MODULE, Ability::ChangeStatus);
    }

    /**
     * Issuing a correction against a locked slip (§6.8). Needs both the right to build a slip and the
     * right to approve one, because a correction is a payroll decision in miniature.
     */
    public function correct(User $user, PayrollRunItem $item): bool|Response
    {
        if (! $item->run?->status->isLocked()) {
            return Response::deny(
                'This slip is still a draft. Regenerate the run instead — a correction exists for slips '
                .'that can no longer be changed.'
            );
        }

        return $this->holds($user, self::RUN_MODULE, Ability::Create)
            && $this->holds($user, self::RUN_MODULE, Ability::Approve);
    }
}
