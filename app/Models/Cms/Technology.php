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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One technology chip (phase-04 §2.4) shared by services and portfolio items; `slug` is the public
 * filter value.
 *
 * Deleting a technology only detaches its pivot rows — it never touches a service or a portfolio item
 * (§6.2). `color` is a `#rrggbb` hex validated in the Form Request. The logo is a `media_assets` row with
 * `ImageProfile::Logo` (D24).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $logo_media_id
 * @property string|null $icon
 * @property string|null $color
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Technology extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    /** The hex format `technologies.color` must match (§2.4). */
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    protected $table = 'technologies';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'logo_media_id',
        'icon',
        'color',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'logo_media_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function moduleSlug(): string
    {
        return 'technologies';
    }

    protected function activityModule(): ?string
    {
        return 'technologies';
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
     * @return BelongsToMany<Service, $this, ServiceTechnology>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_technology', 'technology_id', 'service_id')
            ->using(ServiceTechnology::class)
            ->withPivot('sort_order');
    }

    /**
     * @return BelongsToMany<PortfolioItem, $this, PortfolioItemTechnology>
     */
    public function portfolioItems(): BelongsToMany
    {
        return $this->belongsToMany(PortfolioItem::class, 'portfolio_item_technology', 'technology_id', 'portfolio_item_id')
            ->using(PortfolioItemTechnology::class)
            ->withPivot('sort_order');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'logo_media_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The only filter the public site may use (§9.2).
     *
     * @param  Builder<Technology>  $query
     * @return Builder<Technology>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<Technology>  $query
     * @return Builder<Technology>
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), $active);
    }
}
