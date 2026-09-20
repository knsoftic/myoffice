<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\LeaveApprovalStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One level of a leave approval chain (phase-07 §2.17).
 *
 * The chain is **derived, not configured** ([D-HR-6]): the type's `approval_levels` sets its depth, the
 * reporting line fills level 1 and holders of `leaves.approve` fill level 2.
 *
 * `expected_approver_user_id` may be null, in which case `fallback_permission` means "any holder of this
 * permission" — which keeps the chain working for somebody with no reporting manager.
 *
 * `skipped` is a real outcome, not a missing answer: a level with nobody to fill it is recorded as skipped
 * so the chain reads as a complete history rather than appearing stuck.
 *
 * **An employee never approves their own leave**, whatever they hold.
 */
class LeaveApproval extends Model
{
    use Blameable;
    use LogsActivityWithContext;


    protected $table = 'leave_approvals';



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leave_request_id' => 'integer',
            'level' => 'integer',
            'expected_approver_employee_id' => 'integer',
            'expected_approver_user_id' => 'integer',
            'status' => LeaveApprovalStatus::class,
            'acted_by_user_id' => 'integer',
            'acted_at' => 'datetime',
            'notified_at' => 'datetime',
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

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function expectedApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'expected_approver_employee_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
