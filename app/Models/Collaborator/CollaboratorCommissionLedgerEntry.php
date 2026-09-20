<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\CommissionApprovalMode;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionRuleSource;
use App\Enums\CommissionSourceType;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Enums\LedgerEntryType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Finance\PaymentReversal;
use App\Models\Finance\ProjectPayment;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeePayment;
use App\Models\Project\Project;
use App\Models\Project\ProjectMilestone;
use App\Models\User;
use App\Services\Collaborator\Exceptions\ImmutableLedgerAttributeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * **The spine** (finance spine §2.11, requirement §51).
 *
 * Every movement of entitlement — earning, reversal, clawback, adjustment, write-off — is one immutable
 * row recording which transaction caused it, which rule and rate applied, and the exact bcmath trace.
 * Nothing here is ever corrected by an edit: a wrong commission is corrected by a reversing negative row
 * that references the original (`CLAUDE.md` rule 3), because the reversal is what the partner can be
 * shown.
 *
 * **`signed_amount` is the only column anything ever sums.** `amount` is a positive magnitude with
 * `CHECK > 0`; the direction lives in `entry_type`; the generated column combines them. A sum that has
 * to know about direction is a sum somebody eventually writes without it.
 *
 * **Inserted only by `LedgerWriter`** (INV-21). It composes the dedupe key, locks the wallet, writes the
 * balance delta and the audit row in one transaction — a row created any other way would have none of
 * that and would look completely normal.
 *
 * @property string $amount
 * @property string $signed_amount
 * @property CommissionStatus $status
 * @property LedgerEntryType $entry_type
 * @property LedgerEntryPurpose $purpose
 */
class CollaboratorCommissionLedgerEntry extends Model
{
    use Blameable;
    use FinancialRow;
    use HasGeneratedColumns;
    use LogsActivityWithContext;

    protected $table = 'collaborator_commission_ledger_entries';

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
            'collaborator_id' => 'integer',
            'collaborator_wallet_id' => 'integer',
            'entitlement_id' => 'integer',
            'collaborator_referral_id' => 'integer',
            'commission_setting_id' => 'integer',
            'rule_source' => CommissionRuleSource::class,
            'entry_type' => LedgerEntryType::class,
            'purpose' => LedgerEntryPurpose::class,
            'source_type' => CommissionSourceType::class,
            'source_id' => 'integer',
            'student_fee_payment_id' => 'integer',
            'project_payment_id' => 'integer',
            'payment_reversal_id' => 'integer',
            'reverses_entry_id' => 'integer',
            'student_id' => 'integer',
            'student_fee_id' => 'integer',
            'project_id' => 'integer',
            'project_milestone_id' => 'integer',
            'gross_amount' => 'decimal:2',
            'commission_base' => CommissionBase::class,
            'base_amount' => 'decimal:2',
            'calculation_type' => CommissionCalculationType::class,
            'commission_rate' => 'decimal:4',
            'fixed_amount' => 'decimal:2',
            'entitlement_total' => 'decimal:2',
            'released_before' => 'decimal:2',
            'amount' => 'decimal:2',
            'signed_amount' => 'decimal:2',
            'rule_snapshot' => 'array',
            'status' => CommissionStatus::class,
            'approval_mode' => CommissionApprovalMode::class,
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'hold_until' => 'immutable_date',
            'available_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'allocated_amount' => 'decimal:2',
            'reversed_amount' => 'decimal:2',
            'reversed_at' => 'immutable_datetime',
            'clawed_back_amount' => 'decimal:2',
            'cancelled_at' => 'immutable_datetime',
            'cancelled_by' => 'integer',
            'transaction_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /**
     * `signed_amount` is the only column anything ever sums: `amount` with the direction from
     * `entry_type` applied. `source_guard` is what keeps `uq_cle_source` off manual adjustments, which
     * have no causing row to be unique about (migration `2026_09_12_130023`).
     *
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        return ['signed_amount', 'source_guard'];
    }

    public function moduleSlug(): string
    {
        return 'collaborator_commissions';
    }

    protected function activityModule(): ?string
    {
        return 'collaborator_commissions';
    }

    /**
     * INV-4's whitelist. What somebody earned, from which receipt, under which rule, at which rate, on
     * which date — none of it moves. What moves is the bookkeeping *around* the entry: its status, its
     * approval, how much a payout has claimed, how much has been undone.
     *
     * @return list<string>
     */
    protected function mutableColumns(): array
    {
        return [
            'status', 'approved_by', 'approved_at', 'available_at', 'paid_at', 'hold_until',
            'allocated_amount', 'reversed_amount', 'reversed_at', 'clawed_back_amount',
            'cancelled_at', 'cancelled_by', 'cancel_reason', 'notes',
        ];
    }

    protected function owningService(): string
    {
        return 'App\\Services\\Collaborator\\LedgerWriter';
    }

    /**
     * @param  list<string>  $touched
     * @param  list<string>  $allowed
     */
    protected function immutableAttributeException(array $touched, array $allowed): LogicException
    {
        return ImmutableLedgerAttributeException::forColumns((string) $this->getKey(), $touched, $allowed);
    }

    protected function noDeleteMessage(): string
    {
        return 'Deleting a commission would free its slot in uq_cle_source and let the same receipt pay '
            .'twice. It is reversed by a negative entry that points at it, never deleted.';
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen shows
    |--------------------------------------------------------------------------
    */

    /**
     * The human reference (§51 "Ledger ID"). Derived rather than stored: a second column holding the
     * same fact is a second thing that can disagree with the primary key.
     */
    protected function reference(): Attribute
    {
        return Attribute::get(fn (): string => 'CLE-'.$this->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | The arithmetic of one entry
    |--------------------------------------------------------------------------
    */

    /**
     * How much of this entry a payout could still claim.
     *
     * The same expression `payable()` filters on, kept in one place: allocated **and** reversed both
     * consume it, because paying out an amount that has since been reversed is the mistake INV-11
     * exists to make impossible.
     */
    public function payableRemaining(): string
    {
        $spent = bcadd((string) $this->allocated_amount, (string) $this->reversed_amount, 2);
        $left = bcsub((string) $this->amount, $spent, 2);

        return bccomp($left, '0.00', 2) === 1 ? $left : '0.00';
    }

    /**
     * How much of this entry can still be undone, whether it has been paid out or not.
     */
    public function reversibleRemaining(): string
    {
        $undone = bcadd((string) $this->reversed_amount, (string) $this->clawed_back_amount, 2);
        $left = bcsub((string) $this->amount, $undone, 2);

        return bccomp($left, '0.00', 2) === 1 ? $left : '0.00';
    }

    /**
     * Is this entry spendable **today**? The hold date is part of the answer: a business that holds
     * commission for thirty days has approved entries that are not yet available, and the two states
     * are genuinely different to the partner looking at them.
     */
    public function isPayableOn(Carbon $on): bool
    {
        if (! $this->status->isPayable()) {
            return false;
        }

        if ($this->entry_type !== LedgerEntryType::Credit) {
            return false;
        }

        if (bccomp($this->payableRemaining(), '0.00', 2) !== 1) {
            return false;
        }

        return $this->hold_until === null
            || $this->hold_until->lessThanOrEqualTo($on->copy()->startOfDay());
    }

    /**
     * Has this entry been undone in full?
     */
    public function isFullyUndone(): bool
    {
        $undone = bcadd((string) $this->reversed_amount, (string) $this->clawed_back_amount, 2);

        return bccomp($undone, (string) $this->amount, 2) >= 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes (spine §2.3's list)
    |--------------------------------------------------------------------------
    */

    /**
     * What a payout may consume. The date is passed rather than read from `now()` so a report can ask
     * the same question about a past day and get the answer that was true then.
     */
    public function scopePayable(Builder $query, ?Carbon $on = null): Builder
    {
        $date = ($on ?? now())->copy()->startOfDay()->toDateString();

        return $query
            ->where('status', CommissionStatus::Available->value)
            ->where('entry_type', LedgerEntryType::Credit->value)
            ->whereRaw('`amount` - `allocated_amount` - `reversed_amount` > 0')
            ->where(static fn (Builder $inner): Builder => $inner
                ->whereNull('hold_until')
                ->orWhereDate('hold_until', '<=', $date));
    }

    public function scopeEarnings(Builder $query): Builder
    {
        return $query->whereIn('purpose', [
            LedgerEntryPurpose::StudentCommission->value,
            LedgerEntryPurpose::ProjectCommission->value,
        ]);
    }

    public function scopeUndos(Builder $query): Builder
    {
        return $query->whereIn('purpose', [
            LedgerEntryPurpose::Reversal->value,
            LedgerEntryPurpose::Clawback->value,
            LedgerEntryPurpose::WriteOff->value,
        ]);
    }

    public function scopeForCollaborator(Builder $query, int $collaboratorId): Builder
    {
        return $query->where('collaborator_id', $collaboratorId);
    }

    /**
     * Phase 2's `DateRange` convention, applied to the **business** date. A statement that filtered on
     * `posted_at` would move rows between months whenever a job ran late.
     */
    public function scopeInRange(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('transaction_date', [
            $from->copy()->startOfDay()->toDateString(),
            $to->copy()->startOfDay()->toDateString(),
        ]);
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', CommissionStatus::Pending->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (spine §2.11)
    |--------------------------------------------------------------------------
    */

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CollaboratorWallet::class, 'collaborator_wallet_id');
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(CollaboratorCommissionEntitlement::class, 'entitlement_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(CollaboratorReferral::class, 'collaborator_referral_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CollaboratorCommissionSetting::class, 'commission_setting_id');
    }

    public function studentFeePayment(): BelongsTo
    {
        return $this->belongsTo(StudentFeePayment::class, 'student_fee_payment_id');
    }

    public function projectPayment(): BelongsTo
    {
        return $this->belongsTo(ProjectPayment::class, 'project_payment_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(PaymentReversal::class, 'payment_reversal_id');
    }

    /**
     * The entry this one undoes.
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * The entries that undid this one — several, when a receipt is refunded in instalments.
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_entry_id');
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'student_fee_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CollaboratorPayoutAllocation::class, 'ledger_entry_id');
    }

    /**
     * The payouts that carried this earning — through the allocation pivot, so a partial withdrawal is
     * expressible and a clawback can be traced to the transfer that paid it.
     */
    public function payouts(): BelongsToMany
    {
        return $this->belongsToMany(
            CollaboratorPayout::class,
            'collaborator_payout_allocations',
            'ledger_entry_id',
            'payout_id',
        )->withPivot(['amount', 'is_released', 'release_reason', 'released_at']);
    }
}
