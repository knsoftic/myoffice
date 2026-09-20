<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\AdvanceRecoveryType;
use App\Enums\LedgerEntryType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Hr\Concerns\AppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slice of an advance coming back (phase-07 §2.22, HR-19).
 *
 * **Append-only** (D16, D19): only `notes` may be updated. A recovery that should not have been taken is
 * reversed by a `credit` row on a correction run, not by deleting the original.
 *
 * `amount` is a positive magnitude and `entry_type` carries the direction ([D-HR-4]); the only column
 * anything sums is the generated `signed_amount`.
 *
 * A **waiver** is not money at all — it is the business deciding not to collect — and it counts against
 * the same ceiling as a real recovery, which is what makes `recovered + waived <= amount` meaningful.
 */
class EmployeeAdvanceRepayment extends Model
{
    use AppendOnly;
    use Blameable;
    use LogsActivityWithContext;


    protected $table = 'employee_advance_repayments';



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_advance_id' => 'integer',
            'payroll_run_item_id' => 'integer',
            'entry_type' => LedgerEntryType::class,
            'recovery_type' => AdvanceRecoveryType::class,
            'amount' => 'decimal:2',
            'signed_amount' => 'decimal:2',
            'recovered_on' => 'date',
            'performed_by' => 'integer',
        ];
    }

    /**
     * The only columns an UPDATE may touch (D19). Everything else is evidence.
     *
     * @return list<string>
     */
    protected function updatableColumns(): array
    {
        return [
            'notes',
        ];
    }

    public function moduleSlug(): string
    {
        return 'employee_advances';
    }

    protected function activityModule(): ?string
    {
        return 'employee_advances';
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    public function payrollItem(): BelongsTo
    {
        return $this->belongsTo(PayrollRunItem::class, 'payroll_run_item_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
