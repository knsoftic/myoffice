<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * What a discount does to a live installment plan (phase-18 §6.2, §6.3.3).
 *
 * `InstallmentPlanCalculator::redistribute()` decides; `StudentFeeService` writes. The split matters
 * because the plan wizard and the discount modal both **show** the redistribution before it happens
 * ("installment 5: 10,000.00 → 5,000.00"), and a preview produced by a second implementation is a
 * preview that can be wrong in a way nobody notices until the money is different.
 *
 * `$unconsumed` is the part of a negative delta that no live unpaid line could absorb. It is **not**
 * forced onto a paid line — money already received is evidence, not a slot. It simply leaves the charge
 * with a negative `balance_amount`, which is an advance, and the screen says so in words.
 */
final readonly class PlanRedistribution
{
    /**
     * @param  array<int, string>  $amounts  new amount per line id, for lines that changed
     * @param  list<int>  $cancel  line ids reduced to zero, to be cancelled with the reason
     * @param  string  $unconsumed  the signed remainder no live line could take ('0.00' normally)
     * @param  string|null  $newLineAmount  a positive delta with no live unpaid line left to grow
     */
    public function __construct(
        public array $amounts = [],
        public array $cancel = [],
        public string $unconsumed = '0.00',
        public ?string $newLineAmount = null,
    ) {}

    public function touchesNothing(): bool
    {
        return $this->amounts === [] && $this->cancel === [] && $this->newLineAmount === null;
    }

    /**
     * A one-line human summary for the modal's preview and for the activity log's reason context.
     */
    public function caption(): string
    {
        if ($this->touchesNothing()) {
            return 'The plan is unchanged.';
        }

        $parts = [];

        if ($this->amounts !== []) {
            $parts[] = count($this->amounts).' installment'.(count($this->amounts) === 1 ? '' : 's').' re-amounted';
        }

        if ($this->cancel !== []) {
            $parts[] = count($this->cancel).' cancelled';
        }

        if ($this->newLineAmount !== null) {
            $parts[] = 'one new installment of '.$this->newLineAmount;
        }

        return ucfirst(implode(', ', $parts)).'.';
    }
}
