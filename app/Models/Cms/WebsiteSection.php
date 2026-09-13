<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\Concerns\PublishesSnapshots;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use App\Support\Cms\SectionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One placed section instance (phase-03 §2.2): a hero on the home page, the global header, an about
 * block on a `sections` page.
 *
 * **Snapshot-published (decision D22, INV-1).** `content` is the working draft; `published_content`
 * is the snapshot the public site reads — the section's own fields plus its enabled items, media URLs
 * and srcsets, the resolved CTA and the resolved menu tree at publish time. The public read is
 * {@see self::scopeForPublic()}, which selects {@see self::PUBLIC_COLUMNS} only, so `content` is never
 * fetched on the render path. `has_unpublished_changes` is a STORED generated column (INV-4): read,
 * never written, never fillable.
 *
 * `section_key` is a {@see SectionRegistry} key and is **never cast to an enum** ([D-W3-9]): later
 * phases add types without a migration. A key the registry does not know is an *orphan* (INV-2) — it
 * never renders and never throws; {@see self::isOrphaned()} is the guard every helper here respects.
 *
 * Every write belongs to `SectionService` / `ContentPublisher`, which persist through the query builder
 * and audit through `CmsAuditor`. This model is the read, binding and authorization surface.
 *
 * @property int $id
 * @property string $section_key
 * @property SectionPlacement $placement
 * @property int|null $page_id
 * @property string|null $instance_key
 * @property string|null $name
 * @property string|null $anchor
 * @property int|null $cta_block_id
 * @property int|null $menu_id
 * @property array<string, mixed>|null $content
 * @property array<string, mixed>|null $published_content
 * @property string|null $content_hash
 * @property string|null $published_hash
 * @property bool $has_unpublished_changes
 * @property bool $is_enabled
 * @property ContentStatus $status
 * @property int $sort_order
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property string|null $unpublished_reason
 * @property Carbon|null $draft_updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class WebsiteSection extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use PublishesSnapshots;
    use SoftDeletes;

    /**
     * The columns the public site may read (§6.9, §9). `content`, both hashes, `name`,
     * `unpublished_reason` and the blameable columns are **not** among them. The two reference ids stay
     * so an eager-loaded `hasMany` from a CTA block or menu can still match its rows.
     *
     * @var list<string>
     */
    public const PUBLIC_COLUMNS = [
        'id',
        'section_key',
        'placement',
        'page_id',
        'anchor',
        'cta_block_id',
        'menu_id',
        'published_content',
        'is_enabled',
        'status',
        'sort_order',
        'published_at',
        'updated_at',
    ];

    protected $table = 'website_sections';

    /**
     * `has_unpublished_changes` is generated and never assignable (INV-4).
     *
     * The publish columns stay writable for `ContentPublisher`; no Form Request may accept them —
     * publishing is `website_sections.change_status`, not `website_sections.edit` ([D-W3-10]).
     *
     * @var list<string>
     */
    protected $fillable = [
        'section_key',
        'placement',
        'page_id',
        'instance_key',
        'name',
        'anchor',
        'cta_block_id',
        'menu_id',
        'content',
        'published_content',
        'content_hash',
        'published_hash',
        'is_enabled',
        'status',
        'sort_order',
        'published_at',
        'published_by',
        'unpublished_reason',
        'draft_updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placement' => SectionPlacement::class,
            'page_id' => 'integer',
            'cta_block_id' => 'integer',
            'menu_id' => 'integer',
            'content' => 'array',
            'published_content' => 'array',
            'has_unpublished_changes' => 'boolean',
            'is_enabled' => 'boolean',
            'status' => ContentStatus::class,
            'sort_order' => 'integer',
            'published_at' => 'datetime',
            'published_by' => 'integer',
            'draft_updated_at' => 'datetime',
        ];
    }

    /**
     * The module that owns this model, for `Gate::before`'s module rule
     * (`App\Support\Modules::SUBJECT_MODULE_METHOD`).
     */
    public function moduleSlug(): string
    {
        return 'website_sections';
    }

    protected function activityModule(): ?string
    {
        return 'website_sections';
    }

    /**
     * The two JSON bodies are versioned in `cms_revisions`; the log records the act through the hashes.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'section_key',
            'placement',
            'page_id',
            'name',
            'anchor',
            'cta_block_id',
            'menu_id',
            'content_hash',
            'published_hash',
            'is_enabled',
            'status',
            'sort_order',
            'published_at',
            'published_by',
            'unpublished_reason',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return ['created_at', 'updated_at', 'updated_by', 'draft_updated_at'];
    }

    /*
    |--------------------------------------------------------------------------
    | Registry helpers (INV-2: an orphaned type never throws)
    |--------------------------------------------------------------------------
    */

    /**
     * Is `section_key` unknown to the registry? The admin list flags it; the renderer skips it.
     */
    public function isOrphaned(): bool
    {
        return ! SectionRegistry::exists((string) $this->section_key);
    }

    /**
     * Disable-only, never deletable (INV-7). An orphaned type is not required: it can be cleaned up.
     */
    public function isRequired(): bool
    {
        return ! $this->isOrphaned() && SectionRegistry::isRequired((string) $this->section_key);
    }

    /**
     * One instance per placement (`uq_ws_instance`), therefore never duplicable.
     */
    public function isUnique(): bool
    {
        return ! $this->isOrphaned() && SectionRegistry::isUnique((string) $this->section_key);
    }

    /**
     * The admin-facing name: the override, else the registry label, else the raw key for an orphan.
     */
    public function displayName(): string
    {
        $name = trim((string) $this->name);

        if ($name !== '') {
            return $name;
        }

        return $this->isOrphaned()
            ? (string) $this->section_key
            : SectionRegistry::label((string) $this->section_key);
    }

    /*
    |--------------------------------------------------------------------------
    | Draft / published boundary (INV-1, D22)
    |--------------------------------------------------------------------------
    */

    /**
     * May an anonymous visitor see this section? (§9 — enabled, published, snapshot present, not trashed.)
     */
    public function isLive(): bool
    {
        return (bool) $this->is_enabled
            && $this->status instanceof ContentStatus
            && $this->status->isPublic()
            && $this->published_content !== null
            && ! $this->trashed();
    }

    public function isPublished(): bool
    {
        return $this->status instanceof ContentStatus && $this->status->isPublic();
    }

    public function isEnabled(): bool
    {
        return (bool) $this->is_enabled;
    }

    /**
     * The snapshot the public renderer accepts. Never the draft.
     *
     * @return array<string, mixed>|null
     */
    public function publishedPayload(): ?array
    {
        return $this->published_content;
    }

    /**
     * The draft field values, for the editor and for preview (§6.12) only.
     *
     * Throws when the row was loaded through the published-snapshot path, where `content` is not
     * selected: that is a developer reaching for a draft on the render path.
     *
     * @return array<string, mixed>
     */
    public function draftContent(): array
    {
        if (! $this->draftLoaded()) {
            throw new LogicException(
                'WebsiteSection::draftContent() on a row loaded through the published-snapshot path. '
                .'The public site renders published_content only (INV-1).'
            );
        }

        return $this->content ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The host page when `placement = page` (cascadeOnDelete).
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /**
     * The `cta` type's block (INV-3 — a real FK, never an id inside JSON).
     *
     * @return BelongsTo<CtaBlock, $this>
     */
    public function ctaBlock(): BelongsTo
    {
        return $this->belongsTo(CtaBlock::class, 'cta_block_id');
    }

    /**
     * The `header` / `footer` type's menu (INV-3).
     *
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Every repeater item, all groups, in editor order.
     *
     * @return HasMany<WebsiteSectionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(WebsiteSectionItem::class, 'website_section_id')
            ->orderBy('group')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * The enabled items — what a publish folds into the snapshot (FT-09, FT-45).
     *
     * @return HasMany<WebsiteSectionItem, $this>
     */
    public function enabledItems(): HasMany
    {
        return $this->items()->where('is_enabled', true);
    }

    /**
     * The image / video slots (§2.4). A single-slot role is enforced by `SectionService`, not the DB.
     *
     * @return BelongsToMany<MediaAsset, $this, WebsiteSectionMedia>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'website_section_media', 'website_section_id', 'media_asset_id')
            ->using(WebsiteSectionMedia::class)
            ->withPivot(['role', 'sort_order'])
            ->withTimestamps()
            ->orderBy('website_section_media.role')
            ->orderBy('website_section_media.sort_order');
    }

    /**
     * The assets placed in one registry role (`hero_image`, `gallery`, ...).
     *
     * @return BelongsToMany<MediaAsset, $this, WebsiteSectionMedia>
     */
    public function mediaInRole(string $role): BelongsToMany
    {
        return $this->media()->wherePivot('role', $role);
    }

    /**
     * The FAQs hand-picked into a FAQ section (`content.source = selected`), in their curated order.
     *
     * @return BelongsToMany<Faq, $this, FaqWebsiteSection>
     */
    public function faqs(): BelongsToMany
    {
        return $this->belongsToMany(Faq::class, 'faq_website_section', 'website_section_id', 'faq_id')
            ->using(FaqWebsiteSection::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('faq_website_section.sort_order');
    }

    /**
     * Append-only content history (§2.14), newest first.
     *
     * @return MorphMany<CmsRevision, $this>
     */
    public function revisions(): MorphMany
    {
        return $this->morphMany(CmsRevision::class, 'revisionable')->latest('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Published **and reading the published snapshot** (D22): `status = published`, a non-null
     * `published_content`, and — unless the caller already chose columns — the SELECT restricted to
     * {@see self::PUBLIC_COLUMNS}, so the draft is never fetched. Admin filters use
     * {@see self::scopeWithStatus()} instead, which keeps every column.
     *
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopePublished(Builder $query): Builder
    {
        $query->where($query->qualifyColumn('status'), ContentStatus::Published->value)
            ->whereNotNull($query->qualifyColumn('published_content'));

        return $this->restrictToSnapshotColumns($query);
    }

    /**
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_enabled'), true);
    }

    /**
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_enabled'), false);
    }

    /**
     * Everything an anonymous visitor may see (§9): enabled, published, snapshot present, not trashed.
     *
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->enabled()->published();
    }

    /**
     * One placement. For `page` the page is required and matched; for every other placement `page_id`
     * must be NULL (the CHECK `chk_ws_page_placement` shape), so a missing page can never widen the set.
     *
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopeForPlacement(Builder $query, SectionPlacement|string $placement, Page|int|null $page = null): Builder
    {
        $value = $placement instanceof SectionPlacement ? $placement->value : $placement;
        $pageId = $page instanceof Page ? $page->getKey() : $page;

        $query->where($query->qualifyColumn('placement'), $value);

        if ($value === SectionPlacement::Page->value) {
            return $pageId === null
                ? $query->whereRaw('1 = 0')
                : $query->where($query->qualifyColumn('page_id'), $pageId);
        }

        return $query->whereNull($query->qualifyColumn('page_id'));
    }

    /**
     * The one public read of §6.9: visible sections of one placement, snapshot columns, in order.
     *
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopeForPublic(Builder $query, SectionPlacement|string $placement, Page|int|null $page = null): Builder
    {
        return $query->forPlacement($placement, $page)->visible()->ordered()->publishedSnapshot();
    }

    /**
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('sort_order'))->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Admin status filter — keeps every column (unlike {@see self::scopePublished()}).
     *
     * @param  Builder<WebsiteSection>  $query
     * @param  ContentStatus|string|array<int, ContentStatus|string>  $status
     * @return Builder<WebsiteSection>
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
     * The amber "unpublished changes" badge (INV-4).
     *
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopeWithUnpublishedChanges(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn(self::UNPUBLISHED_CHANGES_COLUMN), true);
    }

    /**
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopeOfType(Builder $query, string $sectionKey): Builder
    {
        return $query->where($query->qualifyColumn('section_key'), $sectionKey);
    }

    /**
     * Rows whose `section_key` the registry no longer declares (INV-2, `cms:verify-published-snapshots`).
     *
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopeOrphaned(Builder $query): Builder
    {
        $keys = SectionRegistry::keys();

        return $keys === []
            ? $query
            : $query->whereNotIn($query->qualifyColumn('section_key'), $keys);
    }

    /**
     * @param  Builder<WebsiteSection>  $query
     * @return Builder<WebsiteSection>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where($builder->qualifyColumn('name'), 'like', $like)
                ->orWhere($builder->qualifyColumn('section_key'), 'like', $like)
                ->orWhere($builder->qualifyColumn('anchor'), 'like', $like);
        });
    }
}
