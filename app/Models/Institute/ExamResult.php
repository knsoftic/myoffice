<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\ExamAttendanceStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * One student's mark on one exam (phase-19-23 §2.12, requirement §82).
 *
 * **INV-20-1: `total_marks` is a snapshot**, which is what lets `chk_er_marks` compare two columns of
 * the same row. A mark above what the paper was out of is therefore impossible rather than merely
 * validated against — and editing the exam later cannot retroactively invalidate a mark already given.
 *
 * **The grade is a snapshot too.** `grade`, `grade_point` and `percentage` are this row's own columns
 * rather than a join, so renaming a band from `A` to `A1` next year cannot rewrite what a student was
 * told, and deactivating a scale cannot blank a card that was printed and handed over.
 *
 * **INV-20-2: four columns are written only by `ResultCalculator`.** `percentage`, `grade`,
 * `grade_point` and `is_passed` are absent from `$fillable` and refused by the hook below unless the
 * service is on the stack. A controller that could set `is_passed` could pass a student who failed,
 * and nothing downstream would ever notice.
 *
 * **Nothing deletes a result.** The policy refuses it for every role, and the hook refuses it for
 * everyone including a Super Admin — because `Gate::before` runs before any policy (D124). A wrong row
 * is amended with a reason (INV-20-5).
 *
 * @property ExamAttendanceStatus $attendance_status
 * @property string $total_marks
 */
class ExamResult extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /** INV-20-2: computed, never posted. */
    private const CALCULATED = ['percentage', 'grade', 'grade_point', 'grade_scale_band_id', 'is_passed'];

    /**
     * Set by `ExamResultService` for the duration of a write it owns, so the hook below can tell a
     * calculated write from a hand-rolled one. A public flag on the model is cruder than a container
     * binding and far easier to read at the call site — and it is deliberately not a setter anything
     * outside the service has a reason to touch.
     */
    public static bool $withinCalculator = false;

    protected $table = 'exam_results';

    /**
     * A marker sets the attendance, the marks and a remark. Everything derived from them is computed.
     *
     * @var list<string>
     */
    protected $fillable = [
        'attendance_status',
        'obtained_marks',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exam_id' => 'integer',
            'student_id' => 'integer',
            'student_batch_enrollment_id' => 'integer',
            'batch_id' => 'integer',
            'course_id' => 'integer',
            'attendance_status' => ExamAttendanceStatus::class,
            'obtained_marks' => 'decimal:2',
            'total_marks' => 'decimal:2',
            'percentage' => 'decimal:4',
            'grade_scale_id' => 'integer',
            'grade_scale_band_id' => 'integer',
            'grade_point' => 'decimal:2',
            'is_passed' => 'boolean',
            'position_in_batch' => 'integer',
            'entered_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'amended_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // INV-20-2. The guard is on the *write*, not on the property: a value that came from the
        // calculator is identical to one somebody typed, so the only thing that distinguishes them
        // is which code path produced it.
        static::saving(static function (self $result): void {
            if (self::$withinCalculator) {
                return;
            }

            $computed = array_intersect(array_keys($result->getDirty()), self::CALCULATED);

            if ($computed !== []) {
                throw new LogicException(sprintf(
                    '%s %s written by ResultCalculator through ExamResultService (INV-20-2). A grade '
                    .'that can be typed is a grade that can be typed wrong.',
                    implode(' and ', $computed),
                    count($computed) === 1 ? 'is only' : 'are only',
                ));
            }
        });

        static::deleting(static function (self $result): never {
            throw new LogicException(sprintf(
                'Result #%s is never deleted (INV-20-5). Amend it with a reason — a mark somebody was '
                .'given is part of their record even when it was wrong.',
                (string) $result->getKey(),
            ));
        });
    }

    /**
     * Run a write that is allowed to set the calculated columns.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function calculated(callable $callback): mixed
    {
        self::$withinCalculator = true;

        try {
            return $callback();
        } finally {
            self::$withinCalculator = false;
        }
    }

    public function moduleSlug(): string
    {
        return 'results';
    }

    protected function activityModule(): ?string
    {
        return 'results';
    }

    public function isPublished(): bool
    {
        return $this->getAttribute('published_at') !== null;
    }

    /** A mark changed after the student had already seen it — always with a reason (INV-20-5). */
    public function wasAmended(): bool
    {
        return $this->getAttribute('amended_at') !== null;
    }

    /** They sat it and have a mark. An absence is not a zero (§2.12). */
    public function wasAssessed(): bool
    {
        return $this->attendance_status->requiresMarks() && $this->getAttribute('obtained_marks') !== null;
    }

    /**
     * A result the calculator could not grade — it has a percentage but no band matched.
     *
     * INV-20-3 makes this unreachable for a valid scale, so the marking screen surfaces it rather than
     * swallowing it: a row like this means a scale has a hole, and that is worth somebody's attention
     * before thirty more are entered against it.
     */
    public function isUngraded(): bool
    {
        return $this->getAttribute('percentage') !== null && $this->getAttribute('grade') === null;
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /** The roster proof: this student was on this batch when they sat it. */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentBatchEnrollment::class, 'student_batch_enrollment_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class, 'grade_scale_id');
    }

    public function band(): BelongsTo
    {
        return $this->belongsTo(GradeScaleBand::class, 'grade_scale_band_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function amender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /** Counted in the class figures — everything except an exemption. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('attendance_status', '!=', ExamAttendanceStatus::Exempt->value);
    }
}
