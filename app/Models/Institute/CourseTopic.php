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
 * Level 2 of the outline, and the unit everything downstream actually counts
 * (`course_topics`, §65, phase-14-17 §2.6).
 *
 * A topic is what a teacher ticks off as covered, what a student's progress is measured against and what
 * a dated class session is taught from — so it is the row three later phases join to. That is why
 * `course_id` is denormalised here: "how far through this course is this student" is the commonest
 * question in the institute, and making it walk two joins to reach the course would make the commonest
 * query the most expensive one. `CourseOutlineService` is the only writer and asserts it matches the
 * module's course.
 *
 * **`weight` is why a percentage means something.** A 40-hour project topic and a 20-minute revision
 * topic both count as "one topic" without it, and a student who finished the project would read as
 * equally far along as one who sat through the recap. 1 means "like any other"; the CHECK caps it at
 * 100 so a weighting stays a weighting rather than drowning the denominator.
 */
class CourseTopic extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'course_topics';

    /**
     * Neither FK is fillable: both are set from the parent by `CourseOutlineService`.
     *
     * @var list<string>
     */
    protected $fillable = ['title', 'description', 'weight', 'estimated_minutes', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_module_id' => 'integer',
            'course_id' => 'integer',
            'sort_order' => 'integer',
            'weight' => 'integer',
            'estimated_minutes' => 'integer',
            'is_active' => 'boolean',
            'lectures_count' => 'integer',
            'resources_count' => 'integer',
            'assignments_count' => 'integer',
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
     * `weight` is logged because changing it moves every student's percentage on that course, which is
     * a decision somebody will be asked to explain.
     *
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['title', 'course_module_id', 'sort_order', 'weight', 'estimated_minutes', 'is_active'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /**
     * Is any later-phase row pointing at this topic?
     *
     * Coverage, progress and a taught session are the three things that make a topic history rather
     * than a draft. The tables belong to phases 16 and 17, so each is checked only if it exists — which
     * is what lets this run correctly before those phases ship and keep working after.
     *
     * @return array<string, int>  table => how many rows reference it
     */
    public function references(): array
    {
        $found = [];

        foreach ([
            'batch_topic_coverage' => 'course_topic_id',
            'student_topic_progress' => 'course_topic_id',
            'class_sessions' => 'course_topic_id',
        ] as $table => $column) {
            if (! app('db')->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $count = app('db')->table($table)->where($column, $this->getKey())->count();

            if ($count > 0) {
                $found[$table] = $count;
            }
        }

        return $found;
    }

    public function isReferenced(): bool
    {
        return $this->references() !== [];
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

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lectures(): HasMany
    {
        return $this->hasMany(CourseLecture::class)->orderBy('sort_order')->orderBy('id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(CourseTopicResource::class)->orderBy('sort_order')->orderBy('id');
    }

    public function assignmentBlueprints(): HasMany
    {
        return $this->hasMany(CourseTopicAssignment::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * What a visitor may see listed on the landing page, before enrolling.
     */
    public function publicResources(): HasMany
    {
        return $this->resources()->where('is_public', true);
    }
}
