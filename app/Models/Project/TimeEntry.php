<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Enums\TimeEntrySource;
use App\Enums\TimeEntryStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Concerns\RelatesToLaterPhases;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One work session — a timer run, however many pauses, or a manual entry (phase-06 §2.10, requirement §23).
 *
 * **It holds no mutable running total** (INV-P5). `duration_seconds` is a cache equal to
 * `SUM(time_entry_segments.duration_seconds)`, recomputed under a row lock and never incremented (INV-P6),
 * so a replayed job cannot inflate it and a wrong figure heals itself. While the timer runs the open
 * segment contributes zero and the live number on screen is `cached seconds + (now − open segment start)`,
 * computed client-side and never persisted.
 *
 * **One running timer per worker, for the life of the database** (INV-P4). That is decided by
 * `uq_te_running` over the generated `running_guard` column — a unique INSERT, never a SELECT-then-INSERT —
 * and `time_entry_segments.uq_tes_open` enforces the same fact independently.
 *
 * **Exactly one worker**: staff (`user_id`, [D-P6-2] / D32) or collaborator (`collaborator_id`), never
 * both. `recorded_by` is who keyed it, which equals the worker for a self-started timer and differs when a
 * manager enters time on someone's behalf.
 *
 * `work_date` is the business date in the worker's timezone and **every daily and weekly rollup groups on
 * it**, never on a timestamp — that is what keeps a 23:50–00:10 session on the day the worker means.
 *
 * `started_at` / `ended_at` are `DATETIME`, not `TIMESTAMP` (D67) — see the migration.
 *
 * A wrong entry is **discarded and re-entered**, never silently edited to zero: the soft delete carries a
 * mandatory `discard_reason`, and a discarded entry leaves every cache and rollup.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $task_id
 * @property int|null $user_id
 * @property int|null $collaborator_id
 * @property int|null $recorded_by
 * @property TimeEntrySource $source
 * @property TimeEntryStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property Carbon $work_date
 * @property int $duration_seconds
 * @property int $duration_minutes generated STORED
 * @property string $duration_hours generated STORED
 * @property string|null $description
 * @property string|null $manual_reason
 * @property string|null $discard_reason
 * @property string|null $running_guard generated STORED — carries uq_te_running (INV-P4)
 */
class TimeEntry extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use RelatesToLaterPhases;
    use SoftDeletes;

    /** Phase 8 (phase-08-09 §2.1). */
    public const COLLABORATOR_MODEL = 'App\\Models\\Collaborator\\Collaborator';

    protected $table = 'time_entries';

    /**
     * What a manual-entry form may carry. `status`, the timestamps, `work_date` and `duration_seconds`
     * belong to `TimerService` / `TimeEntryService` and are written with `forceFill()` there.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'task_id',
        'description',
        'manual_reason',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'stopped',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'task_id' => 'integer',
            'user_id' => 'integer',
            'collaborator_id' => 'integer',
            'recorded_by' => 'integer',
            'source' => TimeEntrySource::class,
            'status' => TimeEntryStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'work_date' => 'date',
            'duration_seconds' => 'integer',
            'duration_minutes' => 'integer',
            'duration_hours' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (TimeEntry $entry): void {
            $hasUser = $entry->getAttribute('user_id') !== null;
            $hasCollaborator = $entry->getAttribute('collaborator_id') !== null;

            if ($hasUser === $hasCollaborator) {
                throw new LogicException(sprintf(
                    'TimeEntry #%s: an entry belongs to exactly one worker (phase-06 chk_te_one_worker); '
                    .'this row has %s.',
                    (string) ($entry->getKey() ?? 'new'),
                    $hasUser ? 'both a user and a collaborator' : 'neither'
                ));
            }
        });

        static::deleting(static function (TimeEntry $entry): void {
            if ($entry->isForceDeleting()) {
                return;
            }

            if ($entry->status instanceof TimeEntryStatus && $entry->status->isLive()) {
                throw new LogicException(sprintf(
                    'TimeEntry #%s: stop the timer before discarding it (phase-06 §2.10) — otherwise the '
                    .'uq_te_running slot would be held by a trashed row.',
                    (string) $entry->getKey()
                ));
            }

            if (blank($entry->getAttribute('discard_reason'))) {
                throw new LogicException(sprintf(
                    'TimeEntry #%s: discarding an entry needs a reason (phase-06 §2.10, INV-P16) — a wrong '
                    .'entry is discarded and re-entered, never silently edited to zero.',
                    (string) $entry->getKey()
                ));
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'time_tracking';
    }

    protected function activityModule(): ?string
    {
        return 'time_tracking';
    }

    /**
     * Is the worker's clock still on this entry — running or merely paused?
     */
    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo($this->laterPhaseModel(self::COLLABORATOR_MODEL, 'Phase 8'), 'collaborator_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(TimeEntrySegment::class, 'time_entry_id')->orderBy('started_at');
    }

    /**
     * The open segment, if the clock is running right now.
     */
    public function openSegment(): HasMany
    {
        return $this->segments()->whereNull('ended_at');
    }
}
