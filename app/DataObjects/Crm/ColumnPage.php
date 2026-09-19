<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Enums\LeadStatus;
use App\Models\Crm\Lead;
use Illuminate\Support\Collection;

/**
 * One "Load more" page of a Kanban column (phase-05 §6.5 `column()`), same scope and ordering as the board.
 */
final readonly class ColumnPage
{
    /**
     * @param  Collection<int, Lead>  $cards
     */
    public function __construct(
        public LeadStatus $status,
        public int $page,
        public int $pageSize,
        public Collection $cards,
        public bool $hasMore,
    ) {}

    public function nextPage(): ?int
    {
        return $this->hasMore ? $this->page + 1 : null;
    }
}
