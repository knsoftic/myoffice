<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\LeaveDayPortion;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One date inside a leave request (phase-07 §2.16, HR-8).
 *
 * **One counted leave day per employee per date**, enforced by `uq_lrd_day` over the generated guard. Two
 * half-day leaves of different types on one date are refused by the database, not by a service that could
 * be bypassed.
 *
 * Skipped weekends and holidays inside a range are **still written**, marked not counted — which is what
 * lets the screen explain why five calendar days cost three quota days instead of leaving a gap somebody
 * has to reconstruct.
 *
 * `is_active` goes false when the request is rejected or cancelled, releasing the guard so the date can be
 * asked for again.
 */
class LeaveRequestDay extends Model
{
    use Blameable;
    use LogsActivityWithContext;


    protected $table = 'leave_request_days';



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leave_request_id' => 'integer',
            'employee_id' => 'integer',
            'leave_date' => 'date',
            'day_portion' => LeaveDayPortion::class,
            'day_fraction' => 'decimal:4',
            'is_counted' => 'boolean',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
            'attendance_id' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'leaves';
    }

    protected function activityModule(): ?string
    {
        return 'leaves';
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }
}
