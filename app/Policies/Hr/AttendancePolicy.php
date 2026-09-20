<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Models\Hr\Attendance;
use App\Models\User;
use App\Policies\Hr\Concerns\ChecksHrPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and change an attendance row (phase-07 §2.9, §9).
 *
 * **A locked row is not editable by anybody**, whatever they hold (HR-18). Payroll has been run for its
 * period, so the arithmetic behind a paid slip is frozen; the remedy is a payroll correction run, not an
 * edit here. That is a row fact rather than a permission fact, which is why it is checked before the
 * ability and returns a plain refusal.
 *
 * **An unlocked change still does not happen here.** Every manual change goes through
 * `AttendanceCorrectionService` so that both paths leave the same evidence (HR-6); `update` exists for the
 * correction flow to ask about, not for a screen to write through.
 */
final class AttendancePolicy
{
    use ChecksHrPermissions;

    public const MODULE = 'attendance';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->holds($user, self::MODULE, Ability::View);
    }

    public function view(User $user, Attendance $attendance): bool|Response
    {
        return $this->reaches(
            $user,
            self::MODULE,
            Ability::View,
            $this->seesEmployee($user, $attendance->employee) || $this->isSelf($user, $attendance->employee)
        );
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Attendance $attendance): bool|Response
    {
        if ($attendance->isLocked()) {
            return Response::deny(
                'This day is behind a locked payroll run. Attendance under a paid month does not change; '
                .'correct the payroll instead.'
            );
        }

        return $this->reaches($user, self::MODULE, Ability::Edit, $this->seesEmployee($user, $attendance->employee));
    }

    public function delete(User $user, Attendance $attendance): bool|Response
    {
        if ($attendance->isLocked()) {
            return Response::deny('A day behind a locked payroll run cannot be removed.');
        }

        return $this->reaches($user, self::MODULE, Ability::Delete, $this->seesEmployee($user, $attendance->employee));
    }

    public function approve(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Approve);
    }

    public function reject(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Reject);
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    public function import(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Import);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }
}
