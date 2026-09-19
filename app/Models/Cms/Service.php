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

/**
 * One sellable service of the software house (phase-04 §2.3, requirement §11).
 *
 * **Money.** `starting_price` is `decimal(15,2)`, cast to a decimal string (never a float) and written
 * straight from the validated `decimal:2` input; null renders "on request". `price_visible = false`
 * hides it publicly while the column keeps its value (§6.4 invariant 1). Phase 4 performs no money
 * arithmetic — display goes through `money()`.
 *
 * **Content.** `full_description` is sanitised rich text (`RichText::sanitize()` on write and on render,
 * D25). `features` is a re-indexed list of trimmed, non-empty strings, or null (§6.4 invariant 2).
 *
 * SEO is the `seo_meta` morph (D23), the image a `media_assets` row (D24). Only `published` is public
 * (§9.2). Later phases point here: `contact_inquiries.service_id` (Phase 4), `leads.service_id`
 * (Phase 5), `projects.service_id` (Phase 6), `collaborator_service` (Phase 8).
 *
 * @property int $id
 * @property int|null $service_category_id
 * @property string $name
 * @property string $slug
 * @property string|null $short_description
 * @property string|null $full_description
 * @property string|null $icon
 * @property int|null $image_media_id
 * @property string|null $starting_price
 * @property string|null $price_note
 * @property bool $price_visible
 * @property list<string>|null $features
 * @property ContentStatus $status
 * @property bool $is_featured
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Service extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    /** `features` ceiling and per-entry length (§6.4 invariant 2, §6.11). */
    public const MAX_FEATURES = 20;

    public const MAX_FEATURE_LENGTH = 150;

    protected $table = 'services';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'service_category_id',
        'name',
        'slug',
        'short_description',
        'full_description',
        'icon',
        'image_media_id',
        'starting_price',
        'price_note',
        'price_visible',
        'features',
        'status',
        'is_featured',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'price_visible' => true,
        'is_featured' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_category_id' => 'integer',
            'image_media_id' => 'integer',
            'starting_price' => 'decimal:2',
            'price_visible' => 'boolean',
            'features' => 'array',
            'status' => ContentStatus::class,
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'services';
    }

    protected function activityModule(): ?string
    {
        return 'services';
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
        return ['name', 'slug', 'short_description'];
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

    /**
     * Has a price that may be shown publicly (visible and not "on request").
     */
    public function showsPrice(): bool
    {
        return (bool) $this->price_visible && $this->starting_price !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'image_media_id');
    }

    /**
     * Technology chips in their service-page order.
     *
     * @return BelongsToMany<Technology, $this, ServiceTechnology>
     */
    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'service_technology', 'service_id', 'technology_id')
            ->using(ServiceTechnology::class)
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * @return HasMany<ContactInquiry, $this>
     */
    public function contactInquiries(): HasMany
    {
        return $this->hasMany(ContactInquiry::class, 'service_id');
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
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ContentStatus::Published->value);
    }

    /**
     * @param  Builder<Service>  $query
     * @param  ContentStatus|string|array<int, ContentStatus|string>  $status
     * @return Builder<Service>
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
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeFeatured(Builder $query, bool $featured = true): Builder
    {
        return $query->where($query->qualifyColumn('is_featured'), $featured);
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeInCategory(Builder $query, ServiceCategory|int|null $category): Builder
    {
        if ($category === null) {
            return $query->whereNull($query->qualifyColumn('service_category_id'));
        }

        return $query->where(
            $query->qualifyColumn('service_category_id'),
            $category instanceof ServiceCategory ? $category->getKey() : $category
        );
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeWithTechnology(Builder $query, Technology|int $technology): Builder
    {
        $id = $technology instanceof Technology ? $technology->getKey() : $technology;

        return $query->whereHas('technologies', function (Builder $builder) use ($id): void {
            $builder->whereKey($id);
        });
    }

    /**
     * The public grid order (§8.11): featured first, then `sort_order`.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeFeaturedFirst(Builder $query): Builder
    {
        return $query->orderByDesc($query->qualifyColumn('is_featured'))
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
