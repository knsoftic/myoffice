<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\PageLayout;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Admin\Cms\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\CmsListRequest;
use App\Http\Requests\Cms\PageTemplate;
use App\Http\Requests\Cms\PublishContentRequest;
use App\Http\Requests\Cms\SchedulePageRequest;
use App\Http\Requests\Cms\StorePageRequest;
use App\Http\Requests\Cms\UnpublishContentRequest;
use App\Http\Requests\Cms\UpdatePageRequest;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Page;
use App\Models\Cms\SeoMeta;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\PageService;
use App\Services\Cms\SeoService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Custom pages — `admin.website.pages.*` (phase-03 §7.3, §8.10): list, create, edit, publish, schedule,
 * unpublish, duplicate, delete, restore, the shareable preview link and the CSV export.
 *
 * Writes go to `PageService` (create, draft, duplicate, delete, restore), `ContentPublisher` (publish,
 * schedule, unpublish — the only writer of the published columns, D22) and `SeoService` (the one SEO
 * writer, D23). `PagePolicy::delete()` refuses a system page (FT-17) and the service refuses it again.
 */
final class PageController extends Controller
{
    use RespondsForCms;
    use StreamsCsv;

    private const SORTABLE = ['title', 'slug', 'status', 'updated_at', 'sort_order'];

    public function __construct(
        private readonly PageService $pages,
        private readonly ContentPublisher $publisher,
        private readonly SeoService $seo,
    ) {}

    public function index(CmsListRequest $request): View
    {
        $this->authorize('pages.view_any');

        $trashed = $request->filterBool('trashed') === true;

        if ($trashed) {
            $this->authorize('pages.restore');
        }

        $sort = $request->sortColumn(self::SORTABLE, 'sort_order');
        $direction = $request->sortDirection('asc');

        $pages = $this->filteredQuery($request, $trashed)
            ->with(['seo'])
            ->withCount('menuItems')
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $completeness = [];

        foreach ($pages->getCollection() as $page) {
            $completeness[(int) $page->getKey()] = $page->seo instanceof SeoMeta ? $this->seo->completeness($page->seo) : null;
        }

        $user = $this->actor($request);

        return view('admin.cms.pages.index', [
            'pages' => $pages,
            'completeness' => $completeness,
            'trashed' => $trashed,
            'sort' => $sort,
            'direction' => $direction,
            'filters' => $request->activeFilters(),
            'statusOptions' => ContentStatus::options(),
            'layoutOptions' => PageLayout::options(),
            'counts' => Page::query()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status')->all(),
            'can' => [
                'create' => $user->can('pages.create'),
                'edit' => $user->can('pages.edit'),
                'publish' => $user->can('pages.change_status'),
                'delete' => $user->can('pages.delete'),
                'restore' => $user->can('pages.restore'),
                'export' => $user->can('pages.export'),
                'revisions' => $user->can('pages.view_logs'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('pages.create');

        return view('admin.cms.pages.create', [
            'page' => new Page(['layout' => PageLayout::Content->value, 'show_banner' => true, 'template' => PageTemplate::DEFAULT]),
            'layoutOptions' => PageLayout::options(),
            'templateOptions' => PageTemplate::options(),
            'reservedSlugs' => $this->pages->reservedSlugs(),
            'seoMeta' => null,
            'seoInherited' => null,
        ]);
    }

    public function store(StorePageRequest $request): Response
    {
        $this->authorize('pages.create');

        return $this->attempt($request, function () use ($request): Response {
            $page = DB::transaction(function () use ($request): Page {
                $page = $this->pages->create($request->pagePayload());

                if ($request->seoPayload() !== null) {
                    $this->seo->save($page, $request->seoPayload());
                }

                return $page;
            });

            return $this->done(
                $request,
                sprintf('"%s" was created as a draft.', $page->title),
                redirect()->route('admin.website.pages.edit', $page),
                ['id' => (int) $page->getKey()],
            );
        }, field: 'slug');
    }

    public function edit(Request $request, Page $page): View
    {
        $this->authorize('pages.view');
        $this->authorize('view', $page);

        $user = $this->actor($request);

        return view('admin.cms.pages.edit', [
            'page' => $page,
            'banner' => $page->banner_media_id === null ? null : MediaAsset::query()->find((int) $page->banner_media_id),
            'sections' => $page->usesSections() ? $page->sections()->get(['id', 'section_key', 'name', 'anchor', 'status', 'is_enabled', 'has_unpublished_changes', 'sort_order']) : collect(),
            'layoutOptions' => PageLayout::options(),
            'templateOptions' => PageTemplate::options(),
            'reservedSlugs' => $this->pages->reservedSlugs(),
            'seoMeta' => $this->seo->meta($page),
            // Each SEO field shows the value it inherits when left blank (§8.12).
            'seoInherited' => $this->seo->for($page),
            'seoCompleteness' => $this->seo->completeness($page),
            'menuItems' => $page->menuItems()->get(['id', 'menu_id', 'label', 'is_enabled']),
            'revisionCount' => CmsRevision::query()
                ->where('revisionable_type', $page->getMorphClass())
                ->where('revisionable_id', $page->getKey())
                ->count(),
            'can' => [
                'edit' => $user->can('update', $page),
                'changeSlug' => $user->can('changeSlug', $page),
                'publish' => $user->can('publish', $page),
                'delete' => $user->can('delete', $page),
                'duplicate' => $user->can('duplicate', $page),
                'revisions' => $user->can('pages.view_logs'),
                'seo' => $user->can('seo.edit') || $user->can('pages.edit'),
            ],
        ]);
    }

    public function update(UpdatePageRequest $request, Page $page): Response
    {
        $this->authorize('pages.edit');
        $this->authorize('update', $page);

        if ($request->has('slug') && $request->input('slug') !== $page->slug) {
            $this->authorize('changeSlug', $page);
        }

        if ($request->wantsPublish()) {
            $this->authorize('pages.change_status');
            $this->authorize('publish', $page);
        }

        return $this->attempt($request, function () use ($request, $page): Response {
            $page = DB::transaction(function () use ($request, $page): Page {
                $page = $this->pages->saveDraft($page, $request->pagePayload());

                if ($request->seoPayload() !== null) {
                    $this->seo->save($page, $request->seoPayload());
                }

                return $page;
            });

            if ($request->wantsPublish()) {
                $page = $this->publisher->publish($page);

                return $this->done($request, sprintf('"%s" was saved and published.', $page->title), redirect()->route('admin.website.pages.edit', $page));
            }

            return $this->done($request, 'Draft saved. Visitors still see the live version until it is published.', redirect()->route('admin.website.pages.edit', $page));
        }, field: 'slug');
    }

    public function publish(PublishContentRequest $request, Page $page): Response
    {
        $this->authorize('pages.change_status');
        $this->authorize('publish', $page);

        return $this->attempt($request, function () use ($request, $page): Response {
            $page = $this->publisher->publish($page, $request->label());
            $status = $page->status instanceof ContentStatus ? $page->status : ContentStatus::tryFrom((string) $page->status);

            return $this->done(
                $request,
                $status === ContentStatus::Scheduled
                    ? sprintf('"%s" is scheduled to publish itself at its publish date.', $page->title)
                    : sprintf('"%s" is live.', $page->title),
            );
        });
    }

    public function schedule(SchedulePageRequest $request, Page $page): Response
    {
        $this->authorize('pages.change_status');
        $this->authorize('schedule', $page);

        $at = $request->publishAt();
        abort_if($at === null, Response::HTTP_UNPROCESSABLE_ENTITY);

        return $this->attempt($request, function () use ($request, $page, $at): Response {
            $page = $this->publisher->schedule($page, Carbon::instance($at));

            return $this->done($request, sprintf('"%s" will publish itself at the scheduled time.', $page->title));
        }, field: 'publish_at');
    }

    public function unpublish(UnpublishContentRequest $request, Page $page): Response
    {
        $this->authorize('pages.change_status');
        $this->authorize('unpublish', $page);

        return $this->attempt($request, function () use ($request, $page): Response {
            $page = $this->publisher->unpublish($page, (string) $request->reason());

            return $this->done($request, sprintf('"%s" is no longer public. Menu links to it are hidden until it is published again.', $page->title));
        });
    }

    public function duplicate(Request $request, Page $page): Response
    {
        $this->authorize('pages.create');
        $this->authorize('duplicate', $page);

        return $this->attempt($request, function () use ($request, $page): Response {
            $copy = $this->pages->duplicate($page);

            return $this->done(
                $request,
                sprintf('"%s" was duplicated as a draft at /%s.', $page->title, $copy->slug),
                redirect()->route('admin.website.pages.edit', $copy),
                ['id' => (int) $copy->getKey()],
            );
        });
    }

    /**
     * Soft delete. A system page is refused (FT-17); menu items pointing at the page are disabled, not
     * deleted, and the response names them (FT-28).
     */
    public function destroy(Request $request, Page $page): Response
    {
        $this->authorize('pages.delete');
        $this->authorize('delete', $page);

        $affected = $page->menuItems()->where('is_enabled', true)->pluck('label')->all();

        return $this->attempt($request, function () use ($request, $page, $affected): Response {
            $this->pages->delete($page);

            $message = $affected === []
                ? sprintf('"%s" was moved to the trash.', $page->title)
                : sprintf('"%s" was moved to the trash. These menu items were disabled: %s.', $page->title, implode(', ', $affected));

            return $this->done($request, $message, redirect()->route('admin.website.pages.index'), ['disabled_menu_items' => $affected]);
        }, Response::HTTP_FORBIDDEN);
    }

    /**
     * Bring a trashed page back (the route binds `{page}` with `withTrashed()`).
     */
    public function restore(Request $request, Page $page): Response
    {
        $this->authorize('pages.restore');
        $this->authorize('restore', $page);

        return $this->attempt($request, function () use ($request, $page): Response {
            $page = $this->pages->restore($page);

            return $this->done(
                $request,
                sprintf('"%s" was restored as a draft.', $page->title),
                redirect()->route('admin.website.pages.edit', $page),
            );
        });
    }

    /**
     * A shareable, expiring signed preview link for a reviewer with no login (§6.12, FT-23).
     */
    public function previewLink(Request $request, Page $page): Response
    {
        $this->authorize('pages.view');
        $this->authorize('preview', $page);

        $minutes = setting('website.preview_ttl_minutes', 120);
        $minutes = is_numeric($minutes) ? max(5, min(10_080, (int) $minutes)) : 120;
        $expires = Carbon::now()->addMinutes($minutes);

        $url = URL::temporarySignedRoute('site.preview.page', $expires, ['page' => $page->getKey()]);

        return $this->done(
            $request,
            sprintf('Preview link created. It expires in %d minutes.', $minutes),
            null,
            ['url' => $url, 'expires_at' => $expires->toIso8601String()],
        );
    }

    public function export(CmsListRequest $request): StreamedResponse
    {
        $this->authorize('pages.export');
        $this->authorize('export', Page::class);

        $trashed = $request->filterBool('trashed') === true;

        if ($trashed) {
            $this->authorize('pages.restore');
        }

        $rows = function () use ($request, $trashed): \Generator {
            foreach ($this->filteredQuery($request, $trashed)->orderBy('sort_order')->orderBy('title')->cursor() as $page) {
                yield [
                    $page->getKey(),
                    $page->title,
                    '/'.$page->slug,
                    $page->layout,
                    $page->status,
                    (bool) $page->has_unpublished_changes,
                    (bool) $page->is_system,
                    $page->published_at,
                    $page->updated_at,
                    $page->trashed() ? 'yes' : 'no',
                ];
            }
        };

        return $this->csv(
            'pages-'.Carbon::now()->format('Y-m-d').'.csv',
            ['ID', 'Title', 'Address', 'Layout', 'Status', 'Unpublished changes', 'System page', 'Published at (UTC)', 'Updated at (UTC)', 'In trash'],
            $rows(),
        );
    }

    /**
     * The list filters of §8.10, shared by the index and the export.
     *
     * @return Builder<Page>
     */
    private function filteredQuery(CmsListRequest $request, bool $trashed): Builder
    {
        $status = $request->filterEnum('status', ContentStatus::class);
        $layout = $request->filterEnum('layout', PageLayout::class);
        $system = $request->filterString('system');
        $unpublished = $request->filterBool('unpublished');
        $missingSeo = $request->filterBool('missing_seo');
        $search = $request->searchTerm();

        return Page::query()
            ->when($trashed, static fn (Builder $query) => $query->onlyTrashed())
            ->when($status instanceof ContentStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($layout instanceof PageLayout, static fn (Builder $query) => $query->where('layout', $layout->value))
            ->when($system !== null, static fn (Builder $query) => $query->where('is_system', $system === 'system'))
            ->when($unpublished !== null, static fn (Builder $query) => $query->where('has_unpublished_changes', $unpublished))
            ->when($missingSeo === true, static fn (Builder $query) => $query->where(static function (Builder $inner): void {
                $inner->whereDoesntHave('seo')
                    ->orWhereHas('seo', static fn (Builder $seo) => $seo->whereNull('title')->orWhereNull('meta_description'));
            }))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', $this->like($search))
                    ->orWhere('slug', 'like', $this->like($search))
                    ->orWhere('excerpt', 'like', $this->like($search))
                    ->orWhere('content', 'like', $this->like($search));
            }));
    }
}
