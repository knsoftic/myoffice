<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Enums\TimerStopReason;
use App\Models\Concerns\Blameable;
use App\Models\Crm\Concerns\RelatesToLaterPhases;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One start-to-stop stretch of a timer (phase-06 §2.11).
 *
 * **This is the only place elapsed time exists in the system** (INV-P5). Every figure anywhere — a task's
 * `actual_seconds`, a project's hours, a daily rollup, an invoice line later — is a SUM over these rows.
 * `duration_seconds` is a STORED generated column that yields **zero while `ended_at` is NULL**, which is
 * exactly what makes "there is no mutable running total" a true statement rather than a convention.
 *
 * **Append-only** (D19, no `deleted_at`). The `updating` hook permits only `ended_at` and `end_reason`,
 * and only while `ended_at` is still NULL — closing a segment is the one legal edit, and re-closing or
 * re-opening one is refused. `deleting` throws unless the parent is being force-deleted, which no UI route
 * offers.
 *
 * `open_guard` carries `uq_tes_open`: at most one open segment per worker across every project and task —
 * the second, independent enforcement of INV-P4, so the one-timer guarantee survives even if
 * `time_entries.running_guard` were ever compromised.
 *
 * `started_at` / `ended_at` are `DATETIME`, not `TIMESTAMP` (D67): on this server a first
 * `TIMESTAMP NOT NULL` column silently acquires `ON UPDATE CURRENT_TIMESTAMP`, which on an append-only
 * clock record would rewrite history and change `duration_seconds` underneath every SUM already taken.
 *
 * @property int $id
 * @property int $time_entry_id
 * @property int|null $user_id
 * @property int|null $collaborator_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int $duration_seconds generated STORED — zero while open
 * @property TimerStopReason|null $end_reason
 * @property string|null $ip_address
 * @property string|null $device
 * @property string|null $open_guard generated STORED — carries uq_tes_open (INV-P4)
 */
class TimeEntrySegment extends Model
{
    use Blameable;
    use RelatesToLaterPhases;

    /** Phase 8 (phase-08-09 §2.1). */
    public const COLLABORATOR_MODEL = 'App\\Models\\Collaborator\\Collaborator';

    /** The only columns an UPDATE may touch, and only while the segment is still open (§2.11). */
    public const CLOSING_COLUMNS = ['ended_at', 'end_reason', 'updated_at', 'updated_by'];

    protected $table = 'time_entry_segments';

    /**
     * Deliberately empty: `TimerService` writes every column explicitly (§6.4).
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'time_entry_id' => 'integer',
            'user_id' => 'integer',
            'collaborator_id' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'end_reason' => TimerStopReason::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (TimeEntrySegment $segment): void {
            $hasUser = $segment->getAttribute('user_id') !== null;
            $hasCollaborator = $segment->getAttribute('collaborator_id') !== null;

            if ($hasUser === $hasCollaborator) {
                throw new LogicException(sprintf(
                    'TimeEntrySegment #%s: a segment belongs to exactly one worker (phase-06 '
                    .'chk_tes_one_worker); this row has %s.',
                    (string) ($segment->getKey() ?? 'new'),
                    $hasUser ? 'both a user and a collaborator' : 'neither'
                ));
            }
        });

        static::updating(static function (TimeEntrySegment $segment): void {
            $illegal = array_values(array_diff(array_keys($segment->getDirty()), self::CLOSING_COLUMNS));

            if ($illegal !== []) {
                throw new LogicException(sprintf(
                    'TimeEntrySegment #%s: the clock record is append-only (phase-06 §2.11, INV-P5); only '
                    .'ended_at and end_reason may be written, not %s.',
                    (string) $segment->getKey(),
                    implode(', ', $illegal)
                ));
            }

            if ($segment->isDirty('ended_at') && $segment->getOriginal('ended_at') !== null) {
                throw new LogicException(sprintf(
                    'TimeEntrySegment #%s: this segment is already closed; a correction is a new segment, '
                    .'never a rewrite of an old one (phase-06 §2.11).',
                    (string) $segment->getKey()
                ));
            }
        });

        static::deleting(static function (TimeEntrySegment $segment): void {
            throw new LogicException(sprintf(
                'TimeEntrySegment #%s: the clock record is append-only (phase-06 §2.11, D19); discard the '
                .'time entry instead, with a reason.',
                (string) $segment->getKey()
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'time_tracking';
    }

    /**
     * Is the clock still running on this segment?
     */
    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class, 'time_entry_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo($this->laterPhaseModel(self::COLLABORATOR_MODEL, 'Phase 8'), 'collaborator_id');
    }
}
