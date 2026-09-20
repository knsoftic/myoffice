<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\DayType;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One employee on one calendar date (phase-07 §2.9, requirement §26, HR-1).
 *
 * A row exists for **every** date — weekends and holidays included — so "was this person absent?" is a
 * lookup rather than an inference from a gap.
 *
 * **The row snapshots its shift** (HR-2). The expected window, the expected minutes and both grace values
 * are written once at creation, so editing or deleting a `work_shift` afterwards cannot change a past
 * day's late minutes, early-leave minutes or hours. That is the whole reason the snapshot exists instead
 * of a join.
 *
 * **`payable_factor` is the only number payroll cares about** (HR-4): in `[0, 1]` by CHECK, and on a
 * working day `lop_days = 1 - payable_factor`. Every state, every half day and every unpaid holiday
 * resolves into that single column.
 *
 * Minutes are **integers** (HR-3), derived by `AttendanceService::resolve()` from the snapshot and nothing
 * else — no float touches them, and they are not money.
 *
 * `is_manual` marks a row a correction has touched, after which `resolve()` leaves it alone; otherwise the
 * nightly pass would undo somebody's decision every night. `locked_at` freezes the row once payroll has
 * been run for its period (HR-18).
 */
class Attendance extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'attendances';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'branch_id' => 'integer',
            'attendance_date' => 'date',
            'work_shift_id' => 'integer',
            'day_type' => DayType::class,
            'holiday_id' => 'integer',
            'status' => AttendanceStatus::class,
            'expected_in_at' => 'datetime',
            'expected_out_at' => 'datetime',
            'expected_minutes' => 'integer',
            'grace_in_minutes' => 'integer',
            'grace_out_minutes' => 'integer',
            'break_minutes' => 'integer',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'check_in_source' => AttendanceSource::class,
            'check_out_source' => AttendanceSource::class,
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'payable_factor' => 'decimal:4',
            'leave_request_id' => 'integer',
            'leave_type_id' => 'integer',
            'is_manual' => 'boolean',
            'requires_correction' => 'boolean',
            'locked_at' => 'datetime',
            'locked_by_payroll_run_id' => 'integer',
        ];
    }

    /**
     * Is this row frozen because payroll has been run for its period (HR-18)?
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * The share of the day that is lost pay. Only a working day can lose any — a weekend is not absence.
     */
    public function lossOfPayDays(): string
    {
        if (! $this->day_type->isWorking()) {
            return '0.0000';
        }

        return Money::round(Money::sub('1', (string) $this->payable_factor), 4);
    }

    public function moduleSlug(): string
    {
        return 'attendance';
    }

    protected function activityModule(): ?string
    {
        return 'attendance';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'work_shift_id');
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class, 'holiday_id');
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function lockedByRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'locked_by_payroll_run_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class, 'attendance_id');
    }
}
