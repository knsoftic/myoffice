<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\AttendanceCorrectionType;
use App\Enums\DayType;
use App\Enums\LeaveApprovalStatus;
use App\Enums\LeaveDayPortion;
use App\Enums\LeaveLedgerReason;
use App\Enums\LeaveRequestStatus;
use App\Models\Hr\Attendance;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveApproval;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveRequestDay;
use App\Models\Hr\LeaveType;
use App\Models\User;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Services\Hr\Exceptions\LockedAttendanceException;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applying for leave, approving it, and what that does to attendance (phase-07 §6.2, §6.5).
 *
 * **A range is expanded into days, and the skipped days are kept.** When the type excludes weekends or
 * holidays, those dates are still written as rows marked `is_counted = false`. That is what lets a screen
 * explain why five calendar days cost three quota days, instead of leaving a gap the employee has to
 * reconstruct from a calendar.
 *
 * **Attendance is touched only on final approval**, and only for counted days (§6.5.2). A pending request
 * changes nothing: leave that might still be refused must not already look like an absence that was
 * excused.
 *
 * **The chain is built from data, not from a flow table** (§6.5.3): level 1 is the reporting manager,
 * level 2 the department head when the type asks for two levels. An employee can never approve their own
 * request — when the resolved approver *is* the requester, the level falls through to the fallback
 * permission, because the alternative is a manager who cannot take a holiday at all.
 *
 * Every refusal names the thing in the way: the existing request on a clashing date, the locked period,
 * the exact shortfall in days.
 */
class LeaveRequestService
{
    public function __construct(
        private readonly HrNumberService $numbers,
        private readonly WorkCalendarService $calendar,
        private readonly LeaveBalanceService $balances,
        private readonly AttendanceService $attendance,
        private readonly AttendanceCorrectionService $corrections,
    ) {}

    /**
     * File a request (§6.5.1): expand the range, check the clashes, reserve the balance, build the chain.
     */
    public function apply(
        Employee $employee,
        LeaveType $type,
        Carbon $from,
        Carbon $to,
        LeaveDayPortion $portion,
        string $reason,
        ?string $contact = null,
        ?User $actor = null,
    ): LeaveRequest {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $this->assertApplicable($employee, $type, $from, $to, $portion, $reason);

        $expansion = $this->expand($employee, $type, $from, $to, $portion);

        if (Money::isZero($expansion['total'])) {
            throw HrRuleException::refuse('from_date', sprintf(
                'Every day in that range is a %s for this leave type, so it would cost no quota and grant '
                .'no time off. Check the dates.',
                $type->excludes_weekends && $type->excludes_holidays ? 'weekend or holiday' : 'non-working day'
            ));
        }

        $this->assertConsecutiveLimit($type, $expansion['total']);
        $this->assertNoLockedDate($employee, $expansion['days']);

        return DB::transaction(function () use (
            $employee, $type, $from, $to, $portion, $reason, $contact, $expansion
        ): LeaveRequest {
            $request = new LeaveRequest;
            $request->forceFill([
                'request_number' => $this->numbers->leaveRequestNumber(),
                'employee_id' => $employee->getKey(),
                'leave_type_id' => $type->getKey(),
                'branch_id' => $employee->branch_id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'day_portion' => $portion,
                'total_days' => $expansion['total'],
                'paid_days' => $type->is_paid ? $expansion['total'] : '0.0000',
                'unpaid_days' => $type->is_paid ? '0.0000' : $expansion['total'],
                'reason' => trim($reason),
                'contact_during_leave' => $contact,
                'status' => LeaveRequestStatus::Pending,
                'current_approval_level' => 1,
                'applied_on' => now()->toDateString(),
            ])->save();

            $request->setRelation('employee', $employee);
            $request->setRelation('leaveType', $type);

            $this->writeDays($request, $employee, $type, $expansion['days']);
            $this->buildChain($request, $employee, $type);

            $this->balances->reserve($request);

            return $request;
        });
    }

    /**
     * One level of the chain approves (§6.5.3).
     *
     * The final approval consumes the balance and writes the leave onto attendance **in the same
     * transaction**, because a leave that is approved but not on the calendar is the same bug as one that
     * is on the calendar but not approved.
     */
    public function approveLevel(LeaveRequest $request, User $actor, ?string $comment = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $comment): LeaveRequest {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status !== LeaveRequestStatus::Pending) {
                throw HrRuleException::refuse('status', sprintf(
                    'This request was already %s. Its outcome is part of the record.',
                    $request->status->label()
                ));
            }

            $level = $request->approvals()
                ->where('level', $request->current_approval_level)
                ->where('status', LeaveApprovalStatus::Pending)
                ->first();

            if ($level === null) {
                throw HrRuleException::refuse('level', 'There is no level of this chain waiting on a '
                    .'decision — it has already been decided.');
            }

            $this->assertMayAct($request, $level, $actor);

            $level->forceFill([
                'status' => LeaveApprovalStatus::Approved,
                'acted_by_user_id' => $actor->getKey(),
                'acted_at' => now(),
                'comment' => $comment,
            ])->save();

            $next = $request->approvals()
                ->where('level', '>', $level->level)
                ->where('status', LeaveApprovalStatus::Pending)
                ->orderBy('level')
                ->first();

            if ($next !== null) {
                $request->forceFill(['current_approval_level' => $next->level])->save();

                return $request;
            }

            $request->forceFill([
                'status' => LeaveRequestStatus::Approved,
                'approved_at' => now(),
            ])->save();

            $this->balances->consume($request);
            $this->applyToAttendance($request);

            return $request;
        });
    }

    /**
     * Refuse the request at any level. The reservation goes back and the remaining levels are `skipped`
     * rather than left pending — a chain that still looks open after a rejection is a chain somebody will
     * act on by mistake.
     */
    public function reject(LeaveRequest $request, User $actor, string $reason): LeaveRequest
    {
        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired(
                'rejection_reason',
                'Say why the leave was refused — the employee sees this.'
            );
        }

        return DB::transaction(function () use ($request, $actor, $reason): LeaveRequest {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status !== LeaveRequestStatus::Pending) {
                throw HrRuleException::refuse('status', sprintf(
                    'This request was already %s.',
                    $request->status->label()
                ));
            }

            $level = $request->approvals()
                ->where('level', $request->current_approval_level)
                ->where('status', LeaveApprovalStatus::Pending)
                ->first();

            if ($level !== null) {
                $this->assertMayAct($request, $level, $actor);

                $level->forceFill([
                    'status' => LeaveApprovalStatus::Rejected,
                    'acted_by_user_id' => $actor->getKey(),
                    'acted_at' => now(),
                    'comment' => trim($reason),
                ])->save();
            }

            $request->approvals()
                ->where('status', LeaveApprovalStatus::Pending)
                ->update([
                    'status' => LeaveApprovalStatus::Skipped->value,
                    'updated_at' => now(),
                ]);

            $request->forceFill([
                'status' => LeaveRequestStatus::Rejected,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ])->save();

            $this->deactivateDays($request);
            $this->balances->release($request, LeaveLedgerReason::ReservationRelease);

            return $request;
        });
    }

    /**
     * Withdraw a request — before approval, or after it.
     *
     * After approval the days come back as `leave_cancelled` and every attendance date it touched is
     * re-resolved, with a correction row per change so the movement is explained. A date that has since
     * been **locked** by payroll refuses the whole cancellation (HR-18): the month is paid, and §6.8's
     * correction run is the honest remedy.
     */
    public function cancel(LeaveRequest $request, User $actor, string $reason): LeaveRequest
    {
        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired(
                'cancellation_reason',
                'Say why the leave is being cancelled — it has already been counted against a quota.'
            );
        }

        return DB::transaction(function () use ($request, $actor, $reason): LeaveRequest {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status->isTerminal()) {
                throw HrRuleException::refuse('status', sprintf(
                    'This request is already %s.',
                    $request->status->label()
                ));
            }

            $wasApproved = $request->status === LeaveRequestStatus::Approved;
            $days = $request->days()->where('is_active', true)->get();

            $this->assertNoLockedAttendance($request, $days);

            $request->approvals()
                ->where('status', LeaveApprovalStatus::Pending)
                ->update([
                    'status' => LeaveApprovalStatus::Skipped->value,
                    'updated_at' => now(),
                ]);

            $request->forceFill([
                'status' => LeaveRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => trim($reason),
            ])->save();

            $this->deactivateDays($request);

            $this->balances->release(
                $request,
                $wasApproved ? LeaveLedgerReason::LeaveCancelled : LeaveLedgerReason::ReservationRelease
            );

            if ($wasApproved) {
                $this->clearFromAttendance($request, $days);
            }

            return $request;
        });
    }

    /**
     * Write an approved leave onto its attendance rows (§6.5.2). Idempotent.
     */
    public function applyToAttendance(LeaveRequest $request): int
    {
        $employee = $request->employee;
        $written = 0;

        foreach ($request->days()->where('is_active', true)->where('is_counted', true)->get() as $day) {
            $row = $this->attendance->rowFor($employee, $day->leave_date->copy(), lock: true);

            if ($row->isLocked()) {
                throw LockedAttendanceException::forPeriod(
                    sprintf('Attendance for %s', $day->leave_date->toDateString()),
                    $row->lockedByRun?->run_number ?? '(unknown run)'
                );
            }

            $row->forceFill([
                'leave_request_id' => $request->getKey(),
                'leave_type_id' => $request->leave_type_id,
            ])->save();

            if (! $row->is_manual) {
                $this->attendance->resolve($row);
            }

            $day->forceFill(['attendance_id' => $row->getKey()])->save();
            $written++;
        }

        $request->forceFill(['attendance_applied_at' => now()])->save();

        return $written;
    }

    /*
    |--------------------------------------------------------------------------
    | The day expansion — §6.5.1
    |--------------------------------------------------------------------------
    */

    /**
     * Turn a range into rows, deciding for each date whether it counts.
     *
     * @return array{days: list<array<string, mixed>>, total: string}
     */
    public function expand(Employee $employee, LeaveType $type, Carbon $from, Carbon $to, LeaveDayPortion $portion): array
    {
        $this->calendar->preload($from, $to);

        $days = [];
        $total = Money::zero();

        foreach ($this->calendar->datesIn($from, $to) as $date) {
            $dayType = $this->calendar->dayTypeFor($employee, $date);

            $skipped = ($dayType === DayType::WeeklyOff && $type->excludes_weekends)
                || ($dayType === DayType::PublicHoliday && $type->excludes_holidays);

            // A half-day portion only ever applies to a single-date request (§2.15), so a multi-day range
            // is always full days whatever the form sent.
            $datePortion = $from->equalTo($to) ? $portion : LeaveDayPortion::FullDay;
            $fraction = $skipped ? '0.0000' : $datePortion->fraction();

            $days[] = [
                'leave_date' => $date->toDateString(),
                'day_portion' => $datePortion->value,
                'day_fraction' => $skipped ? '0.0000' : $fraction,
                'is_counted' => ! $skipped,
                'day_type' => $dayType->value,
            ];

            if (! $skipped) {
                $total = Money::add($total, $fraction);
            }
        }

        return ['days' => $days, 'total' => Money::round($total, 4)];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function writeDays(LeaveRequest $request, Employee $employee, LeaveType $type, array $days): void
    {
        foreach ($days as $day) {
            $row = new LeaveRequestDay;
            $row->forceFill([
                'leave_request_id' => $request->getKey(),
                'employee_id' => $employee->getKey(),
                'leave_date' => $day['leave_date'],
                'day_portion' => $day['day_portion'],
                'day_fraction' => $day['day_fraction'],
                'is_counted' => $day['is_counted'],
                'is_paid' => $type->is_paid,
                'is_active' => true,
            ]);

            try {
                $row->save();
            } catch (UniqueConstraintViolationException) {
                // `uq_lrd_day` (HR-8) — one counted leave day per employee per date, decided by the
                // database rather than by a check a second request could race past.
                $existing = LeaveRequestDay::query()
                    ->with('leaveRequest')
                    ->where('employee_id', $employee->getKey())
                    ->whereDate('leave_date', $day['leave_date'])
                    ->where('is_active', true)
                    ->where('is_counted', true)
                    ->first();

                throw HrRuleException::refuse('from_date', sprintf(
                    '%s is already covered by leave request %s. Cancel that one first — a date cannot be '
                    .'counted against two quotas.',
                    $day['leave_date'],
                    $existing?->leaveRequest?->request_number ?? '(an existing request)',
                ));
            }
        }
    }

    /**
     * Build the chain from the org chart (§6.5.3).
     *
     * A level whose resolved approver is the requester is created with **no named approver**, falling
     * through to the fallback permission. That is not a loophole: it is what stops a department head from
     * being the only person who can never take a holiday.
     */
    private function buildChain(LeaveRequest $request, Employee $employee, LeaveType $type): void
    {
        $levels = max(1, min(2, (int) ($type->approval_levels ?: setting('hr.leave_default_approval_levels', 1))));

        $manager = $employee->reports_to_id === null ? null : $employee->manager;
        $head = $employee->department?->head_employee_id === null ? null : $employee->department->head;

        $candidates = [1 => $manager, 2 => $head];

        for ($level = 1; $level <= $levels; $level++) {
            $expected = $candidates[$level] ?? null;

            // Never the requester themselves, and never the same person twice in one chain.
            if ($expected !== null && (int) $expected->getKey() === (int) $employee->getKey()) {
                $expected = null;
            }

            if ($level === 2 && $expected !== null && $manager !== null
                && (int) $expected->getKey() === (int) $manager->getKey()) {
                $expected = null;
            }

            $approval = new LeaveApproval;
            $approval->forceFill([
                'leave_request_id' => $request->getKey(),
                'level' => $level,
                'expected_approver_employee_id' => $expected?->getKey(),
                'expected_approver_user_id' => $expected?->user_id,
                'fallback_permission' => 'leaves.approve',
                'status' => LeaveApprovalStatus::Pending,
            ])->save();
        }
    }

    /**
     * Who may act on a level: the named approver, or anybody holding the fallback permission — and never
     * the requester, whatever they hold (§2.17).
     */
    private function assertMayAct(LeaveRequest $request, LeaveApproval $level, User $actor): void
    {
        if ($request->employee?->user_id !== null
            && (int) $request->employee->user_id === (int) $actor->getKey()) {
            throw HrRuleException::refuse('leave_request_id',
                'Nobody approves their own leave, whatever permissions they hold. This request needs '
                .'somebody else in the chain.');
        }

        if ($level->expected_approver_user_id !== null
            && (int) $level->expected_approver_user_id === (int) $actor->getKey()) {
            return;
        }

        $permission = $level->fallback_permission ?? 'leaves.approve';

        if (! $actor->can($permission)) {
            throw HrRuleException::refuse('leave_request_id', sprintf(
                'This level is waiting on %s, or on somebody holding %s.',
                $level->expectedApprover?->name ?? 'its approver',
                $permission
            ));
        }
    }

    private function assertApplicable(
        Employee $employee,
        LeaveType $type,
        Carbon $from,
        Carbon $to,
        LeaveDayPortion $portion,
        string $reason,
    ): void {
        if ($to->lessThan($from)) {
            throw HrRuleException::refuse('to_date', 'The last day cannot be before the first.');
        }

        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('reason', 'Say what the leave is for.');
        }

        if (! $type->is_active) {
            throw HrRuleException::refuse('leave_type_id', sprintf('%s is no longer offered.', $type->name));
        }

        if (! $type->appliesTo($employee->employment_type)) {
            throw HrRuleException::refuse('leave_type_id', sprintf(
                '%s is not available to %s staff.',
                $type->name,
                $employee->employment_type->label()
            ));
        }

        if ($portion->isHalf() && ! $type->allow_half_day) {
            throw HrRuleException::refuse('day_portion', sprintf(
                '%s is taken in whole days.',
                $type->name
            ));
        }

        if ($portion->isHalf() && ! $from->equalTo($to)) {
            throw HrRuleException::refuse('day_portion',
                'A half day is a single date. For a longer absence, file whole days.');
        }

        $notice = (int) $type->min_notice_days;

        if ($notice > 0 && $from->lessThan(now()->startOfDay()->addDays($notice))) {
            throw HrRuleException::refuse('from_date', sprintf(
                '%s needs %d day(s) of notice. HR can still record it after the fact, which is deliberate: '
                .'a late application should involve a conversation.',
                $type->name,
                $notice
            ));
        }
    }

    private function assertConsecutiveLimit(LeaveType $type, string $total): void
    {
        $max = (int) $type->max_consecutive_days;

        if ($max > 0 && Money::greaterThan($total, (string) $max)) {
            throw HrRuleException::refuse('to_date', sprintf(
                '%s allows at most %d consecutive day(s); this range counts %s.',
                $type->name,
                $max,
                rtrim(rtrim($total, '0'), '.')
            ));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function assertNoLockedDate(Employee $employee, array $days): void
    {
        $dates = array_column(array_filter($days, fn (array $day): bool => (bool) $day['is_counted']), 'leave_date');

        if ($dates === []) {
            return;
        }

        $locked = Attendance::query()
            ->with('lockedByRun')
            ->where('employee_id', $employee->getKey())
            ->whereIn('attendance_date', $dates)
            ->whereNotNull('locked_at')
            ->first();

        if ($locked !== null) {
            throw LockedAttendanceException::forPeriod(
                sprintf('Attendance for %s', $locked->attendance_date->toDateString()),
                $locked->lockedByRun?->run_number ?? '(unknown run)'
            );
        }
    }

    /**
     * @param  Collection<int, LeaveRequestDay>  $days
     */
    private function assertNoLockedAttendance(LeaveRequest $request, $days): void
    {
        $dates = $days->where('is_counted', true)->pluck('leave_date')
            ->map(fn (Carbon $date): string => $date->toDateString())->all();

        if ($dates === []) {
            return;
        }

        $locked = Attendance::query()
            ->with('lockedByRun')
            ->where('employee_id', $request->employee_id)
            ->whereIn('attendance_date', $dates)
            ->whereNotNull('locked_at')
            ->first();

        if ($locked !== null) {
            throw LockedAttendanceException::forPeriod(
                sprintf('Attendance for %s', $locked->attendance_date->toDateString()),
                $locked->lockedByRun?->run_number ?? '(unknown run)'
            );
        }
    }

    /**
     * Release the day guard so the dates can be asked for again.
     */
    private function deactivateDays(LeaveRequest $request): void
    {
        $request->days()->update(['is_active' => false, 'updated_at' => now()]);
    }

    /**
     * Take an approved leave back off the calendar, writing a correction row per changed day (§6.5.2).
     *
     * @param  Collection<int, LeaveRequestDay>  $days
     */
    private function clearFromAttendance(LeaveRequest $request, $days): void
    {
        foreach ($days->where('is_counted', true) as $day) {
            $row = Attendance::query()
                ->where('employee_id', $request->employee_id)
                ->whereDate('attendance_date', $day->leave_date->toDateString())
                ->first();

            if ($row === null || $row->isLocked()) {
                continue;
            }

            $before = [
                'status' => $row->status->value,
                'payable_factor' => (string) $row->payable_factor,
                'leave_request_id' => $row->leave_request_id,
            ];

            $row->forceFill(['leave_request_id' => null, 'leave_type_id' => null])->save();

            if (! $row->is_manual) {
                $this->attendance->resolve($row);
            }

            $this->corrections->recordSystemChange(
                row: $row,
                type: AttendanceCorrectionType::LeaveRegularisation,
                oldValues: $before,
                newValues: [
                    'status' => $row->status->value,
                    'payable_factor' => (string) $row->payable_factor,
                    'leave_request_id' => null,
                ],
                reason: sprintf(
                    'Leave request %s was cancelled, so this day was re-resolved from its punches.',
                    $request->request_number
                ),
            );
        }
    }
}
