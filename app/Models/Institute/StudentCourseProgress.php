<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\ProgressStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How far one student has got through one course (`student_course_progress`, §83, §2.26).
 *
 * **The grain is the enrolment, not the student.** A student who takes the same course twice — a
 * repeat, a transfer to a different batch of the same course — has two progress rows, because the
 * second attempt does not start where the first left off. `uq_scp_enrollment` is what says so.
 *
 * **Every number here is a cache (INV-I11).** `CourseProgressService::recompute()` is the only writer;
 * the four counters and the two weight totals are re-derivable from the topic rows, and
 * `progress:recompute` proves it nightly. Nothing else may write a percentage — not a controller, not
 * a listener, not a screen.
 *
 * **The outline snapshot is deliberate.** `modules_total`, `topics_total` and `weight_total` record
 * the outline as it stood at the last recompute, so a report can say "9 of 12 topics" without
 * re-reading a curriculum that may have changed since — and the nightly recompute is what brings the
 * snapshot forward when it has.
 *
 * @property ProgressStatus $status
 */
class StudentCourseProgress extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'student_course_progress';

    /**
     * Nothing is fillable. Every column is either the grain, a denormalised key or a computed cache,
     * and a mass-assigned percentage is exactly the second writer INV-I11 exists to forbid.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_batch_enrollment_id' => 'integer',
            'student_id' => 'integer',
            'course_id' => 'integer',
            'batch_id' => 'integer',
            'status' => ProgressStatus::class,
            'completion_percentage' => 'decimal:4',
            'modules_total' => 'integer',
            'modules_completed' => 'integer',
            'topics_total' => 'integer',
            'topics_completed' => 'integer',
            'weight_total' => 'integer',
            'weight_completed' => 'integer',
            'started_on' => 'date',
            'completed_on' => 'date',
            'last_activity_at' => 'datetime',
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
        return ['status', 'completion_percentage', 'topics_completed', 'completed_on'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /** "9 of 12 topics" — the sentence under every progress ring. */
    public function topicsCaption(): string
    {
        return app_number($this->topics_completed).' of '.app_number($this->topics_total)
            .' '.($this->topics_total === 1 ? 'topic' : 'topics');
    }

    public function isComplete(): bool
    {
        return $this->status->isDone();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /** Below the line somebody should look at — the report the §83 screen leads with. */
    public function scopeBelow(Builder $query, float $percentage): Builder
    {
        return $query->where('completion_percentage', '<', $percentage);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentBatchEnrollment::class, 'student_batch_enrollment_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function moduleProgress(): HasMany
    {
        return $this->hasMany(StudentModuleProgress::class, 'student_course_progress_id');
    }

    public function topicProgress(): HasMany
    {
        return $this->hasMany(StudentTopicProgress::class, 'student_course_progress_id');
    }
}
