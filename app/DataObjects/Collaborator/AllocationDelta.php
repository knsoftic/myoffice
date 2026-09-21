<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Support\Money;

/**
 * What one payout movement does to the wallet cache (spine §6.5.1 part B).
 *
 * **Deliberately separate from {@see LedgerDelta}.** That one is about a ledger row changing status;
 * this one is about an allocation. The distinction is not tidiness — it is where the §6.5.1 identity
 * actually splits. `payable_total` comes from the ledger and `reserved` / `paid` come from live
 * allocations joined to their payouts, so an entry moving `available -> paid` changes **nothing** on
 * the ledger side (both statuses sit in `payable_total`) while moving a real amount from one
 * allocation bucket to another. Expressing that through `LedgerDelta` would mean a delta that reports
 * no change for a movement that is plainly a change.
 *
 * `available_balance` is `payable - reserved - paid`, so every constructor below moves it as the
 * mirror of whatever it does to the other two.
 */
final readonly class AllocationDelta
{
    /**
     * @param  array<string, string>  $columns  column => signed change
     */
    private function __construct(
        public array $columns,
        public string $description,
    ) {}

    /**
     * A payout claimed an amount: it leaves `available` and waits in `reserved`.
     */
    public static function reserved(string $amount): self
    {
        return new self([
            'reserved_balance' => Money::of($amount),
            'available_balance' => Money::negate(Money::of($amount)),
        ], 'reserved for a payout');
    }

    /**
     * A payout was rejected, cancelled, or had its claim released for a reversal: the amount goes back
     * to `available`.
     */
    public static function released(string $amount): self
    {
        return new self([
            'reserved_balance' => Money::negate(Money::of($amount)),
            'available_balance' => Money::of($amount),
        ], 'released from a payout');
    }

    /**
     * The bank sent it. `available` does not move — the amount was already out of it, waiting in
     * `reserved`; what changes is which of the two non-spendable buckets holds it.
     */
    public static function settled(string $amount): self
    {
        return new self([
            'reserved_balance' => Money::negate(Money::of($amount)),
            'paid_balance' => Money::of($amount),
            'total_paid_out' => Money::of($amount),
        ], 'paid out');
    }

    /**
     * The bank returned it — the single backward money transition (§6.4.5). Settled money re-enters
     * the spendable bucket, which is why the act that causes this is gated twice and audited.
     */
    public static function returned(string $amount): self
    {
        return new self([
            'paid_balance' => Money::negate(Money::of($amount)),
            'total_paid_out' => Money::negate(Money::of($amount)),
            'available_balance' => Money::of($amount),
        ], 'returned by the bank');
    }

    /**
     * Zero changes dropped, so a movement of nothing writes nothing.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return array_filter($this->columns, static fn (string $change): bool => ! Money::isZero($change));
    }
}
