<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The assignment **blueprint** of §65 (`course_topic_assignments`, phase-14-17 §2.9, [D-IN-6]).
 *
 * It is never graded and has no submissions. This row says "this topic has a practice assignment, worth
 * about these marks, taking about these hours" — curriculum, authored once, the same for every batch
 * that ever runs the course. Phase 19's `assignments` instantiates one *for a batch* with a deadline,
 * and that is where submissions, marks and feedback live (§80).
 *
 * Two tables rather than one because they have different lifetimes: a blueprint changes when the
 * syllabus is revised, an assignment changes when a teacher moves a deadline. Merged, every syllabus
 * edit would touch rows students had already submitted against.
 */
class CourseTopicAssignment extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'course_topic_assignments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title', 'description', 'instructions', 'estimated_marks', 'estimated_hours',
        'attachment_path', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_topic_id' => 'integer',
            'course_id' => 'integer',
            // decimal, because half marks are real and an integer would quietly round somebody's
            // course out of shape when Phase 19 pre-fills from it.
            'estimated_marks' => 'decimal:2',
            'estimated_hours' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
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
        return ['title', 'course_topic_id', 'estimated_marks', 'estimated_hours', 'sort_order', 'is_active'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(CourseTopic::class, 'course_topic_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
