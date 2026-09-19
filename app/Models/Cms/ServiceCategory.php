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
 * One service category (phase-04 §2.2) — the strip of chips above the public service grid.
 *
 * Inactive = hidden from the public site, kept in admin. Deleting a category that still has services is
 * refused by `TaxonomyService::delete()` unless a `reassign_to` category is supplied (§6.2); the
 * database `nullOnDelete` on `services.service_category_id` is only the last-resort net.
 *
 * SEO lives in `seo_meta` (decision D23) and the image is a `media_assets` row (decision D24).
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
class ServiceCategory extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    protected $table = 'service_categories';

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
        return 'service_categories';
    }

    protected function activityModule(): ?string
    {
        return 'service_categories';
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
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'service_category_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'image_media_id');
    }

    /**
     * The one SEO record (decision D23), written only through `SeoService::save()`.
     *
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
     * The only filter the public site may use (§9.2): active (soft-deleted rows are excluded already).
     *
     * @param  Builder<ServiceCategory>  $query
     * @return Builder<ServiceCategory>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<ServiceCategory>  $query
     * @return Builder<ServiceCategory>
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), $active);
    }
}
