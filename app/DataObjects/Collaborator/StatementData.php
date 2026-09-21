<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Support\DateRange;
use App\Support\Money;

/**
 * A partner's statement for one period, and the proof that it balances (spine §6.5.5, §56).
 *
 * **Movement-based, so it is reproducible years later.** Every figure keys off `transaction_date` and
 * `paid_on` — business dates, never a mutable status and never `now()` — which is why re-issuing a
 * closed month gives the same document unless a back-dated receipt genuinely landed in it. When one
 * did, `posted_at` on the entry is what explains the difference.
 *
 * The identity `opening + credits − debits − payouts = closing` is asserted by the builder before this
 * object exists, cross-checked against the same expression evaluated as an opening balance one day
 * after the range. An unbalanced statement is never rendered — a partner given one has no way to tell
 * which of the two numbers to believe.
 */
final readonly class StatementData
{
    /**
     * @param  list<StatementLine>  $lines  in date order, each carrying the running balance after it
     * @param  array<string, string>  $subtotals  the five §56 groups
     */
    public function __construct(
        public int $collaboratorId,
        public DateRange $range,
        public string $opening,
        public string $credits,
        public string $debits,
        public string $payouts,
        public string $closing,
        public array $lines,
        public array $subtotals,
        public StatementFilters $filters,
        /** True when the listed rows do not add up to the totals, because a filter is narrowing them. */
        public bool $isFiltered = false,
    ) {}

    /**
     * The five §56 groups, in the order the screen prints them.
     *
     * @var list<string>
     */
    public const GROUPS = [
        'student_commissions',
        'project_commissions',
        'adjustments',
        'reversals',
        'payouts',
    ];

    public function subtotal(string $group): string
    {
        return Money::of($this->subtotals[$group] ?? Money::ZERO);
    }

    /**
     * §8.7's proof footer, as one sentence. It is printed on the screen, the PDF and the CSV, because
     * a total nobody can check is a total somebody has to trust.
     */
    public function proof(): string
    {
        return sprintf(
            'opening %s + credits %s − debits %s − payouts %s = closing %s',
            Money::format($this->opening, false),
            Money::format($this->credits, false),
            Money::format($this->debits, false),
            Money::format($this->payouts, false),
            Money::format($this->closing, false),
        );
    }

    /**
     * Does the identity hold on the figures as carried? Cheap, and asserted again by whoever renders,
     * because a presenter is allowed to be paranoid about a document going to a partner.
     */
    public function balances(): bool
    {
        return Money::compare($this->closing, Money::sub(
            Money::sub(Money::add($this->opening, $this->credits), $this->debits),
            $this->payouts,
        )) === 0;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * §8.7's empty state. An empty statement still states the balances: a blank page looks like a bug,
     * and "nothing moved" is a real and useful answer.
     */
    public function emptyMessage(): string
    {
        return Money::compare($this->opening, $this->closing) === 0
            ? sprintf('No movements in this range — opening and closing balance both %s.', Money::format($this->opening))
            : sprintf('No movements match this filter. Opening %s, closing %s.',
                Money::format($this->opening), Money::format($this->closing));
    }

    /**
     * @return list<StatementLine>
     */
    public function group(string $group): array
    {
        return array_values(array_filter($this->lines, static fn (StatementLine $l): bool => $l->group() === $group));
    }

    /**
     * The CSV / PDF payload — the totals and the rows in one structure, so no presenter recomputes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'collaborator_id' => $this->collaboratorId,
            'from' => $this->range->start()->toDateString(),
            'to' => $this->range->end()->toDateString(),
            'opening' => $this->opening,
            'credits' => $this->credits,
            'debits' => $this->debits,
            'payouts' => $this->payouts,
            'closing' => $this->closing,
            'subtotals' => $this->subtotals,
            'proof' => $this->proof(),
            'filtered' => $this->isFiltered,
            'lines' => array_map(static fn (StatementLine $l): array => $l->toArray(), $this->lines),
        ];
    }
}
