<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\ProgressStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This batch covered this topic on this date" (`batch_topic_coverage`, §83, phase-14-17 §2.25).
 *
 * **The class-level answer, from which the student-level ones are derived.** A coordinator marks a
 * topic covered for the batch and it fans out to every active enrolment — except the rows somebody
 * set by hand, which `ProgressSource::Manual` protects. So this table is what a teacher actually
 * touches, and `student_topic_progress` is what a student's report reads.
 *
 * **An upsert cache keyed by its unique index, so no soft deletes ([D-IN-2]).** There is exactly one
 * row per (batch, topic) and marking the same topic again overwrites it; a soft-deleted row would sit
 * under that unique index and make the next mark a 1062 nobody could explain.
 *
 * `course_module_id` is denormalised so the module roll-up is one grouped scan rather than a join
 * through `course_topics` on every recompute.
 *
 * @property ProgressStatus $status
 */
class BatchTopicCoverage extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    protected $table = 'batch_topic_coverage';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'status', 'completion_percentage', 'covered_on', 'class_session_id', 'teacher_id', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'course_topic_id' => 'integer',
            'course_module_id' => 'integer',
            'class_session_id' => 'integer',
            'teacher_id' => 'integer',
            'status' => ProgressStatus::class,
            'completion_percentage' => 'decimal:4',
            'covered_on' => 'date',
        ];
    }

    public function moduleSlug(): string
    {
        return 'student_progress';
    }

    protected function activityModule(): ?string
    {
        return 'student_progress';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['status', 'completion_percentage', 'covered_on', 'class_session_id', 'teacher_id'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /** Does this topic's weight belong in the batch's syllabus denominator? A skipped one does not. */
    public function countsInDenominator(): bool
    {
        return $this->status->countsInDenominator();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeCovered(Builder $query): Builder
    {
        return $query->where('status', ProgressStatus::Completed->value);
    }

    public function scopeCounting(Builder $query): Builder
    {
        return $query->where('status', '!=', ProgressStatus::Skipped->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(CourseTopic::class, 'course_topic_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
