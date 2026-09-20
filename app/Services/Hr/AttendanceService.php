<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\DayType;
use App\Models\Hr\Attendance;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveRequestDay;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Services\Hr\Exceptions\LockedAttendanceException;
use App\Support\Format;
use App\Support\Hr\ShiftWindow;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Attendance: the punches, and the deterministic resolution of what a day was (phase-07 §6.2, §6.3).
 *
 * **`resolve()` is a pure function of the row.** Give it the same snapshot, the same leave rows and the
 * same calendar and it produces the same status, minutes and `payable_factor` every time. That is what
 * makes the nightly recompute safe to run twice, and what makes a payroll figure explainable a year later
 * without anybody re-deriving it by hand.
 *
 * Two rows it will not touch, ever:
 *
 * - **`locked_at` is set** — payroll has been run for the period, so the arithmetic behind a paid slip is
 *   frozen (HR-18). Recomputing it would leave the evidence disagreeing with the payment, invisibly.
 * - **`is_manual = true`** — a human decided this row through `AttendanceCorrectionService`. If the
 *   nightly pass overwrote it, every correction would quietly evaporate overnight.
 *
 * Minutes are integers (HR-3) and `payable_factor` is a decimal string through `Money` (HR-4). No float
 * touches either: minutes are a count, and the factor is the single bridge into somebody's salary.
 */
class AttendanceService
{
    /** A punch-out further than this from the punch-in is a mistake, not a long day (§6.10 #7). */
    private const MAX_SHIFT_HOURS = 18;

    public function __construct(
        private readonly WorkCalendarService $calendar,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Punches
    |--------------------------------------------------------------------------
    */

    /**
     * Record the first punch of a day (§6.2, HR-1).
     *
     * **Idempotent by design.** The first punch wins and is never overwritten: a second check-in returns
     * the same row (§6.10 #6), because "I clicked twice" must not become a second attendance record and
     * must not move the time somebody actually arrived.
     */
    public function checkIn(
        Employee $employee,
        Carbon $at,
        AttendanceSource $source = AttendanceSource::SelfWeb,
        ?string $ip = null,
        ?Carbon $date = null,
    ): Attendance {
        $date = $date === null ? $this->businessDate($at) : $date->copy()->startOfDay();
        $at = $at->copy()->startOfSecond();

        return DB::transaction(function () use ($employee, $at, $source, $ip, $date): Attendance {
            $attendance = $this->rowFor($employee, $date, lock: true);

            $this->assertUnlocked($attendance);

            if ($attendance->check_in_at !== null) {
                return $attendance;
            }

            $attendance->forceFill([
                'check_in_at' => $at,
                'check_in_source' => $source,
                'check_in_ip' => $ip,
            ])->save();

            return $this->resolve($attendance);
        });
    }

    /**
     * Record the punch-out (§6.2).
     *
     * A later punch moves `check_out_at` **forward only** — somebody who left, came back and left again
     * worked until the second departure. A punch-out without a punch-in is refused rather than invented,
     * and one more than {@see MAX_SHIFT_HOURS} hours after the arrival is refused and flagged for
     * correction: it is almost always yesterday's forgotten punch, and guessing would pay a double day.
     */
    public function checkOut(
        Employee $employee,
        Carbon $at,
        AttendanceSource $source = AttendanceSource::SelfWeb,
        ?string $ip = null,
        ?Carbon $date = null,
    ): Attendance {
        $at = $at->copy()->startOfSecond();

        /**
         * The implausible punch is reported **out here**, after the transaction has closed. Flagging the
         * row and throwing in the same transaction would roll the flag back with the refusal, and the day
         * would look untouched to the correction queue — the one place that has to see it.
         *
         * @var array{0: Attendance, 1: bool} $result
         */
        $result = DB::transaction(function () use ($employee, $at, $source, $ip, $date): array {
            $attendance = $date !== null
                ? $this->rowFor($employee, $date->copy()->startOfDay(), lock: true)
                : $this->openRowFor($employee, $at);

            $this->assertUnlocked($attendance);

            if ($attendance->check_in_at === null) {
                throw HrRuleException::refuse(
                    'check_out_at',
                    'There is no check-in for this day, so there is nothing to check out of. Ask HR for a '
                    .'correction instead — a punch-out on its own would have to invent an arrival time.'
                );
            }

            if ($at->lessThan($attendance->check_in_at)) {
                throw HrRuleException::refuse(
                    'check_out_at',
                    'A check-out cannot be earlier than the check-in it closes.'
                );
            }

            if ($attendance->check_in_at->diffInHours($at) > self::MAX_SHIFT_HOURS) {
                return [$attendance, true];
            }

            if ($attendance->check_out_at === null || $at->greaterThan($attendance->check_out_at)) {
                $attendance->forceFill([
                    'check_out_at' => $at,
                    'check_out_source' => $source,
                    'check_out_ip' => $ip,
                    'requires_correction' => false,
                ])->save();
            }

            return [$this->resolve($attendance), false];
        });

        [$attendance, $implausible] = $result;

        if ($implausible) {
            $attendance->forceFill(['requires_correction' => true])->save();

            throw HrRuleException::refuse('check_out_at', sprintf(
                'That is more than %d hours after the check-in, which is almost always a punch-out that '
                .'was forgotten on a previous day. The day is flagged for correction instead.',
                self::MAX_SHIFT_HOURS
            ));
        }

        return $attendance;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution — §6.3, in order, stopping at the first step that decides
    |--------------------------------------------------------------------------
    */

    /**
     * Decide what this day was, from the row's own snapshot and nothing else (§6.3).
     *
     * Only `status`, the four minute columns, `day_type`, `holiday_id`, the leave columns,
     * `requires_correction` and `payable_factor` are ever written here. The punches are evidence and are
     * never rewritten by a recomputation.
     */
    public function resolve(Attendance $attendance): Attendance
    {
        // Step 0 — a locked row belongs to a paid period (HR-18).
        if ($attendance->isLocked()) {
            throw LockedAttendanceException::forPeriod(
                sprintf('Attendance for %s', $attendance->attendance_date->toDateString()),
                $attendance->lockedByRun?->run_number ?? '(unknown run)'
            );
        }

        // Step 1 — a correction decided this row; leave it exactly as the human left it.
        if ($attendance->is_manual) {
            return $attendance;
        }

        $employee = $attendance->employee;
        $date = $attendance->attendance_date->copy();
        $window = $this->windowFor($attendance);

        $holiday = $this->calendar->holidayFor($employee, $date);
        $dayType = $this->calendar->dayTypeFor($employee, $date);

        $decision = [
            'day_type' => $dayType,
            'holiday_id' => $holiday?->getKey(),
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'worked_minutes' => 0,
            'overtime_minutes' => 0,
            'requires_correction' => false,
        ];

        $worked = $this->workedMinutes($attendance, $window);

        // Step 2 — an exempt employee is never absent and never late (§6.10 #9).
        if ($employee->is_attendance_exempt) {
            return $this->write($attendance, $decision + [
                'status' => AttendanceStatus::Present,
                'payable_factor' => '1.0000',
                'worked_minutes' => $worked,
            ]);
        }

        // Step 3 — a public holiday. A punch is still measured; it is simply not paid as overtime here.
        if ($dayType === DayType::PublicHoliday) {
            return $this->write($attendance, $decision + [
                'status' => AttendanceStatus::Holiday,
                'payable_factor' => ($holiday?->is_paid ?? true) ? '1.0000' : '0.0000',
                'worked_minutes' => $worked,
                'overtime_minutes' => $worked,
            ]);
        }

        // Step 4 — a weekly off. `holiday_id` survives, so a report can say the holiday fell on a weekend.
        if ($dayType === DayType::WeeklyOff) {
            return $this->write($attendance, $decision + [
                'status' => AttendanceStatus::Holiday,
                'payable_factor' => '1.0000',
                'worked_minutes' => $worked,
                'overtime_minutes' => $worked,
            ]);
        }

        $leaveDay = $this->leaveDayFor($attendance);

        // Step 5 — a full day of approved, counted leave.
        if ($leaveDay !== null && ! $leaveDay->day_portion->isHalf()) {
            return $this->write($attendance, $decision + [
                'status' => AttendanceStatus::OnLeave,
                'leave_request_id' => $leaveDay->leave_request_id,
                'leave_type_id' => $leaveDay->leaveRequest?->leave_type_id,
                'payable_factor' => $leaveDay->is_paid ? '1.0000' : '0.0000',
            ]);
        }

        // Step 6 — half a day of leave; the other half is judged from the punches (§6.10 #11).
        if ($leaveDay !== null) {
            $workedHalf = $worked >= $window->minHalfDayMinutes ? '0.5000' : '0.0000';

            return $this->write($attendance, $decision + [
                'status' => AttendanceStatus::HalfDay,
                'leave_request_id' => $leaveDay->leave_request_id,
                'leave_type_id' => $leaveDay->leaveRequest?->leave_type_id,
                'worked_minutes' => $worked,
                'late_minutes' => $this->lateMinutes($attendance, $window),
                'payable_factor' => Money::round(
                    Money::add($leaveDay->is_paid ? '0.5000' : '0.0000', $workedHalf),
                    4
                ),
            ]);
        }

        // Step 7 — nobody turned up.
        if ($attendance->check_in_at === null) {
            return $this->write($attendance, $decision + [
                'status' => AttendanceStatus::Absent,
                'payable_factor' => '0.0000',
            ]);
        }

        // Step 8 — measure the day against the snapshot.
        $late = $this->lateMinutes($attendance, $window);
        $early = $this->earlyLeaveMinutes($attendance, $window);

        $decision['late_minutes'] = $late;
        $decision['early_leave_minutes'] = $early;
        $decision['worked_minutes'] = $worked;
        $decision['overtime_minutes'] = max(0, $worked - $window->expectedMinutes);

        // Step 9 — a forgotten punch-out never silently pays a full day, and never silently pays nothing.
        if ($attendance->check_out_at === null && $this->shiftHasEnded($window)) {
            return $this->write($attendance, $decision + [
                'status' => AttendanceStatus::HalfDay,
                'payable_factor' => '0.5000',
                'worked_minutes' => 0,
                'overtime_minutes' => 0,
                'requires_correction' => true,
            ]);
        }

        // Step 10 — punched in, no measurable time.
        if ($worked === 0) {
            return $this->write($attendance, $decision + [
                'status' => AttendanceStatus::Absent,
                'payable_factor' => '0.0000',
            ]);
        }

        // Step 11 — under the half-day threshold.
        if ($worked < $window->minHalfDayMinutes) {
            return $this->write($attendance, $decision + [
                'status' => AttendanceStatus::HalfDay,
                'payable_factor' => '0.5000',
            ]);
        }

        // Step 12 — a short day: the business decides whether it costs half a day.
        if ($worked < $window->minFullDayMinutes) {
            $halved = (bool) setting('hr.short_day_as_half_day', false);

            return $this->write($attendance, $decision + [
                'status' => $this->presentState($late, $early, $halved),
                'payable_factor' => $halved ? '0.5000' : '1.0000',
            ]);
        }

        // Step 13 — a full day. Late still shows as late; both minute columns are always stored.
        return $this->write($attendance, $decision + [
            'status' => $this->presentState($late, $early, false),
            'payable_factor' => '1.0000',
        ]);
    }

    /**
     * Re-resolve every unlocked, un-manual row for a date — after a holiday was declared, a shift changed
     * or a leave was approved or cancelled (§6.10 #3).
     *
     * Returns how many rows actually changed, so the caller can log "17 of 43 rows changed" rather than
     * "done".
     *
     * @param  EloquentCollection<int, Employee>|null  $employees
     */
    public function recomputeDate(Carbon $date, ?EloquentCollection $employees = null): int
    {
        $date = $date->copy()->startOfDay();
        $this->calendar->preload($date, $date);

        $rows = Attendance::query()
            ->with(['employee', 'workShift'])
            ->whereDate('attendance_date', $date->toDateString())
            ->whereNull('locked_at')
            ->where('is_manual', false)
            ->when($employees !== null, fn ($query) => $query->whereIn('employee_id', $employees->modelKeys()))
            ->get();

        $changed = 0;

        foreach ($rows as $row) {
            $before = $this->comparable($row);
            $this->resolve($row);

            if ($this->comparable($row) !== $before) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Create the missing row for every payroll-eligible employee for a date, and resolve it (§6.2).
     *
     * **Idempotent**: running it twice changes nothing, which is what lets the nightly command be retried
     * after a failure without anybody checking first.
     *
     * @return array{created: int, resolved: int, flagged: int}
     */
    public function closeDay(Carbon $date): array
    {
        $date = $date->copy()->startOfDay();
        $this->calendar->preload($date, $date);

        $autoAbsent = (bool) setting('hr.auto_absent_enabled', true);

        $employees = Employee::query()
            ->with('workShift')
            ->whereNull('deleted_at')
            ->whereDate('joining_date', '<=', $date->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('exit_date')
                ->orWhereDate('exit_date', '>=', $date->toDateString()))
            ->get()
            // The exit date decides, not the status (§6.10 #2): a leaver still needs rows for the days
            // they actually worked, or their final month has nothing behind it.
            ->filter(fn (Employee $employee): bool => $employee->isPayrollEligibleOn($date));

        $report = ['created' => 0, 'resolved' => 0, 'flagged' => 0];

        foreach ($employees as $employee) {
            $existing = Attendance::query()
                ->where('employee_id', $employee->getKey())
                ->whereDate('attendance_date', $date->toDateString())
                ->first();

            $isWorking = $this->calendar->dayTypeFor($employee, $date)->countsTowardWorkingDays();

            if ($existing === null) {
                // A working day with no punch only becomes an absence when the business asked for that;
                // otherwise the row still exists, but carries the day type and waits for a correction.
                if ($isWorking && ! $autoAbsent && ! $employee->is_attendance_exempt) {
                    continue;
                }

                $existing = DB::transaction(fn (): Attendance => $this->createRow($employee, $date));
                $report['created']++;
            }

            if ($existing->isLocked() || $existing->is_manual) {
                continue;
            }

            $existing->setRelation('employee', $employee);
            $this->resolve($existing);
            $report['resolved']++;

            if ($existing->requires_correction) {
                $report['flagged']++;
            }
        }

        return $report;
    }

    /*
    |--------------------------------------------------------------------------
    | Row plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * The row for an employee on a date, created with its full shift snapshot when it does not exist yet.
     *
     * This is the **only** place an attendance row is born, so the snapshot of HR-2 cannot be forgotten on
     * one of the four paths that need one.
     */
    public function rowFor(Employee $employee, Carbon $date, bool $lock = false): Attendance
    {
        $query = Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $date->toDateString());

        if ($lock) {
            $query->lockForUpdate();
        }

        $attendance = $query->first();

        if ($attendance !== null) {
            $attendance->setRelation('employee', $employee);

            return $attendance;
        }

        return $this->createRow($employee, $date);
    }

    private function createRow(Employee $employee, Carbon $date): Attendance
    {
        $window = $this->calendar->expectedWindow($employee, $date);
        $dayType = $this->calendar->dayTypeFor($employee, $date);
        $holiday = $this->calendar->holidayFor($employee, $date);

        $attendance = new Attendance;
        $attendance->forceFill([
            'employee_id' => $employee->getKey(),
            'branch_id' => $employee->branch_id,
            'attendance_date' => $date->toDateString(),
            'day_type' => $dayType,
            'holiday_id' => $holiday?->getKey(),
            'status' => AttendanceStatus::Absent,
            'payable_factor' => '0.0000',
        ] + $window->toAttendanceColumns());

        $attendance->save();
        $attendance->setRelation('employee', $employee);

        return $attendance;
    }

    /**
     * The row a punch-out belongs to.
     *
     * A night shift's punch-out lands on the **next** calendar date, so today's row is tried first and
     * yesterday's open row second — never a fresh row for the second date (§6.3, §6.10 #7).
     */
    private function openRowFor(Employee $employee, Carbon $at): Attendance
    {
        $businessDate = $this->businessDate($at);

        $today = Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $businessDate->toDateString())
            ->lockForUpdate()
            ->first();

        if ($today !== null && $today->check_in_at !== null) {
            $today->setRelation('employee', $employee);

            return $today;
        }

        $yesterday = Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $businessDate->copy()->subDay()->toDateString())
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->lockForUpdate()
            ->first();

        if ($yesterday !== null) {
            $yesterday->setRelation('employee', $employee);

            return $yesterday;
        }

        return $today ?? $this->rowFor($employee, $businessDate, lock: true);
    }

    /*
    |--------------------------------------------------------------------------
    | Arithmetic — integers only (HR-3)
    |--------------------------------------------------------------------------
    */

    private function workedMinutes(Attendance $attendance, ShiftWindow $window): int
    {
        if ($attendance->check_in_at === null || $attendance->check_out_at === null) {
            return 0;
        }

        $gross = (int) $attendance->check_in_at->diffInMinutes($attendance->check_out_at);

        return max(0, $gross - $window->breakMinutes);
    }

    private function lateMinutes(Attendance $attendance, ShiftWindow $window): int
    {
        $lateAfter = $window->lateAfter();

        if ($lateAfter === null || $attendance->check_in_at === null) {
            return 0;
        }

        return max(0, (int) $lateAfter->diffInMinutes($attendance->check_in_at, absolute: false));
    }

    private function earlyLeaveMinutes(Attendance $attendance, ShiftWindow $window): int
    {
        $earlyBefore = $window->earlyBefore();

        if ($earlyBefore === null || $attendance->check_out_at === null) {
            return 0;
        }

        return max(0, (int) $attendance->check_out_at->diffInMinutes($earlyBefore, absolute: false));
    }

    /**
     * Late wins over an early leave as the *status* — both minute columns are stored either way, so no
     * report loses the early leave (§6.3).
     */
    private function presentState(int $late, int $early, bool $halved): AttendanceStatus
    {
        if ($late > 0) {
            return AttendanceStatus::Late;
        }

        if ($early > 0) {
            return AttendanceStatus::EarlyLeave;
        }

        return $halved ? AttendanceStatus::HalfDay : AttendanceStatus::Present;
    }

    private function shiftHasEnded(ShiftWindow $window): bool
    {
        return $window->expectedOut !== null && $window->expectedOut->isPast();
    }

    /**
     * The window this row was promised, rebuilt from its own snapshot plus the two thresholds the row does
     * not carry ({@see ShiftWindow::fromSnapshot()}).
     */
    private function windowFor(Attendance $attendance): ShiftWindow
    {
        $shift = $attendance->work_shift_id === null ? null : $attendance->workShift;

        return ShiftWindow::fromSnapshot([
            'work_shift_id' => $attendance->work_shift_id,
            'expected_in_at' => $attendance->expected_in_at,
            'expected_out_at' => $attendance->expected_out_at,
            'expected_minutes' => $attendance->expected_minutes,
            'grace_in_minutes' => $attendance->grace_in_minutes,
            'grace_out_minutes' => $attendance->grace_out_minutes,
            'break_minutes' => $attendance->break_minutes,
            'min_half_day_minutes' => $shift?->min_half_day_minutes,
            'min_full_day_minutes' => $shift?->min_full_day_minutes,
        ]);
    }

    /**
     * The active, counted leave day covering this row, if any (§6.3 steps 5-6).
     */
    private function leaveDayFor(Attendance $attendance): ?LeaveRequestDay
    {
        return LeaveRequestDay::query()
            ->with('leaveRequest')
            ->where('employee_id', $attendance->employee_id)
            ->whereDate('leave_date', $attendance->attendance_date->toDateString())
            ->where('is_active', true)
            ->where('is_counted', true)
            ->first();
    }

    /**
     * The **business** calendar date an instant falls on.
     *
     * Stamps are stored UTC (D61) and the business thinks in `localization.timezone`. Reading the date
     * straight off a UTC instant would file a 01:00 punch in Karachi under the previous day — which is
     * a missing attendance row, an absence, and eventually a deduction.
     */
    private function businessDate(Carbon $at): Carbon
    {
        return Carbon::parse($at->copy()->setTimezone(Format::timezone())->toDateString())->startOfDay();
    }

    private function assertUnlocked(Attendance $attendance): void
    {
        if ($attendance->isLocked()) {
            throw LockedAttendanceException::forPeriod(
                sprintf('Attendance for %s', $attendance->attendance_date->toDateString()),
                $attendance->lockedByRun?->run_number ?? '(unknown run)'
            );
        }
    }

    /**
     * Write only the columns resolution owns, and only when something actually changed — an unchanged
     * recompute must not bump `updated_at` and fill the activity log with noise.
     *
     * @param  array<string, mixed>  $values
     */
    private function write(Attendance $attendance, array $values): Attendance
    {
        $values += ['leave_request_id' => null, 'leave_type_id' => null];

        $attendance->forceFill($values);

        if ($attendance->isDirty()) {
            $attendance->save();
        }

        return $attendance;
    }

    /**
     * The fingerprint `recomputeDate()` compares before and after, so "changed" means a figure moved and
     * not that Eloquent touched a timestamp.
     */
    private function comparable(Attendance $attendance): string
    {
        return implode('|', [
            $attendance->status->value,
            $attendance->day_type->value,
            (string) $attendance->payable_factor,
            (string) $attendance->worked_minutes,
            (string) $attendance->late_minutes,
            (string) $attendance->early_leave_minutes,
            (string) $attendance->overtime_minutes,
            (string) ($attendance->holiday_id ?? ''),
            (string) ($attendance->leave_request_id ?? ''),
            $attendance->requires_correction ? '1' : '0',
        ]);
    }
}
