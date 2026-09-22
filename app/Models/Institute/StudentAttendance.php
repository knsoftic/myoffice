<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\AttendanceMarkSource;
use App\Enums\StudentAttendanceStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One student, one class, one answer (`student_attendances`, §75, phase-14-17 §2.24).
 *
 * **Attendance is corrected, never deleted (INV-I10).** A register is a record of what somebody
 * observed, and removing a row would make a percentage move with no trace of why. `amend()` on the
 * service writes the new status beside `amended_at`, `amended_by` and a reason, and the activity log
 * carries the old value — so the correction is itself a record. The `deleting` hook below refuses the
 * delete outright rather than trusting every future caller to remember, and `deleted_at` exists only
 * because `CLAUDE.md` §3 asks for it on a table that is not append-only by category.
 *
 * **`student_batch_enrollment_id` is not a convenience.** It is the proof that this student was on
 * the roster of that batch on that date (INV-I9): without it, an attendance row could point at a
 * student and a session that never had anything to do with each other, and the enrollment counters
 * would have no honest way to find their own rows.
 *
 * **`batch_id` is denormalised on purpose.** The monthly matrix and the batch summary are read far
 * more often than attendance is written, and reaching the batch through the session on every row
 * turns one index scan into a join nobody needs.
 *
 * @property StudentAttendanceStatus $status
 */
class StudentAttendance extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'student_attendances';

    /**
     * Status, the mark stamps and the amendment trio are all the service's: a status written without
     * `marked_at` and `marked_via` is a row nobody can account for, and an amendment written without
     * its reason is the thing INV-I10 exists to prevent.
     *
     * @var list<string>
     */
    protected $fillable = [
        'check_in_time', 'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_session_id' => 'integer',
            'student_id' => 'integer',
            'student_batch_enrollment_id' => 'integer',
            'batch_id' => 'integer',
            'marked_by' => 'integer',
            'amended_by' => 'integer',
            'status' => StudentAttendanceStatus::class,
            'marked_via' => AttendanceMarkSource::class,
            'minutes_late' => 'integer',
            'marked_at' => 'datetime',
            'amended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Not even a soft delete: a register row that disappears takes a percentage with it and leaves
        // nothing to explain the change.
        //
        // **No escape hatch for the test suite**, unlike the append-only tables that have one: this is
        // the layer that makes INV-I10 true for EVERY caller. `Gate::before` allows a Super Admin the
        // `student_attendance.delete` ability, so the policy alone cannot hold the line — this does.
        // `RefreshDatabase` truncates rather than deleting models, so nothing legitimate is blocked.
        static::deleting(static function (self $attendance): void {
            throw new LogicException(sprintf(
                'Attendance #%d may not be deleted (INV-I10). Use AttendanceService::amend(), which '
                .'records the old status, the new one, who changed it and why.',
                (int) $attendance->getKey(),
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'student_attendance';
    }

    protected function activityModule(): ?string
    {
        return 'student_attendance';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'status', 'check_in_time', 'minutes_late', 'remarks', 'marked_via',
            'amended_at', 'amended_by', 'amendment_reason',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    public function countsAsPresent(): bool
    {
        return $this->status->countsAsPresent();
    }

    public function wasAmended(): bool
    {
        return $this->amended_at !== null;
    }

    /**
     * Is this row still inside the window where anybody who can mark may change it freely?
     *
     * After it, a change needs `student_attendance.edit` and a reason (INV-I10) — the point being
     * that fixing a typo on the way out of the classroom is not the same act as revising a register
     * somebody has already reported on.
     */
    public function isWithinLockWindow(?Carbon $now = null): bool
    {
        $hours = max(0, (int) setting('institute.attendance_lock_hours', 48));

        if ($this->marked_at === null) {
            return true;
        }

        return ($now ?? Carbon::now())->lessThan($this->marked_at->copy()->addHours($hours));
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePresent(Builder $query): Builder
    {
        return $query->whereIn('status', [
            StudentAttendanceStatus::Present->value,
            StudentAttendanceStatus::Late->value,
        ]);
    }

    public function scopeForBatch(Builder $query, int $batchId): Builder
    {
        return $query->where('batch_id', $batchId);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentBatchEnrollment::class, 'student_batch_enrollment_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function amender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }
}
