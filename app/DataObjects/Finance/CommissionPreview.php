<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

use App\DataObjects\Collaborator\RuleResolution;
use App\Enums\CommissionSkipReason;
use App\Models\Collaborator\Collaborator;
use App\Support\Collaborator\CommissionCalculation;
use App\Support\Money;

/**
 * What a receipt *would* earn, shown before anybody commits (phase-10-12 §6.3 `dryRun()`, §8.1 step 4).
 *
 * The record-payment wizard shows this on its last step: who would be credited, under which rule
 * version, on which base, and how much — or the exact reason nothing would be earned. A cashier who
 * can see "no rule covers this date for COL-1024" before taking the money can go and fix it; one who
 * finds out from a report a month later cannot.
 *
 * **It writes nothing.** The figures come from the same pure calculator the engine uses, so the
 * preview and the receipt cannot disagree.
 */
final readonly class CommissionPreview
{
    private function __construct(
        public bool $earns,
        public ?Collaborator $collaborator = null,
        public ?RuleResolution $rule = null,
        public ?string $baseAmount = null,
        public ?string $amount = null,
        public ?string $branch = null,
        public ?CommissionSkipReason $skip = null,
        public ?string $detail = null,
        public ?string $step = null,
    ) {}

    public static function earns(
        Collaborator $collaborator,
        RuleResolution $rule,
        CommissionCalculation $calculation,
    ): self {
        return new self(
            earns: true,
            collaborator: $collaborator,
            rule: $rule,
            baseAmount: $calculation->baseAmount,
            amount: $calculation->release,
            branch: $calculation->branch,
        );
    }

    public static function skips(
        CommissionSkipReason $reason,
        string $detail,
        string $step,
        ?Collaborator $collaborator = null,
        ?RuleResolution $rule = null,
    ): self {
        return new self(
            earns: false,
            collaborator: $collaborator,
            rule: $rule,
            skip: $reason,
            detail: $detail,
            step: $step,
        );
    }

    /**
     * The sentence the wizard prints.
     */
    public function sentence(): string
    {
        if (! $this->earns) {
            return $this->detail ?? $this->skip?->label() ?? 'No commission would be earned.';
        }

        return sprintf(
            '%s would earn %s on %s of this payment.',
            trim(sprintf('%s (%s)', (string) $this->collaborator?->name, (string) $this->collaborator?->collaborator_code)),
            Money::format((string) $this->amount),
            Money::format((string) $this->baseAmount),
        );
    }
}
