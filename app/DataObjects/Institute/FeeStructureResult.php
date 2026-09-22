<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Models\Institute\StudentFee;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What `generateStructure()` did — including when it did nothing (phase-18 §6.1.3).
 *
 * **`$created` false is the normal answer to a double-clicked wizard, not an error.** The generator is
 * duplicate-proof by INSERT: it composes `generation_key`, writes, and treats a 1062 on
 * `uq_sf_generation` as "already generated", re-reading the existing charge. Under concurrency an
 * INSERT guard is a guarantee and a SELECT guard is a hope (F-3.15, resolutions R7), so there is no
 * check-then-act anywhere in this path and the loser of a race gets the winner's charges back.
 *
 * `$proofTotal` is what the review step prints beside the admission's `net_payable`. It is carried on
 * the result rather than recomputed by the screen so the number the user approved and the number the
 * service asserted are the same number.
 */
final readonly class FeeStructureResult
{
    /**
     * @param  Collection<int, StudentFee>  $charges  every charge of this admission, new and pre-existing
     * @param  list<string>  $skippedHeads  heads that already existed and were left alone
     */
    public function __construct(
        public Collection $charges,
        public bool $created,
        public string $proofTotal,
        public string $netPayable,
        public array $skippedHeads = [],
        public int $newCount = 0,
    ) {}

    public function balances(): bool
    {
        return Money::compare($this->proofTotal, $this->netPayable) === 0;
    }

    /**
     * The sentence the toast shows — it has to distinguish "I made these" from "these already existed",
     * because a wizard that says "8 charges created" after doing nothing is how somebody generates a
     * second set by hand.
     */
    public function caption(): string
    {
        if (! $this->created) {
            return sprintf(
                'This admission already had its fee structure — %d charges totalling %s. Nothing was changed.',
                $this->charges->count(),
                Money::format($this->proofTotal),
            );
        }

        $sentence = sprintf(
            '%d charge%s raised, totalling %s.',
            $this->newCount,
            $this->newCount === 1 ? '' : 's',
            Money::format($this->proofTotal),
        );

        return $this->skippedHeads === []
            ? $sentence
            : $sentence.' '.sprintf(
                '%s already existed and %s left alone.',
                ucfirst(implode(', ', $this->skippedHeads)),
                count($this->skippedHeads) === 1 ? 'was' : 'were',
            );
    }
}
