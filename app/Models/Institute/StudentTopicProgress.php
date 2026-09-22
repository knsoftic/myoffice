<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\ProgressSource;
use App\Enums\ProgressStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The only hand-marked grain (`student_topic_progress`, §83, §2.28).
 *
 * **`source` is a shield.** Marking a topic covered for a batch fans out to every active enrolment —
 * except rows whose source is `manual`. A teacher who recorded that one student has not grasped
 * something the rest of the class finished must not have that judgement silently overwritten the next
 * time the class-level mark runs, and `isProtectedFromBatchMark()` is where the fan-out asks.
 *
 * No soft deletes ([D-IN-2]): there is one row per (progress, topic) under a unique index, and a
 * soft-deleted one would sit under it and turn the next mark into a 1062 nobody could explain. A topic
 * that should no longer count is `skipped`, which removes its weight from both sides.
 *
 * @property ProgressStatus $status
 * @property ProgressSource $source
 */
class StudentTopicProgress extends Model
{
    protected $table = 'student_topic_progress';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_course_progress_id' => 'integer',
            'course_topic_id' => 'integer',
            'course_module_id' => 'integer',
            'marked_by' => 'integer',
            'status' => ProgressStatus::class,
            'source' => ProgressSource::class,
            'completion_percentage' => 'decimal:4',
            'marked_at' => 'datetime',
            'completed_on' => 'date',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What the fan-out asks
    |--------------------------------------------------------------------------
    */

    /** Would a class-level mark overwrite this row? Not if a person set it for this student. */
    public function isProtectedFromBatchMark(): bool
    {
        return ! $this->source->isOverwritableByBatch();
    }

    public function countsInDenominator(): bool
    {
        return $this->status->countsInDenominator();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeCounting(Builder $query): Builder
    {
        return $query->where('status', '!=', ProgressStatus::Skipped->value);
    }

    public function scopeHandMarked(Builder $query): Builder
    {
        return $query->where('source', ProgressSource::Manual->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function courseProgress(): BelongsTo
    {
        return $this->belongsTo(StudentCourseProgress::class, 'student_course_progress_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(CourseTopic::class, 'course_topic_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
