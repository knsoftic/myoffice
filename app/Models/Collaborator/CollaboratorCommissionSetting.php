<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionRuleStatus;
use App\Enums\CommissionScope;
use App\Enums\FixedCommissionRelease;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use App\Services\Collaborator\Exceptions\ImmutableRuleAttributeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One immutable commission rule version (finance spine §2.9).
 *
 * **A rate change is a new version, never an edit.** That is the whole design: "what was this partner's
 * rate on the day that payment arrived" has to be answerable years later, and a mutable rate column
 * cannot answer it. `effective_to` is the only money-adjacent column that moves, and only from NULL to
 * a date — closing a window, never re-opening one.
 *
 * **There is no global default rate** ([D-FS-9]). When no version covers a date the engine skips with
 * `CommissionSkipReason::NoEffectiveRule` and says so on the report: a visible nothing rather than an
 * invisible something nobody decided on.
 *
 * @property CommissionScope $commission_for
 * @property CommissionCalculationType $calculation_type
 * @property CommissionRuleStatus $status
 */
class CollaboratorCommissionSetting extends Model
{
    use Blameable;
    use FinancialRow;
    use HasGeneratedColumns;
    use LogsActivityWithContext;

    protected $table = 'collaborator_commission_settings';

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
            'commission_for' => CommissionScope::class,
            'is_enabled' => 'boolean',
            'calculation_type' => CommissionCalculationType::class,
            'rate' => 'decimal:4',
            'fixed_amount' => 'decimal:2',
            'fixed_release' => FixedCommissionRelease::class,
            'base_override' => CommissionBase::class,
            'applies_to_fee_types' => 'array',
            'applies_to_milestone_ids' => 'array',
            'min_payment_amount' => 'decimal:2',
            'max_commission_amount' => 'decimal:2',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'status' => CommissionRuleStatus::class,
            'version' => 'integer',
            'supersedes_id' => 'integer',
            'superseded_at' => 'immutable_datetime',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
        ];
    }

    /**
     * Carries `uq_ccs_open`: at most one open-ended version per collaborator per scope.
     *
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        return ['open_guard'];
    }

    public function moduleSlug(): string
    {
        return 'collaborator_commission_settings';
    }

    protected function activityModule(): ?string
    {
        return 'collaborator_commission_settings';
    }

    /**
     * Four columns, and every one of them closes the version rather than changing what it said.
     *
     * @return list<string>
     */
    protected function mutableColumns(): array
    {
        return ['effective_to', 'status', 'superseded_at', 'notes'];
    }

    protected function owningService(): string
    {
        return 'App\\Services\\Collaborator\\CommissionRuleService';
    }

    /**
     * @param  list<string>  $touched
     * @param  list<string>  $allowed
     */
    protected function immutableAttributeException(array $touched, array $allowed): LogicException
    {
        return ImmutableRuleAttributeException::forColumns((string) $this->getKey(), $touched, $allowed);
    }

    protected function noDeleteMessage(): string
    {
        return 'A rule version is superseded, never deleted: every commission it produced quotes it, '
            .'and a deleted version makes those unexplainable.';
    }

    /*
    |--------------------------------------------------------------------------
    | Questions the resolver asks
    |--------------------------------------------------------------------------
    */

    /**
     * Does this version govern a payment dated `$on`?
     */
    public function coversDate(Carbon $on): bool
    {
        $date = $on->copy()->startOfDay();

        if ($this->effective_from->greaterThan($date)) {
            return false;
        }

        if ($this->effective_to !== null && $this->effective_to->lessThan($date)) {
            return false;
        }

        return $this->status->canGovernAPayment();
    }

    /**
     * Is this version actually going to pay anything? A version with `is_enabled = false` exists to
     * record that commission was deliberately switched off for this partner and scope — which is a
     * different fact from there being no rule at all, and reads differently on the skip report.
     */
    public function paysCommission(): bool
    {
        return $this->is_enabled && $this->status->canGovernAPayment();
    }

    /**
     * The base this version uses: its own override, or the business's setting for the scope.
     */
    public function resolvedBase(): CommissionBase
    {
        if ($this->base_override !== null) {
            return $this->base_override;
        }

        $configured = (string) setting($this->commission_for->baseSettingKey(), CommissionBase::Paid->value);

        return CommissionBase::tryFrom($configured) ?? CommissionBase::Paid;
    }

    /**
     * How a fixed amount is released: its own setting, or the business's default.
     */
    public function resolvedRelease(): FixedCommissionRelease
    {
        if ($this->fixed_release !== null) {
            return $this->fixed_release;
        }

        $configured = (string) setting('collaborator.fixed_commission_release', FixedCommissionRelease::Prorated->value);

        return FixedCommissionRelease::tryFrom($configured) ?? FixedCommissionRelease::Prorated;
    }

    /**
     * Is a receipt of this size worth processing under this rule?
     */
    public function meetsMinimumPayment(string $amount): bool
    {
        if ($this->min_payment_amount === null) {
            return true;
        }

        return bccomp($amount, (string) $this->min_payment_amount, 2) >= 0;
    }

    /**
     * The resolver's query: versions that could cover this date, newest window first.
     *
     * `uq_ccs_open` guarantees at most one open-ended version per collaborator per scope, and
     * `uq_ccs_start` that two versions never begin on one day — so this can never return two candidates
     * for a single date.
     */
    public function scopeEffectiveOn(Builder $query, Carbon $date): Builder
    {
        $on = $date->copy()->startOfDay()->toDateString();

        return $query
            ->whereDate('effective_from', '<=', $on)
            ->where(static fn (Builder $inner): Builder => $inner
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $on))
            ->where('status', '<>', CommissionRuleStatus::Cancelled->value)
            ->orderByDesc('effective_from');
    }

    public function scopeOpenEnded(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (spine §2.9)
    |--------------------------------------------------------------------------
    */

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionEntitlement::class, 'commission_setting_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionLedgerEntry::class, 'commission_setting_id');
    }
}
