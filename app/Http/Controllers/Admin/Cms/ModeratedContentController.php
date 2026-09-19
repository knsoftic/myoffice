<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\ApprovalStatus;
use App\Enums\ContentSource;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\BulkModerationRequest;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\ModerationRequest;
use App\Models\User;
use App\Services\Cms\ModerationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two moderation queues of phase-04 §8.5 — client testimonials (§14) and student reviews (§91) —
 * one implementation, two subclasses.
 *
 *   · **one moderation path**: approve, reject, bulk-approve and feature all go through
 *     `ModerationService` (§6.5), which enforces the transitions, stamps `approved_by` / `approved_at`,
 *     never touches the review body, and refuses to feature anything that is not approved (422);
 *   · approve needs `{module}.approve`, reject `{module}.reject` (a reason is required), feature
 *     `{module}.change_status` — a `view_any` holder can read the queue and nothing more (test 17);
 *   · the tabs are Pending (the landing tab), Approved, Rejected, Featured, All and Trashed, each with a
 *     live count; the Trashed tab needs `{module}.restore`.
 *
 * Staff create and correct entries through `ReviewContentService` (`storeTestimonial` /
 * `storeStudentReview` and the two updates), which applies `website.testimonial_auto_approve` to staff entries only and
 * audits a changed review text with its old and new value (§10.5).
 */
abstract class ModeratedContentController extends Controller
{
    use RespondsForContent;

    public const TABS = ['pending', 'approved', 'rejected', 'featured', 'all', 'trashed'];

    public function __construct(
        protected readonly ModerationService $moderation,
    ) {}

    /** `testimonials` or `student_reviews`. */
    abstract protected function module(): string;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** `admin.testimonials` or `admin.student-reviews` (also the view folder). */
    abstract protected function routePrefix(): string;

    /** The column holding the author's name (`author_name` / `student_name`). */
    abstract protected function nameColumn(): string;

    /** "testimonial" / "student review". */
    abstract protected function label(): string;

    /** The name the index view reads the paginator under (`testimonials` / `reviews`). */
    abstract protected function paginatorName(): string;

    /**
     * Extra list filters of the subclass (type, course).
     *
     * @param  Builder<Model>  $query
     */
    abstract protected function applyExtraFilters(Builder $query, ContentListRequest $request): void;

    /**
     * Extra view data of the subclass (type options, course list).
     *
     * @return array<string, mixed>
     */
    abstract protected function extraViewData(): array;

    /*
    |--------------------------------------------------------------------------
    | Shared actions
    |--------------------------------------------------------------------------
    */

    protected function queue(ContentListRequest $request): View
    {
        $module = $this->module();
        $this->authorize($module.'.view_any');

        $user = $this->actor($request);
        $tab = $request->filterString('tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'pending';

        if ($tab === 'trashed' && ! $user->can($module.'.restore')) {
            $tab = 'pending';
        }

        // `name` sorts by the author / student name column of the subclass.
        $sort = $request->sortColumn(['name', 'rating', 'status', 'created_at', 'approved_at', 'sort_order'], 'created_at');
        $direction = $request->sortDirection('desc');

        $records = $this->withAvailable($this->filtered($request, $tab), ['approver', 'submitter', $this->photoRelation()])
            ->orderBy($sort === 'name' ? $this->nameColumn() : $sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        return view($this->routePrefix().'.index', array_merge([
            'records' => $records,
            $this->paginatorName() => $records,
            'tab' => $tab,
            'counts' => $this->tabCounts($user),
            'filters' => $request->activeFilters(),
            'sort' => $sort,
            'direction' => $direction,
            'statusOptions' => ApprovalStatus::options(),
            'sourceOptions' => ContentSource::options(),
            'maxBulk' => BulkModerationRequest::MAX_IDS,
            'can' => $this->abilities($user),
        ], $this->extraViewData()));
    }

    protected function showRecord(Request $request, Model $record): Response
    {
        $this->authorize($this->module().'.view');
        $this->authorize('view', $record);

        if ($request->expectsJson()) {
            $this->loadAvailable($record, ['approver', 'submitter']);

            // The submitter's IP is abuse-tracing data, not something the review modal needs.
            return response()->json(['record' => $record->makeHidden(['ip_address'])]);
        }

        return redirect()->route($this->routePrefix().'.edit', $record);
    }

    protected function approveRecord(ModerationRequest $request, Model $record): Response
    {
        $this->authorize($this->module().'.approve');
        $this->authorize('approve', $record);

        return $this->attempt($request, function () use ($request, $record): Response {
            $this->moderation->approve($record, $request->note());

            return $this->done($request, sprintf('The %s was approved and is now public.', $this->label()), null, ['id' => (int) $record->getKey(), 'status' => ApprovalStatus::Approved->value]);
        }, field: 'reason');
    }

    protected function rejectRecord(ModerationRequest $request, Model $record): Response
    {
        $this->authorize($this->module().'.reject');
        $this->authorize('reject', $record);

        return $this->attempt($request, function () use ($request, $record): Response {
            $this->moderation->reject($record, (string) $request->note());

            return $this->done($request, sprintf('The %s was rejected and stays off the website.', $this->label()), null, ['id' => (int) $record->getKey(), 'status' => ApprovalStatus::Rejected->value]);
        }, field: 'reason');
    }

    /**
     * "12 approved, 2 already approved" — reported honestly (§8.5, test 19).
     */
    protected function bulkApproveRecords(BulkModerationRequest $request): Response
    {
        $this->authorize($this->module().'.approve');

        $ids = $request->ids();
        $class = $this->modelClass();
        $this->authorize('bulkApprove', $class);

        $alreadyApproved = $class::query()->whereIn('id', $ids)->where('status', ApprovalStatus::Approved->value)->count();

        return $this->attempt($request, function () use ($request, $ids, $class, $alreadyApproved): Response {
            $approved = $this->moderation->bulkApprove($class, $ids);
            $skipped = max(0, count($ids) - $approved - $alreadyApproved);

            $message = sprintf('%d approved, %d already approved.', $approved, $alreadyApproved);

            if ($skipped > 0) {
                $message .= sprintf(' %d could not be approved.', $skipped);
            }

            return $this->done($request, $message, null, ['approved' => $approved, 'already_approved' => $alreadyApproved, 'skipped' => $skipped]);
        }, field: 'ids');
    }

    protected function toggleFeaturedRecord(Request $request, Model $record): Response
    {
        $this->authorize($this->module().'.change_status');
        $this->authorize('toggleFeatured', $record);

        return $this->attempt($request, function () use ($request, $record): Response {
            $this->moderation->toggleFeatured($record);
            $featured = (bool) $record->fresh()?->getAttribute('is_featured');

            return $this->done(
                $request,
                $featured ? sprintf('The %s is now featured.', $this->label()) : sprintf('The %s is no longer featured.', $this->label()),
                null,
                ['id' => (int) $record->getKey(), 'is_featured' => $featured],
            );
        }, field: 'is_featured');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * `website.testimonial_auto_approve`, for the create screen's note — read here, never in the view.
     * Only staff entries are affected; a public or panel submission is always pending (§5).
     */
    protected function autoApprove(): bool
    {
        $value = setting('website.testimonial_auto_approve', false);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The relation holding the photo asset (`authorPhoto` / `studentPhoto`).
     */
    protected function photoRelation(): string
    {
        return 'photo';
    }

    /**
     * @return array<string, bool>
     */
    protected function abilities(User $user): array
    {
        $module = $this->module();

        return [
            'create' => $user->can($module.'.create'),
            'edit' => $user->can($module.'.edit'),
            'delete' => $user->can($module.'.delete'),
            'approve' => $user->can($module.'.approve'),
            'reject' => $user->can($module.'.reject'),
            'feature' => $user->can($module.'.change_status'),
            'restore' => $user->can($module.'.restore'),
        ];
    }

    /**
     * @return Builder<Model>
     */
    private function filtered(ContentListRequest $request, string $tab): Builder
    {
        $class = $this->modelClass();
        $search = $request->searchTerm();
        $rating = $request->filterId('rating');
        $source = $request->filterEnum('source', ContentSource::class);
        $featured = $request->filterBool('featured');
        $from = $request->fromDate();
        $to = $request->toDate();
        $name = $this->nameColumn();

        $query = $class::query();
        $this->scopeTab($query, $tab);

        $query
            ->when($rating !== null, static fn (Builder $query) => $query->where('rating', $rating))
            ->when($source instanceof ContentSource, static fn (Builder $query) => $query->where('source', $source->value))
            ->when($featured !== null, static fn (Builder $query) => $query->where('is_featured', $featured))
            ->when($from !== null, static fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, static fn (Builder $query) => $query->where('created_at', '<=', $to))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search, $name): void {
                $inner->where($name, 'like', $this->like($search))
                    ->orWhere('review', 'like', $this->like($search));
            }));

        $this->applyExtraFilters($query, $request);

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function scopeTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'pending' => $query->where('status', ApprovalStatus::Pending->value),
            'approved' => $query->where('status', ApprovalStatus::Approved->value),
            'rejected' => $query->where('status', ApprovalStatus::Rejected->value),
            'featured' => $query->where('is_featured', true),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };
    }

    /**
     * Live tab counts (the Pending count is also the sidebar badge).
     *
     * @return array<string, int>
     */
    private function tabCounts(User $user): array
    {
        $class = $this->modelClass();

        $byStatus = $class::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $counts = [
            'pending' => $byStatus[ApprovalStatus::Pending->value] ?? 0,
            'approved' => $byStatus[ApprovalStatus::Approved->value] ?? 0,
            'rejected' => $byStatus[ApprovalStatus::Rejected->value] ?? 0,
            'featured' => $class::query()->where('is_featured', true)->count(),
            'all' => array_sum($byStatus),
        ];

        if ($user->can($this->module().'.restore')) {
            $counts['trashed'] = $class::query()->onlyTrashed()->count();
        }

        return $counts;
    }
}
