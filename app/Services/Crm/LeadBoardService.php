<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\DataObjects\Crm\BoardColumn;
use App\DataObjects\Crm\BoardData;
use App\DataObjects\Crm\BoardFilters;
use App\DataObjects\Crm\ColumnPage;
use App\DataObjects\Crm\MoveResult;
use App\DataObjects\Crm\StatusChangeData;
use App\Enums\LeadStatus;
use App\Models\Crm\Lead;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Kanban board's data (phase-05 §6.5, §8.2, tests 15-17).
 *
 * **Two data queries per render, whatever the number of columns or cards:**
 *   1. `SELECT status, COUNT(*), SUM(budget_amount), COUNT(budget_amount) … GROUP BY status`;
 *   2. one windowed card query — `ROW_NUMBER() OVER (PARTITION BY status ORDER BY …)` — returning at most
 *      `crm.kanban_page_size + 1` cards per column (the extra row only says "there is more"), with the assignee's
 *      name and avatar joined in rather than eager-loaded.
 * Both start from `Lead::query()`, so both carry the identical §9 `LeadVisibilityScope`: a column's count and sum
 * can never include a lead the viewer cannot open (test 8).
 *
 * **No float anywhere.** `SUM()` comes back as the database's decimal string and is passed through untouched;
 * totals are `Money::sum()`; the formatted figure is `Money::format()` (test 16). `with_budget` travels with each
 * column so "12 of 19 leads have a budget" is always stated beside the sum.
 *
 * Card order inside a column follows `idx_leads_board(status, follow_up_at, id)`: soonest follow-up first, leads
 * with no follow-up last, then by id.
 */
final class LeadBoardService
{
    use InteractsWithCrm;

    public function __construct(
        private readonly LeadService $leads,
    ) {}

    public function board(BoardFilters $filters): BoardData
    {
        $statuses = $this->visibleStatuses($filters);
        $pageSize = $this->pageSize();

        $summaries = $this->aggregate($statuses, $filters);
        $cards = $this->cards($statuses, $filters, $pageSize);

        $columns = [];
        $totals = [];
        $totalCount = 0;
        $totalWithBudget = 0;

        foreach ($statuses as $status) {
            $summary = $summaries[$status->value];
            $statusCards = $cards->get($status->value, new Collection);
            $hasMore = $statusCards->count() > $pageSize;

            $columns[] = new BoardColumn(
                status: $status,
                count: $summary['count'],
                valueSum: $summary['value_sum'],
                valueSumFormatted: $summary['value_sum_formatted'],
                withBudget: $summary['with_budget'],
                cards: $statusCards->take($pageSize)->values(),
                hasMore: $hasMore,
            );

            $totals[] = $summary['value_sum'];
            $totalCount += $summary['count'];
            $totalWithBudget += $summary['with_budget'];
        }

        $totalValue = Money::sum($totals);

        return new BoardData(
            columns: $columns,
            totalCount: $totalCount,
            totalValue: $totalValue,
            totalValueFormatted: Money::format($totalValue),
            totalWithBudget: $totalWithBudget,
            pageSize: $pageSize,
            staleDays: $this->crmInt('stale_lead_days', 7, 1),
        );
    }

    /**
     * "Load more" for one column: page 1 is what `board()` already showed.
     */
    public function column(LeadStatus $status, BoardFilters $filters, int $page): ColumnPage
    {
        $page = max(1, $page);
        $pageSize = $this->pageSize();

        $rows = $this->withAssignee(
            $this->scoped($filters)
                ->where('leads.status', $status->value)
                ->select('leads.*')
        )
            ->orderByRaw('leads.follow_up_at IS NULL')
            ->orderBy('leads.follow_up_at')
            ->orderBy('leads.id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize + 1)
            ->get();

        return new ColumnPage(
            status: $status,
            page: $page,
            pageSize: $pageSize,
            cards: $rows->take($pageSize)->values(),
            hasMore: $rows->count() > $pageSize,
        );
    }

    /**
     * Move a card: delegates to `LeadService::changeStatus()` (which enforces §2.11, the CAS and every rule) and
     * answers with the updated card plus the refreshed figures of exactly the two affected columns.
     */
    public function move(Lead $lead, LeadStatus $to, LeadStatus $expectedFrom, StatusChangeData $d): MoveResult
    {
        $data = new StatusChangeData(
            expectedFrom: $expectedFrom,
            reason: $d->reason,
            lostReason: $d->lostReason,
            followUp: $d->followUp,
        );

        $moved = $this->leads->changeStatus($lead, $to, $data);

        return new MoveResult(
            lead: $moved,
            fromStatus: $expectedFrom->value,
            toStatus: $to->value,
            columns: $this->summaries([$expectedFrom, $to]),
        );
    }

    /**
     * The authoritative header figures for some columns — also what a 409 / 422 answer carries, so the optimistic UI
     * re-renders both headers from the server after a refused move.
     *
     * @param  list<LeadStatus>  $statuses
     * @return array<string, array{status: string, label: string, count: int, value_sum: string, value_sum_formatted: string, with_budget: int}>
     */
    public function summaries(array $statuses, ?BoardFilters $filters = null): array
    {
        $unique = [];

        foreach ($statuses as $status) {
            $unique[$status->value] = $status;
        }

        return $this->aggregate(array_values($unique), $filters ?? new BoardFilters);
    }

    /**
     * The statuses shown as columns, in `sortOrder()`: `crm.lead_statuses_on_board`, narrowed by a status filter.
     *
     * @return list<LeadStatus>
     */
    public function visibleStatuses(?BoardFilters $filters = null): array
    {
        $configured = [];

        foreach ($this->crmList('lead_statuses_on_board', array_map(static fn (LeadStatus $s): string => $s->value, LeadStatus::cases())) as $value) {
            $status = LeadStatus::tryFrom($value);

            if ($status instanceof LeadStatus) {
                $configured[$status->value] = $status;
            }
        }

        if ($configured === []) {
            foreach (LeadStatus::cases() as $status) {
                $configured[$status->value] = $status;
            }
        }

        if ($filters !== null && $filters->statuses !== []) {
            $wanted = array_map(static fn (LeadStatus $s): string => $s->value, $filters->statuses);
            $configured = array_filter($configured, static fn (LeadStatus $s): bool => in_array($s->value, $wanted, true));
        }

        $statuses = array_values($configured);

        usort($statuses, static fn (LeadStatus $a, LeadStatus $b): int => $a->sortOrder() <=> $b->sortOrder());

        return $statuses;
    }

    /**
     * Query 1: counts, sums and budget counts per status, zero-filled for empty columns.
     *
     * @param  list<LeadStatus>  $statuses
     * @return array<string, array{status: string, label: string, count: int, value_sum: string, value_sum_formatted: string, with_budget: int}>
     */
    private function aggregate(array $statuses, BoardFilters $filters): array
    {
        $out = [];

        foreach ($statuses as $status) {
            $out[$status->value] = $this->summary($status, 0, null, 0);
        }

        if ($statuses === []) {
            return $out;
        }

        $rows = $this->scoped($filters)
            ->whereIn('leads.status', array_map(static fn (LeadStatus $s): string => $s->value, $statuses))
            ->toBase()
            ->selectRaw('leads.status AS status, COUNT(*) AS c, SUM(leads.budget_amount) AS v, COUNT(leads.budget_amount) AS with_budget')
            ->groupBy('leads.status')
            ->get();

        foreach ($rows as $row) {
            $status = LeadStatus::tryFrom((string) $row->status);

            if (! $status instanceof LeadStatus || ! array_key_exists($status->value, $out)) {
                continue;
            }

            $out[$status->value] = $this->summary($status, (int) $row->c, $row->v === null ? null : (string) $row->v, (int) $row->with_budget);
        }

        return $out;
    }

    /**
     * Query 2: the first page (+1) of every column in one windowed statement.
     *
     * @param  list<LeadStatus>  $statuses
     * @return Collection<string, Collection<int, Lead>>
     */
    private function cards(array $statuses, BoardFilters $filters, int $pageSize): Collection
    {
        if ($statuses === []) {
            return new Collection;
        }

        $inner = $this->scoped($filters)
            ->whereIn('leads.status', array_map(static fn (LeadStatus $s): string => $s->value, $statuses))
            ->select('leads.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY leads.status ORDER BY leads.follow_up_at IS NULL, leads.follow_up_at, leads.id) AS board_rank');

        $query = Lead::query()
            ->withoutGlobalScopes()
            ->fromSub($inner, 'leads')
            ->where('leads.board_rank', '<=', $pageSize + 1)
            ->select('leads.*');

        return $this->withAssignee($query)
            ->orderBy('leads.status')
            ->orderBy('leads.board_rank')
            ->get()
            ->groupBy(static function (Lead $lead): string {
                $status = $lead->getAttribute('status');

                return $status instanceof LeadStatus ? $status->value : (string) $status;
            });
    }

    /**
     * `Lead::query()` — the §9 scope included — narrowed by the filter bar (without its status filter, which the
     * board applies per column).
     *
     * @return Builder<Lead>
     */
    private function scoped(BoardFilters $filters): Builder
    {
        return $filters->apply(Lead::query(), $this->actorId(), withStatuses: false);
    }

    /**
     * Join the assignee's display fields instead of eager loading them (one query, not two).
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function withAssignee(Builder $query): Builder
    {
        return $query
            ->leftJoin('users as lead_assignee', 'lead_assignee.id', '=', 'leads.assigned_to')
            ->addSelect([
                'lead_assignee.name as assignee_name',
                'lead_assignee.avatar_path as assignee_avatar_path',
            ]);
    }

    /**
     * @return array{status: string, label: string, count: int, value_sum: string, value_sum_formatted: string, with_budget: int}
     */
    private function summary(LeadStatus $status, int $count, ?string $sum, int $withBudget): array
    {
        $value = $sum === null ? Money::ZERO : Money::of($sum);

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'count' => $count,
            'value_sum' => $value,
            'value_sum_formatted' => Money::format($value),
            'with_budget' => $withBudget,
        ];
    }

    private function pageSize(): int
    {
        return $this->crmInt('kanban_page_size', 25, 5, 100);
    }
}
