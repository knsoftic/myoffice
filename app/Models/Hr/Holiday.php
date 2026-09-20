<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\HolidayType;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One public, religious or company holiday (phase-07 §2.8, requirement §26).
 *
 * **One row per date, never a range** ([D-HR-6]). The attendance engine asks "is this date a holiday?"
 * once per employee per day; a range would make that a scan and make the per-date unique guard impossible.
 *
 * An `optional` holiday is **informational**: {@see HolidayType::changesDayType()} is false for it, so it
 * appears on the calendar without turning a working day into a paid day off for everybody.
 *
 * `is_paid = false` produces a payable factor of 0 for that date — an unpaid shutdown is a real thing and
 * must not silently pay people.
 */
class Holiday extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;


    protected $table = 'holidays';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'holiday_date',
        'title',
        'holiday_type',
        'is_paid',
        'is_recurring_yearly',
        'description',
        'is_active',
    ];



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'holiday_date' => 'date',
            'holiday_type' => HolidayType::class,
            'is_paid' => 'boolean',
            'is_recurring_yearly' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Does this row actually make the day non-working?
     */
    public function closesTheOffice(): bool
    {
        return $this->is_active && $this->holiday_type->changesDayType();
    }

    public function moduleSlug(): string
    {
        return 'holidays';
    }

    protected function activityModule(): ?string
    {
        return 'holidays';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'holiday_id');
    }
}
