<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Enums\LeadStatus;

/**
 * The whole Kanban board (phase-05 §6.5 `board()`): one column per visible status in `sortOrder()`, plus the
 * board totals, all produced by exactly two data queries under the §9 visibility scope.
 */
final readonly class BoardData
{
    /**
     * @param  list<BoardColumn>  $columns
     */
    public function __construct(
        public array $columns,
        public int $totalCount,
        public string $totalValue,
        public string $totalValueFormatted,
        public int $totalWithBudget,
        public int $pageSize,
        public int $staleDays,
    ) {}

    public function column(LeadStatus $status): ?BoardColumn
    {
        foreach ($this->columns as $column) {
            if ($column->status === $status) {
                return $column;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->totalCount === 0;
    }

    /**
     * @return array<string, array{status: string, label: string, count: int, value_sum: string, value_sum_formatted: string, with_budget: int}>
     */
    public function summaries(): array
    {
        $out = [];

        foreach ($this->columns as $column) {
            $out[$column->status->value] = $column->summary();
        }

        return $out;
    }
}
