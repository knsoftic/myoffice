<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\ClassroomType;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A room a class can be held in (`classrooms`, phase-14-17 §2.19).
 *
 * **A virtual room is a room that cannot be double-booked.** `ClassroomType::Virtual` exists so an
 * online batch can still name where it meets without that name colliding with anybody else's — a Zoom
 * link holds as many classes at once as you care to schedule. `ScheduleClashDetector` skips the
 * classroom dimension for it, and `capacityLimits()` says so once rather than in each caller.
 *
 * **`capacity` is a second ceiling, not the ceiling.** The batch's own `student_capacity` is what
 * enrolment checks; the room's capacity is checked too when the batch is physical, because twenty-two
 * students and eighteen chairs is a problem nobody discovers from a screen.
 */
class Classroom extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'classrooms';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id', 'code', 'name', 'type', 'capacity', 'location', 'is_active', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'type' => ClassroomType::class,
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function moduleSlug(): string
    {
        return 'classrooms';
    }

    protected function activityModule(): ?string
    {
        return 'classrooms';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['code', 'name', 'type', 'capacity', 'location', 'is_active', 'branch_id'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /** Does booking this room stop anybody else booking it? Not if it is a meeting link. */
    public function isBookable(): bool
    {
        return $this->is_active && $this->type->isBookable();
    }

    /** Does this room's own capacity cap a batch? A virtual room seats everybody. */
    public function capacityLimits(): bool
    {
        return $this->type->limitsCapacity();
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
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
