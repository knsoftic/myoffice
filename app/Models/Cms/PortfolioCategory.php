<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Models\Cms\Concerns\OrdersBySortOrder;
use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One portfolio category (phase-04 §2.6) — identical in shape to `ServiceCategory`, a separate table on
 * purpose (§12.2 Q3).
 *
 * Deleting a category that still has items is refused by `TaxonomyService::delete()` unless a
 * `reassign_to` category is supplied (§6.2). SEO lives in `seo_meta` (D23); the image is a `media_assets`
 * row (D24).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property int|null $image_media_id
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class PortfolioCategory extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    protected $table = 'portfolio_categories';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'image_media_id',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'image_media_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function moduleSlug(): string
    {
        return 'portfolio_categories';
    }

    protected function activityModule(): ?string
    {
        return 'portfolio_categories';
    }

    public function sluggableSource(): string
    {
        return (string) $this->name;
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['name', 'slug'];
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return HasMany<PortfolioItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PortfolioItem::class, 'portfolio_category_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'image_media_id');
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
     * The only filter the public site may use (§9.2).
     *
     * @param  Builder<PortfolioCategory>  $query
     * @return Builder<PortfolioCategory>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<PortfolioCategory>  $query
     * @return Builder<PortfolioCategory>
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), $active);
    }
}
