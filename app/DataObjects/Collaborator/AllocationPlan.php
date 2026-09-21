<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Support\Money;

/**
 * The FIFO preview the payout wizard shows at step 2 (spine §6.4.2, phase-10-12 §6.3 `plan()`).
 *
 * **It writes nothing**, and it names the exact entries a payout would consume rather than just a
 * total. That is the whole of [D-FS-11]: a running balance can answer "we paid him 20,000" but not
 * "we paid him exactly these commissions", which the statement, a clawback and §120.9 all need.
 *
 * `shortfall` is non-zero when the request is larger than what is actually allocatable. The service
 * refuses in that case rather than paying less than was asked for — a payout that silently shrank is
 * the kind of thing nobody notices until the partner does.
 */
final readonly class AllocationPlan
{
    /**
     * @param  list<array{entry_id: int, reference: string, transaction_date: string, available: string, slice: string}>  $slices
     */
    public function __construct(
        public array $slices,
        public string $requested,
        public string $allocatable,
        public string $shortfall,
    ) {}

    public function isSatisfiable(): bool
    {
        return Money::isZero($this->shortfall);
    }

    public function entryCount(): int
    {
        return count($this->slices);
    }

    /**
     * @return list<int>
     */
    public function entryIds(): array
    {
        return array_map(static fn (array $slice): int => $slice['entry_id'], $this->slices);
    }
}
