<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\EnrollmentStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One student's seat in one batch (`student_batch_enrollments`, phase-14-17 §2.21).
 *
 * **The seat, not the admission, is what attendance and progress hang off.** A student who transfers
 * batches keeps the attendance they earned in the first one: the old row goes to `transferred_out`
 * with its history intact and a new row starts, linked both ways. Hanging attendance off the
 * admission instead would make a transfer either lose the past or fake it.
 *
 * **`current_guard` is the generated column behind `uq_sbe_active`.** It is `1` while the seat is
 * held and NULL once it is given up, so a student can be in a batch once at a time and can be
 * re-enrolled in a batch they dropped. The service still checks, and reads the 1062 as "already
 * enrolled" rather than a crash.
 *
 * **The five attendance counters and two percentages are caches.** Phase 17 writes them from
 * `student_attendances`; nothing here adds to them, and the report that matters re-derives from the
 * rows.
 *
 * @property EnrollmentStatus $status
 */
class StudentBatchEnrollment extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'student_batch_enrollments';

    /**
     * Status, the transfer links and every counter move through `BatchEnrollmentService`: a seat
     * given up without a reason, or a transfer written from one side only, is the kind of row that
     * makes a roster disagree with itself.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id', 'batch_id', 'course_id', 'student_admission_id',
        'roll_number', 'enrolled_on', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'batch_id' => 'integer',
            'course_id' => 'integer',
            'student_admission_id' => 'integer',
            'transferred_from_id' => 'integer',
            'transferred_to_id' => 'integer',
            'status' => EnrollmentStatus::class,
            'enrolled_on' => 'date',
            'completed_on' => 'date',
            'left_on' => 'date',
            'is_overbooked' => 'boolean',
            'sessions_expected_count' => 'integer',
            'present_count' => 'integer',
            'absent_count' => 'integer',
            'leave_count' => 'integer',
            'late_count' => 'integer',
            'attendance_percentage' => 'decimal:4',
            'progress_percentage' => 'decimal:4',
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
            'status', 'batch_id', 'roll_number', 'left_on', 'leave_reason',
            'transferred_to_id', 'transferred_from_id', 'transfer_reason', 'is_overbooked',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    public function holdsASeat(): bool
    {
        return $this->status->countsInCapacity();
    }

    /**
     * Was this seat held on a given date? The question attendance marking asks, so a student who
     * joined in week six is never marked absent for week two.
     */
    public function wasActiveOn(Carbon $date): bool
    {
        if ($this->enrolled_on->gt($date)) {
            return false;
        }

        if ($this->left_on !== null && $this->left_on->lt($date)) {
            return false;
        }

        return $this->status->countsInAttendance()
            || ($this->left_on !== null && $this->left_on->gte($date));
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Active->value);
    }

    /**
     * The roster as it stood on a date: enrolled by then, and not gone before it.
     */
    public function scopeActiveOn(Builder $query, Carbon $date): Builder
    {
        $on = $date->toDateString();

        return $query->whereDate('enrolled_on', '<=', $on)
            ->where(function (Builder $q) use ($on): void {
                $q->whereNull('left_on')->orWhereDate('left_on', '>=', $on);
            })
            ->whereIn('status', [
                EnrollmentStatus::Active->value,
                EnrollmentStatus::Completed->value,
                EnrollmentStatus::TransferredOut->value,
                EnrollmentStatus::Dropped->value,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(StudentAdmission::class, 'student_admission_id');
    }

    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_from_id');
    }

    public function transferredTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_to_id');
    }
}
