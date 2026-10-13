<?php

declare(strict_types=1);

namespace App\Observers\Hr;

use App\Models\Hr\Attendance;
use App\Models\Hr\Employee;
use App\Services\Hr\AttendanceDeductionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps attendance deductions in step with attendance — after every write that could change one.
 *
 * Every path that changes a day goes through an Eloquent save on `attendances`: a punch, the day close,
 * a correction, a leave approved or cancelled (a recompute), a shift or holiday change (a recompute), an
 * import. So this one hook covers them all, and payroll generation re-syncs anyway as the backstop.
 *
 * **After commit, once per employee-month per request.** A day close or a recompute touches many rows in
 * one transaction; each employee's month is synced once, after the transaction commits, so a rollback
 * never leaves a deduction behind for attendance that never happened. A failure here is reported and
 * never fails the attendance write itself.
 */
final class AttendanceDeductionObserver
{
    /** Attributes whose change can change a deduction. */
    private const RELEVANT = ['status', 'day_type', 'payable_factor', 'attendance_date', 'late_minutes', 'leave_request_id', 'check_in_at'];

    /** @var array<string, true> employee|year|month already queued in this request */
    private static array $queued = [];

    public function saved(Attendance $attendance): void
    {
        if (! $attendance->wasRecentlyCreated && ! $attendance->wasChanged(self::RELEVANT)) {
            return;
        }

        $this->queue($attendance);

        // A row moved to another month leaves a month behind that needs re-syncing too.
        $previous = $attendance->getOriginal('attendance_date');

        if ($attendance->wasChanged('attendance_date') && $previous !== null) {
            $this->queueFor((int) $attendance->employee_id, Carbon::parse($previous));
        }
    }

    public function deleted(Attendance $attendance): void
    {
        $this->queue($attendance);
    }

    /**
     * A rolled-back transaction discards the after-commit syncs it held, so the months they covered must
     * not stay marked as queued — or the next write to them in this process would be skipped. Clearing
     * everything is safe: at worst a month is synced twice, and the sync is idempotent.
     */
    public static function forgetQueued(): void
    {
        self::$queued = [];
    }

    private function queue(Attendance $attendance): void
    {
        if ($attendance->attendance_date === null || $attendance->employee_id === null) {
            return;
        }

        $this->queueFor((int) $attendance->employee_id, $attendance->attendance_date);
    }

    private function queueFor(int $employeeId, Carbon $date): void
    {
        $year = (int) $date->year;
        $month = (int) $date->month;
        $key = $employeeId.'|'.$year.'|'.$month;

        if (isset(self::$queued[$key])) {
            return;
        }

        self::$queued[$key] = true;

        DB::afterCommit(static function () use ($employeeId, $year, $month, $key): void {
            unset(self::$queued[$key]);

            $employee = Employee::query()->find($employeeId);

            if ($employee !== null) {
                app(AttendanceDeductionService::class)->syncIfNeeded($employee, $year, $month);
            }
        });
    }
}
