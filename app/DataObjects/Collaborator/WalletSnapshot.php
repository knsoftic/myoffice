<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Support\Money;

/**
 * A partner's balance, derived from the ledger (spine §6.5.1).
 *
 * **The only definition of a balance in this system** (INV-26). Everything that prints one — a screen,
 * a report, a notification, a dashboard widget, an export — reads one of these. A second definition
 * would be a second number, and two numbers about one partner's money is the failure this invariant
 * exists to make impossible.
 *
 * Every field is a decimal string. `identityHolds()` is the §6.5.2 closed identity, which is the first
 * thing the reconciler asserts and the line the wallet screen prints.
 */
final readonly class WalletSnapshot
{
    public function __construct(
        public string $pendingBalance,
        public string $availableBalance,
        public string $reservedBalance,
        public string $paidBalance,
        public string $lifetimeEarned,
        public string $totalStudentCommission,
        public string $totalProjectCommission,
        public string $totalAdjustments,
        public string $totalReversed,
        public string $totalPaidOut,
        public int $ledgerEntryCount,
    ) {}

    /**
     * §6.5.2: `lifetime = pending + available + reserved + paid`.
     *
     * It holds because `(pending, approved)` and `(available, paid)` partition every non-cancelled
     * entry, and `available` is defined as `payable − reserved − paid`. When it fails, the cache is not
     * merely stale — something structural is wrong, and the reconciler says `failed` rather than
     * `drift`.
     */
    public function identityHolds(): bool
    {
        return Money::compare($this->lifetimeEarned, $this->bucketSum()) === 0;
    }

    public function bucketSum(): string
    {
        return Money::sum(
            $this->pendingBalance,
            $this->availableBalance,
            $this->reservedBalance,
            $this->paidBalance,
        );
    }

    /**
     * What the partner could ask to be paid right now. Negative is legitimate — a clawback on money
     * already paid out puts a real debt here, and hiding it behind a floor of zero would mean the next
     * payout paid it out again.
     */
    public function isInDebit(): bool
    {
        return Money::isNegative($this->availableBalance);
    }

    /**
     * The cached columns, in the shape `collaborator_wallets` stores them.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return [
            'pending_balance' => $this->pendingBalance,
            'available_balance' => $this->availableBalance,
            'reserved_balance' => $this->reservedBalance,
            'paid_balance' => $this->paidBalance,
            'lifetime_earned' => $this->lifetimeEarned,
            'total_student_commission' => $this->totalStudentCommission,
            'total_project_commission' => $this->totalProjectCommission,
            'total_adjustments' => $this->totalAdjustments,
            'total_reversed' => $this->totalReversed,
            'total_paid_out' => $this->totalPaidOut,
            'ledger_entry_count' => $this->ledgerEntryCount,
        ];
    }

    /**
     * Where this snapshot differs from a stored wallet row, and by how much.
     *
     * Returns `column => [stored, derived, difference]` for every money column that disagrees — the
     * reconciler's R1, and what a drift report shows instead of "the wallet is wrong".
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public function differencesFrom(array $stored): array
    {
        $differences = [];

        foreach ($this->columns() as $column => $derived) {
            if ($column === 'ledger_entry_count') {
                continue;
            }

            $was = Money::of((string) ($stored[$column] ?? '0.00'));
            $now = Money::of((string) $derived);

            if (Money::compare($was, $now) !== 0) {
                $differences[$column] = [$was, $now, Money::sub($now, $was)];
            }
        }

        return $differences;
    }

    /**
     * The total absolute drift — one figure for "how far out is this cache", which is what
     * `collaborator_wallets.drift_amount` stores.
     *
     * @param  array<string, mixed>  $stored
     */
    public function driftFrom(array $stored): string
    {
        $total = Money::ZERO;

        foreach ($this->differencesFrom($stored) as [, , $difference]) {
            $total = Money::add($total, Money::abs($difference));
        }

        return $total;
    }
}
