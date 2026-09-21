<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\DeliveryMode;
use App\Enums\DemoClassStatus;
use App\Enums\DemoSubjectType;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A trial class somebody is booked into (`demo_classes`, §87, phase-14-17 §2.16).
 *
 * **A demo is a real booking.** It holds a teacher and a room for an hour exactly as a class does, so
 * it takes part in clash detection — a demo that could be dropped into a room a batch is already using
 * would be discovered by two groups arriving at the same door. The two `active_guard` unique indexes
 * are the backstop underneath Phase 16's `ScheduleClashDetector`: the detector explains the overlap in
 * words, the index makes the identical double-booking impossible even under a race.
 *
 * **Exactly one subject, and `subject_type` says which.** A demo is for an enquiry, an applicant or an
 * existing student, never two; `chk_dc_one_subject` enforces the count and `chk_dc_subject_matches`
 * keeps the type column honest about which key is filled. The alternative — inferring the subject from
 * three `IS NOT NULL` tests — puts the same three-way branch in every query and report.
 *
 * **`attendee_name` and `attendee_phone` are snapshots.** The slip a receptionist prints says who is
 * coming; the enquiry behind it may be converted, merged or renamed by then, and a printed document
 * that changes its own name afterwards is one nobody can rely on.
 *
 * @property DemoClassStatus $status
 */
class DemoClass extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'demo_classes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id', 'course_id', 'batch_id', 'teacher_id', 'classroom_id',
        'delivery_mode', 'meeting_url', 'scheduled_on', 'start_time', 'end_time',
        'attendance_remarks', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'course_inquiry_id' => 'integer',
            'student_application_id' => 'integer',
            'student_id' => 'integer',
            'course_id' => 'integer',
            'batch_id' => 'integer',
            'teacher_id' => 'integer',
            'classroom_id' => 'integer',
            'converted_admission_id' => 'integer',
            'subject_type' => DemoSubjectType::class,
            'status' => DemoClassStatus::class,
            'delivery_mode' => DeliveryMode::class,
            'scheduled_on' => 'date',
            'attended_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    public function moduleSlug(): string
    {
        return 'demo_classes';
    }

    protected function activityModule(): ?string
    {
        return 'demo_classes';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'status', 'scheduled_on', 'start_time', 'end_time', 'teacher_id', 'classroom_id',
            'delivery_mode', 'cancellation_reason', 'converted_admission_id',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /** The slot as one moment, for sorting a day view and for comparing two bookings. */
    public function startsAt(): Carbon
    {
        return Carbon::parse($this->scheduled_on->toDateString().' '.$this->start_time);
    }

    public function endsAt(): Carbon
    {
        return Carbon::parse($this->scheduled_on->toDateString().' '.$this->end_time);
    }

    /**
     * Still `scheduled` after its end time has passed — the row the daily job flags for somebody to
     * mark. It never marks it: "did they turn up" is a fact only a person in the room has.
     */
    public function isUnmarkedPast(?Carbon $now = null): bool
    {
        return $this->status === DemoClassStatus::Scheduled
            && $this->endsAt()->lt($now ?? Carbon::now());
    }

    /**
     * Does the room matter for this booking? An online demo occupies nobody's classroom, so it is out
     * of room clash detection — the same rule `DeliveryMode::needsClassroom()` states once.
     */
    public function occupiesAClassroom(): bool
    {
        return $this->classroom_id !== null && $this->delivery_mode->needsClassroom();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', DemoClassStatus::Scheduled->value);
    }

    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('scheduled_on', [$from->toDateString(), $to->toDateString()]);
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

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(CourseInquiry::class, 'course_inquiry_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(StudentApplication::class, 'student_application_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function convertedAdmission(): BelongsTo
    {
        return $this->belongsTo(StudentAdmission::class, 'converted_admission_id');
    }
}
