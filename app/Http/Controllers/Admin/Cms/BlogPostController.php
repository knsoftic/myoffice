<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ContentStatus;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\ScheduleBlogPostRequest;
use App\Http\Requests\Cms\StoreBlogPostRequest;
use App\Http\Requests\Cms\UpdateBlogPostRequest;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\Cms\BlogTag;
use App\Models\User;
use App\Services\Cms\BlogService;
use App\Services\Cms\BlogViewCounter;
use App\Services\Cms\SeoService;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\SlugGenerator;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Blog posts — `admin.blog-posts.*` (phase-04 §2.16, §6.7, §7.2, §8.7, §9.1.1), `module:blog_posts`:
 * the list, the two-column editor, the scheduling calendar, the per-post stats and the signed preview link.
 *
 * **Author / editor split (§4, §9.1.1).** Every list, the calendar and the stats go through
 * `BlogPost::visibleTo()` — an author sees and counts only its own posts; `blog_posts.approve` makes the
 * holder an editor who sees all of them. Record writes run `BlogPostPolicy` (`update`, `delete`,
 * `changeStatus`), so an author gets a 403 on another author's post (test 30). Stats for a post the user
 * cannot see is a 404.
 *
 * Writes: `BlogService` (store, update, publish, schedule, unpublish, archive, delete; tags are synced by
 * store/update) and `SeoService::save()` for the SEO box (D23). The transition map is
 * `BlogService::allowedNext()`; the service refuses anything else (422).
 *
 * The editor's buttons post `intent` = `save` | `publish` | `schedule`. A save never changes the status.
 * Publish / schedule run only when `blog_posts.change_status` and `BlogPostPolicy::changeStatus()` allow
 * it; otherwise the post is saved and the toast says why it was not published — nothing is ever published
 * without the ability.
 */
final class BlogPostController extends Controller
{
    use RespondsForContent;

    public const TABS = ['all', 'published', 'scheduled', 'draft', 'archived', 'mine', 'trashed'];

    private const SORTABLE = ['title', 'status', 'published_at', 'views_count', 'updated_at'];

    public function __construct(
        private readonly BlogService $blog,
        private readonly SeoService $seo,
    ) {}

    public function index(ContentListRequest $request): View
    {
        $this->authorize('blog_posts.view_any');

        $user = $this->actor($request);
        $tab = $request->filterString('tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'all';

        if ($tab === 'trashed' && ! $user->can('blog_posts.restore')) {
            $tab = 'all';
        }

        $sort = $request->sortColumn(self::SORTABLE, 'updated_at');
        $direction = $request->sortDirection('desc');

        $posts = $this->withAvailable($this->filteredQuery($request, $user, $tab), ['category', 'tags', 'author', 'featuredImage'])
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        return view('admin.blog-posts.index', [
            'posts' => $posts,
            'tab' => $tab,
            'counts' => $this->tabCounts($user),
            'filters' => $request->activeFilters(),
            'sort' => $sort,
            'direction' => $direction,
            'categoryOptions' => BlogCategory::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'tagOptions' => BlogTag::query()->orderBy('name')->pluck('name', 'id')->all(),
            'authorOptions' => $this->authorFilterOptions($user),
            'statusOptions' => ContentStatus::options(),
            'can' => [
                'create' => $user->can('blog_posts.create'),
                'edit' => $user->can('blog_posts.edit'),
                'delete' => $user->can('blog_posts.delete'),
                'changeStatus' => $user->can('blog_posts.change_status'),
                'approve' => $user->can('blog_posts.approve'),
                'reports' => $user->can('blog_posts.view_reports'),
                'restore' => $user->can('blog_posts.restore'),
            ],
        ]);
    }

    /**
     * The month grid of §8.7. The posts of the visible grid — the month plus the leading and trailing days of
     * its weeks — scheduled and published by `published_at`, drafts by `updated_at`; the view lays out the
     * grid in the display timezone.
     */
    public function calendar(ContentListRequest $request): View
    {
        $this->authorize('blog_posts.view_any');

        $user = $this->actor($request);
        $timezone = Format::displayTimezone();
        $today = CarbonImmutable::now($timezone);
        $month = $request->filterString('month');

        try {
            $first = $month !== null ? CarbonImmutable::createFromFormat('!Y-m', $month, $timezone) : null;
        } catch (Throwable) {
            $first = null;
        }

        $first = $first instanceof CarbonImmutable ? $first->startOfMonth() : $today->startOfMonth();
        $weekStartsOn = Format::weekStartsOn();
        $startUtc = $first->startOfWeek($weekStartsOn)->startOfDay()->utc();
        $endUtc = $first->endOfMonth()->endOfWeek(($weekStartsOn + 6) % 7)->endOfDay()->utc();

        $posts = $this->withAvailable(BlogPost::query()->visibleTo($user), ['author'])
            ->where(static function (Builder $query) use ($startUtc, $endUtc): void {
                $query->where(static function (Builder $dated) use ($startUtc, $endUtc): void {
                    $dated->whereIn('status', [ContentStatus::Scheduled->value, ContentStatus::Published->value])
                        ->whereBetween('published_at', [$startUtc, $endUtc]);
                })->orWhere(static function (Builder $draft) use ($startUtc, $endUtc): void {
                    $draft->where('status', ContentStatus::Draft->value)
                        ->whereBetween('updated_at', [$startUtc, $endUtc]);
                });
            })
            ->orderBy('published_at')
            ->orderBy('updated_at')
            ->limit(1000)
            ->get();

        return view('admin.blog-posts.calendar', [
            'month' => $first->format('Y-m'),
            'posts' => $posts,
            'weekStartsOn' => $weekStartsOn,
            'statusOptions' => ContentStatus::options(),
            'canChangeStatus' => $user->can('blog_posts.change_status'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('blog_posts.create');

        $user = $this->actor($request);

        return view('admin.blog-posts.create', array_merge($this->editorData($user), [
            'post' => null,
            'seoMeta' => null,
            'seoInherited' => null,
            'seoCompleteness' => null,
            'publicUrl' => null,
            'canChangeStatus' => $user->can('blog_posts.change_status'),
            'canDelete' => false,
        ]));
    }

    public function store(StoreBlogPostRequest $request): Response
    {
        $this->authorize('blog_posts.create');

        $user = $this->actor($request);
        $intent = $request->intent();
        $mayPublish = $intent === 'save' || $user->can('blog_posts.change_status');

        return $this->attempt($request, function () use ($request, $user, $intent, $mayPublish): Response {
            $post = DB::transaction(function () use ($request, $user, $intent, $mayPublish): BlogPost {
                $payload = $request->blogPostPayload();

                // §4: a post is owned by its creator; only an editor may name another author.
                if (! array_key_exists('author_id', $payload) || $payload['author_id'] === null || ! $user->can('blog_posts.approve')) {
                    $payload['author_id'] = (int) $user->getKey();
                }

                $post = $this->blog->store($payload, $request->featuredImage(), $request->tagNames());

                if ($request->seoPayload() !== null) {
                    $this->seo->save($post, $request->seoPayload());
                }

                return $mayPublish ? $this->applyIntent($post, $intent, $request->publishedAt()) : $post;
            });

            return $this->done(
                $request,
                $this->intentMessage($post, $mayPublish ? $intent : 'refused'),
                redirect()->route('admin.blog-posts.edit', $post),
                ['id' => (int) $post->getKey()],
                $mayPublish ? 'success' : 'warning',
            );
        }, field: 'title');
    }

    public function show(Request $request, BlogPost $post): Response
    {
        $this->authorize('blog_posts.view');
        $this->authorize('view', $post);

        $user = $this->actor($request);

        if ($user->can('blog_posts.edit') && $user->can('update', $post)) {
            return redirect()->route('admin.blog-posts.edit', $post);
        }

        return redirect()->away($this->signedPreviewUrl($post)['url']);
    }

    public function edit(Request $request, BlogPost $post): View
    {
        $this->authorize('blog_posts.edit');
        $this->authorize('update', $post);

        $user = $this->actor($request);
        $this->loadAvailable($post, ['category', 'tags', 'author', 'featuredImage', 'editor']);
        $status = $this->statusOf($post);

        return view('admin.blog-posts.edit', array_merge($this->editorData($user), [
            'post' => $post,
            'seoMeta' => $this->seo->meta($post),
            'seoInherited' => $this->seo->for($post),
            'seoCompleteness' => $this->seo->completeness($post),
            'allowedNext' => $status === null ? [] : $this->statusValues($this->blog->allowedNext($status)),
            'publicUrl' => $status === ContentStatus::Published && Route::has('site.blog.show') ? route('site.blog.show', ['blogPost' => $post->slug]) : null,
            'canChangeStatus' => $user->can('blog_posts.change_status') && $user->can('changeStatus', $post),
            'canDelete' => $user->can('blog_posts.delete') && $user->can('delete', $post),
            'canViewReports' => $user->can('blog_posts.view_reports'),
        ]));
    }

    public function update(UpdateBlogPostRequest $request, BlogPost $post): Response
    {
        $this->authorize('blog_posts.edit');
        $this->authorize('update', $post);

        $user = $this->actor($request);
        $intent = $request->intent();
        $mayPublish = $intent === 'save' || ($user->can('blog_posts.change_status') && $user->can('changeStatus', $post));

        return $this->attempt($request, function () use ($request, $post, $intent, $mayPublish): Response {
            $post = DB::transaction(function () use ($request, $post, $intent, $mayPublish): BlogPost {
                $post = $this->blog->update(
                    $post,
                    $request->blogPostPayload(),
                    $request->featuredImage(),
                    $request->hasTags() ? $request->tagNames() : $this->currentTagNames($post),
                );

                if ($request->seoPayload() !== null) {
                    $this->seo->save($post, $request->seoPayload());
                }

                return $mayPublish ? $this->applyIntent($post, $intent, $request->publishedAt()) : $post;
            });

            return $this->done(
                $request,
                $this->intentMessage($post, $mayPublish ? $intent : 'refused'),
                redirect()->route('admin.blog-posts.edit', $post),
                [],
                $mayPublish ? 'success' : 'warning',
            );
        }, field: 'title');
    }

    /**
     * Soft delete: the views rows and the slug stay (§6.7 invariant 7).
     */
    public function destroy(Request $request, BlogPost $post): Response
    {
        $this->authorize('blog_posts.delete');
        $this->authorize('delete', $post);

        $title = $post->title;

        return $this->attempt($request, function () use ($request, $post, $title): Response {
            $this->blog->delete($post);

            return $this->done($request, sprintf('"%s" was moved to the trash.', $title), redirect()->route('admin.blog-posts.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    public function publish(Request $request, BlogPost $post): Response
    {
        $this->authorizeStatusChange($post, 'publish');

        return $this->attempt($request, function () use ($request, $post): Response {
            $post = $this->blog->publish($post);

            return $this->done($request, sprintf('"%s" is live.', $post->title), null, $this->statePayload($post));
        }, field: 'status');
    }

    public function schedule(ScheduleBlogPostRequest $request, BlogPost $post): Response
    {
        $this->authorizeStatusChange($post, 'schedule');

        $at = $request->publishedAt();
        abort_if($at === null, Response::HTTP_UNPROCESSABLE_ENTITY);

        return $this->attempt($request, function () use ($request, $post, $at): Response {
            $post = $this->blog->schedule($post, $at);

            return $this->done(
                $request,
                sprintf('"%s" will publish itself on %s.', $post->title, Format::dateTime($post->published_at)),
                null,
                $this->statePayload($post),
            );
        }, field: 'published_at');
    }

    public function unpublish(Request $request, BlogPost $post): Response
    {
        $this->authorizeStatusChange($post, 'unpublish');

        return $this->attempt($request, function () use ($request, $post): Response {
            $post = $this->blog->unpublish($post);

            return $this->done($request, sprintf('"%s" is back to draft and no longer public.', $post->title), null, $this->statePayload($post));
        }, field: 'status');
    }

    public function archive(Request $request, BlogPost $post): Response
    {
        $this->authorizeStatusChange($post, 'archive');

        return $this->attempt($request, function () use ($request, $post): Response {
            $post = $this->blog->archive($post);

            return $this->done($request, sprintf('"%s" was archived.', $post->title), null, $this->statePayload($post));
        }, field: 'status');
    }

    /**
     * Per-post views (§8.7 Stats): rows in range, unique visitors in range, the lifetime counter, a daily
     * line over the Phase 2 range selector (default: the last 30 days) and the top referrer hosts. Views are
     * de-duplicated per visitor per day, so these numbers are lower and truer than a hit counter.
     */
    public function stats(ContentListRequest $request, BlogPost $post, BlogViewCounter $counter): View
    {
        $this->authorize('blog_posts.view_reports');

        $user = $this->actor($request);
        $this->abortUnlessVisible(BlogPost::query()->visibleTo($user)->whereKey($post->getKey())->exists());
        $this->authorize('viewReports', $post);

        $timezone = Format::displayTimezone();
        $preset = $request->filterString('range');
        $range = $preset === null && $request->filterString('from') === null
            ? DateRange::lastDays(30, $timezone)
            : DateRange::make($preset, $request->filterString('from'), $request->filterString('to'), $timezone);

        $daily = array_map('intval', $counter->dailyTotals($post, $range));
        $inRange = static fn () => $range->applyDates(DB::table('blog_post_views')->where('blog_post_id', $post->getKey()), 'viewed_on');

        return view('admin.blog-posts.stats', [
            'post' => $post,
            'range' => $range,
            'rangePresets' => DateRange::presets(),
            'daily' => $daily,
            'totals' => [
                'views' => array_sum($daily),
                'unique_visitors' => (int) $inRange()->distinct()->count('visitor_hash'),
                'lifetime' => (int) $post->views_count,
            ],
            'referrers' => $inRange()
                ->selectRaw('referrer_host, COUNT(*) AS aggregate')
                ->groupBy('referrer_host')
                ->orderByDesc('aggregate')
                ->limit(10)
                ->get()
                ->map(static fn (object $row): array => [
                    'host' => $row->referrer_host === null || $row->referrer_host === '' ? null : (string) $row->referrer_host,
                    'views' => (int) $row->aggregate,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * A signed, expiring preview URL for a draft or scheduled post (§7.1 `site.blog.preview`). The
     * preview route re-checks the login and `BlogPostPolicy::view()` on every request.
     */
    public function previewLink(Request $request, BlogPost $post): Response
    {
        $this->authorize('blog_posts.view');
        $this->authorize('view', $post);

        $link = $this->signedPreviewUrl($post);

        return $this->done(
            $request,
            sprintf('Preview link created. It expires in %d minutes.', $link['minutes']),
            null,
            ['url' => $link['url'], 'expires_at' => $link['expires_at']],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function authorizeStatusChange(BlogPost $post, string $action): void
    {
        $this->authorize('blog_posts.change_status');
        $this->authorize('changeStatus', $post);
        $this->authorize($action, $post);
    }

    /**
     * Act on the editor button that was pressed, inside the save's transaction.
     */
    private function applyIntent(BlogPost $post, string $intent, ?CarbonImmutable $at): BlogPost
    {
        return match ($intent) {
            'publish' => $this->blog->publish($post),
            'schedule' => $at === null ? $post : $this->blog->schedule($post, $at),
            default => $post,
        };
    }

    private function intentMessage(BlogPost $post, string $intent): string
    {
        return match ($intent) {
            'publish' => sprintf('"%s" was saved and published.', $post->title),
            'schedule' => sprintf('"%s" was saved and will publish itself on %s.', $post->title, Format::dateTime($post->published_at)),
            'refused' => sprintf('"%s" was saved, but not published: publishing this post needs the permission to change its status.', $post->title),
            default => sprintf('"%s" was saved.', $post->title),
        };
    }

    /**
     * @return Builder<BlogPost>
     */
    private function filteredQuery(ContentListRequest $request, User $user, string $tab): Builder
    {
        $search = $request->searchTerm();
        $category = $request->filterId('category');
        $tag = $request->filterId('tag');
        $author = $request->filterId('author');
        $status = $request->filterEnum('status', ContentStatus::class);
        $featured = $request->filterBool('featured');
        $hasImage = $request->filterBool('has_image');
        $from = $request->fromDate();
        $to = $request->toDate();

        $query = BlogPost::query()->visibleTo($user);
        $this->scopeTab($query, $tab, $user);

        return $query
            ->when($category !== null, static fn (Builder $query) => $query->where('blog_category_id', $category))
            ->when($tag !== null, static fn (Builder $query) => $query->whereHas('tags', static fn (Builder $inner) => $inner->whereKey($tag)))
            ->when($author !== null, static fn (Builder $query) => $query->where('author_id', $author))
            ->when($status instanceof ContentStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($featured !== null, static fn (Builder $query) => $query->where('is_featured', $featured))
            ->when($hasImage === true, static fn (Builder $query) => $query->whereNotNull('featured_image_media_id'))
            ->when($hasImage === false, static fn (Builder $query) => $query->whereNull('featured_image_media_id'))
            ->when($from !== null, static fn (Builder $query) => $query->where('published_at', '>=', $from))
            ->when($to !== null, static fn (Builder $query) => $query->where('published_at', '<=', $to))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', $this->like($search))
                    ->orWhere('excerpt', 'like', $this->like($search))
                    ->orWhere('content', 'like', $this->like($search));
            }));
    }

    /**
     * @param  Builder<BlogPost>  $query
     */
    private function scopeTab(Builder $query, string $tab, User $user): void
    {
        match ($tab) {
            'published' => $query->where('status', ContentStatus::Published->value),
            'scheduled' => $query->where('status', ContentStatus::Scheduled->value),
            'draft' => $query->where('status', ContentStatus::Draft->value),
            'archived' => $query->where('status', ContentStatus::Archived->value),
            'mine' => $query->where('author_id', $user->getKey()),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };
    }

    /**
     * Tab counts through `visibleTo()`: an author never counts another author's drafts (§9.1.1).
     *
     * @return array<string, int>
     */
    private function tabCounts(User $user): array
    {
        $byStatus = $this->countBy(BlogPost::query()->visibleTo($user), 'status');

        $counts = [
            'all' => array_sum($byStatus),
            'published' => $byStatus[ContentStatus::Published->value] ?? 0,
            'scheduled' => $byStatus[ContentStatus::Scheduled->value] ?? 0,
            'draft' => $byStatus[ContentStatus::Draft->value] ?? 0,
            'archived' => $byStatus[ContentStatus::Archived->value] ?? 0,
            'mine' => BlogPost::query()->where('author_id', $user->getKey())->count(),
        ];

        if ($user->can('blog_posts.restore')) {
            $counts['trashed'] = BlogPost::query()->visibleTo($user)->onlyTrashed()->count();
        }

        return $counts;
    }

    /**
     * The author filter of the list: every author for an editor, nothing for an author (whose list is its
     * own posts already).
     *
     * @return array<int, string>
     */
    private function authorFilterOptions(User $user): array
    {
        if (! $user->can('blog_posts.approve')) {
            return [];
        }

        $ids = BlogPost::query()->withTrashed()->whereNotNull('author_id')->distinct()->pluck('author_id')->all();

        return User::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * What the create and edit screens share.
     *
     * @return array<string, mixed>
     */
    private function editorData(User $user): array
    {
        $timezone = Format::displayTimezone();

        return [
            'categoryOptions' => BlogCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'tagSuggestions' => BlogTag::query()->where('is_active', true)->orderBy('name')->limit(500)->pluck('name')->all(),
            // The author select is rendered only for an editor (`blog_posts.approve`).
            'authorOptions' => $user->can('blog_posts.approve')
                ? User::query()->active()->orderBy('name')->pluck('name', 'id')->all()
                : [],
            'statusOptions' => ContentStatus::options(),
            'reservedSlugs' => SlugGenerator::RESERVED,
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'timezone' => $timezone,
            // The schedule picker's minimum: now + 5 minutes, as a `datetime-local` value in the display zone.
            'minScheduleAt' => CarbonImmutable::now($timezone)->addMinutes(5)->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * @return list<string>
     */
    private function currentTagNames(BlogPost $post): array
    {
        return array_map('strval', $post->tags()->pluck('name')->all());
    }

    /**
     * @return array{url: string, expires_at: string, minutes: int}
     */
    private function signedPreviewUrl(BlogPost $post): array
    {
        $minutes = setting('website.preview_ttl_minutes', 120);
        $minutes = is_numeric($minutes) ? max(5, min(10_080, (int) $minutes)) : 120;
        $expires = Carbon::now()->addMinutes($minutes);

        return [
            'url' => URL::temporarySignedRoute('site.blog.preview', $expires, ['blogPost' => $post->getKey()]),
            'expires_at' => $expires->toIso8601String(),
            'minutes' => $minutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statePayload(BlogPost $post): array
    {
        return [
            'id' => (int) $post->getKey(),
            'status' => $this->statusOf($post)?->value,
            'published_at' => $post->published_at === null ? null : CarbonImmutable::parse($post->published_at)->toIso8601String(),
        ];
    }

    private function statusOf(BlogPost $post): ?ContentStatus
    {
        return $post->status instanceof ContentStatus ? $post->status : ContentStatus::tryFrom((string) $post->status);
    }

    /**
     * @param  array<int, mixed>  $statuses
     * @return list<string>
     */
    private function statusValues(array $statuses): array
    {
        return array_values(array_map(
            static fn (mixed $status): string => $status instanceof BackedEnum ? (string) $status->value : (string) $status,
            $statuses,
        ));
    }
}
