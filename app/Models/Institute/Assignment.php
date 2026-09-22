<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\AssignmentStatus;
use App\Enums\SubmissionType;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The gradable event (phase-19-23 §2.6, requirement §80).
 *
 * Instantiated from Phase 14's `CourseTopicAssignment` blueprint when one exists — the blueprint says
 * what the syllabus intends, this row says what a batch was actually set, and the two diverge the moment
 * a teacher moves a deadline.
 *
 * **A deadline and a cutoff are different things.** `deadline_at` is when work becomes late;
 * `late_cutoff_at` is when it stops being accepted. Without both, the only choices would be "no late
 * work ever" and "late work for ever", and §80 asks for neither.
 *
 * **`total_marks`, `deadline_at` and `submission_type` freeze once anything has been graded** — the
 * hook below refuses the change rather than leaving it to a screen to remember. Marks already given were
 * given out of a particular total, against a particular deadline; changing either afterwards would
 * silently restate what somebody was judged on. A genuine correction goes through
 * `AssignmentService::amend()`, which takes a reason, logs old and new, and recomputes the caches while
 * INV-19-7 keeps existing lateness decisions intact.
 *
 * **An assignment with a submission is closed or archived, never deleted** (§2.7): `restrictOnDelete`
 * backs it at the database, and `AssignmentStatus::Closed` exists precisely so that stopping collection
 * never means hiding what everybody was marked on.
 *
 * @property AssignmentStatus $status
 * @property SubmissionType $submission_type
 * @property string $total_marks
 */
class Assignment extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /** Frozen the moment a graded submission exists. See the class note. */
    private const FROZEN_ONCE_GRADED = ['total_marks', 'deadline_at', 'submission_type'];

    protected $table = 'assignments';

    /**
     * The attachment columns are absent: they are decided by `SecureFileService` from the bytes. So
     * are every cache column and every timestamp — a request that could set `submitted_count` could
     * make an assignment claim a participation it never had.
     *
     * @var list<string>
     */
    protected $fillable = [
        'course_topic_assignment_id',
        'course_topic_id',
        'teacher_id',
        'title',
        'description',
        'instructions',
        'total_marks',
        'passing_marks',
        'submission_type',
        'allowed_extensions',
        'max_file_size_mb',
        'max_files',
        'assigned_on',
        'deadline_at',
        'late_submission_allowed',
        'late_cutoff_at',
        'late_penalty_percentage',
        'allow_resubmission',
        'max_attempts',
        'marks_visible_to_students',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'course_id' => 'integer',
            'batch_id' => 'integer',
            'course_topic_assignment_id' => 'integer',
            'course_topic_id' => 'integer',
            'teacher_id' => 'integer',
            'attachment_size_bytes' => 'integer',
            'total_marks' => 'decimal:2',
            'passing_marks' => 'decimal:2',
            'submission_type' => SubmissionType::class,
            'allowed_extensions' => 'array',
            'max_file_size_mb' => 'integer',
            'max_files' => 'integer',
            'assigned_on' => 'immutable_date',
            'deadline_at' => 'immutable_datetime',
            'late_submission_allowed' => 'boolean',
            'late_cutoff_at' => 'immutable_datetime',
            'late_penalty_percentage' => 'decimal:4',
            'allow_resubmission' => 'boolean',
            'max_attempts' => 'integer',
            'marks_visible_to_students' => 'boolean',
            'status' => AssignmentStatus::class,
            'published_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'expected_count' => 'integer',
            'submitted_count' => 'integer',
            'late_count' => 'integer',
            'graded_count' => 'integer',
            'missed_count' => 'integer',
            'average_marks' => 'decimal:2',
            'highest_marks' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Gate::before lets a Super Admin past every policy, so a rule this consequential cannot live
        // in one — it lives here, where nobody's permissions reach it.
        static::updating(static function (self $assignment): void {
            $frozen = array_intersect(array_keys($assignment->getDirty()), self::FROZEN_ONCE_GRADED);

            if ($frozen === [] || ! $assignment->hasGradedWork()) {
                return;
            }

            throw new LogicException(sprintf(
                'Assignment #%s has marked work, so %s cannot be changed here. Use the amend screen, '
                .'which records why and recomputes what depends on it.',
                (string) $assignment->getKey(),
                implode(' and ', $frozen),
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'assignments';
    }

    protected function activityModule(): ?string
    {
        return 'assignments';
    }

    /** Has anything been marked? The freeze above turns on this, so it asks the table, not a cache. */
    public function hasGradedWork(): bool
    {
        return $this->submissions()->whereNotNull('graded_at')->exists();
    }

    /** Past the deadline — which is not the same as closed, and not the same as past the cutoff. */
    public function isLateAt(Carbon $at): bool
    {
        return $at->greaterThan($this->deadline_at);
    }

    /**
     * Whether a submission may be created right now: published, and either inside the deadline or
     * inside whatever lateness the assignment still allows.
     */
    public function acceptsSubmissionAt(Carbon $at): bool
    {
        if (! $this->status->acceptsSubmissions() || $this->trashed()) {
            return false;
        }

        if (! $this->isLateAt($at)) {
            return true;
        }

        if (! $this->getAttribute('late_submission_allowed')) {
            return false;
        }

        $cutoff = $this->getAttribute('late_cutoff_at');

        return $cutoff === null || $at->lessThanOrEqualTo($cutoff);
    }

    /** Minutes past the deadline, or null when it is not late. Decided once, at insert (INV-19-7). */
    public function minutesLateAt(Carbon $at): ?int
    {
        return $this->isLateAt($at) ? (int) $this->deadline_at->diffInMinutes($at) : null;
    }

    public function hasPassLine(): bool
    {
        return $this->getAttribute('passing_marks') !== null;
    }

    public function hasBrief(): bool
    {
        return $this->getAttribute('attachment_path') !== null;
    }

    /** Everything a student may see: published or closed, and not trashed. */
    public function scopeVisibleToStudents(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AssignmentStatus::Published->value,
            AssignmentStatus::Closed->value,
        ]);
    }

    /** The sweeper's query: published, deadline passed, still collecting. */
    public function scopeOverdueBy(Builder $query, Carbon $at): Builder
    {
        return $query
            ->where('status', AssignmentStatus::Published->value)
            ->where('deadline_at', '<', $at);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(CourseTopicAssignment::class, 'course_topic_assignment_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(CourseTopic::class, 'course_topic_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class, 'assignment_id');
    }

    /** The live ones only — one per student (INV-19-5), superseded attempts excluded. */
    public function liveSubmissions(): HasMany
    {
        return $this->submissions()->whereNotNull('current_guard');
    }
}
