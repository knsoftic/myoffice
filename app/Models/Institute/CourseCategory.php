<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The catalogue's top level (`course_categories`, §64, phase-14-17 §2.3).
 *
 * **Flat by design.** There is no `parent_id`: §64 asks for a reorderable list, and a tree would buy one
 * screen a nesting nobody asked for while every catalogue query, breadcrumb and filter in four phases
 * gained a recursion.
 *
 * **Switching a category off hides it; it does not retire its courses.** A disabled category disappears
 * from the public site and from the course form, and every course inside it keeps the status it had —
 * unless the admin explicitly asks for the cascade, which moves published courses to draft and logs each
 * one. Two facts, two switches, the same discipline Phase 2 applies to modules.
 *
 * `courses_count` is a **cache**. It is rewritten by `CourseCategoryService::recount()` and no screen
 * may treat it as truth; the number that decides whether a category can be deleted is a live count.
 *
 * @property bool $is_active
 */
class CourseCategory extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'course_categories';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name', 'slug', 'description', 'icon', 'image_path',
        'is_active', 'sort_order', 'seo_title', 'seo_description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'courses_count' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'course_categories';
    }

    protected function activityModule(): ?string
    {
        return 'course_categories';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['name', 'slug', 'description', 'icon', 'is_active', 'sort_order'];
    }

    /**
     * The URL segment, so a route-model binding reads the slug rather than an id nobody can check.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /**
     * Is anything filed under it right now?
     *
     * A **live** count, deliberately not `courses_count`: the cache answers "how many did we last
     * think", and a delete guard has to answer "how many are there". The FK refuses the delete either
     * way; this is what lets the screen say why before the database has to.
     */
    public function isInUse(): bool
    {
        return $this->courses()->withTrashed()->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The order the catalogue and the admin list both use, so they never disagree about what is first.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $inner) => $inner
            ->where('name', 'like', '%'.$term.'%')
            ->orWhere('slug', 'like', '%'.$term.'%'));
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'course_category_id');
    }

    /**
     * Only what the public may see — used by the catalogue and the category page.
     */
    public function publishedCourses(): HasMany
    {
        return $this->courses()->published();
    }
}
