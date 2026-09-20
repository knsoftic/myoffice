<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\PaymentMethod;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollRunType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use App\Services\Hr\Exceptions\ImmutablePayrollAttributeException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One salary slip (phase-07 §2.24, requirement §28).
 *
 * **Every figure a slip prints is stored here, and the totals are the SUM of its own component rows**
 * (HR-13) — never a recomputation at render time. {@see totalsAgree()} is the identity
 * `PayrollRunService::lock()` asserts before it locks, so a slip that does not add up can never become
 * immutable.
 *
 * **Immutability comes in two steps** (HR-15). Once the item is anything but `draft`, every money column,
 * snapshot and day count is frozen — only the status, the hold reason, the payment fields and `notes` may
 * move. Once it is **paid**, the payment fields freeze too, and `notes` is the only field left.
 *
 * `run_type` is denormalised from the run so `chk_pri_sign` can exist: a negative slip is legal only on a
 * correction run (HR-17), and a CHECK cannot reach across a foreign key to find out.
 *
 * The department, designation, employment type, joining date and every day count are **snapshots** — a
 * slip prints what was true then, whatever has changed since.
 */
class PayrollRunItem extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    /** What may still move once the item is locked (HR-15). */
    public const UPDATABLE_AFTER_LOCK = [
        'status',
        'hold_reason',
        'paid_at',
        'payment_method',
        'payment_reference',
        'paid_by',
        'notes',
        'updated_at',
        'updated_by',
    ];

    /** What may still move once the item is paid — the payment is evidence too. */
    public const UPDATABLE_AFTER_PAYMENT = ['notes', 'updated_at', 'updated_by'];

    protected $table = 'payroll_run_items';

    /**
     * Deliberately empty: `PayrollCalculator` writes every column explicitly, and nothing else writes a
     * slip at all.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payroll_run_id' => 'integer',
            'run_type' => PayrollRunType::class,
            'employee_id' => 'integer',
            'salary_structure_id' => 'integer',
            'attendance_monthly_summary_id' => 'integer',
            'corrects_item_id' => 'integer',
            'joining_date' => 'date',
            'basic_salary' => 'decimal:2',
            'contracted_gross' => 'decimal:2',
            'gross_earnings' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'taxable_gross' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'allowance_amount' => 'decimal:2',
            'bonus_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'overtime_amount' => 'decimal:2',
            'advance_recovery_amount' => 'decimal:2',
            'unpaid_leave_deduction' => 'decimal:2',
            'late_deduction' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'payable_days' => 'decimal:4',
            'lop_days' => 'decimal:4',
            'working_days' => 'decimal:4',
            'present_days' => 'decimal:4',
            'paid_leave_days' => 'decimal:4',
            'unpaid_leave_days' => 'decimal:4',
            'late_count' => 'integer',
            'day_divisor' => 'decimal:4',
            'per_day_amount' => 'decimal:2',
            'calculation_snapshot' => 'array',
            'status' => PayrollItemStatus::class,
            'paid_at' => 'datetime',
            'payment_method' => PaymentMethod::class,
            'paid_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (PayrollRunItem $item): void {
            // getRawOriginal(), not getOriginal(): the latter applies the cast and returns an enum,
            // which would make even a draft slip look locked.
            $original = $item->getRawOriginal('status');

            if ($original === PayrollItemStatus::Draft->value) {
                return;
            }

            $allowed = $original === PayrollItemStatus::Paid->value
                ? self::UPDATABLE_AFTER_PAYMENT
                : self::UPDATABLE_AFTER_LOCK;

            $touched = array_values(array_diff(array_keys($item->getDirty()), $allowed));

            if ($touched !== []) {
                throw ImmutablePayrollAttributeException::locked('PayrollRunItem', $item->getKey() ?? 'new', $touched);
            }
        });

        static::deleting(static function (PayrollRunItem $item): void {
            // A draft run's items may be removed — that is what "regenerate" means. After that, nothing.
            $status = $item->run?->status;

            if ($status !== null && $status->isEditable()) {
                return;
            }

            throw ImmutablePayrollAttributeException::locked(
                'PayrollRunItem',
                $item->getKey() ?? 'new',
                ['the slip itself — after lock a slip is corrected, never deleted (HR-16)'],
            );
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
     * Do the stored totals equal the sum of this slip's own component rows (HR-13)?
     *
     * `PayrollRunService::lock()` asks this of every item before it locks anything: a slip that does not
     * add up must never become immutable, because after that the only fix is a correction run.
     */
    public function totalsAgree(): bool
    {
        $earnings = (string) $this->components()->where('side', 'earning')->sum('amount');
        $deductions = (string) $this->components()->where('side', 'deduction')->sum('amount');

        return Money::compare((string) $this->gross_earnings, $earnings) === 0
            && Money::compare((string) $this->total_deductions, $deductions) === 0
            && Money::compare((string) $this->net_salary, Money::sub($earnings, $deductions)) === 0;
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function attendanceSummary(): BelongsTo
    {
        return $this->belongsTo(AttendanceMonthlySummary::class, 'attendance_monthly_summary_id');
    }

    public function correctedItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_item_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_item_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function components(): HasMany
    {
        return $this->hasMany(PayrollRunItemComponent::class, 'payroll_run_item_id')->orderBy('sort_order');
    }

    public function advanceRepayments(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceRepayment::class, 'payroll_run_item_id');
    }
}
