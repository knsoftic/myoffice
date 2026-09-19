<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ContentStatus;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\ReorderRequest;
use App\Http\Requests\Cms\StoreSuccessStoryRequest;
use App\Http\Requests\Cms\UpdateContentStatusRequest;
use App\Http\Requests\Cms\UpdateSuccessStoryRequest;
use App\Models\Cms\SuccessStory;
use App\Models\User;
use App\Services\Cms\ContentOrderService;
use App\Services\Cms\SuccessStoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Success stories — `admin.success-stories.*` (phase-04 §2.12, §6.4, §7.2, §8.6),
 * `module:success_stories`. Staff-authored, so there is no approval queue: a story is a draft until
 * `success_stories.change_status` publishes it.
 *
 * Writes: `SuccessStoryService` (store, update, changeStatus, toggleFeatured, delete) and
 * `ContentOrderService::reorder()`. The form's *Publishing* tab carries `status` and `is_featured`; a
 * change to either needs `success_stories.change_status` on top of `edit`.
 */
final class SuccessStoryController extends Controller
{
    use RespondsForContent;

    private const SORTABLE = ['student_name', 'course_name', 'status', 'sort_order', 'updated_at'];

    public function __construct(
        private readonly SuccessStoryService $stories,
        private readonly ContentOrderService $order,
    ) {}

    public function index(ContentListRequest $request): View
    {
        $this->authorize('success_stories.view_any');

        $user = $this->actor($request);
        $trashed = $this->wantsTrashed($request, $user, 'success_stories');
        $sort = $request->sortColumn(self::SORTABLE, 'sort_order');
        $direction = $request->sortDirection('asc');
        $search = $request->searchTerm();
        $course = $request->filterString('course');
        $platform = $request->filterString('platform');
        $status = $request->filterEnum('status', ContentStatus::class);
        $featured = $request->filterBool('featured');

        $stories = $this->withAvailable(SuccessStory::query(), ['photo'])
            ->when($trashed, static fn (Builder $query) => $query->onlyTrashed())
            ->when($course !== null, static fn (Builder $query) => $query->where('course_name', $course))
            ->when($platform !== null, static fn (Builder $query) => $query->where('platform', $platform))
            ->when($status instanceof ContentStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($featured !== null, static fn (Builder $query) => $query->where('is_featured', $featured))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('student_name', 'like', $this->like($search))
                    ->orWhere('headline', 'like', $this->like($search))
                    ->orWhere('company_name', 'like', $this->like($search));
            }))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $filters = $request->activeFilters();

        return view('admin.success-stories.index', [
            'stories' => $stories,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'trashed' => $trashed,
            'courseOptions' => $this->distinctValues('course_name'),
            'platformOptions' => $this->distinctValues('platform'),
            'statusOptions' => ContentStatus::options(),
            'counts' => $this->counts($user),
            'canReorder' => ! $trashed && $filters === [] && $stories->lastPage() === 1 && $user->can('success_stories.edit'),
            'can' => [
                'create' => $user->can('success_stories.create'),
                'edit' => $user->can('success_stories.edit'),
                'delete' => $user->can('success_stories.delete'),
                'changeStatus' => $user->can('success_stories.change_status'),
                'restore' => $user->can('success_stories.restore'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('success_stories.create');

        return view('admin.success-stories.create', [
            'story' => null,
            'statusOptions' => ContentStatus::options(),
            'courseOptions' => $this->distinctValues('course_name'),
            'platformOptions' => $this->distinctValues('platform'),
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'canChangeStatus' => $this->actor($request)->can('success_stories.change_status'),
        ]);
    }

    public function store(StoreSuccessStoryRequest $request): Response
    {
        $this->authorize('success_stories.create');

        $status = $request->requestedStatus();
        $featured = $request->requestedFeatured();
        $statusChanges = $status !== null && $status !== ContentStatus::Draft;
        $featuredChanges = $featured === true;

        if ($statusChanges || $featuredChanges) {
            $this->authorize('success_stories.change_status');
        }

        return $this->attempt($request, function () use ($request, $status, $statusChanges, $featuredChanges): Response {
            $story = DB::transaction(function () use ($request, $status, $statusChanges, $featuredChanges): SuccessStory {
                $story = $this->stories->store($request->successStoryPayload(), $request->uploadedImage('photo'));

                if ($statusChanges && $status !== null) {
                    $story = $this->stories->changeStatus($story, $status);
                }

                if ($featuredChanges && ! $story->is_featured) {
                    $story = $this->stories->toggleFeatured($story);
                }

                return $story;
            });

            return $this->done(
                $request,
                sprintf('%s\'s story was saved.', $story->student_name),
                redirect()->route('admin.success-stories.edit', $story),
                ['id' => (int) $story->getKey()],
            );
        }, field: 'story');
    }

    public function show(Request $request, SuccessStory $story): Response
    {
        $this->authorize('success_stories.view');
        $this->authorize('view', $story);

        return redirect()->route('admin.success-stories.edit', $story);
    }

    public function edit(Request $request, SuccessStory $story): View
    {
        $this->authorize('success_stories.edit');
        $this->authorize('update', $story);

        $this->loadAvailable($story, ['photo', 'editor']);
        $user = $this->actor($request);

        return view('admin.success-stories.edit', [
            'story' => $story,
            'statusOptions' => ContentStatus::options(),
            'courseOptions' => $this->distinctValues('course_name'),
            'platformOptions' => $this->distinctValues('platform'),
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'canChangeStatus' => $user->can('success_stories.change_status'),
            'can' => [
                'edit' => $user->can('update', $story),
                'delete' => $user->can('success_stories.delete') && $user->can('delete', $story),
                'changeStatus' => $user->can('success_stories.change_status'),
            ],
        ]);
    }

    public function update(UpdateSuccessStoryRequest $request, SuccessStory $story): Response
    {
        $this->authorize('success_stories.edit');
        $this->authorize('update', $story);

        $current = $story->status instanceof ContentStatus ? $story->status : ContentStatus::tryFrom((string) $story->status);
        $status = $request->requestedStatus();
        $featured = $request->requestedFeatured();
        $statusChanges = $status !== null && $status !== $current;
        $featuredChanges = $featured !== null && $featured !== (bool) $story->is_featured;

        if ($statusChanges || $featuredChanges) {
            $this->authorize('success_stories.change_status');
        }

        return $this->attempt($request, function () use ($request, $story, $status, $statusChanges, $featuredChanges): Response {
            $story = DB::transaction(function () use ($request, $story, $status, $statusChanges, $featuredChanges): SuccessStory {
                $story = $this->stories->update($story, $request->successStoryPayload(), $request->uploadedImage('photo'));

                if ($statusChanges && $status !== null) {
                    $story = $this->stories->changeStatus($story, $status);
                }

                if ($featuredChanges) {
                    $story = $this->stories->toggleFeatured($story);
                }

                return $story;
            });

            return $this->done($request, 'The story was saved.', redirect()->route('admin.success-stories.edit', $story));
        }, field: 'story');
    }

    public function destroy(Request $request, SuccessStory $story): Response
    {
        $this->authorize('success_stories.delete');
        $this->authorize('delete', $story);

        return $this->attempt($request, function () use ($request, $story): Response {
            $this->stories->delete($story);

            return $this->done($request, 'The story was deleted.', redirect()->route('admin.success-stories.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    public function reorder(ReorderRequest $request): Response
    {
        $this->authorize('success_stories.edit');

        return $this->attempt($request, function () use ($request): Response {
            $this->order->reorder(SuccessStory::class, $request->orderedIds());

            return $this->done($request, 'Story order saved.', null, ['ids' => $request->orderedIds()]);
        }, field: 'ids');
    }

    public function status(UpdateContentStatusRequest $request, SuccessStory $story): Response
    {
        $this->authorize('success_stories.change_status');
        $this->authorize('changeStatus', $story);

        $status = $request->contentStatus();

        return $this->attempt($request, function () use ($request, $story, $status): Response {
            $story = $this->stories->changeStatus($story, $status);

            return $this->done(
                $request,
                sprintf('The story is now %s.', mb_strtolower($status->label())),
                null,
                ['id' => (int) $story->getKey(), 'status' => $status->value],
            );
        }, field: 'status');
    }

    public function featured(Request $request, SuccessStory $story): Response
    {
        $this->authorize('success_stories.change_status');
        $this->authorize('toggleFeatured', $story);

        return $this->attempt($request, function () use ($request, $story): Response {
            $story = $this->stories->toggleFeatured($story);
            $featured = (bool) $story->is_featured;

            return $this->done(
                $request,
                $featured ? 'The story is now featured.' : 'The story is no longer featured.',
                null,
                ['id' => (int) $story->getKey(), 'is_featured' => $featured],
            );
        });
    }

    /**
     * Distinct snapshot values of one column, value => label (filter options and form suggestions).
     *
     * @return array<string, string>
     */
    private function distinctValues(string $column): array
    {
        $options = [];

        foreach (SuccessStory::query()->whereNotNull($column)->distinct()->orderBy($column)->pluck($column) as $value) {
            $options[(string) $value] = (string) $value;
        }

        return $options;
    }

    /**
     * @return array{all: int, published: int, draft: int, archived: int, featured: int, trashed: int}
     */
    private function counts(User $user): array
    {
        $byStatus = $this->countBy(SuccessStory::query(), 'status');

        return [
            'all' => array_sum($byStatus),
            'published' => $byStatus[ContentStatus::Published->value] ?? 0,
            'draft' => $byStatus[ContentStatus::Draft->value] ?? 0,
            'archived' => $byStatus[ContentStatus::Archived->value] ?? 0,
            'featured' => SuccessStory::query()->where('is_featured', true)->count(),
            'trashed' => $user->can('success_stories.restore') ? SuccessStory::query()->onlyTrashed()->count() : 0,
        ];
    }
}
