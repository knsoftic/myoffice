<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One blog post (phase-04 §2.16, requirement §15).
 *
 * **Status.** `status` casts the shared `ContentStatus` (F-5.1 — `PostStatus` does not exist). The blog's
 * transition map is `BlogService::allowedNext()`, not the enum's. For `scheduled`, `published_at` is the
 * future go-live moment; for `published` it is the first live moment and is never rewritten by a
 * re-publish or by the scheduler (§6.7 invariants 1-2, §6.7.3).
 *
 * **Public means both** `status = published` **and** `published_at <= now` (§9.2): a `scheduled` row with a
 * past `published_at` stays private until `blog:publish-scheduled` flips it, so the two sources of truth
 * can never disagree. Drafts and scheduled posts are reachable only through the signed preview route.
 *
 * **Isolation (§9.1.1).** `scopeVisibleTo()`: an editor (`blog_posts.approve`) sees every post, an
 * author only its own — the index, calendar and stats screen all go through it.
 *
 * **Counter.** `views_count` is not fillable: it is incremented atomically, only when `BlogViewCounter`
 * actually inserts a `blog_post_views` row, so it always equals that count within the retention window
 * and is never reduced by pruning.
 *
 * `content` is sanitised rich text (D25). SEO is the `seo_meta` morph (D23); the featured image a
 * `media_assets` row (D24), with `featured_image_alt` as an optional per-post override of its alt text.
 *
 * @property int $id
 * @property int|null $blog_category_id
 * @property int|null $author_id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $content
 * @property int|null $featured_image_media_id
 * @property string|null $featured_image_alt
 * @property ContentStatus $status
 * @property Carbon|null $published_at
 * @property bool $is_featured
 * @property int|null $reading_minutes
 * @property int $views_count
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class BlogPost extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use SearchesContent;
    use SoftDeletes;

    /** Tags per post (§6.7 invariant 4, §6.11) and the tag name length. */
    public const MAX_TAGS = 40;

    public const MAX_TAG_LENGTH = 40;

    /** Reading speed for `reading_minutes` (§6.7 invariant 5). */
    public const WORDS_PER_MINUTE = 200;

    /** Length of the auto-filled excerpt (§2.16). */
    public const EXCERPT_FALLBACK_LENGTH = 160;

    protected $table = 'blog_posts';

    /**
     * `views_count` is deliberately absent (atomic increment only).
     *
     * @var list<string>
     */
    protected $fillable = [
        'blog_category_id',
        'author_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image_media_id',
        'featured_image_alt',
        'status',
        'published_at',
        'is_featured',
        'reading_minutes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_featured' => false,
        'views_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blog_category_id' => 'integer',
            'author_id' => 'integer',
            'featured_image_media_id' => 'integer',
            'status' => ContentStatus::class,
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
            'reading_minutes' => 'integer',
            'views_count' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'blog_posts';
    }

    protected function activityModule(): ?string
    {
        return 'blog_posts';
    }

    public function sluggableSource(): string
    {
        return (string) $this->title;
    }

    /**
     * `blog_posts.slug` is `string(200)` (§2.16).
     */
    public function slugMaxLength(): int
    {
        return 200;
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['title', 'excerpt', 'content'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Would the public site render this post right now? (§9.2)
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status === ContentStatus::Published
            && $this->published_at !== null
            && ! $this->published_at->isFuture()
            && ! $this->trashed();
    }

    public function isScheduled(): bool
    {
        return $this->status === ContentStatus::Scheduled;
    }

    /**
     * Has this post ever been live? Drives the `slug_changed` audit and the "links will break" warning
     * (§6.1, §12.1 R2). A scheduled post whose moment has not been promoted yet has never been live.
     */
    public function hasEverBeenPublished(): bool
    {
        return $this->published_at !== null
            && ! $this->published_at->isFuture()
            && $this->status !== ContentStatus::Scheduled;
    }

    public function isAuthoredBy(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $id !== null && $this->author_id !== null && (int) $this->author_id === (int) $id;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<BlogCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    /**
     * Null renders as the company name (§2.16).
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'featured_image_media_id');
    }

    /**
     * @return BelongsToMany<BlogTag, $this, BlogPostBlogTag>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_blog_tag', 'blog_post_id', 'blog_tag_id')
            ->using(BlogPostBlogTag::class);
    }

    /**
     * @return HasMany<BlogPostView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(BlogPostView::class, 'blog_post_id');
    }

    /**
     * @return MorphOne<SeoMeta, $this>
     */
    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The only filter the public site may use (§9.2): published **and** `published_at <= now`.
     *
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ContentStatus::Published->value)
            ->whereNotNull($query->qualifyColumn('published_at'))
            ->where($query->qualifyColumn('published_at'), '<=', now());
    }

    /**
     * §9.1.1 row scoping: an editor (`blog_posts.approve`) sees every post; anyone else only the posts
     * it authored. No role name — the permission decides.
     *
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('blog_posts.approve')) {
            return $query;
        }

        return $query->where($query->qualifyColumn('author_id'), $user->getKey());
    }

    /**
     * @param  Builder<BlogPost>  $query
     * @param  ContentStatus|string|array<int, ContentStatus|string>  $status
     * @return Builder<BlogPost>
     */
    public function scopeWithStatus(Builder $query, ContentStatus|string|array $status): Builder
    {
        $values = array_map(
            static fn (ContentStatus|string $value): string => $value instanceof ContentStatus ? $value->value : $value,
            is_array($status) ? $status : [$status]
        );

        return $query->whereIn($query->qualifyColumn('status'), $values);
    }

    /**
     * Scheduled posts whose go-live moment has come — what `blog:publish-scheduled` promotes (§6.7.3).
     *
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeDueForPublishing(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ContentStatus::Scheduled->value)
            ->whereNotNull($query->qualifyColumn('published_at'))
            ->where($query->qualifyColumn('published_at'), '<=', now());
    }

    /**
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeFeatured(Builder $query, bool $featured = true): Builder
    {
        return $query->where($query->qualifyColumn('is_featured'), $featured);
    }

    /**
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeByAuthor(Builder $query, User|int $author): Builder
    {
        return $query->where($query->qualifyColumn('author_id'), $author instanceof User ? $author->getKey() : $author);
    }

    /**
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeInCategory(Builder $query, BlogCategory|int|null $category): Builder
    {
        if ($category === null) {
            return $query->whereNull($query->qualifyColumn('blog_category_id'));
        }

        return $query->where(
            $query->qualifyColumn('blog_category_id'),
            $category instanceof BlogCategory ? $category->getKey() : $category
        );
    }

    /**
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeWithTag(Builder $query, BlogTag|int $tag): Builder
    {
        $id = $tag instanceof BlogTag ? $tag->getKey() : $tag;

        return $query->whereHas('tags', function (Builder $builder) use ($id): void {
            $builder->whereKey($id);
        });
    }

    /**
     * Newest live moment first, id as the tie-break.
     *
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeLatestPublished(Builder $query): Builder
    {
        return $query->orderByDesc($query->qualifyColumn('published_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }
}
