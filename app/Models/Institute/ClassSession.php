<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\ClassCancellationReason;
use App\Enums\ClassSessionStatus;
use App\Enums\DeliveryMode;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One dated class (`class_sessions`, phase-14-17 §2.23).
 *
 * **[D-IN-10] Attendance, topic coverage and cancellations attach here, never to the weekly rule.**
 * §71 describes only the pattern, but §75 needs a register per day and §83 needs to know which class
 * covered which topic. A pure recurrence model cannot record either without lying about the past.
 *
 * **`teacher_id` is who took it; `original_teacher_id` is who was supposed to.** A substitution that
 * overwrote one column would turn "how many classes did this teacher miss" into a question with no
 * answer — which is exactly what §99 asks.
 *
 * **`attendance_marked_at` being NULL is the definition of unmarked**, and it is a timestamp rather
 * than a boolean because "when" is the part somebody follows up on.
 *
 * @property ClassSessionStatus $status
 */
class ClassSession extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'class_sessions';

    /**
     * Status, the cancellation pair, the reschedule links, the substitution and every counter move
     * through `ClassSessionService`: each one has a side effect (a notification, a recount, a linked
     * successor) that a mass-assigned form would skip.
     *
     * @var list<string>
     */
    protected $fillable = [
        'batch_id', 'timetable_entry_id', 'teacher_id', 'classroom_id', 'session_date',
        'start_time', 'end_time', 'delivery_mode', 'meeting_url', 'title',
        'course_topic_id', 'course_lecture_id', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'timetable_entry_id' => 'integer',
            'batch_id' => 'integer',
            'course_id' => 'integer',
            'teacher_id' => 'integer',
            'original_teacher_id' => 'integer',
            'classroom_id' => 'integer',
            'course_topic_id' => 'integer',
            'course_lecture_id' => 'integer',
            'rescheduled_to_id' => 'integer',
            'rescheduled_from_id' => 'integer',
            'attendance_marked_by' => 'integer',
            'status' => ClassSessionStatus::class,
            'cancellation_reason' => ClassCancellationReason::class,
            'delivery_mode' => DeliveryMode::class,
            'session_date' => 'date',
            'sequence_no' => 'integer',
            'expected_count' => 'integer',
            'present_count' => 'integer',
            'absent_count' => 'integer',
            'leave_count' => 'integer',
            'late_count' => 'integer',
            'attendance_marked_at' => 'datetime',
        ];
    }

    public function moduleSlug(): string
    {
        return 'timetable';
    }

    protected function activityModule(): ?string
    {
        return 'timetable';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'status', 'session_date', 'start_time', 'end_time', 'teacher_id', 'original_teacher_id',
            'classroom_id', 'cancellation_reason', 'cancellation_detail', 'rescheduled_to_id',
            'course_topic_id', 'attendance_marked_at',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    public function startsAt(): Carbon
    {
        return Carbon::parse($this->session_date->toDateString().' '.$this->start_time);
    }

    public function endsAt(): Carbon
    {
        return Carbon::parse($this->session_date->toDateString().' '.$this->end_time);
    }

    /** Does this class still occupy its teacher's and its room's hour? */
    public function holdsASlot(): bool
    {
        return $this->status->holdsASlot();
    }

    public function occupiesAClassroom(): bool
    {
        return $this->classroom_id !== null && $this->delivery_mode->needsClassroom();
    }

    public function wasTaughtBySubstitute(): bool
    {
        return $this->original_teacher_id !== null
            && $this->original_teacher_id !== $this->teacher_id;
    }

    public function isAttendanceMarked(): bool
    {
        return $this->attendance_marked_at !== null;
    }

    /**
     * Held, over, and still with no register — the row the daily sweep lists for somebody to finish.
     * It never fills it in: who was in the room is a fact only the person in the room has.
     */
    public function isUnmarkedPast(?Carbon $now = null): bool
    {
        return $this->status->countsInAttendance()
            && ! $this->isAttendanceMarked()
            && $this->endsAt()->lt($now ?? Carbon::now());
    }

    public function displayTitle(): string
    {
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        return $this->sequence_no !== null
            ? 'Class '.$this->sequence_no
            : $this->session_date->format('d M Y');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /** The two statuses that still hold a slot — the detector's filter, stated once. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ClassSessionStatus::Scheduled->value,
            ClassSessionStatus::Held->value,
        ]);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', ClassSessionStatus::Scheduled->value);
    }

    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('session_date', [$from->toDateString(), $to->toDateString()]);
    }

    public function scopeUnmarked(Builder $query): Builder
    {
        return $query->where('status', ClassSessionStatus::Held->value)
            ->whereNull('attendance_marked_at');
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

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TimetableEntry::class, 'timetable_entry_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function originalTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'original_teacher_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(CourseTopic::class, 'course_topic_id');
    }

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(CourseLecture::class, 'course_lecture_id');
    }

    public function rescheduledTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_to_id');
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function attendanceMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_marked_by');
    }
}
