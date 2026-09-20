<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\CommissionProcessingState;
use App\Enums\CommissionSkipReason;
use App\Enums\PaymentMethod;
use App\Enums\ReceivedPaymentStatus;
use App\Models\Branch;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorReferral;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Finance\PaymentReversal;
use App\Models\User;
use App\Services\Finance\Exceptions\ImmutablePaymentAttributeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One physical receipt of student money (finance spine §2.5).
 *
 * **The only student-side commission trigger.** Not an admission, not a registration, not a fee charge —
 * money actually received (`CLAUDE.md` rule 5), and `ReceivedPaymentStatus::countsAsReceived()` is the
 * single expression of what that means.
 *
 * **Two dates, always both.** `paid_on` is the **value date**: it may be back-dated, and it is what
 * selects the rule version and the referral in force. `recorded_at` is when the system heard about it.
 * Keeping only one of them is how a back-dated receipt ends up earning at this year's rate.
 *
 * **`amount`, `paid_on` and `payment_method_id` are outside the whitelist for ever** (INV-8). A receipt
 * that can be edited is a receipt whose commission, whose income report and whose student balance can
 * all move after the fact without a trace. The correct act is a **void** and a fresh receipt.
 *
 * @property string $amount
 * @property string $net_received_amount
 * @property ReceivedPaymentStatus $status
 * @property CommissionProcessingState $commission_state
 */
class StudentFeePayment extends Model
{
    use Blameable;
    use FinancialRow;
    use HasGeneratedColumns;
    use LogsActivityWithContext;

    protected $table = 'student_fee_payments';

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
            'student_fee_id' => 'integer',
            'student_fee_installment_id' => 'integer',
            'student_id' => 'integer',
            'branch_id' => 'integer',
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'net_received_amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'payment_method_id' => 'integer',
            'paid_on' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
            'status' => ReceivedPaymentStatus::class,
            'collaborator_id' => 'integer',
            'collaborator_referral_id' => 'integer',
            'commission_state' => CommissionProcessingState::class,
            'commission_skip_reason' => CommissionSkipReason::class,
            'commission_attempts' => 'integer',
            'commission_processed_at' => 'immutable_datetime',
            'received_by' => 'integer',
        ];
    }

    /**
     * The database computes `amount - refunded_amount`, so an income report that sums it cannot forget the refund.
     *
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        return ['net_received_amount'];
    }

    public function moduleSlug(): string
    {
        return 'student_fees';
    }

    protected function activityModule(): ?string
    {
        return 'student_fees';
    }

    /**
     * INV-8's whitelist, verbatim. `amount`, `paid_on` and every commission *figure* stay outside it.
     *
     * `collaborator_id` and `collaborator_referral_id` are in it because the engine resolves and
     * snapshots them **after** the receipt is written — not because an operator may change who was
     * credited, which is what `ReferralService::change()` is for.
     *
     * @return list<string>
     */
    protected function mutableColumns(): array
    {
        return [
            'notes', 'reference_no', 'receipt_path', 'status', 'refunded_amount',
            'commission_state', 'commission_skip_reason', 'commission_skip_detail',
            'commission_attempts', 'commission_processed_at',
            'collaborator_id', 'collaborator_referral_id',
        ];
    }

    protected function owningService(): string
    {
        return 'App\\Services\\Finance\\PaymentService';
    }

    /**
     * @param  list<string>  $touched
     * @param  list<string>  $allowed
     */
    protected function immutableAttributeException(array $touched, array $allowed): LogicException
    {
        return ImmutablePaymentAttributeException::forColumns(
            'Student fee receipt', $this->receipt_no ?? (string) $this->getKey(), $touched, $allowed
        );
    }

    protected function noDeleteMessage(): string
    {
        return 'A receipt is voided or refunded through payment_reversals, never deleted: deleting it '
            .'would free its commission slot and let the same money pay twice (D16).';
    }

    /*
    |--------------------------------------------------------------------------
    | Questions the engine asks
    |--------------------------------------------------------------------------
    */

    /**
     * Guard step 3 of §6.2, and the whole of `CLAUDE.md` rule 5.
     */
    public function countsAsReceived(): bool
    {
        return $this->status->countsAsReceived();
    }

    /**
     * What is still refundable — recomputed rather than trusted, because INV-9's ceiling is enforced
     * against this number under the row's lock.
     */
    public function refundableRemaining(): string
    {
        return bcsub((string) $this->amount, (string) $this->refunded_amount, 2);
    }

    /**
     * Has the engine finished with this receipt one way or the other?
     */
    public function commissionSettled(): bool
    {
        return $this->commission_state->isSettled();
    }

    /**
     * The sentence a screen shows when nothing was earned. The stored detail wins, because it names the
     * specific partner or rule; the enum's label is the fallback.
     */
    public function commissionSkipSentence(): ?string
    {
        if ($this->commission_state !== CommissionProcessingState::Skipped) {
            return null;
        }

        return $this->commission_skip_detail ?? $this->commission_skip_reason?->label();
    }

    /**
     * The sweeper's query: receipts the engine has not finished with, oldest first.
     */
    public function scopeAwaitingCommission(Builder $query): Builder
    {
        return $query->whereIn('commission_state', [
            CommissionProcessingState::Queued->value,
            CommissionProcessingState::Failed->value,
        ])->orderBy('id');
    }

    public function scopeReceived(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ReceivedPaymentStatus::Cleared->value,
            ReceivedPaymentStatus::PartiallyRefunded->value,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (spine §2.5)
    |--------------------------------------------------------------------------
    */

    public function fee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'student_fee_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(StudentFeeInstallment::class, 'student_fee_installment_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(CollaboratorReferral::class, 'collaborator_referral_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(PaymentReversal::class, 'student_fee_payment_id');
    }

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionLedgerEntry::class, 'student_fee_payment_id');
    }
}
