<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\CtaVariant;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One reusable call-to-action block (phase-03 §2.8, §100).
 *
 * Referenced through `website_sections.cta_block_id`, never copied (INV-3), so changing the button
 * once changes it everywhere. **Status-gated and live** — a CTA block is not snapshot-published
 * (§2.15): `draft` simply never renders. The referencing section's own snapshot freezes the resolved
 * CTA at publish time, which is what keeps a half-written CTA off a published page.
 *
 * `usage_count` is a cache recomputed by `CtaBlockService::recount()`; `CtaBlockPolicy::delete()`
 * refuses while it is above zero.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property CtaVariant $variant
 * @property string $heading
 * @property string|null $subheading
 * @property string|null $description
 * @property string|null $primary_label
 * @property string|null $primary_url
 * @property ButtonStyle $primary_style
 * @property bool $primary_new_tab
 * @property string|null $secondary_label
 * @property string|null $secondary_url
 * @property ButtonStyle $secondary_style
 * @property bool $secondary_new_tab
 * @property int|null $background_media_id
 * @property string|null $background_color
 * @property ContentStatus $status
 * @property int $usage_count
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class CtaBlock extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'cta_blocks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'variant',
        'heading',
        'subheading',
        'description',
        'primary_label',
        'primary_url',
        'primary_style',
        'primary_new_tab',
        'secondary_label',
        'secondary_url',
        'secondary_style',
        'secondary_new_tab',
        'background_media_id',
        'background_color',
        'status',
        'usage_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variant' => CtaVariant::class,
            'primary_style' => ButtonStyle::class,
            'primary_new_tab' => 'boolean',
            'secondary_style' => ButtonStyle::class,
            'secondary_new_tab' => 'boolean',
            'status' => ContentStatus::class,
            'usage_count' => 'integer',
        ];
    }

    /**
     * The module that owns this model, for `Gate::before`'s module rule
     * (`App\Support\Modules::SUBJECT_MODULE_METHOD`) — the class name alone would guess `cta_blocks`.
     */
    public function moduleSlug(): string
    {
        return 'website_cta_blocks';
    }

    protected function activityModule(): ?string
    {
        return 'website_cta_blocks';
    }

    /**
     * `usage_count` is recomputed by a service, not edited by a human (§6.13).
     *
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return ['created_at', 'updated_at', 'updated_by', 'usage_count'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * May an anonymous visitor see this block? (§2.15 — status-gated, live.)
     */
    public function isPublic(): bool
    {
        return $this->status instanceof ContentStatus && $this->status->isPublic();
    }

    /**
     * Is any section still pointing at it? The delete guard of §6.13.
     */
    public function isInUse(): bool
    {
        return (int) $this->usage_count > 0;
    }

    public function hasPrimaryAction(): bool
    {
        return trim((string) $this->primary_label) !== '' && trim((string) $this->primary_url) !== '';
    }

    public function hasSecondaryAction(): bool
    {
        return trim((string) $this->secondary_label) !== '' && trim((string) $this->secondary_url) !== '';
    }

    /**
     * Background image wins over the colour — `background_color` is ignored when an image is set
     * (§2.8), and `CtaBlockService::save()` clears whichever was set earlier.
     */
    public function usesBackgroundImage(): bool
    {
        return $this->background_media_id !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function background(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'background_media_id');
    }

    /**
     * @return HasMany<WebsiteSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(WebsiteSection::class, 'cta_block_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<CtaBlock>  $query
     * @return Builder<CtaBlock>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published->value);
    }

    /**
     * What an anonymous visitor may resolve (§9). CTA blocks are live, so "visible" is the status
     * check alone — there is no snapshot column on this table (§2.15).
     *
     * @param  Builder<CtaBlock>  $query
     * @return Builder<CtaBlock>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->published();
    }

    /**
     * Admin status filter.
     *
     * @param  Builder<CtaBlock>  $query
     * @param  ContentStatus|string|array<int, ContentStatus|string>  $status
     * @return Builder<CtaBlock>
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
     * @param  Builder<CtaBlock>  $query
     * @return Builder<CtaBlock>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name')->orderBy('id');
    }

    /**
     * @param  Builder<CtaBlock>  $query
     * @return Builder<CtaBlock>
     */
    public function scopeUnused(Builder $query): Builder
    {
        return $query->where('usage_count', 0);
    }

    /**
     * @param  Builder<CtaBlock>  $query
     * @return Builder<CtaBlock>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where('name', 'like', $like)
                ->orWhere('key', 'like', $like)
                ->orWhere('heading', 'like', $like);
        });
    }
}
