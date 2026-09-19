<?php

declare(strict_types=1);

namespace App\Models\Cms;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One technology chip on one portfolio item — the `portfolio_item_technology` pivot (phase-04 §2.7),
 * identical in shape to `service_technology`.
 *
 * Composite primary key, both sides `cascadeOnDelete`, no timestamps, no soft deletes, no blameable
 * (decision D19 link pivot). No delete guard: re-syncing and deleting a technology detach rows (§6.2).
 *
 * @property int $portfolio_item_id
 * @property int $technology_id
 * @property int $sort_order
 */
class PortfolioItemTechnology extends Pivot
{
    protected $table = 'portfolio_item_technology';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'portfolio_item_id',
        'technology_id',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portfolio_item_id' => 'integer',
            'technology_id' => 'integer',
            'sort_order' => 'integer',
        ];
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
     * @return BelongsTo<Technology, $this>
     */
    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class, 'technology_id');
    }
}
