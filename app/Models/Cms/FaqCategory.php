<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One FAQ group (phase-03 §2.9): General, Courses, Admissions.
 *
 * Status-gated and live (§2.15) through `is_enabled`. `slug` is uniquely indexed (`uq_faqcat_slug`)
 * and is what a FAQ section's `category` source stores — a scalar, never an id in JSON (INV-3).
 * Deleting a category (hard delete) sets `faqs.faq_category_id` to NULL; the UI only soft-deletes.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property bool $is_enabled
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class FaqCategory extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'faq_categories';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'is_enabled',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'faq_categories';
    }

    protected function activityModule(): ?string
    {
        return 'faq_categories';
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

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return HasMany<Faq, $this>
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class, 'faq_category_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<FaqCategory>  $query
     * @return Builder<FaqCategory>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_enabled'), true);
    }

    /**
     * What an anonymous visitor may see (§9). A category has no `status`: enabled is the whole gate.
     *
     * @param  Builder<FaqCategory>  $query
     * @return Builder<FaqCategory>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->enabled();
    }

    /**
     * @param  Builder<FaqCategory>  $query
     * @return Builder<FaqCategory>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * @param  Builder<FaqCategory>  $query
     * @return Builder<FaqCategory>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where($builder->qualifyColumn('name'), 'like', $like)
                ->orWhere($builder->qualifyColumn('slug'), 'like', $like)
                ->orWhere($builder->qualifyColumn('description'), 'like', $like);
        });
    }
}
