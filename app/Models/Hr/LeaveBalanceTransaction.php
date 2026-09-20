<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\LeaveLedgerReason;
use App\Enums\LedgerEntryType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Hr\Concerns\AppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of leave days (phase-07 §2.14, HR-7).
 *
 * **Append-only** (D19): the balance above it is a cache, and this is the truth. A wrong entry is
 * corrected by an opposite entry with a note, never by an edit.
 *
 * `days` is a positive magnitude and `entry_type` carries the direction ([D-HR-4]); the only column
 * anything sums is the generated `signed_days`. Storing a signed number would make "how many days were
 * credited this year" a query with a sign filter instead of a SUM.
 *
 * `occurred_on` is the **value date**, never `now()`: a grant back-dated to the start of the leave year
 * belongs to that year even if somebody entered it in March.
 *
 * `balance_after_days` snapshots the balance after the write, so the statement reads like a bank
 * statement rather than a list somebody has to add up to understand.
 */
class LeaveBalanceTransaction extends Model
{
    use AppendOnly;
    use Blameable;
    use LogsActivityWithContext;


    protected $table = 'leave_balance_transactions';



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'leave_year' => 'integer',
            'entry_type' => LedgerEntryType::class,
            'reason' => LeaveLedgerReason::class,
            'days' => 'decimal:4',
            'signed_days' => 'decimal:4',
            'leave_request_id' => 'integer',
            'balance_after_days' => 'decimal:4',
            'performed_by' => 'integer',
            'occurred_on' => 'date',
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
            'balance_after_days',
        ];
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

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
