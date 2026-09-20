<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One working pattern (phase-07 §2.7, requirement §26).
 *
 * **Editing a shift changes nothing about the past** (HR-2). Every attendance row snapshots the window it
 * was measured against, so fixing a typo or moving the start time cannot rewrite a late mark from three
 * months ago. The edit screen says so in a sentence, because the opposite assumption is the natural one.
 *
 * `crosses_midnight` and `expected_minutes` are derived by `WorkShiftService` from the times and are never
 * hand-entered: a value that disagreed with the clock would quietly change everybody's hours.
 *
 * `default_guard` carries `uq_ws_default` — one default shift per branch ([D-HR-5]).
 */
class WorkShift extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'work_shifts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'branch_id',
        'start_time',
        'end_time',
        'break_minutes',
        'grace_in_minutes',
        'grace_out_minutes',
        'min_full_day_minutes',
        'min_half_day_minutes',
        'weekly_off_days',
        'is_default',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'crosses_midnight' => 'boolean',
            'break_minutes' => 'integer',
            'expected_minutes' => 'integer',
            'grace_in_minutes' => 'integer',
            'grace_out_minutes' => 'integer',
            'min_full_day_minutes' => 'integer',
            'min_half_day_minutes' => 'integer',
            'weekly_off_days' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The days this shift does not work, falling back to the business-wide setting.
     *
     * @return list<string>
     */
    public function offDays(): array
    {
        $days = $this->weekly_off_days;

        if (is_array($days) && $days !== []) {
            return array_values($days);
        }

        return (array) setting('hr.weekend_days', ['sunday']);
    }

    public function moduleSlug(): string
    {
        return 'attendance';
    }

    protected function activityModule(): ?string
    {
        return 'attendance';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'work_shift_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'work_shift_id');
    }
}
