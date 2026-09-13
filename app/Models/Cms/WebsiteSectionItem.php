<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\StatisticMetric;
use App\Enums\Cms\StatisticValueMode;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One repeater item inside a section (phase-03 §2.3, [D-W3-3]): a hero statistic, an about
 * why-choose-us point, a history entry, a highlight, a header top-bar link.
 *
 * Every repeater of every section type is a row here, discriminated by `group`. Items have **no
 * snapshot of their own** (§2.15): a publish folds the enabled items, in `sort_order`, into the parent
 * section's `published_content`, so reordering six statistics never goes live before the hero does.
 * The public site therefore never reads this table.
 *
 * `metric`, `value_mode` and `manual_value` are real columns because they are the only repeater values
 * a service resolves, validates and reports on (§6.11, §8.11). `manual_value` is `decimal(15,2)` and is
 * cast to a **decimal string**, never a float (INV-12, CLAUDE.md §1.4). CHECK `chk_wsi_value` makes
 * `value_mode = auto` without a `metric` a database error.
 *
 * @property int $id
 * @property int $website_section_id
 * @property string $group
 * @property array<string, mixed>|null $content
 * @property StatisticMetric|null $metric
 * @property StatisticValueMode $value_mode
 * @property string|null $manual_value
 * @property int|null $media_asset_id
 * @property bool $is_enabled
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class WebsiteSectionItem extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /** The repeater group the statistics screen of §8.11 lists across every section. */
    public const GROUP_STATISTIC = 'statistic';

    protected $table = 'website_section_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'website_section_id',
        'group',
        'content',
        'metric',
        'value_mode',
        'manual_value',
        'media_asset_id',
        'is_enabled',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'website_section_id' => 'integer',
            'content' => 'array',
            'metric' => StatisticMetric::class,
            'value_mode' => StatisticValueMode::class,
            'manual_value' => 'decimal:2',
            'media_asset_id' => 'integer',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Items are edited inside the section form under `website_sections.edit` (§4.1) — no module of
     * their own.
     */
    public function moduleSlug(): string
    {
        return 'website_sections';
    }

    protected function activityModule(): ?string
    {
        return 'website_sections';
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isEnabled(): bool
    {
        return (bool) $this->is_enabled;
    }

    public function isStatistic(): bool
    {
        return $this->group === self::GROUP_STATISTIC;
    }

    /**
     * Counted live (§9) rather than typed in. `StatisticsProvider::valueFor()` resolves it, falling back
     * to `manual_value`, and renders nothing when both are null (INV-12).
     */
    public function isAuto(): bool
    {
        return $this->value_mode === StatisticValueMode::Auto;
    }

    /**
     * One field value of the item's `content`.
     */
    public function field(string $key, mixed $default = null): mixed
    {
        $content = $this->content ?? [];

        return array_key_exists($key, $content) ? $content[$key] : $default;
    }

    /**
     * The editor's collapsed summary line (§8.5): the label, else the title, else the year.
     */
    public function summary(): string
    {
        foreach (['label', 'title', 'year'] as $key) {
            $value = trim((string) (is_scalar($this->field($key)) ? $this->field($key) : ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '#'.$this->getKey();
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<WebsiteSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(WebsiteSection::class, 'website_section_id');
    }

    /**
     * The item's image or custom icon (nullOnDelete, INV-3).
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<WebsiteSectionItem>  $query
     * @return Builder<WebsiteSectionItem>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_enabled'), true);
    }

    /**
     * @param  Builder<WebsiteSectionItem>  $query
     * @return Builder<WebsiteSectionItem>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('sort_order'))->orderBy($query->qualifyColumn('id'));
    }

    /**
     * @param  Builder<WebsiteSectionItem>  $query
     * @return Builder<WebsiteSectionItem>
     */
    public function scopeInGroup(Builder $query, string $group): Builder
    {
        return $query->where($query->qualifyColumn('group'), $group);
    }

    /**
     * @param  Builder<WebsiteSectionItem>  $query
     * @return Builder<WebsiteSectionItem>
     */
    public function scopeForSection(Builder $query, WebsiteSection|int $section): Builder
    {
        return $query->where(
            $query->qualifyColumn('website_section_id'),
            $section instanceof WebsiteSection ? $section->getKey() : $section
        );
    }

    /**
     * Every statistic item in the system — the statistics screen of §8.11.
     *
     * @param  Builder<WebsiteSectionItem>  $query
     * @return Builder<WebsiteSectionItem>
     */
    public function scopeStatistics(Builder $query): Builder
    {
        return $query->inGroup(self::GROUP_STATISTIC);
    }

    /**
     * @param  Builder<WebsiteSectionItem>  $query
     * @return Builder<WebsiteSectionItem>
     */
    public function scopeUsingMetric(Builder $query, StatisticMetric|string $metric): Builder
    {
        return $query->where(
            $query->qualifyColumn('metric'),
            $metric instanceof StatisticMetric ? $metric->value : $metric
        );
    }

    /**
     * @param  Builder<WebsiteSectionItem>  $query
     * @return Builder<WebsiteSectionItem>
     */
    public function scopeAuto(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('value_mode'), StatisticValueMode::Auto->value);
    }
}
