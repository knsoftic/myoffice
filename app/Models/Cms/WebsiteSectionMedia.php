<?php

declare(strict_types=1);

namespace App\Models\Cms;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One image or video slot of a section — the `website_section_media` pivot (phase-03 §2.4, D24, INV-3).
 *
 * Primary key (`website_section_id`, `role`, `media_asset_id`); no surrogate id, no soft delete, no
 * blameable (it is a pivot). `media_asset_id` is `restrictOnDelete`: an image in use cannot be removed
 * out from under a section. Single-slot roles are enforced by `SectionService` from the registry's
 * `multiple => false`, not by the database (a second unique index would block galleries).
 *
 * @property int $website_section_id
 * @property int $media_asset_id
 * @property string $role
 * @property int $sort_order
 */
class WebsiteSectionMedia extends Pivot
{
    protected $table = 'website_section_media';

    public $incrementing = false;

    public $timestamps = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'website_section_id',
        'media_asset_id',
        'role',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'website_section_id' => 'integer',
            'media_asset_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WebsiteSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(WebsiteSection::class, 'website_section_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
