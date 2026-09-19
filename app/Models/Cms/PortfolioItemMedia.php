<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Throwable;

/**
 * One gallery image of one portfolio item — the `portfolio_item_media` pivot (phase-04 §2.8, F-2.4,
 * decision D24). **There is no `portfolio_images` table.**
 *
 * The image itself is a `media_assets` row; this row is only its placement: gallery `sort_order` and a
 * per-placement `caption`. `alt_text`, `width`, `height` and `size_bytes` live on `media_assets` and are
 * never duplicated here. `UNIQUE uq_pim(portfolio_item_id, media_asset_id)` makes a double attach a no-op
 * rather than a second thumbnail; `media_asset_id` is `restrictOnDelete`, so an image in use cannot be
 * removed out from under an item.
 *
 * History pivot (decision D19): timestamps, no `deleted_at`, and `created_by` (who attached it) with
 * **no** `updated_by` (F-9.4) — which is why `Blameable` is not used: it would stamp a column that does
 * not exist. Detaching a row never deletes the binary (`PortfolioService::detachImage()`), so there is no
 * delete guard on the row itself.
 *
 * `PortfolioService` attaches through `PortfolioItem::media()`, which uses this class, so the `creating`
 * hook below stamps `created_by` on every attach.
 *
 * @property int $portfolio_item_id
 * @property int $media_asset_id
 * @property int $sort_order
 * @property string|null $caption
 * @property int|null $created_by
 */
class PortfolioItemMedia extends Pivot
{
    /** The attached-asset ceiling per item (§6.3 invariant 3). */
    public const MAX_PER_ITEM = 20;

    protected $table = 'portfolio_item_media';

    public $incrementing = false;

    public $timestamps = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'portfolio_item_id',
        'media_asset_id',
        'sort_order',
        'caption',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portfolio_item_id' => 'integer',
            'media_asset_id' => 'integer',
            'sort_order' => 'integer',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (Model $pivot): void {
            if ($pivot->getAttribute('created_by') !== null) {
                return;
            }

            try {
                $actor = auth()->id();
            } catch (Throwable) {
                return;
            }

            if (is_int($actor) || is_string($actor)) {
                $pivot->setAttribute('created_by', $actor);
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'portfolio';
    }

    /**
     * @return BelongsTo<PortfolioItem, $this>
     */
    public function portfolioItem(): BelongsTo
    {
        return $this->belongsTo(PortfolioItem::class, 'portfolio_item_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /**
     * The user who attached the image.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
