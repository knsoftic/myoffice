<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionScope;
use App\Enums\FixedCommissionRelease;
use App\Support\Money;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * What a new commission rule version says (phase-10-12 §6.3 `CommissionRuleService::createVersion()`).
 *
 * A version is never edited, so this is the *whole* of what the new row will contain — there is no
 * partial update form anywhere in the system, and a DTO with optional "leave this one alone" fields
 * would imply there is.
 *
 * `effectiveFrom` is a business date, and may be in the future: that is a scheduled rate change, which
 * is a normal thing for a business to agree in March and start in April.
 */
final readonly class RuleData
{
    /**
     * @param  list<string>|null  $appliesToFeeTypes  null = defer to `collaborator.commissionable_fee_types`
     * @param  list<int>|null  $appliesToMilestoneIds
     */
    public function __construct(
        public CommissionScope $scope,
        public CommissionCalculationType $calculationType,
        public CarbonInterface $effectiveFrom,
        public ?string $rate = null,
        public ?string $fixedAmount = null,
        public ?FixedCommissionRelease $release = null,
        public ?CommissionBase $baseOverride = null,
        public bool $isEnabled = true,
        public ?string $minPaymentAmount = null,
        public ?string $maxCommissionAmount = null,
        public ?array $appliesToFeeTypes = null,
        public ?array $appliesToMilestoneIds = null,
        public ?string $notes = null,
    ) {
        $this->assertCoherent();
    }

    /**
     * The three ways a rule can be self-contradictory, refused at construction rather than at the
     * moment somebody's receipt fails to earn.
     */
    private function assertCoherent(): void
    {
        if ($this->calculationType === CommissionCalculationType::Manual) {
            throw new InvalidArgumentException(
                'A rule version cannot have calculation type `manual`. That type belongs to an '
                .'adjustment a person posted by hand, which has no rule behind it by definition.'
            );
        }

        if ($this->calculationType === CommissionCalculationType::Percentage && $this->rate === null) {
            throw new InvalidArgumentException('A percentage rule needs a rate. A null rate is not 0 %.');
        }

        if ($this->calculationType === CommissionCalculationType::Fixed && $this->fixedAmount === null) {
            throw new InvalidArgumentException('A fixed rule needs an amount.');
        }

        if ($this->baseOverride !== null && ! $this->baseOverride->appliesTo($this->scope)) {
            throw new InvalidArgumentException(sprintf(
                'Base `%s` does not apply to %s commission. Saving it would make every payment under '
                .'this rule fall back to `paid` and log a warning — so it is refused where somebody can '
                .'still fix it.',
                $this->baseOverride->value,
                $this->scope->value,
            ));
        }

        foreach (['rate' => $this->rate, 'fixedAmount' => $this->fixedAmount,
            'minPaymentAmount' => $this->minPaymentAmount, 'maxCommissionAmount' => $this->maxCommissionAmount] as $name => $value) {
            if ($value !== null && Money::isNegative($value)) {
                throw new InvalidArgumentException(sprintf('%s cannot be negative.', $name));
            }
        }
    }

    /**
     * The columns of the row this describes, minus the versioning the service owns.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return [
            'commission_for' => $this->scope->value,
            'is_enabled' => $this->isEnabled,
            'calculation_type' => $this->calculationType->value,
            'rate' => $this->rate === null ? null : Money::round($this->rate, Money::RATE_SCALE),
            'fixed_amount' => $this->fixedAmount === null ? null : Money::of($this->fixedAmount),
            'fixed_release' => $this->release?->value,
            'base_override' => $this->baseOverride?->value,
            // Both columns are cast `array` on the model, which does the encoding. Encoding here as
            // well would store `"[\\"course_fee\\"]"` — a JSON string containing JSON — and the
            // resolver would compare a fee type against a list of one long string.
            'applies_to_fee_types' => $this->appliesToFeeTypes === null ? null : array_values($this->appliesToFeeTypes),
            'applies_to_milestone_ids' => $this->appliesToMilestoneIds === null ? null : array_values($this->appliesToMilestoneIds),
            'min_payment_amount' => $this->minPaymentAmount === null ? null : Money::of($this->minPaymentAmount),
            'max_commission_amount' => $this->maxCommissionAmount === null ? null : Money::of($this->maxCommissionAmount),
            'notes' => $this->notes === null ? null : mb_substr($this->notes, 0, 65535),
        ];
    }
}
