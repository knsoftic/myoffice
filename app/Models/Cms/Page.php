<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\PageLayout;
use App\Enums\Cms\SectionPlacement;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One custom page at its own slug (phase-03 §2.7, §101): about, privacy policy, terms, refund
 * policy, course policy and anything else the business wants to publish.
 *
 * **Snapshot-published (decision D22, INV-1):** `content` is the working draft and
 * `published_content` is the only copy an anonymous visitor may ever see. The public read path is
 * {@see self::scopeForPublic()} / {@see self::scopePublishedSnapshot()}, which does not even select
 * the draft column — so a renderer that reaches for `content` finds nothing rather than shipping an
 * unfinished edit. `has_unpublished_changes` is a STORED generated column (INV-4): it is read, never
 * written, and is deliberately absent from `$fillable`.
 *
 * `is_system` marks the four policy pages: their content is editable, the row is never deletable
 * (`PagePolicy::delete()`), and a slug change needs `pages.change_status` (§6.4).
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property PageLayout $layout
 * @property string|null $excerpt
 * @property string|null $content
 * @property string|null $published_content
 * @property string|null $content_hash
 * @property string|null $published_hash
 * @property bool $has_unpublished_changes
 * @property bool $show_banner
 * @property int|null $banner_media_id
 * @property string|null $banner_heading
 * @property string|null $banner_subheading
 * @property string|null $template
 * @property ContentStatus $status
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property string|null $unpublished_reason
 * @property bool $is_system
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Page extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /**
     * The columns the public site may read (§9). `content`, the hashes, `unpublished_reason` and the
     * blameable columns are **not** among them.
     *
     * @var list<string>
     */
    public const PUBLIC_COLUMNS = [
        'id',
        'title',
        'slug',
        'layout',
        'excerpt',
        'published_content',
        'show_banner',
        'banner_media_id',
        'banner_heading',
        'banner_subheading',
        'template',
        'status',
        'published_at',
        'sort_order',
        'updated_at',
    ];

    protected $table = 'pages';

    /**
     * `has_unpublished_changes` is a generated column and can never be assigned (INV-4).
     *
     * The four publish columns are writable because `PageService::publish()` owns them; no Form
     * Request may accept them — publishing is `pages.change_status`, not `pages.edit` ([D-W3-10]).
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'layout',
        'excerpt',
        'content',
        'published_content',
        'content_hash',
        'published_hash',
        'show_banner',
        'banner_media_id',
        'banner_heading',
        'banner_subheading',
        'template',
        'status',
        'published_at',
        'published_by',
        'unpublished_reason',
        'is_system',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout' => PageLayout::class,
            'has_unpublished_changes' => 'boolean',
            'show_banner' => 'boolean',
            'status' => ContentStatus::class,
            'published_at' => 'datetime',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function activityModule(): ?string
    {
        return 'pages';
    }

    /**
     * The body itself is versioned in `cms_revisions` (§2.14), which is the append-only history of
     * the prose. `activity_log` records the **act** — the slug change, the publish, the status flip —
     * so the two long-text columns are represented here by their hashes instead of by 40 KB of HTML.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'title',
            'slug',
            'layout',
            'excerpt',
            'content_hash',
            'published_hash',
            'show_banner',
            'banner_media_id',
            'banner_heading',
            'banner_subheading',
            'template',
            'status',
            'published_at',
            'published_by',
            'unpublished_reason',
            'is_system',
            'sort_order',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Draft / published boundary (INV-1, D22)
    |--------------------------------------------------------------------------
    */

    /**
     * The body the public site renders. Never the draft.
     */
    public function publishedBody(): ?string
    {
        return $this->published_content;
    }

    /**
     * The draft body, for the editor and for preview (§6.12) only.
     *
     * Throws when the row was loaded through the public path, where `content` is not selected: that
     * is a developer reaching for a draft on the render path, and a loud failure in a test beats a
     * silent unpublished paragraph on the home page.
     */
    public function draftBody(): ?string
    {
        if (! array_key_exists('content', $this->attributes)) {
            throw new LogicException(
                'Page::draftBody() on a row loaded through the published-snapshot path. '
                .'The public site renders published_content only (INV-1).'
            );
        }

        return $this->content;
    }

    /**
     * May an anonymous visitor see this page? (§9 — anything else is a 404, never a 403.)
     */
    public function isPublic(): bool
    {
        return $this->status instanceof ContentStatus && $this->status->isPublic();
    }

    /**
     * INV-4: derived from the hashes by the database, never set by code.
     */
    public function hasUnpublishedChanges(): bool
    {
        return (bool) $this->has_unpublished_changes;
    }

    /**
     * Does the body come from `website_sections` rather than from the rich-text column?
     */
    public function usesSections(): bool
    {
        return $this->layout instanceof PageLayout && $this->layout->usesSections();
    }

    /**
     * One of the four policy pages — content editable, row never deletable (§2.7, FT-17).
     */
    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The sections placed on this page (`placement = page`, cascadeOnDelete).
     *
     * @return HasMany<WebsiteSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(WebsiteSection::class, 'page_id')
            ->where('placement', SectionPlacement::Page->value)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function banner(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'banner_media_id');
    }

    /**
     * The one SEO store (decision D23): no SEO column lives on this table.
     *
     * @return MorphOne<SeoMeta, $this>
     */
    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    /**
     * @return MorphMany<CmsRevision, $this>
     */
    public function revisions(): MorphMany
    {
        return $this->morphMany(CmsRevision::class, 'revisionable')->latest('id');
    }

    /**
     * Menu items pointing at this page. A draft, trashed or missing target hides the item (§6.3).
     *
     * @return HasMany<MenuItem, $this>
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'page_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published->value);
    }

    /**
     * Everything an anonymous visitor may see (§9): published and not trashed. A `scheduled` page is
     * not public until `cms:publish-scheduled` flips it, which is the whole point of the case.
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->published();
    }

    /**
     * Restrict the SELECT to the snapshot columns of {@see self::PUBLIC_COLUMNS} (INV-1, D22).
     *
     * The draft column is not fetched at all, so `draftBody()` throws instead of leaking it.
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopePublishedSnapshot(Builder $query): Builder
    {
        return $query->select(self::PUBLIC_COLUMNS);
    }

    /**
     * The one query `PublicPageService::page()` should run: published, snapshot columns only,
     * resolved by slug.
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeForPublic(Builder $query, ?string $slug = null): Builder
    {
        $query->visible()->publishedSnapshot();

        return $slug === null ? $query : $query->where('slug', $slug);
    }

    /**
     * Pages due to go live — `cms:publish-scheduled` (§10.4).
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeDueForPublishing(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Scheduled->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeWithUnpublishedChanges(Builder $query): Builder
    {
        return $query->where('has_unpublished_changes', true);
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('title')->orderBy('id');
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where('title', 'like', $like)
                ->orWhere('slug', 'like', $like)
                ->orWhere('excerpt', 'like', $like);
        });
    }
}
