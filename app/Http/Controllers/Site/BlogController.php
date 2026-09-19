<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Http\Requests\Cms\SiteListRequest;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\Cms\BlogTag;
use App\Models\Cms\MediaAsset;
use App\Services\Cms\BlogService;
use App\Services\Cms\BlogViewCounter;
use App\Services\Cms\Data\SeoPayload;
use App\Services\Cms\MediaService;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The public blog — `site.blog.index`, `.category`, `.tag`, `.show` and the signed `site.blog.preview`
 * (phase-04 §6.7, §7.1, §8.11, §9.2), `site_module:blog_posts`.
 *
 *   · **Only `BlogPost::public()`** — published and `published_at <= now()` — renders anywhere here; a
 *     draft or scheduled post is a 404 (test 25), an inactive category or tag is a 404 (§8.11);
 *   · the lists eager-load category, tags, author and the featured image, so the page issues a bounded
 *     number of queries however many posts it shows (test 32);
 *   · the content is sanitised **again** on render (`RichText::sanitize()`, D25);
 *   · a real read of a public post goes to `BlogViewCounter::track()`, which applies the prefetch / bot /
 *     editor / session layers at once and dispatches `RecordBlogPostView` after the response for the
 *     unique-row insert (§6.7.1), so the page never waits. The preview never counts.
 */
final class BlogController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function __construct(
        private readonly BlogService $blog,
        private readonly BlogViewCounter $views,
    ) {}

    public function index(SiteListRequest $request): Response
    {
        $search = $request->searchTerm();
        $page = (int) ($request->validated('page') ?? 1);

        $featured = $search === null && $page <= 1
            ? $this->postQuery()->where('is_featured', true)->orderByDesc('published_at')->first()
            : null;

        $posts = $this->postQuery()
            ->when($featured instanceof BlogPost, static fn (Builder $query) => $query->whereKeyNot($featured->getKey()))
            ->when($search !== null, static function (Builder $query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

                $query->where(static function (Builder $inner) use ($like): void {
                    $inner->where('title', 'like', $like)->orWhere('excerpt', 'like', $like);
                });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($this->sitePerPage('website.blog_per_page', 9))
            ->withQueryString();

        return $this->listing('site.blog.index', $posts, $this->routeSeo('site.blog.index'), 'site-blog', ['title' => 'Blog', 'slug' => 'blog'], [
            'featuredPost' => $featured,
            'search' => $search,
        ]);
    }

    public function category(SiteListRequest $request, BlogCategory $blogCategory): Response
    {
        $category = BlogCategory::query()->public()->whereKey($blogCategory->getKey())->first();

        if (! $category instanceof BlogCategory) {
            return $this->notFound();
        }

        $posts = $this->postQuery()
            ->where('blog_category_id', $category->getKey())
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($this->sitePerPage('website.blog_per_page', 9))
            ->withQueryString();

        $path = route('site.blog.category', ['blogCategory' => $category->slug], false);

        return $this->listing('site.blog.category', $posts, $this->modelSeo($category, $path), 'site-blog site-blog-category', ['title' => $category->name, 'slug' => 'blog/category/'.$category->slug], [
            'category' => $category,
        ]);
    }

    public function tag(SiteListRequest $request, BlogTag $blogTag): Response
    {
        $tag = BlogTag::query()->public()->whereKey($blogTag->getKey())->first();

        if (! $tag instanceof BlogTag) {
            return $this->notFound();
        }

        $posts = $this->postQuery()
            ->whereHas('tags', static fn (Builder $query) => $query->whereKey($tag->getKey()))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($this->sitePerPage('website.blog_per_page', 9))
            ->withQueryString();

        $path = route('site.blog.tag', ['blogTag' => $tag->slug], false);

        // A tag has no SEO row of its own (D23 names seven entities, tags are not one): the blog's.
        $seo = $this->routeSeo('site.blog.index');

        return $this->listing('site.blog.tag', $posts, $seo, 'site-blog site-blog-tag', ['title' => $tag->name, 'slug' => 'blog/tag/'.$tag->slug], [
            'tag' => $tag,
            'tagPath' => $path,
        ]);
    }

    public function show(Request $request, BlogPost $blogPost): Response
    {
        $post = $this->postQuery()->whereKey($blogPost->getKey())->first();

        if (! $post instanceof BlogPost) {
            return $this->notFound();
        }

        // Layers 1-3 now, the unique-row insert in `RecordBlogPostView` after the response: the reader never
        // waits, and a counting failure never breaks the page.
        try {
            $this->views->track($post, $request);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->article($post, preview: false);
    }

    /**
     * A draft or scheduled post through a signed link from the editor (§7.1, §9.2, test 25). The route
     * carries `auth` + `active`; the policy runs here; a missing, expired or tampered signature is a 404
     * (checked first, so an id is never probeable). Never cached, never indexed, never counted.
     */
    public function preview(Request $request, BlogPost $blogPost): Response
    {
        if (! $request->hasValidSignature()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        Gate::authorize('view', $blogPost);

        $post = $this->eagerPublic(BlogPost::query()->whereKey($blogPost->getKey()), ['category', 'tags', 'author', 'featuredImage'])->first();

        if (! $post instanceof BlogPost) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $this->article($post, preview: true);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Published posts with everything a card renders.
     *
     * @return Builder<BlogPost>
     */
    private function postQuery(): Builder
    {
        return $this->eagerPublic(BlogPost::query()->public(), [
            'category',
            'author',
            'featuredImage',
            'tags' => static fn ($query) => $query->public(),
        ]);
    }

    /**
     * @param  LengthAwarePaginator<int, BlogPost>  $posts
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $extra
     */
    private function listing(string $view, LengthAwarePaginator $posts, SeoPayload $seo, string $bodyClass, array $page, array $extra): Response
    {
        return $this->contentPage($view, array_merge([
            'posts' => $posts,
            'sidebarCategories' => $this->sidebarCategories(),
            'tagCloud' => $this->tagCloud(),
        ], $extra), $seo, $bodyClass, $page);
    }

    private function article(BlogPost $post, bool $preview): Response
    {
        $path = route('site.blog.show', ['blogPost' => $post->slug], false);
        $related = $preview ? collect() : $this->blog->related($post);

        $response = $this->contentPage('site.blog.show', [
            'post' => $post,
            // Sanitised again at render — the database is not a trust boundary (D25).
            'content' => RichText::sanitize((string) $post->content),
            'related' => $related,
            'shareUrl' => url($path),
            'jsonLd' => $preview ? null : $this->jsonLd($post, url($path)),
            'isPreview' => $preview,
        ], $this->modelSeo($post, $path, $preview), 'site-blog-post site-blog-post-'.$post->slug, ['title' => $post->title, 'slug' => 'blog/'.$post->slug], $preview);

        if ($preview) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * Active categories with their count of public posts, one query (a subselect through the scope).
     *
     * @return Collection<int, BlogCategory>
     */
    private function sidebarCategories(): Collection
    {
        return BlogCategory::query()
            ->public()
            ->select('blog_categories.*')
            ->selectSub(
                BlogPost::query()->public()->selectRaw('COUNT(*)')->whereColumn('blog_posts.blog_category_id', 'blog_categories.id'),
                'public_posts_count'
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Active tags that are on at least one public post, with that count.
     *
     * @return Collection<int, BlogTag>
     */
    private function tagCloud(): Collection
    {
        return BlogTag::query()
            ->public()
            ->select('blog_tags.*')
            ->selectSub(
                BlogPost::query()->public()
                    ->selectRaw('COUNT(*)')
                    ->join('blog_post_blog_tag', 'blog_post_blog_tag.blog_post_id', '=', 'blog_posts.id')
                    ->whereColumn('blog_post_blog_tag.blog_tag_id', 'blog_tags.id'),
                'public_posts_count'
            )
            ->orderBy('name')
            ->limit(60)
            ->get()
            ->filter(static fn (BlogTag $tag): bool => (int) $tag->getAttribute('public_posts_count') > 0)
            ->values();
    }

    /**
     * The `BlogPosting` JSON-LD, built from the row (§8.11). Dates are ISO 8601 instants.
     *
     * @return array<string, mixed>
     */
    private function jsonLd(BlogPost $post, string $url): array
    {
        $image = null;

        if ($post->relationLoaded('featuredImage') && $post->featuredImage instanceof MediaAsset) {
            try {
                $image = app(MediaService::class)->url($post->featuredImage, 1200);
            } catch (Throwable) {
                $image = null;
            }
        }

        $company = (string) (setting('company.name') ?: config('app.name', ''));

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => (string) $post->title,
            'description' => $post->excerpt,
            'url' => $url,
            'mainEntityOfPage' => $url,
            'image' => $image,
            'datePublished' => $post->published_at === null ? null : CarbonImmutable::parse($post->published_at)->toIso8601String(),
            'dateModified' => $post->updated_at === null ? null : CarbonImmutable::parse($post->updated_at)->toIso8601String(),
            'author' => [
                '@type' => $post->relationLoaded('author') && $post->author !== null ? 'Person' : 'Organization',
                'name' => $post->relationLoaded('author') && $post->author !== null ? (string) $post->author->name : $company,
            ],
            'publisher' => ['@type' => 'Organization', 'name' => $company],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
