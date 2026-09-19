<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

/**
 * The Kanban board's filter bar (phase-05 §6.5, §8.2).
 *
 * The same filter set as the list, applied without the status filter: the board partitions by status itself,
 * limited to `crm.lead_statuses_on_board`. `statuses` here, when given, narrows the columns further.
 */
final readonly class BoardFilters extends LeadFilters {}
