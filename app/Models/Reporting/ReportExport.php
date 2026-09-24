<?php

declare(strict_types=1);

namespace App\Models\Reporting;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * One requested export of one report (phase-19-23 §2.26, requirement §99).
 *
 * **The row is the receipt; the file is the parcel.** They have different lifetimes on purpose. A
 * file is deleted when `expires_at` passes and the row is marked {@see ExportStatus::Expired} — so
 * somebody who bookmarked a download link is told the file has gone rather than that it never
 * existed, and the record of what left the building outlives the bytes. That is also why this table
 * carries **no `deleted_at`** (CLAUDE.md §3, run history) and why {@see self::bootReportExport()}
 * refuses an Eloquent delete outright.
 *
 * **`uuid` is the only id that ever reaches a URL.** {@see self::getRouteKeyName()} makes that the
 * default rather than something each route has to remember. A sequential id in a download link is an
 * invitation to try the one before it — and while `download()` checks ownership regardless, a
 * guessable id makes that check load-bearing instead of a backstop.
 *
 * **Nothing here decides who may download.** The row knows `requested_by` and `report_key`; it does
 * not know whether that person still holds the report's permissions. `ReportExportService::download()`
 * re-authorises against live state every time (§6.21, PH23-16): somebody who lost `view_financial`
 * yesterday cannot download yesterday's file today. {@see self::isDownloadable()} answers only the
 * question this row can answer — is there a file, and has it expired — and says so in its own name.
 *
 * @property int $id
 * @property string $uuid
 * @property string $report_key
 * @property ExportFormat $format
 * @property array<string, mixed> $filters
 * @property CarbonInterface|null $date_from
 * @property CarbonInterface|null $date_to
 * @property int $requested_by
 * @property ExportStatus $status
 * @property int|null $row_count
 * @property string $storage_disk
 * @property string|null $file_path
 * @property int|null $file_size_bytes
 * @property string|null $checksum_sha256
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $expires_at
 * @property int $download_count
 * @property CarbonInterface|null $last_downloaded_at
 * @property string|null $error_class
 * @property string|null $error_message
 */
class ReportExport extends Model
{
    protected $table = 'report_exports';

    /**
     * Everything the *request* decides. Nothing the build decides.
     *
     * `status`, `row_count`, `file_path`, the checksum, the timestamps and the error pair are all
     * absent deliberately: they are written by `ReportExportService` and `BuildReportExport` through
     * explicit assignment, so a stray `fill()` from request data can never mark a queued export
     * completed or point it at a file somebody else's export built.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'report_key',
        'format',
        'filters',
        'date_from',
        'date_to',
        'requested_by',
        'storage_disk',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => ExportFormat::class,
            'status' => ExportStatus::class,
            'filters' => 'array',
            'date_from' => 'date',
            'date_to' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
            'row_count' => 'integer',
            'file_size_bytes' => 'integer',
            'download_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A uuid is not optional — it is the route key. Generating it here rather than in the
        // service means a row created by a test, a factory or a console command is reachable too.
        static::creating(static function (self $export): void {
            if ((string) $export->getAttribute('uuid') === '') {
                $export->setAttribute('uuid', (string) Str::uuid());
            }
        });

        // The model half of the append-only rule (CLAUDE.md §3, D19). `prune()` deletes the *file*
        // and marks the row expired; it never removes the row, and nothing else should either.
        // Scope: model events only — a query-builder delete does not fire this, which is the same
        // boundary `ForbidsDeletion` documents for the CMS tables.
        static::deleting(static function (self $export): never {
            throw new LogicException(sprintf(
                'ReportExport %s is the record of a file that left the building (no deleted_at, '
                .'decision D19) and cannot be deleted. Expire it instead: the file goes, the row stays.',
                (string) ($export->getAttribute('uuid') ?: $export->getKey()),
            ));
        });
    }

    /** The only id that ever reaches a URL. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /*
    |--------------------------------------------------------------------------
    | What this row can answer on its own
    |--------------------------------------------------------------------------
    */

    /**
     * Is there a file here to serve?
     *
     * Deliberately **not** named `canBeDownloadedBy()`: three conditions the row knows about, and
     * none of the three about the person. Ownership and the report's live permissions are
     * `ReportExportService::download()`'s to check, every time, against current state.
     */
    public function isDownloadable(): bool
    {
        return $this->status->isDownloadable()
            && (string) $this->file_path !== ''
            && ! $this->hasExpired();
    }

    /**
     * Has the retention window closed?
     *
     * A null `expires_at` means "not yet decided" — a queued or running export has no expiry
     * because it has no `completed_at` to count from — and is therefore not expired. Treating null
     * as expired would make every in-flight export undownloadable the moment it finished.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Still waiting on the queue, or being built right now. */
    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    /**
     * How long the build took, in seconds, or null while it is unknown.
     *
     * Shown on the list screen because it is the number that tells somebody whether to wait or to
     * narrow their filters.
     */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->completed_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at, absolute: true);
    }

    /**
     * The failure, in one line, or null.
     *
     * The real exception text is stored and shown (§2.26): a queued job that failed silently is
     * somebody refreshing a page for ten minutes, and "it did not build, here is what the database
     * said" is somebody who can act. The class name is included only when there is no message,
     * because a bare class name is better than nothing and worse than a sentence.
     */
    public function failureReason(): ?string
    {
        if ($this->status !== ExportStatus::Failed) {
            return null;
        }

        $message = trim((string) $this->error_message);

        if ($message !== '') {
            return $message;
        }

        $class = trim((string) $this->error_class);

        return $class !== '' ? class_basename($class) : 'The export failed for an unrecorded reason.';
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The only list screen this table has: an export belongs to the person who asked for it.
     *
     * This is the data-isolation rule (CLAUDE.md §1.10) expressed once, so a controller cannot
     * forget it. There is no "all exports" screen — not even for an administrator — because the
     * file contains whatever the requester's own scope let them see, and handing it to somebody
     * with a different scope would launder the isolation every report applies.
     */
    public function scopeRequestedBy(Builder $query, User|int $user): Builder
    {
        return $query->where('requested_by', $user instanceof User ? $user->getKey() : $user);
    }

    /** Rows whose file is due for removal: finished, past its expiry, still holding a path. */
    public function scopeDueForPrune(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $query
            ->where('status', ExportStatus::Completed)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now ?? now())
            ->whereNotNull('file_path');
    }

    /** Queued or running — used by the list screen's "in progress" filter and by the stuck-job sweep. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [ExportStatus::Queued, ExportStatus::Running]);
    }

    public function scopeForReport(Builder $query, string $reportKey): Builder
    {
        return $query->where('report_key', $reportKey);
    }
}
