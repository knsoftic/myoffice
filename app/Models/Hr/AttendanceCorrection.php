<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\AttendanceCorrectionStatus;
use App\Enums\AttendanceCorrectionType;
use App\Enums\CorrectionSource;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Hr\Concerns\AppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit trail of one manual attendance change (phase-07 §2.10, HR-6).
 *
 * **An attendance row is never silently edited.** Every manual change writes one of these carrying the
 * values before and after, a mandatory reason and the actor. An HR-direct correction is created already
 * approved and applied in the same transaction, so the evidence is **identical** whichever path was taken
 * — the difference is the `source` column, not whether a record exists.
 *
 * `attendance_id` is nullable because a correction can precede its row: asking for a day that was never
 * marked is exactly the case that needs correcting.
 *
 * **Append-only** (D19): only the review columns move. Deleting this row would destroy the explanation of
 * why a number changed.
 */
class AttendanceCorrection extends Model
{
    use AppendOnly;
    use Blameable;
    use LogsActivityWithContext;


    protected $table = 'attendance_corrections';



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_id' => 'integer',
            'employee_id' => 'integer',
            'attendance_date' => 'date',
            'correction_type' => AttendanceCorrectionType::class,
            'source' => CorrectionSource::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'status' => AttendanceCorrectionStatus::class,
            'requested_by' => 'integer',
            'requested_at' => 'datetime',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * The only columns an UPDATE may touch (D19). Everything else is evidence.
     *
     * @return list<string>
     */
    protected function updatableColumns(): array
    {
        return [
            'status',
            'reviewed_by',
            'reviewed_at',
            'review_comment',
            'applied_at',
            'attendance_id',
        ];
    }

    public function moduleSlug(): string
    {
        return 'attendance';
    }

    protected function activityModule(): ?string
    {
        return 'attendance';
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
