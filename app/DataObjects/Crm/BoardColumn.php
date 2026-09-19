<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Enums\LeadStatus;
use App\Models\Crm\Lead;
use Illuminate\Support\Collection;

/**
 * One Kanban column (phase-05 §6.5, §8.2).
 *
 * `valueSum` is `SUM(budget_amount)` exactly as the database returned it (a decimal string, never a float), and
 * `withBudget` says how many of the `count` leads carry a budget, so the footer can read "12 of 19 leads have a
 * budget" and the sum is never mistaken for the whole column (test 16).
 */
final readonly class BoardColumn
{
    /**
     * @param  Collection<int, Lead>  $cards
     */
    public function __construct(
        public LeadStatus $status,
        public int $count,
        public string $valueSum,
        public string $valueSumFormatted,
        public int $withBudget,
        public Collection $cards,
        public bool $hasMore,
    ) {}

    /**
     * The header figures the optimistic UI overwrites after every move.
     *
     * @return array{status: string, label: string, count: int, value_sum: string, value_sum_formatted: string, with_budget: int}
     */
    public function summary(): array
    {
        return [
            'status' => $this->status->value,
            'label' => $this->status->label(),
            'count' => $this->count,
            'value_sum' => $this->valueSum,
            'value_sum_formatted' => $this->valueSumFormatted,
            'with_budget' => $this->withBudget,
        ];
    }
}
