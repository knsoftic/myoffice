<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\DeliveryMode;
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
 * One recurring weekly slot (`timetable_entries`, §71, phase-14-17 §2.22).
 *
 * **A rule, not an event.** "This batch, Mondays, 09:00–11:00, from March until the batch ends" is
 * what this row says; what actually happened on a given Monday is a `class_sessions` row. Keeping
 * them apart is what lets one Monday be cancelled, moved or taught by a substitute without editing
 * the pattern — and what lets §83 ask which class covered which topic (D46, [D-IN-10]).
 *
 * **`effective_to` NULL means "until the batch ends", not "no window".** Every date predicate
 * coalesces it to the far future, which is why `endsOnOrAfter()` exists rather than a NULL test
 * repeated in five queries.
 *
 * **`teacher_id` NULL means "whoever teaches the batch".** Leaving it empty is how a slot follows the
 * batch's teacher when that teacher changes; naming one is how a single slot is given to somebody
 * else. `ClassSessionService::generate()` resolves it at generation time.
 */
class TimetableEntry extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'timetable_entries';

    /**
     * `branch_id` and `course_id` are copied from the batch by the service — a slot whose course
     * disagreed with its batch's would make the course-wise view of §71 lie.
     *
     * @var list<string>
     */
    protected $fillable = [
        'batch_id', 'teacher_id', 'day_of_week', 'start_time', 'end_time', 'classroom_id',
        'delivery_mode', 'meeting_url', 'notes', 'effective_from', 'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'batch_id' => 'integer',
            'course_id' => 'integer',
            'teacher_id' => 'integer',
            'classroom_id' => 'integer',
            'day_of_week' => Weekday::class,
            'delivery_mode' => DeliveryMode::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
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
            'batch_id', 'teacher_id', 'classroom_id', 'day_of_week', 'start_time', 'end_time',
            'delivery_mode', 'effective_from', 'effective_to', 'is_active',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /** The far-future stand-in for an open window — one definition, every predicate. */
    public const OPEN_ENDED = '9999-12-31';

    public function effectiveToOrForever(): Carbon
    {
        return $this->effective_to ?? Carbon::parse(self::OPEN_ENDED);
    }

    /** Does this rule produce a class on that date? The generator's whole question. */
    public function appliesOn(Carbon $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (Weekday::fromDate($date) !== $this->day_of_week) {
            return false;
        }

        return $date->gte($this->effective_from) && $date->lte($this->effectiveToOrForever());
    }

    /** Does the room matter? An online slot occupies nobody's classroom. */
    public function occupiesAClassroom(): bool
    {
        return $this->classroom_id !== null && $this->delivery_mode->needsClassroom();
    }

    public function slotLabel(): string
    {
        return $this->day_of_week->short().' '
            .Carbon::parse($this->start_time)->format('H:i').'–'
            .Carbon::parse($this->end_time)->format('H:i');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Rules whose window overlaps the given span — the calendar's and the detector's filter. */
    public function scopeEffectiveBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereDate('effective_from', '<=', $to->toDateString())
            ->where(function (Builder $q) use ($from): void {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString());
            });
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

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }
}
