<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\CommissionProcessingState;
use App\Enums\CommissionSkipReason;
use App\Enums\PaymentMethod;
use App\Enums\ReceivedPaymentStatus;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorReferral;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Client;
use App\Models\Project\Project;
use App\Models\Project\ProjectMilestone;
use App\Models\User;
use App\Services\Finance\Exceptions\ImmutablePaymentAttributeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One client payment against a project (finance spine §2.6).
 *
 * **The only project-side commission trigger**, and deliberately the same shape as
 * `StudentFeePayment`: two dates, the same duplicate layers, the same commission columns, the same
 * whitelist. One mental model across both engines is worth more than a table tailored to each side,
 * because the thing that goes wrong is always the difference nobody remembered.
 *
 * **`invoice_id` is the single concession to INV-8** (D43), and it is narrow: `InvoiceService` may move
 * it NULL → value → NULL to attach an advance to an invoice raised later, with a mandatory reason, an
 * audit row, and **zero commission effect**. `PaymentService` sets it once at insert and never again.
 *
 * @property string $amount
 * @property string $net_received_amount
 * @property ReceivedPaymentStatus $status
 */
class ProjectPayment extends Model
{
    use Blameable;
    use FinancialRow;
    use HasGeneratedColumns;
    use LogsActivityWithContext;

    protected $table = 'project_payments';

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
            'project_id' => 'integer',
            'client_id' => 'integer',
            'project_milestone_id' => 'integer',
            'invoice_id' => 'integer',
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'net_received_amount' => 'decimal:2',
            'is_advance' => 'boolean',
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
        return 'payments';
    }

    protected function activityModule(): ?string
    {
        return 'payments';
    }

    /**
     * INV-8's whitelist plus `invoice_id`, which is D43's concession and nothing more. No other money
     * column is ever added here: `amount`, `paid_on` and `payment_method_id` stay outside it for ever.
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
            // D43, and only for the document-link move NULL -> value -> NULL by InvoiceService.
            'invoice_id', 'is_advance',
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
            'Project payment', $this->payment_no ?? (string) $this->getKey(), $touched, $allowed
        );
    }

    protected function noDeleteMessage(): string
    {
        return 'A payment is voided or refunded through payment_reversals, never deleted: deleting it '
            .'would free its commission slot and let the same money pay twice (D16).';
    }

    public function countsAsReceived(): bool
    {
        return $this->status->countsAsReceived();
    }

    public function refundableRemaining(): string
    {
        return bcsub((string) $this->amount, (string) $this->refunded_amount, 2);
    }

    public function commissionSettled(): bool
    {
        return $this->commission_state->isSettled();
    }

    public function commissionSkipSentence(): ?string
    {
        if ($this->commission_state !== CommissionProcessingState::Skipped) {
            return null;
        }

        return $this->commission_skip_detail ?? $this->commission_skip_reason?->label();
    }

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
    | Relationships (spine §2.6)
    |--------------------------------------------------------------------------
    */

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
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
        return $this->hasMany(PaymentReversal::class, 'project_payment_id');
    }

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionLedgerEntry::class, 'project_payment_id');
    }
}
