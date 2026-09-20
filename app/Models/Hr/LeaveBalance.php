<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Concerns\Blameable;
use App\Support\Money;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's balance of one leave type for one leave year (phase-07 §2.13, HR-7).
 *
 * **A cache with no truth of its own.** Every column equals the sum of its ledger rows, and
 * `available_days = entitled + carried_forward + accrued + adjusted - consumed - pending - encashed
 * - expired`. The test helper re-derives all of it after every leave test, so a drift is a failing test
 * rather than a number somebody notices a year later.
 *
 * `pending_days` is what makes two overlapping requests impossible to fit into one quota: a pending
 * request **reserves** days and only approval consumes them.
 *
 * Only `LeaveBalanceService` writes this table, always under a row lock, always in the same transaction as
 * the ledger row that justifies the change.
 */
class LeaveBalance extends Model
{
    use Blameable;
    use LogsActivityWithContext;


    protected $table = 'leave_balances';



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'leave_year' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'entitled_days' => 'decimal:4',
            'carried_forward_days' => 'decimal:4',
            'accrued_days' => 'decimal:4',
            'adjusted_days' => 'decimal:4',
            'consumed_days' => 'decimal:4',
            'pending_days' => 'decimal:4',
            'encashed_days' => 'decimal:4',
            'expired_days' => 'decimal:4',
            'available_days' => 'decimal:4',
            'last_recalculated_at' => 'datetime',
        ];
    }

    /**
     * What `available_days` must equal, computed from this row's own columns (HR-7).
     *
     * Kept here rather than in the service so a test, a screen and the service all ask the same question
     * of the same definition.
     */
    public function derivedAvailable(): string
    {
        $credits = Money::sum(
            (string) $this->entitled_days,
            (string) $this->carried_forward_days,
            (string) $this->accrued_days,
            (string) $this->adjusted_days,
        );

        $debits = Money::sum(
            (string) $this->consumed_days,
            (string) $this->pending_days,
            (string) $this->encashed_days,
            (string) $this->expired_days,
        );

        return Money::round(Money::sub($credits, $debits), 4);
    }

    public function moduleSlug(): string
    {
        return 'leaves';
    }

    protected function activityModule(): ?string
    {
        return 'leaves';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(LeaveBalanceTransaction::class, 'employee_id', 'employee_id')
            ->where('leave_type_id', $this->leave_type_id)
            ->where('leave_year', $this->leave_year);
    }
}
