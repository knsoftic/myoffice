<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\Concerns\OrdersBySortOrder;
use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One portfolio case study (phase-04 §2.7, requirement §12).
 *
 * **Gallery (F-2.4, D24).** Images are `media_assets` rows attached through `portfolio_item_media`
 * (`media()`), ordered by the pivot `sort_order`. The cover is the `cover_media_id` column — exactly one,
 * and it must be one of the attached assets (`PortfolioService::setCover()` refuses anything else, §6.3).
 * There is no `is_cover` flag, so there is no "two covers" state to repair.
 *
 * **Deferred link (§2.1).** `client_id` points at Phase 5's `clients` with no foreign key yet; the site
 * renders the `client_name` snapshot and never joins, so a missing client can never break a page.
 *
 * SEO is the `seo_meta` morph (D23). Only `published` is public (§9.2). A soft delete keeps every
 * attachment and binary; `PortfolioService::forceDelete()` detaches the pivot rows and deletes no file.
 *
 * @property int $id
 * @property int|null $portfolio_category_id
 * @property string $title
 * @property string $slug
 * @property string|null $client_name
 * @property int|null $client_id
 * @property string|null $summary
 * @property string|null $description
 * @property string|null $technologies_note
 * @property int|null $cover_media_id
 * @property string|null $project_url
 * @property Carbon|null $completion_date
 * @property ContentStatus $status
 * @property bool $is_featured
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class PortfolioItem extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    protected $table = 'portfolio_items';

    /**
     * `cover_media_id` is deliberately absent: only `PortfolioService::setCover()` / `addImages()` /
     * `detachImage()` write it, after checking the asset is attached to this item.
     *
     * @var list<string>
     */
    protected $fillable = [
        'portfolio_category_id',
        'title',
        'slug',
        'client_name',
        'client_id',
        'summary',
        'description',
        'technologies_note',
        'project_url',
        'completion_date',
        'status',
        'is_featured',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_featured' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portfolio_category_id' => 'integer',
            'client_id' => 'integer',
            'cover_media_id' => 'integer',
            'completion_date' => 'date',
            'status' => ContentStatus::class,
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'portfolio';
    }

    protected function activityModule(): ?string
    {
        return 'portfolio';
    }

    /**
     * `cover_media_id` is not fillable but its moves are still worth an audit row.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [...$this->getFillable(), 'cover_media_id'];
    }

    public function sluggableSource(): string
    {
        return (string) $this->title;
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['title', 'slug', 'client_name'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isPublished(): bool
    {
        return $this->status === ContentStatus::Published;
    }

    public function hasCover(): bool
    {
        return $this->cover_media_id !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<PortfolioCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PortfolioCategory::class, 'portfolio_category_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'cover_media_id');
    }

    /**
     * The gallery, in pivot `sort_order` (§2.8). Attaching through this relation fires
     * `PortfolioItemMedia`'s `creating` hook, which stamps `created_by`.
     *
     * @return BelongsToMany<MediaAsset, $this, PortfolioItemMedia>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'portfolio_item_media', 'portfolio_item_id', 'media_asset_id')
            ->using(PortfolioItemMedia::class)
            ->withPivot(['sort_order', 'caption', 'created_by'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * The pivot rows themselves (counts, reorder checks) without loading the assets.
     *
     * @return HasMany<PortfolioItemMedia, $this>
     */
    public function mediaAttachments(): HasMany
    {
        return $this->hasMany(PortfolioItemMedia::class, 'portfolio_item_id');
    }

    /**
     * @return BelongsToMany<Technology, $this, PortfolioItemTechnology>
     */
    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'portfolio_item_technology', 'portfolio_item_id', 'technology_id')
            ->using(PortfolioItemTechnology::class)
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
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
     * The only filter the public site may use (§9.2): `status = published`.
     *
     * @param  Builder<PortfolioItem>  $query
     * @return Builder<PortfolioItem>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ContentStatus::Published->value);
    }

    /**
     * @param  Builder<PortfolioItem>  $query
     * @param  ContentStatus|string|array<int, ContentStatus|string>  $status
     * @return Builder<PortfolioItem>
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
     * @param  Builder<PortfolioItem>  $query
     * @return Builder<PortfolioItem>
     */
    public function scopeFeatured(Builder $query, bool $featured = true): Builder
    {
        return $query->where($query->qualifyColumn('is_featured'), $featured);
    }

    /**
     * @param  Builder<PortfolioItem>  $query
     * @return Builder<PortfolioItem>
     */
    public function scopeInCategory(Builder $query, PortfolioCategory|int|null $category): Builder
    {
        if ($category === null) {
            return $query->whereNull($query->qualifyColumn('portfolio_category_id'));
        }

        return $query->where(
            $query->qualifyColumn('portfolio_category_id'),
            $category instanceof PortfolioCategory ? $category->getKey() : $category
        );
    }

    /**
     * @param  Builder<PortfolioItem>  $query
     * @return Builder<PortfolioItem>
     */
    public function scopeWithTechnology(Builder $query, Technology|int $technology): Builder
    {
        $id = $technology instanceof Technology ? $technology->getKey() : $technology;

        return $query->whereHas('technologies', function (Builder $builder) use ($id): void {
            $builder->whereKey($id);
        });
    }

    /**
     * The §8.3 "completion year" filter.
     *
     * @param  Builder<PortfolioItem>  $query
     * @return Builder<PortfolioItem>
     */
    public function scopeCompletedIn(Builder $query, int $year): Builder
    {
        return $query->whereBetween($query->qualifyColumn('completion_date'), [
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year),
        ]);
    }

    /**
     * The public grid order: featured first, then `sort_order`.
     *
     * @param  Builder<PortfolioItem>  $query
     * @return Builder<PortfolioItem>
     */
    public function scopeFeaturedFirst(Builder $query): Builder
    {
        return $query->orderByDesc($query->qualifyColumn('is_featured'))
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
