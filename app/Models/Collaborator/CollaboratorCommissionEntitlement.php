<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\CommissionApprovalMode;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionRuleSource;
use App\Enums\CommissionScope;
use App\Enums\EntitlementDocumentType;
use App\Enums\EntitlementStatus;
use App\Enums\FixedCommissionRelease;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Institute\StudentFee;
use App\Models\Project\Project;
use App\Models\Project\ProjectMilestone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The promise a rule made about one document (finance spine §2.10).
 *
 * **The contract layer between a rule and the releases.** A base other than `paid` promises money before
 * the business has collected any — "10 % of the gross fee" is a number the moment the charge exists.
 * This row records that promise once, with the rule and the figures snapshotted, and each receipt
 * releases a slice. The total can therefore never exceed what was agreed, however many payments arrive
 * and whatever anybody later changes the rule to.
 *
 * **One is opened for every commission, including the uncapped `paid` base** ([D-FS-7]), where
 * `entitlement_amount` is NULL. One uniform code path rather than a conditional one: the conditional
 * version is the one where the rarely-taken branch carries the bug.
 *
 * **It is also the lock.** Two receipts landing against the same document at the same moment serialise
 * on this row, which is what stops a fixed amount being promised twice.
 *
 * @property string|null $entitlement_amount
 * @property string $released_amount
 * @property EntitlementStatus $status
 */
class CollaboratorCommissionEntitlement extends Model
{
    use Blameable;
    use FinancialRow;
    use HasGeneratedColumns;
    use LogsActivityWithContext;

    protected $table = 'collaborator_commission_entitlements';

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
            'collaborator_referral_id' => 'integer',
            'commission_setting_id' => 'integer',
            'rule_source' => CommissionRuleSource::class,
            'document_type' => EntitlementDocumentType::class,
            'student_admission_id' => 'integer',
            'student_fee_id' => 'integer',
            'project_id' => 'integer',
            'project_milestone_id' => 'integer',
            'commission_for' => CommissionScope::class,
            'calculation_type' => CommissionCalculationType::class,
            'commission_rate' => 'decimal:4',
            'fixed_amount' => 'decimal:2',
            'fixed_release' => FixedCommissionRelease::class,
            'commission_base' => CommissionBase::class,
            'approval_mode' => CommissionApprovalMode::class,
            'hold_days' => 'integer',
            'document_base_amount' => 'decimal:2',
            'collectible_amount' => 'decimal:2',
            'entitlement_amount' => 'decimal:2',
            'max_commission_amount' => 'decimal:2',
            'released_amount' => 'decimal:2',
            'reversed_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'over_released_amount' => 'decimal:2',
            'ledger_entry_count' => 'integer',
            'status' => EntitlementStatus::class,
            'opened_on' => 'immutable_date',
            'closed_on' => 'immutable_date',
            'supersedes_id' => 'integer',
            'superseded_at' => 'immutable_datetime',
            'rule_snapshot' => 'array',
        ];
    }

    /**
     * `document_key` is a non-null key so `uq_cce_current` actually bites; `current_guard` is what makes that index mean "at most one CURRENT promise per document".
     *
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        return ['document_key', 'current_guard'];
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
     * The running totals and the closing bookkeeping. Every snapshotted rule figure stays fixed: the
     * promise is what it was when it was made, and re-reading the rule at release time is exactly how a
     * rate change would silently rewrite history.
     *
     * @return list<string>
     */
    protected function mutableColumns(): array
    {
        return [
            'released_amount', 'reversed_amount', 'collected_amount', 'over_released_amount',
            'ledger_entry_count', 'status', 'closed_on',
            'superseded_at', 'supersede_reason',
            // A document whose figure moves — a fee discounted after the fact, a project revalued —
            // re-floors the promise through supersede(), which writes these two on the successor.
            'entitlement_amount', 'document_base_amount', 'collectible_amount',
        ];
    }

    protected function owningService(): string
    {
        return 'App\\Services\\Collaborator\\CommissionEntitlementService';
    }

    protected function noDeleteMessage(): string
    {
        return 'An entitlement is the promise every commission on this document was released against. '
            .'It is superseded, never deleted.';
    }

    /*
    |--------------------------------------------------------------------------
    | The arithmetic of a promise
    |--------------------------------------------------------------------------
    */

    /**
     * Is this promise uncapped? True for the default `paid` base, where each receipt simply earns its
     * percentage and there is no total to run out of.
     */
    public function isUncapped(): bool
    {
        return $this->entitlement_amount === null;
    }

    /**
     * How much of the promise is left to release. `null` when uncapped — deliberately not `'0.00'` and
     * not some large number, because a caller that treats "no limit" as a figure will eventually
     * compare it.
     */
    public function remaining(): ?string
    {
        if ($this->isUncapped()) {
            return null;
        }

        $left = bcsub((string) $this->entitlement_amount, (string) $this->released_amount, 2);

        return bccomp($left, '0.00', 2) === 1 ? $left : '0.00';
    }

    /**
     * Cap a proposed release at what is left. The database's `chk_cce_cap` is the backstop; this is the
     * arithmetic that means it never has to fire.
     */
    public function capRelease(string $proposed): string
    {
        $remaining = $this->remaining();

        if ($remaining === null) {
            return $proposed;
        }

        return bccomp($proposed, $remaining, 2) === 1 ? $remaining : $proposed;
    }

    /**
     * Has the whole promise been handed over?
     */
    public function isFullyReleased(): bool
    {
        return ! $this->isUncapped()
            && bccomp((string) $this->released_amount, (string) $this->entitlement_amount, 2) >= 0;
    }

    public function canRelease(): bool
    {
        return $this->status->canRelease() && ! $this->isFullyReleased();
    }

    /**
     * Was more released than the promise now allows? Set when a supersede floors the promise below what
     * has already been paid out — the discrepancy queue reads this, and **nothing claws it back
     * automatically** (§6.6): somebody decides.
     */
    public function isOverReleased(): bool
    {
        return bccomp((string) $this->over_released_amount, '0.00', 2) === 1;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', EntitlementStatus::Open->value)->whereNull('superseded_at');
    }

    public function scopeDiscrepant(Builder $query): Builder
    {
        return $query->where('over_released_amount', '>', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (spine §2.10)
    |--------------------------------------------------------------------------
    */

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(CollaboratorReferral::class, 'collaborator_referral_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CollaboratorCommissionSetting::class, 'commission_setting_id');
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

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionLedgerEntry::class, 'entitlement_id');
    }
}
