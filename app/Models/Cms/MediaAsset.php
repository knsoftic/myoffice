<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Enums\Cms\MediaProcessingStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One uploaded original plus its generated derivative set (phase-03 §2.13, decision **D24**).
 *
 * The single CMS image/video library: every image rendered on the public website is a row here,
 * reached through a real foreign key or the `website_section_media` pivot — never a path inside a
 * JSON blob (INV-3). `variants` carries the width-keyed derivative set written by
 * `GenerateImageDerivatives`; the URL helpers live in `MediaService` (§6.8) so there is exactly one
 * place that turns a row into a `<picture>`.
 *
 * `usage_count` is a cache recomputed by `MediaService::recountUsage()`. `MediaAssetPolicy::delete()`
 * refuses while it is above zero, so an image cannot be deleted out from under a published page.
 *
 * @property int $id
 * @property string $disk
 * @property string $directory
 * @property string $filename
 * @property string $original_name
 * @property string $mime_type
 * @property string $extension
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_seconds
 * @property string $checksum
 * @property MediaCollection $collection
 * @property ImageProfile|null $profile
 * @property array<string, mixed>|null $variants
 * @property string|null $alt_text
 * @property string|null $title
 * @property string|null $caption
 * @property MediaProcessingStatus $derivatives_status
 * @property Carbon|null $derivatives_generated_at
 * @property string|null $failure_reason
 * @property int $usage_count
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class MediaAsset extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /**
     * MIME prefixes the pipeline accepts (§6.8). SVG is rejected at upload ([D-W3-15]).
     */
    public const IMAGE_MIME_PREFIX = 'image/';

    public const VIDEO_MIME_PREFIX = 'video/';

    protected $table = 'media_assets';

    /**
     * Written by `MediaService` only — a controller never mass-assigns a binary's metadata.
     *
     * @var list<string>
     */
    protected $fillable = [
        'disk',
        'directory',
        'filename',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'width',
        'height',
        'duration_seconds',
        'checksum',
        'collection',
        'profile',
        'variants',
        'alt_text',
        'title',
        'caption',
        'derivatives_status',
        'derivatives_generated_at',
        'failure_reason',
        'usage_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
            'collection' => MediaCollection::class,
            'profile' => ImageProfile::class,
            'variants' => 'array',
            'derivatives_status' => MediaProcessingStatus::class,
            'derivatives_generated_at' => 'datetime',
            'usage_count' => 'integer',
        ];
    }

    protected function activityModule(): ?string
    {
        return 'website_media';
    }

    /**
     * `variants` is a machine-written derivative map, not an editorial value: logging its old and
     * new shape on every queue run would bury the alt-text edits this log exists for.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'disk',
            'directory',
            'filename',
            'original_name',
            'mime_type',
            'extension',
            'size_bytes',
            'collection',
            'profile',
            'alt_text',
            'title',
            'caption',
            'derivatives_status',
            'failure_reason',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return [
            'created_at',
            'updated_at',
            'updated_by',
            'usage_count',
            'derivatives_generated_at',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Path of the original on its disk — `cms/2026/09/01H9Z.../01H9Z....webp`.
     */
    public function path(): string
    {
        return trim((string) $this->directory, '/').'/'.$this->filename;
    }

    /**
     * Stored path of one derivative, or null when that width was never generated.
     */
    public function variantPath(int $width): ?string
    {
        $variant = $this->variant($width);
        $path = $variant['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * One entry of the `variants` map, keyed by width as §2.13 writes it.
     *
     * @return array<string, mixed>|null
     */
    public function variant(int $width): ?array
    {
        $variants = $this->variants ?? [];
        $entry = $variants[(string) $width] ?? $variants[$width] ?? null;

        return is_array($entry) ? $entry : null;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, self::IMAGE_MIME_PREFIX);
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime_type, self::VIDEO_MIME_PREFIX);
    }

    /**
     * May this asset be rendered? `ready` or `skipped` (a video is skipped, not failed).
     */
    public function isUsable(): bool
    {
        return $this->derivatives_status instanceof MediaProcessingStatus
            && $this->derivatives_status->isUsable();
    }

    /**
     * INV-11/§8.13: an image placed in a section must carry alt text. Validated at section save.
     */
    public function hasAltText(): bool
    {
        return trim((string) $this->alt_text) !== '';
    }

    /**
     * Is the asset referenced anywhere? The delete guard of §2.13.
     */
    public function isInUse(): bool
    {
        return (int) $this->usage_count > 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The sections that place this asset in one of their media roles (§2.4).
     *
     * @return BelongsToMany<WebsiteSection, $this>
     */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(WebsiteSection::class, 'website_section_media')
            ->withPivot(['role', 'sort_order'])
            ->withTimestamps()
            ->orderBy('website_section_media.sort_order');
    }

    /**
     * @return HasMany<WebsiteSectionItem, $this>
     */
    public function sectionItems(): HasMany
    {
        return $this->hasMany(WebsiteSectionItem::class, 'media_asset_id');
    }

    /**
     * @return HasMany<Page, $this>
     */
    public function pageBanners(): HasMany
    {
        return $this->hasMany(Page::class, 'banner_media_id');
    }

    /**
     * @return HasMany<CtaBlock, $this>
     */
    public function ctaBackgrounds(): HasMany
    {
        return $this->hasMany(CtaBlock::class, 'background_media_id');
    }

    /**
     * @return HasMany<SeoMeta, $this>
     */
    public function seoImages(): HasMany
    {
        return $this->hasMany(SeoMeta::class, 'og_image_media_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeImages(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', self::IMAGE_MIME_PREFIX.'%');
    }

    /**
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeVideos(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', self::VIDEO_MIME_PREFIX.'%');
    }

    /**
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeInCollection(Builder $query, MediaCollection|string $collection): Builder
    {
        return $query->where(
            'collection',
            $collection instanceof MediaCollection ? $collection->value : $collection
        );
    }

    /**
     * Renderable assets only — `ready` or `skipped` (§6.8).
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereIn('derivatives_status', [
            MediaProcessingStatus::Ready->value,
            MediaProcessingStatus::Skipped->value,
        ]);
    }

    /**
     * The library's "unused only" filter (§8.13, [D-W3-17] — a human deletes, never a command).
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeUnused(Builder $query): Builder
    {
        return $query->where('usage_count', 0);
    }

    /**
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeMissingAltText(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->whereNull('alt_text')->orWhere('alt_text', '');
        });
    }

    /**
     * Newest upload first — the library's default order.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where('original_name', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhere('alt_text', 'like', $like)
                ->orWhere('caption', 'like', $like);
        });
    }
}
