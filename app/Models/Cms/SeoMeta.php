<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\RobotsDirective;
use App\Enums\Cms\SitemapChangeFrequency;
use App\Models\Cms\Concerns\ForbidsDeletion;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The SEO record of one public target (phase-03 §2.12, §105) — **the one SEO store** (decision D23).
 *
 * No phase adds SEO columns to its own table: a page, and later a service, course or blog post, reaches
 * its row through `morphOne(SeoMeta::class, 'seoable')`, and a named route with no row of its own
 * (`site.home`) through `route_key`. CHECK `chk_seo_target` keeps the two forms mutually exclusive.
 * Every write goes through `SeoService::save()`; every validation rule through `SeoService::rules()`.
 *
 * **No `deleted_at`** (decision D19, §2.14, §12.2 Q1): the row is a 1:1 attribute of its target, its
 * history lives in `activity_log`, and an Eloquent delete throws ({@see ForbidsDeletion}). Live, not
 * snapshot-published (§2.15): metadata has no half-written state worth hiding.
 *
 * `sitemap_priority` is `decimal(2,1)` and is cast to a decimal string, never a float.
 *
 * @property int $id
 * @property string|null $seoable_type
 * @property int|null $seoable_id
 * @property string|null $route_key
 * @property string|null $title
 * @property string|null $meta_description
 * @property string|null $meta_keywords
 * @property string|null $canonical_url
 * @property RobotsDirective $robots
 * @property string|null $og_title
 * @property string|null $og_description
 * @property int|null $og_image_media_id
 * @property string $og_type
 * @property bool $sitemap_include
 * @property string $sitemap_priority
 * @property SitemapChangeFrequency $sitemap_changefreq
 * @property Carbon|null $last_checked_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class SeoMeta extends Model
{
    use Blameable;
    use ForbidsDeletion;
    use LogsActivityWithContext;

    /** Explicit: the convention would look for `seo_metas`. */
    protected $table = 'seo_meta';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'seoable_type',
        'seoable_id',
        'route_key',
        'title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'og_image_media_id',
        'og_type',
        'sitemap_include',
        'sitemap_priority',
        'sitemap_changefreq',
        'last_checked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seoable_id' => 'integer',
            'robots' => RobotsDirective::class,
            'og_image_media_id' => 'integer',
            'sitemap_include' => 'boolean',
            'sitemap_priority' => 'decimal:1',
            'sitemap_changefreq' => SitemapChangeFrequency::class,
            'last_checked_at' => 'datetime',
        ];
    }

    public function moduleSlug(): string
    {
        return 'seo';
    }

    protected function activityModule(): ?string
    {
        return 'seo';
    }

    /**
     * The completeness run stamps `last_checked_at`; that alone is not an SEO change.
     *
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return ['created_at', 'updated_at', 'updated_by', 'last_checked_at'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Addressed by a named route (`site.home`) rather than by a model.
     */
    public function isRouteTarget(): bool
    {
        return $this->route_key !== null && $this->route_key !== '';
    }

    /**
     * The stored directive only. The effective robots value is stricter-wins and belongs to
     * `SeoService::for()` (site-wide noindex, maintenance, preview — INV-9).
     */
    public function isIndexable(): bool
    {
        return $this->robots instanceof RobotsDirective && $this->robots->isIndexable();
    }

    public function includedInSitemap(): bool
    {
        return (bool) $this->sitemap_include && $this->isIndexable();
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The page (or later-phase model) this record describes. Null for a route-keyed row.
     *
     * @return MorphTo<Model, $this>
     */
    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * §105 OG image (nullOnDelete, D24).
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'og_image_media_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<SeoMeta>  $query
     * @return Builder<SeoMeta>
     */
    public function scopeForRoute(Builder $query, string $routeKey): Builder
    {
        return $query->where($query->qualifyColumn('route_key'), $routeKey);
    }

    /**
     * The row of one model target, matched on its morph class (`uq_seo_target`).
     *
     * @param  Builder<SeoMeta>  $query
     * @return Builder<SeoMeta>
     */
    public function scopeForTarget(Builder $query, Model $target): Builder
    {
        return $query->where($query->qualifyColumn('seoable_type'), $target->getMorphClass())
            ->where($query->qualifyColumn('seoable_id'), $target->getKey());
    }

    /**
     * @param  Builder<SeoMeta>  $query
     * @return Builder<SeoMeta>
     */
    public function scopeRouteKeyed(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('route_key'));
    }

    /**
     * @param  Builder<SeoMeta>  $query
     * @return Builder<SeoMeta>
     */
    public function scopeModelKeyed(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('seoable_id'));
    }

    /**
     * Stored directive allows indexing (`index_follow` / `index_nofollow`).
     *
     * @param  Builder<SeoMeta>  $query
     * @return Builder<SeoMeta>
     */
    public function scopeIndexable(Builder $query): Builder
    {
        $values = array_values(array_map(
            static fn (RobotsDirective $directive): string => $directive->value,
            array_filter(RobotsDirective::cases(), static fn (RobotsDirective $directive): bool => $directive->isIndexable())
        ));

        return $query->whereIn($query->qualifyColumn('robots'), $values);
    }

    /**
     * The sitemap query's own filter (`INDEX (robots, sitemap_include)`).
     *
     * @param  Builder<SeoMeta>  $query
     * @return Builder<SeoMeta>
     */
    public function scopeInSitemap(Builder $query): Builder
    {
        return $query->indexable()->where($query->qualifyColumn('sitemap_include'), true);
    }
}
