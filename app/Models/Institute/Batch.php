<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\BatchStatus;
use App\Enums\DeliveryMode;
use App\Enums\EnrollmentStatus;
use App\Enums\Weekday;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A group of students taking one course together (`batches`, §70, phase-14-17 §2.20).
 *
 * **`current_students` is a cache with no authority (INV-I7, D48).** It is what a list screen prints
 * so a hundred rows do not become a hundred COUNT queries; it is never what a decision is made on.
 * `BatchEnrollmentService` recounts under a row lock before every seat is given out, and the nightly
 * command recounts every batch, so a cache that drifts is repaired rather than believed. Nothing
 * increments it — `BatchService::recountStudents()` is the only writer.
 *
 * **`days` + `start_time` + `end_time` are the shape of the week, not the timetable.** They prefill
 * `TimetableService::seedFromBatch()`, which expands them into one `timetable_entries` row per
 * weekday; after that the entries are the truth and these three are a convenience. A batch that meets
 * at a different hour on Saturdays says so in its entries, which these columns cannot express.
 *
 * @property BatchStatus $status
 */
class Batch extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'batches';

    /**
     * `current_students`, `sessions_held_count` and `syllabus_completion_percentage` are caches and
     * are deliberately absent: they move through the recount methods, never through a form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id', 'code', 'name', 'course_id', 'teacher_id', 'start_date', 'end_date',
        'days', 'start_time', 'end_time', 'classroom_id', 'delivery_mode', 'meeting_url',
        'student_capacity', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'course_id' => 'integer',
            'teacher_id' => 'integer',
            'classroom_id' => 'integer',
            'status' => BatchStatus::class,
            'delivery_mode' => DeliveryMode::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'completed_on' => 'date',
            'days' => 'array',
            'student_capacity' => 'integer',
            'current_students' => 'integer',
            'sessions_planned_count' => 'integer',
            'sessions_held_count' => 'integer',
            'syllabus_completion_percentage' => 'decimal:4',
        ];
    }

    public function moduleSlug(): string
    {
        return 'batches';
    }

    protected function activityModule(): ?string
    {
        return 'batches';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'code', 'name', 'course_id', 'teacher_id', 'classroom_id', 'status',
            'start_date', 'end_date', 'student_capacity', 'delivery_mode', 'cancellation_reason',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /** The weekdays this batch meets, as enum cases — `days` is stored as their string values. */
    public function weekdays(): array
    {
        $days = [];

        foreach ((array) ($this->days ?? []) as $value) {
            $day = is_string($value) ? Weekday::tryFrom($value) : null;

            if ($day !== null) {
                $days[] = $day;
            }
        }

        return $days;
    }

    public function acceptsEnrollment(): bool
    {
        return $this->status->acceptsEnrollment();
    }

    /**
     * Seats left according to the cache — good enough to grey out a button, never good enough to
     * take the decision. The service recounts.
     */
    public function seatsLeftApproximately(): int
    {
        return max(0, $this->student_capacity - $this->current_students);
    }

    public function isFullApproximately(): bool
    {
        return $this->seatsLeftApproximately() === 0;
    }

    /** Does a dated class on this day fall inside the batch's own run? */
    public function coversDate(Carbon $date): bool
    {
        if ($date->lt($this->start_date)) {
            return false;
        }

        return $this->end_date === null || $date->lte($this->end_date);
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeEnrolling(Builder $query): Builder
    {
        return $query->where('status', BatchStatus::Enrolling->value);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BatchStatus::Planned->value,
            BatchStatus::Enrolling->value,
            BatchStatus::Running->value,
        ]);
    }

    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($branchId): void {
            $q->where('branch_id', $branchId)->orWhereNull('branch_id');
        });
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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentBatchEnrollment::class);
    }

    /** The roster as of right now; `BatchEnrollmentService::roster()` answers it for a past date. */
    public function activeEnrollments(): HasMany
    {
        return $this->hasMany(StudentBatchEnrollment::class)
            ->where('status', EnrollmentStatus::Active->value);
    }

    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }
}
