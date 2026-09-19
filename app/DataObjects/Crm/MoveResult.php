<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Models\Crm\Lead;

/**
 * What a successful board move answers (phase-05 §6.5 `move()`, R-2, test 15): the updated card and the
 * authoritative header figures of exactly the two affected columns, which the optimistic UI overwrites.
 */
final readonly class MoveResult
{
    /**
     * @param  array<string, array{status: string, label: string, count: int, value_sum: string, value_sum_formatted: string, with_budget: int}>  $columns  status value => summary
     */
    public function __construct(
        public Lead $lead,
        public string $fromStatus,
        public string $toStatus,
        public array $columns,
    ) {}

    /**
     * @return array{lead_id: int, from_status: string, to_status: string, columns: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'lead_id' => (int) $this->lead->getKey(),
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'columns' => $this->columns,
        ];
    }
}
