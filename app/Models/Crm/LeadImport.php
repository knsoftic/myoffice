<?php

declare(strict_types=1);

namespace App\Models\Crm;

use App\Enums\LeadImportDuplicateStrategy;
use App\Enums\LeadImportStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One CSV import batch (phase-05 §2.5).
 *
 * **Isolation (§9.1).** A batch is visible to the user who created it, or to a `leads.view_any` holder
 * ({@see scopeVisibleTo()}, {@see isVisibleTo()}); `LeadImportPolicy` answers 404 otherwise.
 *
 * **Private files (D21).** `stored_path` and `error_report_path` point at the private `local` disk; both are hidden
 * from serialisation and are streamed only by the policy-checked routes.
 *
 * The four counters move only through atomic `increment()` inside each row's transaction (§6.6) and are never
 * mass assignable; `processedCount()` is their plain integer sum.
 *
 * Mass assignable: the wizard's choices (file name, delimiter, encoding, column map, defaults, duplicate strategy).
 * `stored_path`, `file_hash`, the status, the counters, the timestamps and `failure_message` are written by
 * `LeadImportService` with `forceFill()`.
 *
 * @property int $id
 * @property string $original_filename
 * @property string $stored_path
 * @property string $file_hash
 * @property string $delimiter
 * @property string $encoding
 * @property array<string, string> $column_map
 * @property array<string, mixed>|null $defaults
 * @property LeadImportDuplicateStrategy $duplicate_strategy
 * @property LeadImportStatus $status
 * @property int $total_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $failed_count
 * @property string|null $error_report_path
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class LeadImport extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'lead_imports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'original_filename',
        'delimiter',
        'encoding',
        'column_map',
        'defaults',
        'duplicate_strategy',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'stored_path',
        'error_report_path',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'delimiter' => ',',
        'encoding' => 'UTF-8',
        'duplicate_strategy' => 'import_and_flag',
        'status' => 'pending',
        'total_rows' => 0,
        'created_count' => 0,
        'updated_count' => 0,
        'skipped_count' => 0,
        'failed_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'defaults' => 'array',
            'duplicate_strategy' => LeadImportDuplicateStrategy::class,
            'status' => LeadImportStatus::class,
            'total_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function moduleSlug(): string
    {
        return 'leads';
    }

    protected function activityModule(): ?string
    {
        return 'leads';
    }

    /**
     * The batch's decisions and outcome. The counters move once per row and are summarised by the completion
     * notification instead; the private paths are never logged.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['original_filename', 'file_hash', 'duplicate_strategy', 'defaults', 'status', 'failure_message'];
    }

    /**
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return [
            'created_at', 'updated_at', 'updated_by', 'total_rows', 'created_count', 'updated_count', 'skipped_count',
            'failed_count', 'started_at', 'finished_at', 'error_report_path', 'column_map',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Rows that reached an outcome: created + updated + skipped + failed.
     */
    public function processedCount(): int
    {
        return (int) $this->getAttribute('created_count')
            + (int) $this->getAttribute('updated_count')
            + (int) $this->getAttribute('skipped_count')
            + (int) $this->getAttribute('failed_count');
    }

    public function isOwnedBy(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;
        $creator = $this->getAttribute('created_by');

        return $id !== null && $creator !== null && (int) $creator === (int) $id;
    }

    /**
     * §9.1: the importer, or a `leads.view_any` holder.
     */
    public function isVisibleTo(User $user): bool
    {
        return $this->isOwnedBy($user) || LeadVisibilityScope::seesWholePipeline($user);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return HasMany<LeadImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(LeadImportRow::class, 'lead_import_id');
    }

    /**
     * The leads this batch created. Subject to the lead visibility scope.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'lead_import_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<LeadImport>  $query
     * @return Builder<LeadImport>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (LeadVisibilityScope::seesWholePipeline($user)) {
            return $query;
        }

        return $query->where($query->qualifyColumn('created_by'), $user->getKey());
    }

    /**
     * @param  Builder<LeadImport>  $query
     * @param  LeadImportStatus|string|array<int, LeadImportStatus|string>  $status
     * @return Builder<LeadImport>
     */
    public function scopeWithStatus(Builder $query, LeadImportStatus|string|array $status): Builder
    {
        $values = array_map(
            static fn (LeadImportStatus|string $value): string => $value instanceof LeadImportStatus ? $value->value : $value,
            is_array($status) ? $status : [$status]
        );

        return $query->whereIn($query->qualifyColumn('status'), $values);
    }
}
