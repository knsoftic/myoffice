<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\StudentAttendance;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may take a register, and who may change one (§75, phase-14-17 §9, INV-I10).
 *
 * **`delete` is false for everybody, always.** Attendance is corrected, never removed: a register row
 * that disappears moves a percentage with no trace of why. The ability exists on the module because
 * the table carries the `deleted_at` `CLAUDE.md` §3 asks for, and this is where it is answered — a
 * gate that returned "no permission" would suggest the right permission could unlock it.
 *
 * **Marking and amending are different rights.** `create` is what a teacher needs at the classroom
 * door; `edit` is what somebody needs to revise a register after the lock window, and the service
 * asks for a reason on top. Whether this particular row is inside that window is the service's
 * question — a policy answers about a permission, not about a clock.
 */
final class StudentAttendancePolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'student_attendance';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, StudentAttendance $attendance): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($attendance));
    }

    /** Taking a register. */
    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /** Revising one. The window and the reason are the service's to enforce (INV-I10). */
    public function update(User $user, StudentAttendance $attendance): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && $this->sharesBranch($user, $this->branchOf($attendance));
    }

    public function changeStatus(User $user, StudentAttendance $attendance): bool
    {
        return $this->update($user, $attendance);
    }

    public function import(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Import);
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function print(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print);
    }

    public function viewLogs(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs);
    }

    /**
     * Never. Not for a Super Admin, not with `student_attendance.delete`, not at all (INV-I10).
     */
    public function delete(User $user, StudentAttendance $attendance): bool
    {
        return false;
    }

    public function restore(User $user, StudentAttendance $attendance): bool
    {
        return false;
    }

    private function branchOf(StudentAttendance $attendance): ?int
    {
        $branchId = $attendance->batch?->branch_id;

        return $branchId === null ? null : (int) $branchId;
    }
}
