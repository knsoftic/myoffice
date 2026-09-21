<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Level 1 of the outline (`course_modules`, §65, phase-14-17 §2.5).
 *
 * The tree is exactly three levels and has no `parent_id` anywhere (INV-I12): a module holds topics, a
 * topic holds lectures, and nothing holds a module but its course. That is a schema fact rather than a
 * convention, which is what stops four later phases having to cope with a depth nobody designed for.
 *
 * **Deactivate, do not delete.** A module whose topics a student has been marked against is history;
 * removing it would move that student's percentage without anybody deciding to (INV-I13).
 */
class CourseModule extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'course_modules';

    /**
     * `course_id` is absent on purpose: `CourseOutlineService` sets it from the parent the node is
     * added to, never from a request body, which is what makes a crafted POST unable to graft a module
     * onto somebody else's course.
     *
     * @var list<string>
     */
    protected $fillable = ['title', 'description', 'duration_minutes', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'sort_order' => 'integer',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'topics_count' => 'integer',
            'lectures_count' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'course_outline';
    }

    protected function activityModule(): ?string
    {
        return 'course_outline';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['title', 'sort_order', 'is_active', 'duration_minutes'];
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

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(CourseTopic::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeTopics(): HasMany
    {
        return $this->topics()->where('is_active', true);
    }
}
