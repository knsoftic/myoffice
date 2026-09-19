<?php

declare(strict_types=1);

namespace App\Models\Cms;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One technology chip on one service — the `service_technology` pivot (phase-04 §2.5).
 *
 * Composite primary key (`service_id`, `technology_id`), both sides `cascadeOnDelete`; `sort_order` is
 * the chip order on the service page. No timestamps, no soft deletes, no blameable: a link pivot
 * (decision D19). It carries no delete guard because the contract detaches it — re-syncing a service's
 * technologies and deleting a technology both remove pivot rows (§6.2).
 *
 * @property int $service_id
 * @property int $technology_id
 * @property int $sort_order
 */
class ServiceTechnology extends Pivot
{
    protected $table = 'service_technology';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'technology_id',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_id' => 'integer',
            'technology_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'services';
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /**
     * @return BelongsTo<Technology, $this>
     */
    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class, 'technology_id');
    }
}
