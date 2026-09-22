<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Models\Institute\StudentFee;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything a fee slip prints, assembled once (phase-18 §6.7, requirement §41).
 *
 * **`$commission` is `null` when it is withheld, never an array of zeroes**, and that distinction is
 * the whole of §6.7.1 in one property. A block of zeroes reads as "this student's referral earned
 * nobody anything", which is a factual claim and usually a false one. `null` reads as "you are not
 * being shown this", which is the truth. The Blade tests the property rather than the numbers.
 *
 * **`$printedAt` is on the document, not taken from `now()` in the template.** A receipt reprinted
 * months later carries a balance recomputed at print time, and R-6 accepts that only because the
 * document says which moment it is describing. A timestamp the view invents for itself could differ
 * from the one the figures were read at.
 */
final readonly class FeeSlipData
{
    /**
     * @param  Collection<int, \App\Models\Institute\StudentFeeInstallment>  $installments
     * @param  Collection<int, \App\Models\Institute\StudentFeePayment>  $payments
     * @param  Collection<int, \App\Models\Institute\StudentFeeDiscount>  $discounts
     * @param  array{collaborator: string, base: string, base_amount: string, rate: string, amount: string}|null  $commission
     */
    public function __construct(
        public StudentFee $charge,
        public Collection $installments,
        public Collection $payments,
        public Collection $discounts,
        public CarbonImmutable $printedAt,
        public FeeSlipOptions $options,
        public ?array $commission = null,
        public ?string $collaboratorName = null,
        public ?string $footerNote = null,
    ) {}

    /** Recomputed at print time and stamped with `$printedAt`, per §6.7.2 and R-6. */
    public function balance(): string
    {
        return (string) $this->charge->balance_amount;
    }

    public function isInAdvance(): bool
    {
        return Money::isNegative($this->balance());
    }

    /**
     * The sentence under the total. A negative balance is an advance, and saying so in words is what
     * stops a minus sign being read as a debt.
     */
    public function balanceCaption(): string
    {
        return match (true) {
            $this->isInAdvance() => Money::format(Money::abs($this->balance())).' in advance',
            Money::isPositive($this->balance()) => Money::format($this->balance()).' payable',
            default => 'Paid in full',
        };
    }

    /** §41 lists the collaborator among the slip's fields; Q9 confirms the student sees the name only. */
    public function showsCollaborator(): bool
    {
        return $this->collaboratorName !== null;
    }

    public function showsCommission(): bool
    {
        return $this->commission !== null;
    }
}
