<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\AttendanceDeductionStatus;
use App\Enums\AttendanceDeductionType;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One attendance-based salary deduction: an employee, a rule, a date (attendance-based deductions).
 *
 * **Written only by `AttendanceDeductionService`.** The service recalculates the whole month from the
 * attendance rows and lands on this row through `uq_attd_day`, so the same late arrival or absence can
 * never be charged twice however many times it is processed.
 *
 * **Never deleted, and frozen once applied.** A deduction that stops applying becomes `reversed`; one a
 * locked payroll slip took is evidence, and only its reversal columns may still be written (the refund
 * goes through a correction slip). Every change is in the activity log with old and new values.
 *
 * @property AttendanceDeductionType $deduction_type
 * @property AttendanceDeductionStatus $status
 */
class AttendanceDeduction extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    /** Once a locked slip has taken the deduction, these are history. */
    public const FROZEN_AFTER_APPLY = [
        'employee_id', 'deduction_type', 'deduction_date', 'period_year', 'period_month',
        'daily_salary', 'rate_percentage', 'amount', 'payroll_run_id', 'payroll_run_item_id', 'applied_at',
    ];

    protected $table = 'attendance_deductions';

    /**
     * Nothing is mass-assignable from a request: every column is decided by the service.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'branch_id' => 'integer',
            'attendance_id' => 'integer',
            'deduction_type' => AttendanceDeductionType::class,
            'deduction_date' => 'date',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'daily_salary' => 'decimal:2',
            'rate_percentage' => 'decimal:4',
            'amount' => 'decimal:2',
            'absence_sequence' => 'integer',
            'allowance_days' => 'integer',
            'calculation' => 'array',
            'status' => AttendanceDeductionStatus::class,
            'payroll_run_id' => 'integer',
            'payroll_run_item_id' => 'integer',
            'applied_at' => 'datetime',
            'reversed_at' => 'datetime',
            'reversed_by' => 'integer',
            'correction_item_id' => 'integer',
            'notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (AttendanceDeduction $deduction): void {
            if ($deduction->getRawOriginal('status') !== AttendanceDeductionStatus::Applied->value) {
                return;
            }

            $touched = array_values(array_intersect(array_keys($deduction->getDirty()), self::FROZEN_AFTER_APPLY));

            if ($touched !== []) {
                throw new LogicException(sprintf(
                    'AttendanceDeduction #%s: %s cannot change once a locked payroll slip has taken it — '
                    .'a refund goes through a correction slip instead.',
                    (string) $deduction->getKey(),
                    implode(', ', $touched),
                ));
            }
        });

        static::deleting(static function (AttendanceDeduction $deduction): void {
            throw new LogicException(sprintf(
                'AttendanceDeduction #%s cannot be deleted: a deduction that no longer applies is reversed, '
                .'so the record of what was charged and why stays.',
                (string) $deduction->getKey(),
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'payroll';
    }

    protected function activityModule(): ?string
    {
        return 'payroll';
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'employee_id', 'deduction_type', 'deduction_date', 'daily_salary', 'rate_percentage', 'amount',
            'absence_sequence', 'status', 'payroll_run_id', 'payroll_run_item_id', 'reversal_reason',
            'correction_item_id',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return ['created_at', 'updated_at', 'updated_by', 'notified_state', 'notified_at'];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations and scopes
    |--------------------------------------------------------------------------
    */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function payrollRunItem(): BelongsTo
    {
        return $this->belongsTo(PayrollRunItem::class, 'payroll_run_item_id');
    }

    public function correctionItem(): BelongsTo
    {
        return $this->belongsTo(PayrollRunItem::class, 'correction_item_id');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * @param  Builder<AttendanceDeduction>  $query
     * @return Builder<AttendanceDeduction>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AttendanceDeductionStatus::Pending->value);
    }

    /**
     * @param  Builder<AttendanceDeduction>  $query
     * @return Builder<AttendanceDeduction>
     */
    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('period_year', $year)->where('period_month', $month);
    }

    public function isPending(): bool
    {
        return $this->status === AttendanceDeductionStatus::Pending;
    }

    public function isApplied(): bool
    {
        return $this->status === AttendanceDeductionStatus::Applied;
    }

    /**
     * The one sentence an employee reads — used by the notification, the dashboard card and both lists.
     */
    public function explanation(): string
    {
        $date = $this->deduction_date->format('j F Y');
        $amount = Money::format((string) $this->amount);
        $rate = rtrim(rtrim((string) $this->rate_percentage, '0'), '.');

        $sentence = match ($this->deduction_type) {
            AttendanceDeductionType::LateArrival => sprintf(
                'You arrived late on %s. A %s%% deduction of %s (of a daily salary of %s) %s for this day.',
                $date,
                $rate,
                $amount,
                Money::format((string) $this->daily_salary),
                $this->verb(),
            ),
            AttendanceDeductionType::ExcessAbsence => sprintf(
                'You exceeded your monthly allowance of %s eligible day%s off. A salary deduction of %s %s for '
                .'the additional absence on %s (absence %d this month).',
                (string) ($this->allowance_days ?? 1),
                ($this->allowance_days ?? 1) === 1 ? '' : 's',
                $amount,
                $this->verb(),
                $date,
                (int) $this->absence_sequence,
            ),
        };

        if ($this->status === AttendanceDeductionStatus::Reversed) {
            $reason = rtrim(trim((string) $this->reversal_reason), '.');

            $sentence .= ' This deduction has been reversed'.($reason !== '' ? ': '.$reason : '').'.';
        }

        return $sentence;
    }

    private function verb(): string
    {
        return match ($this->status) {
            AttendanceDeductionStatus::Pending => 'will be applied to your salary',
            AttendanceDeductionStatus::Applied => 'has been applied to your salary',
            AttendanceDeductionStatus::Reversed => 'was recorded',
        };
    }
}
