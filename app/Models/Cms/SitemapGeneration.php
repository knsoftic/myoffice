<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Models\Cms\Concerns\ForbidsDeletion;
use App\Models\Cms\Concerns\ForbidsUpdates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One sitemap build (phase-03 §2.14, §105): URL count, bytes, duration, trigger, outcome, and the
 * per-provider breakdown so a reviewer sees which phase contributed what.
 *
 * A run-history log line: **write-once** ({@see ForbidsUpdates}, `UPDATED_AT = null`) and **no
 * `deleted_at`** (decision D19) — an Eloquent delete throws ({@see ForbidsDeletion}).
 * `SitemapGenerator::regenerate()` is the only writer.
 *
 * `trigger` and `status` are declared by §2.14 as plain strings with a closed value set and no enum;
 * the values live here as constants so no caller spells them out.
 *
 * @property int $id
 * @property int $url_count
 * @property int $byte_size
 * @property int $duration_ms
 * @property string $trigger
 * @property string $status
 * @property string|null $failure_reason
 * @property array<string, int>|null $providers
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class SitemapGeneration extends Model
{
    use ForbidsDeletion;
    use ForbidsUpdates;

    /** A build record is a log line (§2.14). */
    public const UPDATED_AT = null;

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_PUBLISH = 'publish';

    public const TRIGGER_SCHEDULED = 'scheduled';

    /** @var list<string> */
    public const TRIGGERS = [self::TRIGGER_MANUAL, self::TRIGGER_PUBLISH, self::TRIGGER_SCHEDULED];

    /** @var list<string> */
    public const STATUSES = [self::STATUS_OK, self::STATUS_FAILED];

    protected $table = 'sitemap_generations';

    /**
     * `created_by` is stamped from the authenticated user by {@see ForbidsUpdates}.
     *
     * @var list<string>
     */
    protected $fillable = [
        'url_count',
        'byte_size',
        'duration_ms',
        'trigger',
        'status',
        'failure_reason',
        'providers',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'url_count' => 'integer',
            'byte_size' => 'integer',
            'duration_ms' => 'integer',
            'providers' => 'array',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Sitemap history is read under `seo.view` (§4.1) — no module of its own.
     */
    public function moduleSlug(): string
    {
        return 'seo';
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    public function failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * provider key => URL count, e.g. `['pages' => 12, 'static' => 1]`.
     *
     * @return array<string, int>
     */
    public function providerCounts(): array
    {
        $counts = [];

        foreach ((array) ($this->providers ?? []) as $key => $count) {
            $counts[(string) $key] = (int) $count;
        }

        return $counts;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<SitemapGeneration>  $query
     * @return Builder<SitemapGeneration>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_OK);
    }

    /**
     * @param  Builder<SitemapGeneration>  $query
     * @return Builder<SitemapGeneration>
     */
    public function scopeFailures(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_FAILED);
    }

    /**
     * Newest first (`INDEX (created_at)`).
     *
     * @param  Builder<SitemapGeneration>  $query
     * @return Builder<SitemapGeneration>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc($query->qualifyColumn('created_at'))->orderByDesc($query->qualifyColumn('id'));
    }
}
