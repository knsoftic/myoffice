<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

use App\Support\Money;
use LogicException;

/**
 * What an invoice comes to, and the proof that its parts agree (phase-13 §2.7).
 *
 * `assertBalances()` is the five identities of §2.7, and it is called by the service **before** anything
 * is written. An invoice whose header and lines disagree is not a document to be saved and fixed later:
 * somebody is going to be asked to pay it, and the first thing they will do is add up the column.
 */
final readonly class InvoiceTotals
{
    /**
     * @param  list<InvoiceLineResult>  $lines
     */
    public function __construct(
        public array $lines,
        public string $subtotalAmount,
        public string $itemDiscountAmount,
        public string $discountAmount,
        public string $taxableAmount,
        public string $taxAmount,
        public string $roundOffAmount,
        public string $totalAmount,
    ) {}

    /**
     * `item_discount_amount + discount_amount` — the generated column, computed here so a caller can
     * check it without a round trip.
     */
    public function totalDiscountAmount(): string
    {
        return Money::add($this->itemDiscountAmount, $this->discountAmount);
    }

    /**
     * The header columns `invoices` stores.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'subtotal_amount' => $this->subtotalAmount,
            'item_discount_amount' => $this->itemDiscountAmount,
            'discount_amount' => $this->discountAmount,
            'taxable_amount' => $this->taxableAmount,
            'tax_amount' => $this->taxAmount,
            'round_off_amount' => $this->roundOffAmount,
            'total_amount' => $this->totalAmount,
        ];
    }

    /**
     * The five identities of §2.7, checked together.
     *
     * @throws LogicException naming which one failed and by how much
     */
    public function assertBalances(): void
    {
        $lineTotals = Money::sum(array_map(
            static fn (InvoiceLineResult $line): string => $line->lineTotal,
            $this->lines,
        ) ?: [Money::ZERO]);

        $allocated = Money::sum(array_map(
            static fn (InvoiceLineResult $line): string => $line->allocatedDiscountAmount,
            $this->lines,
        ) ?: [Money::ZERO]);

        $taxable = Money::sum(array_map(
            static fn (InvoiceLineResult $line): string => $line->taxableAmount,
            $this->lines,
        ) ?: [Money::ZERO]);

        $fromParts = Money::add(
            Money::add(Money::sub($this->subtotalAmount, $this->totalDiscountAmount()), $this->taxAmount),
            $this->roundOffAmount,
        );

        $this->check('total = subtotal − discounts + tax + round-off', $this->totalAmount, $fromParts);
        $this->check('total = the sum of the printed lines + round-off',
            $this->totalAmount, Money::add($lineTotals, $this->roundOffAmount));
        $this->check('taxable = the sum of the taxable lines', $this->taxableAmount, $taxable);
        // The one the apportionment exists for: if the shares do not sum to the discount, somebody is
        // being charged a paisa nobody can account for.
        $this->check('the apportioned shares sum to the invoice discount', $this->discountAmount, $allocated);
    }

    private function check(string $identity, string $left, string $right): void
    {
        if (Money::compare($left, $right) === 0) {
            return;
        }

        throw new LogicException(sprintf(
            'The invoice does not balance — %s: %s against %s, out by %s. Nothing was written: an '
            .'invoice whose header disagrees with its own lines is not a document to save and fix later.',
            $identity, $left, $right, Money::sub($left, $right),
        ));
    }
}
