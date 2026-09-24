<?php

declare(strict_types=1);

namespace App\Models\Ops;

use App\Enums\IntegrityCheckStatus;
use App\Enums\IntegrityCheckSuite;
use App\Models\Concerns\Blameable;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The record that a proof was run, and what it found (phase-24-25 §2.3, §110).
 *
 * **Append-only, and the reason is the whole point of the table.** A run row is evidence: it says
 * that on a given day the wallet balances reconciled with the ledger, or that they did not. Evidence
 * somebody can delete after reading it is not evidence, so there is no `deleted_at` (D19), a model
 * hook refuses `delete()`, and a `BEFORE DELETE` trigger refuses it below the model as well — an
 * `->delete()` that slipped past the hook, a raw query, a `truncate`.
 *
 * **Retention releases the findings, never the row.** `ops:prune-integrity-runs` nulls `findings`
 * on rows past `ops.integrity_run_retention_days` and sets `findings_truncated`; the verdict and
 * the counts stay for ever. A run that *failed* keeps its findings for three years, because a
 * failure is the one a dispute is about.
 *
 * **`uuid` and `run_uuid` are not the same identifier.** One `integrity:verify --suite=all`
 * invocation writes nine rows — one per suite — each with its own `uuid` and all sharing one
 * `run_uuid`. That is what lets "the run on Tuesday morning" be a thing you can ask about, rather
 * than nine unrelated rows that happen to be near each other in time.
 *
 * @property int $id
 * @property string $uuid
 * @property string $run_uuid
 * @property IntegrityCheckSuite $suite
 * @property IntegrityCheckStatus $status
 * @property string|null $scope
 * @property int $checks_total
 * @property int $checks_passed
 * @property int $checks_warned
 * @property int $checks_failed
 * @property array<int, array<string, mixed>>|null $findings
 * @property bool $findings_truncated
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property int|null $duration_ms
 * @property string $triggered_by
 * @property string|null $command
 * @property int|null $exit_code
 * @property string|null $app_version
 */
final class IntegrityCheckRun extends Model
{
    use Blameable;
    use HasFactory;

    /**
     * How a run was started.
     */
    public const TRIGGERS = ['manual', 'scheduled', 'deploy', 'test', 'restore'];

    /**
     * The most findings a row will hold.
     *
     * A sweep over a broken database can produce tens of thousands. Storing them all turns one bad
     * night into a table nobody can open, and the two-hundredth finding tells a reader nothing the
     * first twenty did not — `findings_truncated` says that more existed, which is the fact that
     * matters.
     */
    public const MAX_FINDINGS = 200;

    /**
     * Days a failed run keeps its findings, whatever the retention setting says.
     */
    public const FAILED_FINDINGS_DAYS = 1095;

    protected $fillable = [
        'uuid',
        'run_uuid',
        'suite',
        'status',
        'scope',
        'checks_total',
        'checks_passed',
        'checks_warned',
        'checks_failed',
        'findings',
        'findings_truncated',
        'started_at',
        'finished_at',
        'duration_ms',
        'triggered_by',
        'command',
        'exit_code',
        'app_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suite' => IntegrityCheckSuite::class,
            'status' => IntegrityCheckStatus::class,
            'checks_total' => 'integer',
            'checks_passed' => 'integer',
            'checks_warned' => 'integer',
            'checks_failed' => 'integer',
            'findings' => 'array',
            'findings_truncated' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'exit_code' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /*
        | The model half of the append-only guarantee (D19). The trigger below it is the other half
        | and the one that cannot be bypassed; this one exists so the failure is a readable
        | exception in the place the mistake was made rather than a MySQL error 1644 from three
        | layers down.
        */
        static::deleting(static function (self $run): void {
            throw new RuntimeException(sprintf(
                'integrity_check_runs is append-only: run %s (%s) may not be deleted. '
                .'Retention releases `findings`; the verdict is kept for ever.',
                $run->uuid,
                $run->suite instanceof IntegrityCheckSuite ? $run->suite->value : 'unknown',
            ));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Who started it, when a person did.
     *
     * Null for a scheduled run, which is most of them — and that null is information, not a gap.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSuite(Builder $query, IntegrityCheckSuite|string $suite): Builder
    {
        return $query->where('suite', $suite instanceof IntegrityCheckSuite ? $suite->value : $suite);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForRun(Builder $query, string $runUuid): Builder
    {
        return $query->where('run_uuid', $runUuid);
    }

    /**
     * Runs whose verdict would stop a go-live.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner
                ->where('status', IntegrityCheckStatus::Failed->value)
                ->orWhere(function (Builder $financial): void {
                    // A warning blocks only on a financial suite — see IntegrityCheckStatus.
                    $financial
                        ->where('status', IntegrityCheckStatus::Warning->value)
                        ->whereIn('suite', array_map(
                            static fn (IntegrityCheckSuite $suite): string => $suite->value,
                            array_filter(
                                IntegrityCheckSuite::cases(),
                                static fn (IntegrityCheckSuite $suite): bool => $suite->isFinancial(),
                            ),
                        ));
                });
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this verdict stops a go-live.
     */
    public function blocksGoLive(): bool
    {
        return $this->status->blocksGoLive($this->suite);
    }

    /**
     * Whether the findings have been released by retention.
     *
     * Distinct from "there were none": a run that passed has no findings and was never truncated.
     */
    public function findingsReleased(): bool
    {
        return $this->findings_truncated && ($this->findings === null || $this->findings === []);
    }

    /**
     * A one-line account, for a console table or a notification.
     */
    public function summary(): string
    {
        return sprintf(
            '%s: %s — %d of %d passed, %d warned, %d failed%s',
            $this->suite->label(),
            $this->status->label(),
            $this->checks_passed,
            $this->checks_total,
            $this->checks_warned,
            $this->checks_failed,
            $this->duration_ms === null ? '' : sprintf(' (%.1fs)', $this->duration_ms / 1000),
        );
    }
}
