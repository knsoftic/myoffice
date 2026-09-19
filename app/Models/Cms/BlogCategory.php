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
 * One blog category (phase-04 §2.13) — a flat list, no nesting. Drives `/blog/category/{slug}`; an
 * inactive category 404s there (§8.11).
 *
 * Deleting a category that still has posts is refused by the service unless `reassign_to` is supplied
 * (§6.2). SEO lives in `seo_meta` (D23); the image is a `media_assets` row (D24).
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
class BlogCategory extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    protected $table = 'blog_categories';

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
        return 'blog_categories';
    }

    protected function activityModule(): ?string
    {
        return 'blog_categories';
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
     * @return HasMany<BlogPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'blog_category_id');
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
     * @param  Builder<BlogCategory>  $query
     * @return Builder<BlogCategory>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<BlogCategory>  $query
     * @return Builder<BlogCategory>
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), $active);
    }
}
