<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Enums\AttendanceCorrectionStatus;
use App\Models\Hr\AttendanceCorrection;
use App\Models\User;
use App\Policies\Hr\Concerns\ChecksHrPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may ask for, approve and refuse an attendance correction (phase-07 §2.10, §6.8, §9).
 *
 * The correction row is **evidence** and is append-only: there is no `update` and no `delete` here at all,
 * because deleting the explanation of why a number changed is the one thing that would make the audit
 * trail worthless.
 *
 * An employee may withdraw their own request while it is still pending — that is `cancel`, and it leaves
 * the row behind with a terminal status rather than removing it.
 */
final class AttendanceCorrectionPolicy
{
    use ChecksHrPermissions;

    public const MODULE = 'attendance';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, AttendanceCorrection $correction): bool|Response
    {
        if ($this->isSelf($user, $correction->employee)) {
            return true;
        }

        return $this->reaches($user, self::MODULE, Ability::View, $this->seesEmployee($user, $correction->employee));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create)
            || $this->holds($user, 'employee_self_service', Ability::Create);
    }

    public function approve(User $user, AttendanceCorrection $correction): bool|Response
    {
        if ($correction->status !== AttendanceCorrectionStatus::Pending) {
            return Response::deny(sprintf('This correction was already %s.', $correction->status->label()));
        }

        if ($this->isSelf($user, $correction->employee)) {
            return Response::deny('Nobody approves their own correction.');
        }

        return $this->reaches($user, self::MODULE, Ability::Approve, $this->seesEmployee($user, $correction->employee));
    }

    public function reject(User $user, AttendanceCorrection $correction): bool|Response
    {
        if ($correction->status !== AttendanceCorrectionStatus::Pending) {
            return Response::deny(sprintf('This correction was already %s.', $correction->status->label()));
        }

        return $this->reaches($user, self::MODULE, Ability::Reject, $this->seesEmployee($user, $correction->employee));
    }

    /**
     * Withdrawing one's own pending request.
     */
    public function cancel(User $user, AttendanceCorrection $correction): bool|Response
    {
        if ($correction->status !== AttendanceCorrectionStatus::Pending) {
            return Response::deny(sprintf('This correction was already %s.', $correction->status->label()));
        }

        return $this->isSelf($user, $correction->employee)
            || $this->holds($user, self::MODULE, Ability::ChangeStatus);
    }
}
