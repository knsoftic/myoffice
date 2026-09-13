<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ContentStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One question and answer (phase-03 §2.10), optionally attached to another model.
 *
 * **Status-gated and live** (§2.15): a `draft` FAQ simply never renders; there is no snapshot column.
 * A FAQ section folds the resolved answers into its own `published_content` at publish time, which is
 * what keeps a half-written answer off a published page.
 *
 * `faqable_*` is shared CMS infrastructure (§1.3, F-2.2): course FAQs (§90) are rows here with
 * `faqable_type = App\Models\Institute\Course`, written by the owning phase's service — **no
 * `course_faqs` table exists**. Phase 3 leaves the morph NULL, and the CMS FAQ form never posts it;
 * `FaqPolicy` defers an attached FAQ to its owner's module.
 *
 * `answer` is sanitized rich text (INV-13): `RichText::sanitize()` on write and again on render.
 *
 * @property int $id
 * @property int|null $faq_category_id
 * @property string|null $faqable_type
 * @property int|null $faqable_id
 * @property string $question
 * @property string $answer
 * @property ContentStatus $status
 * @property bool $is_featured
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Faq extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'faqs';

    /**
     * `faqable_type` / `faqable_id` stay writable for the owning phase's service; no CMS Form Request
     * accepts them (§6.13).
     *
     * @var list<string>
     */
    protected $fillable = [
        'faq_category_id',
        'faqable_type',
        'faqable_id',
        'question',
        'answer',
        'status',
        'is_featured',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'faq_category_id' => 'integer',
            'faqable_id' => 'integer',
            'status' => ContentStatus::class,
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'faqs';
    }

    protected function activityModule(): ?string
    {
        return 'faqs';
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * May an anonymous visitor see this FAQ? (§2.15 — status-gated, live.)
     */
    public function isPublic(): bool
    {
        return $this->status instanceof ContentStatus && $this->status->isPublic();
    }

    public function isFeatured(): bool
    {
        return (bool) $this->is_featured;
    }

    /**
     * Attached to another model (a course FAQ, Phase 14-17) rather than a standalone CMS FAQ.
     */
    public function isAttached(): bool
    {
        return $this->faqable_type !== null && $this->faqable_type !== '';
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<FaqCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'faq_category_id');
    }

    /**
     * The course (§90) or other owner. Unused in Phase 3.
     *
     * @return MorphTo<Model, $this>
     */
    public function faqable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The FAQ sections this question was hand-picked into (`content.source = selected`).
     *
     * @return BelongsToMany<WebsiteSection, $this, FaqWebsiteSection>
     */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(WebsiteSection::class, 'faq_website_section', 'faq_id', 'website_section_id')
            ->using(FaqWebsiteSection::class)
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * FAQs are live, so "published" is the status alone — there is no draft copy to hide (§2.15).
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ContentStatus::Published->value);
    }

    /**
     * What an anonymous visitor may see (§9): published, and either uncategorised or in an enabled,
     * non-trashed category.
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->published()->where(function (Builder $builder): void {
            $builder->whereNull($builder->qualifyColumn('faq_category_id'))
                ->orWhereHas('category', function (Builder $category): void {
                    $category->enabled();
                });
        });
    }

    /**
     * Admin status filter.
     *
     * @param  Builder<Faq>  $query
     * @param  ContentStatus|string|array<int, ContentStatus|string>  $status
     * @return Builder<Faq>
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
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_featured'), true);
    }

    /**
     * One category, or the uncategorised bucket when null (§6.13 reorder scope).
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeInCategory(Builder $query, FaqCategory|int|null $category): Builder
    {
        if ($category === null) {
            return $query->whereNull($query->qualifyColumn('faq_category_id'));
        }

        return $query->where(
            $query->qualifyColumn('faq_category_id'),
            $category instanceof FaqCategory ? $category->getKey() : $category
        );
    }

    /**
     * The FAQ section's `category` source stores the category **slug** (a scalar, INV-3).
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeInCategorySlug(Builder $query, string $slug): Builder
    {
        return $query->whereHas('category', function (Builder $category) use ($slug): void {
            $category->where($category->qualifyColumn('slug'), $slug);
        });
    }

    /**
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeUncategorised(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('faq_category_id'));
    }

    /**
     * Standalone CMS FAQs — not attached to a course or other owner.
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeStandalone(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('faqable_type'));
    }

    /**
     * The §8.11 "attached to a course" filter — empty until Phase 14.
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeAttached(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('faqable_type'));
    }

    /**
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeForFaqable(Builder $query, Model $owner): Builder
    {
        return $query->where($query->qualifyColumn('faqable_type'), $owner->getMorphClass())
            ->where($query->qualifyColumn('faqable_id'), $owner->getKey());
    }

    /**
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('sort_order'))->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Search across question and answer (§8.11).
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where($builder->qualifyColumn('question'), 'like', $like)
                ->orWhere($builder->qualifyColumn('answer'), 'like', $like);
        });
    }
}
