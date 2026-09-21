<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

/**
 * What one invoice line comes to (phase-13 §2.7 steps 1–9).
 *
 * Every field is a decimal string produced by `Money`. `index` is the caller's own key into the lines
 * it passed in, so a result can be matched back to the row that produced it without the calculator
 * knowing anything about models or database ids.
 */
final readonly class InvoiceLineResult
{
    public function __construct(
        public int $index,
        public string $grossAmount,
        public string $discountAmount,
        public string $netAmount,
        public string $allocatedDiscountAmount,
        public string $taxableAmount,
        public string $taxAmount,
        public string $lineTotal,
    ) {}

    /**
     * The columns `invoice_items` stores, in the shape an update expects.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'gross_amount' => $this->grossAmount,
            'discount_amount' => $this->discountAmount,
            'net_amount' => $this->netAmount,
            'allocated_discount_amount' => $this->allocatedDiscountAmount,
            'taxable_amount' => $this->taxableAmount,
            'tax_amount' => $this->taxAmount,
            'line_total' => $this->lineTotal,
        ];
    }
}
