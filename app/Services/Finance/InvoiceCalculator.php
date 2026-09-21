<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DataObjects\Finance\InvoiceLineResult;
use App\DataObjects\Finance\InvoiceTotals;
use App\Enums\DiscountMode;
use App\Support\Money;

/**
 * The invoice money algorithm as a **pure function** (phase-13 §2.7).
 *
 * Nothing here touches the database, so the figure a draft preview shows and the figure that gets
 * stored are the same number by construction rather than by two implementations agreeing. That is the
 * same reason the commission engine keeps `CommissionCalculator` separate from the service that writes
 * the row.
 *
 * **Every operation is `Money`** (bcmath, quantised half-up at 2). No PHP `+ - * /` touches a money
 * value anywhere in this file.
 *
 * Two decisions are load-bearing and easy to get wrong:
 *
 * 1. **Each line's gross is quantised before anything else** (step 1). 1.5 × 3,333.33 becomes 5,000.00
 *    here, not 4,999.995 carried forward and rounded at the end — so the total is always the sum of the
 *    numbers actually printed on the page. A client checking the arithmetic by hand has to get the same
 *    answer, or the invoice is wrong whatever the ledger says.
 * 2. **The invoice-level discount is apportioned by cumulative target** (step 6), with the residual
 *    landing on the last line. Dividing it evenly and rounding each share independently loses or gains
 *    a paisa on most invoices; targeting the running total means the shares sum to the discount
 *    exactly. It is the same technique the commission engine uses for proportional release.
 */
final class InvoiceCalculator
{
    /**
     * Run steps 1–11 over a set of lines and return what every column should hold.
     *
     * @param  list<array<string, mixed>>  $lines  in `sort_order`; each carries quantity, unit_price,
     *                                             discount_mode, discount_rate, discount_fixed,
     *                                             is_taxable and tax_rate
     */
    public function compute(
        array $lines,
        DiscountMode $invoiceDiscountMode = DiscountMode::None,
        ?string $invoiceDiscountRate = null,
        ?string $invoiceDiscountFixed = null,
        bool $roundOff = false,
        int $roundingPrecision = 1,
    ): InvoiceTotals {
        // ---- steps 1-4: each line on its own ----------------------------------------------------
        $computed = [];
        $subtotal = Money::ZERO;
        $itemDiscount = Money::ZERO;
        $netTotal = Money::ZERO;

        foreach ($lines as $index => $line) {
            $gross = Money::round(Money::mul(
                Money::of((string) ($line['quantity'] ?? '1.0000')),
                Money::of((string) ($line['unit_price'] ?? '0.00')),
            ));

            $discount = $this->lineDiscount(
                $gross,
                DiscountMode::tryFrom((string) ($line['discount_mode'] ?? DiscountMode::None->value)) ?? DiscountMode::None,
                $line['discount_rate'] ?? null,
                $line['discount_fixed'] ?? null,
            );

            $net = Money::sub($gross, $discount);

            $computed[$index] = [
                'gross' => $gross,
                'discount' => $discount,
                'net' => $net,
                'is_taxable' => (bool) ($line['is_taxable'] ?? true),
                'tax_rate' => Money::of((string) ($line['tax_rate'] ?? '0.0000')),
            ];

            $subtotal = Money::add($subtotal, $gross);
            $itemDiscount = Money::add($itemDiscount, $discount);
            $netTotal = Money::add($netTotal, $net);
        }

        // ---- step 5: the invoice-level discount --------------------------------------------------
        $invoiceDiscount = $this->invoiceDiscount(
            $netTotal, $invoiceDiscountMode, $invoiceDiscountRate, $invoiceDiscountFixed,
        );

        // ---- step 6: apportion it by cumulative target -------------------------------------------
        $cumulativeNet = Money::ZERO;
        $previousTarget = Money::ZERO;

        foreach ($computed as $index => $line) {
            $cumulativeNet = Money::add($cumulativeNet, $line['net']);

            $target = Money::isZero($netTotal)
                ? Money::ZERO
                // `prorate` is multiply-then-divide with a single rounding, which is what makes the
                // targets monotonic and the differences between them exact.
                : Money::prorate($invoiceDiscount, $cumulativeNet, $netTotal);

            $computed[$index]['allocated'] = Money::sub($target, $previousTarget);
            $previousTarget = $target;
        }

        // ---- steps 7-9: tax, per line -------------------------------------------------------------
        $taxableTotal = Money::ZERO;
        $taxTotal = Money::ZERO;
        $results = [];

        foreach ($computed as $index => $line) {
            $afterAllocation = Money::sub($line['net'], $line['allocated']);

            // An exempt line contributes nothing taxable and carries no tax — `chk_ii_exempt` refuses
            // the row otherwise, so this is the service half of a rule the database also holds.
            $taxable = $line['is_taxable'] ? $afterAllocation : Money::ZERO;
            $tax = $line['is_taxable'] ? Money::percentage($taxable, $line['tax_rate']) : Money::ZERO;

            $results[] = new InvoiceLineResult(
                index: $index,
                grossAmount: $line['gross'],
                discountAmount: $line['discount'],
                netAmount: $line['net'],
                allocatedDiscountAmount: $line['allocated'],
                taxableAmount: $taxable,
                taxAmount: $tax,
                lineTotal: Money::add($afterAllocation, $tax),
            );

            $taxableTotal = Money::add($taxableTotal, $taxable);
            $taxTotal = Money::add($taxTotal, $tax);
        }

        // ---- step 11: the grand total, and the optional round-off ---------------------------------
        $preRound = Money::add(
            Money::sub($subtotal, Money::add($itemDiscount, $invoiceDiscount)),
            $taxTotal,
        );

        $total = $preRound;
        $roundOffAmount = Money::ZERO;

        if ($roundOff) {
            $total = Money::roundTo($preRound, $roundingPrecision);
            // Signed, and deliberately so: rounding down makes it negative, and hiding the sign would
            // mean the printed figures stop adding up.
            $roundOffAmount = Money::sub($total, $preRound);
        }

        return new InvoiceTotals(
            lines: $results,
            subtotalAmount: $subtotal,
            itemDiscountAmount: $itemDiscount,
            discountAmount: $invoiceDiscount,
            taxableAmount: $taxableTotal,
            taxAmount: $taxTotal,
            roundOffAmount: $roundOffAmount,
            totalAmount: $total,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Step 2. A fixed discount is capped at the line: a discount bigger than the thing being discounted
     * is a typo, and `chk_ii_discount_ceiling` would refuse the row anyway — better to clamp it here
     * than to turn a slip into a failed save the user cannot interpret.
     */
    private function lineDiscount(string $gross, DiscountMode $mode, mixed $rate, mixed $fixed): string
    {
        return match ($mode) {
            DiscountMode::Percentage => Money::percentage($gross, Money::of((string) ($rate ?? '0.0000'))),
            DiscountMode::Fixed => Money::min(Money::of((string) ($fixed ?? '0.00')), $gross),
            DiscountMode::None => Money::ZERO,
        };
    }

    /**
     * Step 5. On a zero invoice the discount is zero whatever was asked for: there is nothing to
     * discount, and a non-zero figure here would make `chk_inv_discount_ceiling` refuse the save.
     */
    private function invoiceDiscount(string $netTotal, DiscountMode $mode, mixed $rate, mixed $fixed): string
    {
        if (Money::compare($netTotal, Money::ZERO) <= 0) {
            return Money::ZERO;
        }

        return match ($mode) {
            DiscountMode::Percentage => Money::percentage($netTotal, Money::of((string) ($rate ?? '0.0000'))),
            DiscountMode::Fixed => Money::min(Money::of((string) ($fixed ?? '0.00')), $netTotal),
            DiscountMode::None => Money::ZERO,
        };
    }
}
