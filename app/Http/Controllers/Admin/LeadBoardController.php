<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DataObjects\Crm\BoardColumn;
use App\DataObjects\Crm\BoardFilters;
use App\Enums\InquirySource;
use App\Enums\LeadFollowUpType;
use App\Enums\LeadStatus;
use App\Http\Controllers\Admin\Crm\Concerns\RespondsForCrm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ChangeLeadStatusRequest;
use App\Http\Requests\Crm\CrmListRequest;
use App\Models\Crm\Lead;
use App\Models\User;
use App\Services\Crm\Exceptions\CrmRuleException;
use App\Services\Crm\Exceptions\IllegalLeadTransitionException;
use App\Services\Crm\Exceptions\StaleLeadStatusException;
use App\Services\Crm\LeadBoardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The Kanban board — `admin.leads.board`, `.board.column`, `.board.move` (phase-05 §6.5, §8.2, tests 12-18),
 * `module:leads`.
 *
 * **Figures come from the server, twice.** `LeadBoardService::board()` issues two queries (the grouped count / sum /
 * with-budget, and the windowed cards) through the same visibility scope, so a header never counts a lead the viewer
 * cannot open; sums are decimal strings formatted with `money()` — no float anywhere.
 *
 * **The move endpoint is compare-and-swap.** The PATCH carries `to_status` + `expected_from_status`:
 *
 *   · 200 → `{message, card_html, columns, convert_url}` — the authoritative figures of exactly the two affected
 *     columns, which the optimistic UI overwrites;
 *   · 422 → an illegal transition or missing data: `{message, errors, allowed?, columns}` (test 12);
 *   · 409 → `expected_from_status` is stale: `{message, current_status, columns}`; nothing is written (test 14).
 *
 * Every card also has a "Move to" menu posting the identical endpoint (touch and keyboard, R-3).
 */
final class LeadBoardController extends Controller
{
    use RespondsForCrm;

    public function __construct(
        private readonly LeadBoardService $board,
    ) {}

    public function index(CrmListRequest $request): View
    {
        $this->authorize('leads.view');
        $this->authorize('viewAny', Lead::class);

        $actor = $this->actor($request);
        $filters = $this->boardFilters($request);
        $data = $this->board->board($filters);
        $canMove = $actor->can('leads.change_status');

        $columns = array_map(fn (BoardColumn $column): array => [
            'status' => $column->status->value,
            'label' => $column->status->label(),
            'color' => $column->status->color(),
            'count' => $column->count,
            'value_sum' => $column->valueSum,
            'value_sum_formatted' => $column->valueSumFormatted,
            'with_budget' => $column->withBudget,
            'cards' => $column->cards,
            'has_more' => $column->hasMore,
            'next_page' => $column->hasMore ? 2 : null,
            'column_url' => route('admin.leads.board.column', ['status' => $column->status->value] + $filters->toQuery()),
        ], $data->columns);

        return view('admin.leads.board', [
            'board' => $data,
            'columns' => $columns,
            'boardIsEmpty' => $data->isEmpty(),
            'filters' => $request->activeFilters(),
            'filterQuery' => $filters->toQuery(),
            'transitions' => $this->transitions(),
            'statusLabels' => $this->statusOptions(),
            'followUpRequiredStatuses' => $this->followUpRequiredStatuses(),
            'lostReasons' => $this->lostReasons(),
            'followUpTypeOptions' => LeadFollowUpType::options(),
            'followUpDefaultAt' => $this->followUpDefaultAt(),
            'staleDays' => $data->staleDays,
            'pageSize' => $data->pageSize,
            'sourceOptions' => InquirySource::options(),
            'assigneeOptions' => $actor->can('leads.view_any') ? $this->usersHolding('leads.view') : [],
            'canMove' => $canMove,
            'canCreate' => $actor->can('leads.create'),
        ]);
    }

    /**
     * "Load more" for one column: the next page of cards as rendered card HTML, and the column's figures.
     */
    public function column(CrmListRequest $request, string $status): JsonResponse
    {
        $this->authorize('leads.view');
        $this->authorize('viewAny', Lead::class);

        $column = LeadStatus::tryFrom($status);
        $filters = $this->boardFilters($request);

        abort_unless($column instanceof LeadStatus && in_array($column, $this->board->visibleStatuses($filters), true), Response::HTTP_NOT_FOUND);

        $actor = $this->actor($request);
        $page = $this->board->column($column, $filters, max(2, $request->page()));
        $summary = $this->board->summaries([$column], $filters)[$column->value] ?? null;

        return new JsonResponse([
            'status' => $column->value,
            'html' => $page->cards->map(fn (Lead $lead): string => $this->renderCard($lead, $actor))->implode(''),
            'has_more' => $page->hasMore,
            'next_page' => $page->nextPage(),
            'column' => $summary,
        ]);
    }

    /**
     * The drag / "Move to" endpoint.
     */
    public function move(ChangeLeadStatusRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('leads.change_status');
        $this->authorize('changeStatus', $lead);

        $actor = $this->actor($request);
        $to = $request->targetStatus();
        $expected = $request->expectedFrom() ?? ($lead->status instanceof LeadStatus ? $lead->status : LeadStatus::from((string) $lead->status));

        try {
            $result = $this->board->move($lead, $to, $expected, $request->toData());
        } catch (StaleLeadStatusException $exception) {
            return new JsonResponse(array_merge(
                ['message' => $exception->getMessage()],
                $exception->context(),
                ['columns' => $this->safeSummaries([$exception->current, $exception->expected, $to])],
            ), Response::HTTP_CONFLICT);
        } catch (IllegalLeadTransitionException $exception) {
            return new JsonResponse(array_merge(
                ['message' => $exception->getMessage(), 'errors' => $exception->errors()],
                $exception->context(),
                ['columns' => $this->safeSummaries([$expected, $to])],
            ), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (CrmRuleException $exception) {
            return new JsonResponse([
                'message' => (string) (collect($exception->errors())->flatten()->first() ?? $exception->getMessage()),
                'errors' => $exception->errors(),
                'columns' => $this->safeSummaries([$expected, $to]),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $moved = $result->lead->loadMissing('assignee:id,name,email');

        return new JsonResponse(array_merge($result->toArray(), [
            'message' => sprintf('%s moved to %s.', $moved->name, $to->label()),
            'card_html' => $this->renderCard($moved, $actor),
            'convert_url' => $to === LeadStatus::Won && $actor->can('convert', $moved) && Route::has('admin.leads.convert.form')
                ? route('admin.leads.convert.form', $moved)
                : null,
        ]));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The board's filter bar (search, assignee, source, follow-up window) as the service's DTO. The board partitions
     * by status itself; a trashed view does not exist here.
     */
    private function boardFilters(CrmListRequest $request): BoardFilters
    {
        return BoardFilters::fromArray([
            'search' => $request->searchTerm(),
            'status' => array_map(static fn (LeadStatus $status): string => $status->value, $request->filterEnums('status', LeadStatus::class)),
            'source' => array_map(static fn (InquirySource $source): string => $source->value, $request->filterEnums('source', InquirySource::class)),
            'assignee' => $request->filterString('assignee') === 'all' ? null : $request->filterString('assignee'),
            'follow_up' => $request->filterString('follow_up'),
            'budget_min' => $request->filterMoney('budget_min'),
            'budget_max' => $request->filterMoney('budget_max'),
            'service_id' => $request->filterId('service_id'),
        ]);
    }

    private function renderCard(Lead $lead, User $actor): string
    {
        return view('admin.leads.partials.board-card', [
            'lead' => $lead,
            'transitions' => $this->transitions(),
            'statusLabels' => $this->statusOptions(),
            'staleDays' => $this->crmInt('stale_lead_days', 7, 1),
            'canMove' => $actor->can('leads.change_status'),
        ])->render();
    }

    /**
     * Column figures for an error payload; a refused move must still answer even if the aggregate cannot run.
     *
     * @param  list<LeadStatus>  $statuses
     * @return array<string, array<string, mixed>>
     */
    private function safeSummaries(array $statuses): array
    {
        try {
            return $this->board->summaries($statuses);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
