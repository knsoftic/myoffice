<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's month of attendance, aggregated (phase-07 §2.11, requirement §26).
 *
 * **This is the only input payroll reads** (HR-5). `PayrollCalculator` takes a summary in its signature,
 * so there is exactly one place attendance is aggregated and exactly one number to check when a slip looks
 * wrong — payroll never touches `attendances` at all.
 *
 * `payable_days` is the sum of every row's payable factor; `lop_days` is the sum of `1 - factor` over
 * **working** rows only, because a weekend is not a loss of pay (HR-4).
 *
 * `period_start` / `period_end` are stored rather than derived, so a business on a 26th-to-25th cycle
 * needs no migration later.
 *
 * A rebuild is idempotent and **refuses a locked period**, naming the run that locked it (HR-18).
 */
class AttendanceMonthlySummary extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    protected $table = 'attendance_monthly_summaries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'branch_id' => 'integer',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'calendar_days' => 'integer',
            'working_days' => 'decimal:4',
            'weekly_off_days' => 'integer',
            'holiday_days' => 'integer',
            'present_days' => 'decimal:4',
            'late_count' => 'integer',
            'early_leave_count' => 'integer',
            'half_day_count' => 'integer',
            'absent_days' => 'decimal:4',
            'paid_leave_days' => 'decimal:4',
            'unpaid_leave_days' => 'decimal:4',
            'payable_days' => 'decimal:4',
            'lop_days' => 'decimal:4',
            'worked_minutes' => 'integer',
            'expected_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'attendance_percentage' => 'decimal:4',
            'generated_at' => 'datetime',
            'is_final' => 'boolean',
            'locked_at' => 'datetime',
            'locked_by_payroll_run_id' => 'integer',
        ];
    }

    /**
     * Is this period frozen because payroll has been run against it (HR-18)?
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * A human label for the period, for a message that has to name it.
     */
    public function periodLabel(): string
    {
        return $this->period_start?->format('F Y') ?? sprintf('%04d-%02d', $this->period_year, $this->period_month);
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

    public function lockedByRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'locked_by_payroll_run_id');
    }
}
