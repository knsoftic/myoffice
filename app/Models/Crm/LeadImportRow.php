<?php

declare(strict_types=1);

namespace App\Models\Crm;

use App\Enums\LeadDuplicateMatchType;
use App\Enums\LeadImportRowStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The result of one CSV line (phase-05 §2.6).
 *
 * **Append-only per-row log** (D19): timestamps only, no `deleted_at`, no blameable pair (resolutions §8 row 11 —
 * the child rows of a blameable batch). An Eloquent delete throws. Rows leave only through `crm:prune-imports`
 * (a query-builder delete of rows older than `crm.import_row_retention_days`, {@see scopeCreatedBefore()}) or
 * the cascade of a force-deleted batch.
 *
 * The line's identity and evidence — `lead_import_id`, `row_number`, `raw` — are fixed at insert; the dry run
 * writes the row and the run later moves its outcome (`status`, the lead it created or updated, the match, the
 * errors), which is the only update the model allows.
 *
 * `UNIQUE uq_lir_row(lead_import_id, row_number)` is the idempotency guard: a retried chunk inserting the same line
 * raises 1062 and is skipped (§11 test 39).
 *
 * Isolation follows the batch: `LeadImportPolicy` decides on {@see import()}.
 *
 * @property int $id
 * @property int $lead_import_id
 * @property int $row_number
 * @property array<string, mixed> $raw
 * @property LeadImportRowStatus $status
 * @property int|null $lead_id
 * @property int|null $duplicate_lead_id
 * @property LeadDuplicateMatchType|null $duplicate_match_type
 * @property array<string, list<string>>|null $errors
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadImportRow extends Model
{
    /** Fixed at insert (§2.6). */
    public const IMMUTABLE_COLUMNS = ['lead_import_id', 'row_number', 'raw'];

    protected $table = 'lead_import_rows';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'row_number',
        'raw',
        'status',
        'lead_id',
        'duplicate_lead_id',
        'duplicate_match_type',
        'errors',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lead_import_id' => 'integer',
            'row_number' => 'integer',
            'raw' => 'array',
            'status' => LeadImportRowStatus::class,
            'lead_id' => 'integer',
            'duplicate_lead_id' => 'integer',
            'duplicate_match_type' => LeadDuplicateMatchType::class,
            'errors' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (LeadImportRow $row): void {
            $changed = array_intersect(array_keys($row->getDirty()), self::IMMUTABLE_COLUMNS);

            if ($changed !== []) {
                throw new LogicException(sprintf(
                    'Lead import row #%s: %s is fixed when the line is recorded (phase-05 §2.6).',
                    (string) $row->getKey(),
                    implode(', ', $changed)
                ));
            }
        });

        static::deleting(static function (LeadImportRow $row): never {
            throw new LogicException(sprintf(
                'Lead import row #%s is an append-only log record (no deleted_at, D19) and cannot be deleted; '
                .'crm:prune-imports removes expired rows.',
                (string) $row->getKey()
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'leads';
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<LeadImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(LeadImport::class, 'lead_import_id')->withTrashed();
    }

    /**
     * The lead this line created or updated. Subject to the lead visibility scope.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * The existing lead this line matched. Subject to the lead visibility scope.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function duplicateLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'duplicate_lead_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<LeadImportRow>  $query
     * @param  LeadImportRowStatus|string|array<int, LeadImportRowStatus|string>  $status
     * @return Builder<LeadImportRow>
     */
    public function scopeWithStatus(Builder $query, LeadImportRowStatus|string|array $status): Builder
    {
        $values = array_map(
            static fn (LeadImportRowStatus|string $value): string => $value instanceof LeadImportRowStatus ? $value->value : $value,
            is_array($status) ? $status : [$status]
        );

        return $query->whereIn($query->qualifyColumn('status'), $values);
    }

    /**
     * The lines that belong in the error report (`skipped_invalid`, `failed`), in file order.
     *
     * @param  Builder<LeadImportRow>  $query
     * @return Builder<LeadImportRow>
     */
    public function scopeErrors(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), [
            LeadImportRowStatus::SkippedInvalid->value,
            LeadImportRowStatus::Failed->value,
        ])->orderBy($query->qualifyColumn('row_number'));
    }

    /**
     * The prune window of `crm:prune-imports`.
     *
     * @param  Builder<LeadImportRow>  $query
     * @return Builder<LeadImportRow>
     */
    public function scopeCreatedBefore(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query->where($query->qualifyColumn('created_at'), '<', $cutoff);
    }
}
