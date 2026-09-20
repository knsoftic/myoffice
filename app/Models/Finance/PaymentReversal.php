<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\CommissionProcessingState;
use App\Enums\CommissionSkipReason;
use App\Enums\PaymentMethod;
use App\Enums\ReversalApprovalStatus;
use App\Enums\ReversalType;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Institute\StudentFeePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Every un-doing of received money (finance spine §2.7).
 *
 * **One table for both sides.** A student refund and a client refund are the same event with a
 * different target, and two tables would mean two reversal engines that eventually disagree about what
 * a partial refund does to commission.
 *
 * **It is itself a commission trigger**, so it gets exactly the guarantees an earning does: its own
 * idempotency key, its own `commission_state`, its own slot in `uq_cle_source`. A double-clicked Refund
 * cannot claw back twice.
 *
 * **Commission is not clawed back until the reversal is approved.** A refund somebody entered and a
 * refund somebody authorised are different facts, and taking money off a partner on the strength of the
 * first would be a debt raised by a data-entry error.
 *
 * @property string $amount
 * @property ReversalType $type
 * @property ReversalApprovalStatus $approval_status
 */
class PaymentReversal extends Model
{
    use Blameable;
    use FinancialRow;
    use LogsActivityWithContext;

    protected $table = 'payment_reversals';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_fee_payment_id' => 'integer',
            'project_payment_id' => 'integer',
            'type' => ReversalType::class,
            'amount' => 'decimal:2',
            'refund_method' => PaymentMethod::class,
            'occurred_on' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
            'approval_status' => ReversalApprovalStatus::class,
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'performed_by' => 'integer',
            'commission_state' => CommissionProcessingState::class,
            'commission_skip_reason' => CommissionSkipReason::class,
            'commission_attempts' => 'integer',
            'commission_processed_at' => 'immutable_datetime',
        ];
    }

    public function moduleSlug(): string
    {
        return 'payments';
    }

    protected function activityModule(): ?string
    {
        return 'payments';
    }

    /**
     * The approval decision and the commission bookkeeping. The amount, the type, the target and the
     * date are what the reversal **is** and never move.
     *
     * @return list<string>
     */
    protected function mutableColumns(): array
    {
        return [
            'notes', 'reference_no', 'attachment_path',
            'approval_status', 'approved_by', 'approved_at',
            'rejected_at', 'rejection_reason',
            'commission_state', 'commission_skip_reason', 'commission_skip_detail',
            'commission_attempts', 'commission_processed_at',
        ];
    }

    protected function owningService(): string
    {
        return 'App\\Services\\Finance\\PaymentService';
    }

    protected function noDeleteMessage(): string
    {
        return 'A reversal is the evidence that money went back. It is never deleted (D16) — the '
            .'clawback it caused points at it.';
    }

    /*
    |--------------------------------------------------------------------------
    | Questions the engine asks
    |--------------------------------------------------------------------------
    */

    /**
     * May the engine act on this yet? Guard step 1 of the reversal flow.
     */
    public function mayReverseCommission(): bool
    {
        return $this->approval_status->allowsCommissionReversal();
    }

    /**
     * The receipt this undoes — whichever side it is on. `chk_pr_one_target` guarantees exactly one.
     */
    public function target(): StudentFeePayment|ProjectPayment|null
    {
        return $this->studentFeePayment ?? $this->projectPayment;
    }

    /**
     * Is this a full un-doing, or a slice?
     *
     * Asked against the receipt rather than the type alone: a `full_refund` of a receipt that was
     * already half refunded is still, arithmetically, the rest of it — and the status the receipt ends
     * in depends on that, not on the label somebody picked.
     */
    public function isFullReversal(): bool
    {
        $target = $this->target();

        if ($target === null) {
            return $this->type->isFullVoid();
        }

        return bccomp(
            bcadd((string) $target->refunded_amount, (string) $this->amount, 2),
            (string) $target->amount,
            2
        ) >= 0;
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('approval_status', ReversalApprovalStatus::Pending->value);
    }

    public function scopeAwaitingCommission(Builder $query): Builder
    {
        return $query->whereIn('commission_state', [
            CommissionProcessingState::Queued->value,
            CommissionProcessingState::Failed->value,
        ])->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (spine §2.7)
    |--------------------------------------------------------------------------
    */

    public function studentFeePayment(): BelongsTo
    {
        return $this->belongsTo(StudentFeePayment::class, 'student_fee_payment_id');
    }

    public function projectPayment(): BelongsTo
    {
        return $this->belongsTo(ProjectPayment::class, 'project_payment_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The negative entries this reversal caused.
     */
    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionLedgerEntry::class, 'payment_reversal_id');
    }
}
