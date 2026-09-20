<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionRuleSource;
use App\Enums\CommissionScope;
use App\Enums\FixedCommissionRelease;
use App\Models\Collaborator\CollaboratorCommissionSetting;
use App\Support\Money;

/**
 * "Which rule governs this payment, and what does it actually say" (spine §6.1.1, phase-10-12 §6.3).
 *
 * The engine never re-reads a rule row after this point. Everything a calculation needs is flattened
 * here **once**, from one of two authorities — the collaborator's effective-dated version, or a
 * per-project override — so the arithmetic below it has no idea which one it came from and cannot grow a
 * branch that treats them differently.
 *
 * `$rule` is nullable on purpose: a project override (§45) authorises commission from the project row
 * itself and may have no version behind it, which is exactly why `collaborator_commission_settings_id`
 * is nullable on both the entitlement and the ledger entry.
 */
final readonly class RuleResolution
{
    /**
     * @param  list<string>|null  $appliesToFeeTypes  null = "whatever the business setting says"
     * @param  list<int>|null  $appliesToMilestoneIds
     */
    public function __construct(
        public CommissionRuleSource $source,
        public CommissionScope $scope,
        public CommissionCalculationType $calculationType,
        public ?string $rate,
        public ?string $fixedAmount,
        public FixedCommissionRelease $release,
        public CommissionBase $base,
        public bool $isEnabled,
        public ?string $minPaymentAmount = null,
        public ?string $maxCommissionAmount = null,
        public ?array $appliesToFeeTypes = null,
        public ?array $appliesToMilestoneIds = null,
        public ?CollaboratorCommissionSetting $rule = null,
        public bool $baseFallback = false,
    ) {}

    /**
     * The collaborator's own effective-dated version — precedence rank 2, and the only authority on the
     * student side.
     */
    public static function fromRule(CollaboratorCommissionSetting $rule, ?CommissionBase $base = null, bool $baseFallback = false): self
    {
        return new self(
            source: CommissionRuleSource::CollaboratorRule,
            scope: $rule->commission_for,
            calculationType: $rule->calculation_type,
            rate: $rule->rate === null ? null : (string) $rule->rate,
            fixedAmount: $rule->fixed_amount === null ? null : (string) $rule->fixed_amount,
            release: $rule->resolvedRelease(),
            base: $base ?? $rule->resolvedBase(),
            isEnabled: $rule->is_enabled,
            minPaymentAmount: $rule->min_payment_amount === null ? null : (string) $rule->min_payment_amount,
            maxCommissionAmount: $rule->max_commission_amount === null ? null : (string) $rule->max_commission_amount,
            appliesToFeeTypes: $rule->applies_to_fee_types,
            appliesToMilestoneIds: $rule->applies_to_milestone_ids,
            rule: $rule,
            baseFallback: $baseFallback,
        );
    }

    /**
     * The same resolution with a different base — how [D-FS-9]'s fallback records itself when a
     * configured base does not apply to the scope. A copy rather than a mutation, because this object is
     * snapshotted into `rule_snapshot` and a mutable one could be snapshotted mid-change.
     */
    public function withBase(CommissionBase $base, bool $fallback): self
    {
        return new self(
            source: $this->source,
            scope: $this->scope,
            calculationType: $this->calculationType,
            rate: $this->rate,
            fixedAmount: $this->fixedAmount,
            release: $this->release,
            base: $base,
            isEnabled: $this->isEnabled,
            minPaymentAmount: $this->minPaymentAmount,
            maxCommissionAmount: $this->maxCommissionAmount,
            appliesToFeeTypes: $this->appliesToFeeTypes,
            appliesToMilestoneIds: $this->appliesToMilestoneIds,
            rule: $this->rule,
            baseFallback: $fallback,
        );
    }

    public function ruleId(): ?int
    {
        return $this->rule === null ? null : (int) $this->rule->getKey();
    }

    /**
     * Is a receipt of this size worth processing? (G11 / spine step 7.)
     */
    public function meetsMinimumPayment(string $amount): bool
    {
        return $this->minPaymentAmount === null
            || Money::compare($amount, $this->minPaymentAmount) >= 0;
    }

    /**
     * Is this a percentage rule that can actually multiply? A `percentage` type with a null rate is a
     * misconfiguration the resolver refuses rather than quietly treating as 0 %.
     */
    public function isUsable(): bool
    {
        return match ($this->calculationType) {
            CommissionCalculationType::Percentage => $this->rate !== null,
            CommissionCalculationType::Fixed => $this->fixedAmount !== null,
            // `manual` is what an adjustment posted by a human carries. It is never the outcome of rule
            // resolution, so reaching here with it means a rule row was saved with a calculation type
            // no rule can have — refused rather than guessed at.
            CommissionCalculationType::Manual => false,
        };
    }

    /**
     * The rule exactly as it stood, for `rule_snapshot` (spine §6.1.9). The whole row when there is one,
     * so a later version can never change what a past entry means.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'rule_source' => $this->source->value,
            'commission_for' => $this->scope->value,
            'calculation_type' => $this->calculationType->value,
            'rate' => $this->rate,
            'fixed_amount' => $this->fixedAmount,
            'fixed_release' => $this->release->value,
            'commission_base' => $this->base->value,
            'is_enabled' => $this->isEnabled,
            'min_payment_amount' => $this->minPaymentAmount,
            'max_commission_amount' => $this->maxCommissionAmount,
            'applies_to_fee_types' => $this->appliesToFeeTypes,
            'applies_to_milestone_ids' => $this->appliesToMilestoneIds,
            'commission_setting_id' => $this->ruleId(),
            'rule_row' => $this->rule?->attributesToArray(),
        ];
    }
}
