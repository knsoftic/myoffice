<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\LeaveDayPortion;
use App\Enums\LeaveRequestStatus;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One leave application (phase-07 §2.15, requirement §27).
 *
 * `total_days` is the **counted** days after weekends and holidays are excluded per the type, and
 * `paid_days + unpaid_days = total_days` is a CHECK — a quota overrun approved under a negative-balance
 * allowance lands in `unpaid_days` rather than quietly becoming free leave.
 *
 * `balance_snapshot_days` records what the balance was when the request was filed. Months later, when
 * somebody argues about a number, that snapshot is the difference between an answer and a guess.
 *
 * A **pending** request reserves days and only an **approved** one consumes them, which is why a rejection
 * releases a reservation rather than refunding a consumption (HR-7).
 */
class LeaveRequest extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;


    protected $table = 'leave_requests';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'from_date',
        'to_date',
        'day_portion',
        'reason',
        'contact_during_leave',
    ];



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'branch_id' => 'integer',
            'from_date' => 'date',
            'to_date' => 'date',
            'day_portion' => LeaveDayPortion::class,
            'total_days' => 'decimal:4',
            'paid_days' => 'decimal:4',
            'unpaid_days' => 'decimal:4',
            'status' => LeaveRequestStatus::class,
            'current_approval_level' => 'integer',
            'balance_snapshot_days' => 'decimal:4',
            'applied_on' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
            'attendance_applied_at' => 'datetime',
        ];
    }

    /**
     * The human reference used in messages and on screens.
     */
    public function getReferenceAttribute(): string
    {
        return (string) $this->request_number;
    }

    /**
     * Is this request still waiting on somebody?
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function days(): HasMany
    {
        return $this->hasMany(LeaveRequestDay::class, 'leave_request_id')->orderBy('leave_date');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveApproval::class, 'leave_request_id')->orderBy('level');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'leave_request_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveBalanceTransaction::class, 'leave_request_id');
    }
}
