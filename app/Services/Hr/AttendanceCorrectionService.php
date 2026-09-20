<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\AttendanceCorrectionStatus;
use App\Enums\AttendanceCorrectionType;
use App\Enums\AttendanceStatus;
use App\Enums\CorrectionSource;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceCorrection;
use App\Models\Hr\Employee;
use App\Models\User;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Services\Hr\Exceptions\LockedAttendanceException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every manual change to attendance, and the evidence of why (phase-07 §6.2, §6.8, HR-6).
 *
 * **A correction is never an edit.** There is no path anywhere in this phase that writes a status or a
 * punch onto an attendance row without leaving one of these behind, carrying the values before and after,
 * a mandatory reason and the person who decided. Two doors lead here — an employee asking, and HR acting
 * directly — and they leave **identical** evidence; the only difference is the `source` column. A design
 * where HR's own edits skipped the trail would make the trail worthless exactly where it matters.
 *
 * Applying a correction sets `is_manual = true` on the row, which is what stops the nightly resolution
 * from undoing somebody's decision at 23:50 every night.
 *
 * Three refusals worth naming, because each one is a real mistake somebody will make:
 *
 * - a **locked** date — the period is paid, and §6.8's correction run is the remedy, not this;
 * - a date older than `hr.attendance_correction_window_days` — a month-old "I forgot to punch out" is a
 *   conversation with HR, not a form;
 * - a **second pending request** for the same date, which would otherwise let two approvals fight.
 */
class AttendanceCorrectionService
{
    /** Long enough that "fix my punch" has to say what and why (§2.10). */
    private const MIN_REASON = 10;

    /**
     * The only keys a correction may write onto an attendance row. Anything else — the punches' IP, the
     * lock, the snapshot — is evidence or is owned by another service.
     *
     * @var list<string>
     */
    private const WRITABLE = [
        'check_in_at',
        'check_out_at',
        'status',
        'payable_factor',
        'remarks',
        'leave_request_id',
        'leave_type_id',
    ];

    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * An employee asks for a day to be fixed (§6.2). Nothing is written onto the attendance row yet.
     *
     * @param  array<string, mixed>  $newValues
     */
    public function request(
        Employee $employee,
        Carbon $date,
        AttendanceCorrectionType $type,
        array $newValues,
        string $reason,
        ?User $actor = null,
    ): AttendanceCorrection {
        $date = $date->copy()->startOfDay();

        $this->assertReason($reason);
        $this->assertInsideWindow($date);

        return DB::transaction(function () use ($employee, $date, $type, $newValues, $reason, $actor) {
            $row = Attendance::query()
                ->where('employee_id', $employee->getKey())
                ->whereDate('attendance_date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            if ($row !== null && $row->isLocked()) {
                throw LockedAttendanceException::forPeriod(
                    sprintf('Attendance for %s', $date->toDateString()),
                    $row->lockedByRun?->run_number ?? '(unknown run)'
                );
            }

            $pending = AttendanceCorrection::query()
                ->where('employee_id', $employee->getKey())
                ->whereDate('attendance_date', $date->toDateString())
                ->where('status', AttendanceCorrectionStatus::Pending)
                ->exists();

            if ($pending) {
                throw HrRuleException::refuse(
                    'attendance_date',
                    'There is already a correction waiting for this date. Add to that one rather than '
                    .'filing a second — two approvals of the same day would each think they were first.'
                );
            }

            return $this->write(
                employee: $employee,
                date: $date,
                row: $row,
                type: $type,
                source: CorrectionSource::SelfRequest,
                newValues: $this->sanitise($newValues),
                reason: $reason,
                status: AttendanceCorrectionStatus::Pending,
                actor: $actor,
            );
        });
    }

    /**
     * HR changes a day themselves (§6.2, HR-6): the row is written `approved` **and** applied in the same
     * transaction, so both paths leave the same audit trail.
     *
     * @param  array<string, mixed>  $newValues
     */
    public function applyDirect(
        Employee $employee,
        Carbon $date,
        AttendanceCorrectionType $type,
        array $newValues,
        string $reason,
        ?User $actor = null,
    ): AttendanceCorrection {
        $date = $date->copy()->startOfDay();

        $this->assertReason($reason);

        return DB::transaction(function () use ($employee, $date, $type, $newValues, $reason, $actor) {
            $row = $this->attendance->rowFor($employee, $date, lock: true);

            if ($row->isLocked()) {
                throw LockedAttendanceException::forPeriod(
                    sprintf('Attendance for %s', $date->toDateString()),
                    $row->lockedByRun?->run_number ?? '(unknown run)'
                );
            }

            $correction = $this->write(
                employee: $employee,
                date: $date,
                row: $row,
                type: $type,
                source: CorrectionSource::HrDirect,
                newValues: $this->sanitise($newValues),
                reason: $reason,
                status: AttendanceCorrectionStatus::Approved,
                actor: $actor,
            );

            $this->applyTo($row, $correction, $actor);

            return $correction;
        });
    }

    /**
     * Approve a pending request and write it onto the row (§6.2).
     *
     * The lock is re-checked here rather than only at request time: a payroll run may have been locked in
     * between, and approving into a paid period is exactly the thing HR-18 exists to stop.
     */
    public function approve(AttendanceCorrection $correction, ?User $actor = null): AttendanceCorrection
    {
        $this->assertPending($correction);

        return DB::transaction(function () use ($correction, $actor) {
            $employee = $correction->employee;
            $row = $this->attendance->rowFor($employee, $correction->attendance_date->copy(), lock: true);

            if ($row->isLocked()) {
                throw LockedAttendanceException::forPeriod(
                    sprintf('Attendance for %s', $correction->attendance_date->toDateString()),
                    $row->lockedByRun?->run_number ?? '(unknown run)'
                );
            }

            $correction->forceFill([
                'attendance_id' => $row->getKey(),
                'old_values' => $this->snapshot($row),
                'status' => AttendanceCorrectionStatus::Approved,
                'reviewed_by' => $actor?->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $this->applyTo($row, $correction, $actor);

            return $correction;
        });
    }

    /**
     * Refuse a request. The attendance row is not touched — a rejection changes nothing but the record of
     * having asked, and the comment is mandatory because "no" without a reason is not an answer.
     */
    public function reject(AttendanceCorrection $correction, string $comment, ?User $actor = null): AttendanceCorrection
    {
        $this->assertPending($correction);

        if (trim($comment) === '') {
            throw HrRuleException::reasonRequired(
                'review_comment',
                'Say why the correction was refused — the employee sees this, and "rejected" on its own '
                .'is not an answer.'
            );
        }

        $correction->forceFill([
            'status' => AttendanceCorrectionStatus::Rejected,
            'reviewed_by' => $actor?->getKey(),
            'reviewed_at' => now(),
            'review_comment' => trim($comment),
        ])->save();

        return $correction;
    }

    /**
     * Withdraw one's own pending request.
     */
    public function cancel(AttendanceCorrection $correction, ?User $actor = null): AttendanceCorrection
    {
        $this->assertPending($correction);

        $correction->forceFill([
            'status' => AttendanceCorrectionStatus::Cancelled,
            'reviewed_by' => $actor?->getKey(),
            'reviewed_at' => now(),
        ])->save();

        return $correction;
    }

    /**
     * The system's own correction: a holiday declared late, a leave approved or cancelled (§6.10 #3).
     *
     * It is written the same way a human's is — already approved, with old and new values — so a figure
     * that moved on its own is as explainable as one a person moved.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function recordSystemChange(
        Attendance $row,
        AttendanceCorrectionType $type,
        array $oldValues,
        array $newValues,
        string $reason,
    ): AttendanceCorrection {
        $correction = new AttendanceCorrection;
        $correction->forceFill([
            'attendance_id' => $row->getKey(),
            'employee_id' => $row->employee_id,
            'attendance_date' => $row->attendance_date->toDateString(),
            'correction_type' => $type,
            'source' => CorrectionSource::HrDirect,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'status' => AttendanceCorrectionStatus::Approved,
            'requested_at' => now(),
            'reviewed_at' => now(),
            'applied_at' => now(),
        ])->save();

        return $correction;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function write(
        Employee $employee,
        Carbon $date,
        ?Attendance $row,
        AttendanceCorrectionType $type,
        CorrectionSource $source,
        array $newValues,
        string $reason,
        AttendanceCorrectionStatus $status,
        ?User $actor,
    ): AttendanceCorrection {
        if ($newValues === []) {
            throw HrRuleException::refuse(
                'new_values',
                'A correction has to change something. Name the punch, the status or the payable share '
                .'that should be different.'
            );
        }

        $correction = new AttendanceCorrection;
        $correction->forceFill([
            'attendance_id' => $row?->getKey(),
            'employee_id' => $employee->getKey(),
            'attendance_date' => $date->toDateString(),
            'correction_type' => $type,
            'source' => $source,
            'old_values' => $row === null ? null : $this->snapshot($row),
            'new_values' => $newValues,
            'reason' => trim($reason),
            'status' => $status,
            'requested_by' => $actor?->getKey(),
            'requested_at' => now(),
            'reviewed_by' => $status === AttendanceCorrectionStatus::Approved ? $actor?->getKey() : null,
            'reviewed_at' => $status === AttendanceCorrectionStatus::Approved ? now() : null,
        ])->save();

        return $correction;
    }

    /**
     * Write the approved values onto the row and mark it manual, so `resolve()` leaves it alone from here.
     */
    private function applyTo(Attendance $row, AttendanceCorrection $correction, ?User $actor): void
    {
        $values = $this->sanitise((array) $correction->new_values);

        $row->forceFill($values + ['is_manual' => true, 'requires_correction' => false])->save();

        $correction->forceFill([
            'attendance_id' => $row->getKey(),
            'applied_at' => now(),
        ])->save();
    }

    /**
     * Keep only the keys a correction may write, and normalise each to the shape the column expects.
     *
     * A whitelist rather than a blacklist: a correction form that grew a field would otherwise be able to
     * write a lock or a shift snapshot, and nobody would notice until a payroll disagreed.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitise(array $values): array
    {
        $clean = [];

        foreach (self::WRITABLE as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            $clean[$key] = match ($key) {
                'check_in_at', 'check_out_at' => $value === null
                    ? null
                    : Carbon::parse((string) $value)->startOfSecond()->toDateTimeString(),
                'status' => $value instanceof AttendanceStatus
                    ? $value->value
                    : AttendanceStatus::from((string) $value)->value,
                'payable_factor' => Money::round((string) $value, 4),
                default => $value,
            };
        }

        return $clean;
    }

    /**
     * The row as it was, for `old_values` — the columns a correction can move, and nothing else.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Attendance $row): array
    {
        return [
            'check_in_at' => $row->check_in_at?->toDateTimeString(),
            'check_out_at' => $row->check_out_at?->toDateTimeString(),
            'status' => $row->status->value,
            'payable_factor' => (string) $row->payable_factor,
            'remarks' => $row->remarks,
            'leave_request_id' => $row->leave_request_id,
            'leave_type_id' => $row->leave_type_id,
        ];
    }

    private function assertReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < self::MIN_REASON) {
            throw HrRuleException::correctionReasonTooShort(self::MIN_REASON);
        }
    }

    private function assertInsideWindow(Carbon $date): void
    {
        $days = (int) setting('hr.attendance_correction_window_days', 7);

        if ($days > 0 && $date->lessThan(now()->startOfDay()->subDays($days))) {
            throw HrRuleException::refuse('attendance_date', sprintf(
                'Corrections can be asked for up to %d days back. %s is older than that — HR can still '
                .'change it directly, which is deliberate: an old day should involve a conversation.',
                $days,
                $date->toDateString()
            ));
        }
    }

    private function assertPending(AttendanceCorrection $correction): void
    {
        if ($correction->status !== AttendanceCorrectionStatus::Pending) {
            throw HrRuleException::refuse('status', sprintf(
                'This correction was already %s. Its outcome is part of the record and is not revisited; '
                .'file a new one if the day still needs fixing.',
                $correction->status->label()
            ));
        }
    }
}
