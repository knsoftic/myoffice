<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Money advanced against future salary (phase-07 §2.21, requirement §28).
 *
 * **`recovered + waived <= amount` is a CHECK** (HR-19): an advance can never be recovered for more than
 * it was worth, whatever a calculation does. The three amount columns are caches over the append-only
 * repayment rows — recomputed by SUM, never incremented — so a replayed payroll cannot collect twice.
 *
 * Waiving is a recorded decision, not a disappearance: it counts against the same ceiling as a real
 * recovery and needs a note.
 *
 * Once disbursed, the amount, the employee and the disbursement date are **frozen**. Changing what was
 * advanced after the money left would leave the recovery schedule describing a loan that never happened.
 *
 * `first_recovery_year` / `_month` say which payroll period recovery starts in, so an advance taken on the
 * 28th is not recovered from the salary being run that same week unless somebody meant it to be.
 */
class EmployeeAdvance extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    /** Frozen once the money has gone out. */
    public const FROZEN_AFTER_DISBURSEMENT = ['amount', 'employee_id', 'disbursed_on'];

    protected $table = 'employee_advances';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'amount',
        'reason',
        'requested_on',
        'installment_count',
        'first_recovery_year',
        'first_recovery_month',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'requested',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'amount' => 'decimal:2',
            'requested_on' => 'date',
            'installment_count' => 'integer',
            'installment_amount' => 'decimal:2',
            'first_recovery_year' => 'integer',
            'first_recovery_month' => 'integer',
            'recovered_amount' => 'decimal:2',
            'waived_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'status' => AdvanceStatus::class,
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'rejected_by' => 'integer',
            'rejected_at' => 'datetime',
            'disbursed_on' => 'date',
            'disbursement_method' => PaymentMethod::class,
            'settled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (EmployeeAdvance $advance): void {
            // getRawOriginal(): getOriginal() would apply the cast and hand back an enum, which never
            // equals the string it is compared to — so the guard would pass silently on every advance.
            $wasDisbursed = in_array(
                $advance->getRawOriginal('status'),
                [
                    AdvanceStatus::Disbursed->value,
                    AdvanceStatus::Recovering->value,
                    AdvanceStatus::Settled->value,
                    AdvanceStatus::WrittenOff->value,
                ],
                true
            );

            if (! $wasDisbursed) {
                return;
            }

            $touched = array_values(array_intersect(
                array_keys($advance->getDirty()),
                self::FROZEN_AFTER_DISBURSEMENT
            ));

            if ($touched !== []) {
                throw new LogicException(sprintf(
                    'EmployeeAdvance #%s: %s cannot change once the money has gone out (phase-07 §2.21) — '
                    .'the recovery schedule would describe a loan that never happened.',
                    (string) $advance->getKey(),
                    implode(', ', $touched),
                ));
            }
        });

        static::deleting(static function (EmployeeAdvance $advance): void {
            throw new LogicException(sprintf(
                'EmployeeAdvance #%s cannot be deleted (phase-07 D16): cancellation and write-off are '
                .'statuses, so the money that moved stays on the record.',
                (string) $advance->getKey(),
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'employee_advances';
    }

    protected function activityModule(): ?string
    {
        return 'employee_advances';
    }

    /**
     * What is still owed, derived from this row's own columns — the figure the ceiling protects.
     */
    public function derivedOutstanding(): string
    {
        return Money::round(
            Money::sub((string) $this->amount, Money::add((string) $this->recovered_amount, (string) $this->waived_amount)),
            2
        );
    }

    /**
     * Should payroll take a recovery line for this advance this month?
     */
    public function isRecoverableIn(int $year, int $month): bool
    {
        if (! $this->status->isRecoverable() || Money::compare($this->derivedOutstanding(), '0') <= 0) {
            return false;
        }

        if ($this->first_recovery_year === null || $this->first_recovery_month === null) {
            return true;
        }

        return ($year * 100 + $month) >= ($this->first_recovery_year * 100 + $this->first_recovery_month);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceRepayment::class, 'employee_advance_id')->orderBy('recovered_on');
    }
}
