<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\DeliveryMode;
use App\Enums\ExamStatus;
use App\Enums\ExamType;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A scheduled assessment (phase-19-23 §2.11, requirement §81).
 *
 * **Publication freezes four columns.** Once `results_published_at` is set, `total_marks`,
 * `passing_marks`, `grade_scale_id` and `scheduled_date` cannot move — every one of them is an input
 * to a result card that has already been printed and handed over, and a card that cannot be reproduced
 * is not a record. A genuine correction is `unpublish()` with a reason, then an amendment.
 *
 * **The freeze lives here rather than in a policy**, because `Gate::before` allows a Super Admin
 * everything before a policy runs — the lesson D124 cost Phase 19 a pair of tests to learn. A rule
 * that restates what a whole class was measured against should not depend on who is asking.
 *
 * **`active_guard` is NULL for a cancelled exam**, which frees the batch's slot in `uq_ex_batch_slot`
 * without deleting anything. A cancelled exam keeps its date, its reason and any results already
 * entered, because "what happened to the exam I sat" is a question a student asks and a row that
 * reverted to draft answers it with silence.
 *
 * @property ExamStatus $status
 * @property ExamType $exam_type
 * @property string $total_marks
 */
class Exam extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /** Frozen the moment results are published. See the class note. */
    private const FROZEN_ONCE_PUBLISHED = ['total_marks', 'passing_marks', 'grade_scale_id', 'scheduled_date'];

    protected $table = 'exams';

    /**
     * Every cache column and every timestamp is absent: a request that could set `passed_count` could
     * make an exam claim a result nobody sat.
     *
     * @var list<string>
     */
    protected $fillable = [
        'course_topic_id',
        'teacher_id',
        'classroom_id',
        'exam_type',
        'name',
        'delivery_mode',
        'meeting_url',
        'scheduled_date',
        'start_time',
        'end_time',
        'duration_minutes',
        'total_marks',
        'passing_marks',
        'weight_percentage',
        'grade_scale_id',
        'instructions',
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
            'course_topic_id' => 'integer',
            'teacher_id' => 'integer',
            'classroom_id' => 'integer',
            'grade_scale_id' => 'integer',
            'exam_type' => ExamType::class,
            'delivery_mode' => DeliveryMode::class,
            'scheduled_date' => 'immutable_date',
            'duration_minutes' => 'integer',
            'total_marks' => 'decimal:2',
            'passing_marks' => 'decimal:2',
            'weight_percentage' => 'decimal:4',
            'status' => ExamStatus::class,
            'results_published_at' => 'immutable_datetime',
            'results_verified_at' => 'immutable_datetime',
            'expected_count' => 'integer',
            'results_entered_count' => 'integer',
            'appeared_count' => 'integer',
            'absent_count' => 'integer',
            'passed_count' => 'integer',
            'failed_count' => 'integer',
            'highest_marks' => 'decimal:2',
            'lowest_marks' => 'decimal:2',
            'average_marks' => 'decimal:2',
            'average_percentage' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $exam): void {
            $frozen = array_intersect(array_keys($exam->getDirty()), self::FROZEN_ONCE_PUBLISHED);

            if ($frozen === [] || $exam->getOriginal('results_published_at') === null) {
                return;
            }

            throw new LogicException(sprintf(
                'Exam #%s has published results, so %s cannot be changed here. Unpublish it with a '
                .'reason first — a result card that was handed over has to stay reproducible.',
                (string) $exam->getKey(),
                implode(' and ', $frozen),
            ));
        });

        // An exam with a result is cancelled, never removed — and a soft delete is an UPDATE that
        // `restrictOnDelete` never sees (D124).
        static::deleting(static function (self $exam): void {
            if ($exam->results()->exists()) {
                throw new LogicException(sprintf(
                    'Exam #%s has results against it. Cancel it with a reason instead — removing it '
                    .'would take the marks with it.',
                    (string) $exam->getKey(),
                ));
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'exams';
    }

    protected function activityModule(): ?string
    {
        return 'exams';
    }

    public function isPublished(): bool
    {
        return $this->getAttribute('results_published_at') !== null;
    }

    /** Marks may be entered: the exam has happened and has not been published or called off. */
    public function acceptsResultEntry(): bool
    {
        return $this->status->acceptsResultEntry() && ! $this->trashed();
    }

    /** `active_guard` is generated from `status`, so this reads it rather than re-deriving it. */
    public function holdsItsSlot(): bool
    {
        return $this->getAttribute('active_guard') !== null;
    }

    /** When it starts, as an instant — `scheduled_date` is a calendar date and `start_time` a clock. */
    public function startsAt(): ?Carbon
    {
        $time = $this->getAttribute('start_time');

        if ($time === null) {
            return null;
        }

        return Carbon::parse($this->scheduled_date->format('Y-m-d').' '.$time);
    }

    /** The scale this exam grades against, or null to fall back to the institute's default. */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class, 'grade_scale_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(CourseTopic::class, 'course_topic_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'results_published_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'results_verified_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class, 'exam_id');
    }

    /** Everything a student may see: published only (§3.2). */
    public function scopeVisibleToStudents(Builder $query): Builder
    {
        return $query->where('status', ExamStatus::ResultsPublished->value);
    }

    /** The calendar: everything real, in date order. A draft and a cancellation are neither. */
    public function scopeOnTheCalendar(Builder $query): Builder
    {
        return $query
            ->whereNotIn('status', [ExamStatus::Draft->value, ExamStatus::Cancelled->value])
            ->orderBy('scheduled_date');
    }
}
