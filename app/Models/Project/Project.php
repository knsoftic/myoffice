<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Enums\CommissionCalculationType;
use App\Enums\Priority;
use App\Enums\ProgressBasis;
use App\Enums\ProgressMode;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Cms\Service;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Client;
use App\Models\Crm\Concerns\RelatesToLaterPhases;
use App\Models\Crm\Lead;
use App\Models\Project\Concerns\GuardsServiceOwnedColumns;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One delivery project (phase-06 §2.1, requirement §20).
 *
 * **Three column groups have exactly one writer each** ({@see GuardsServiceOwnedColumns}); everything else
 * is an ordinary edit:
 *
 *   · {@see VALUE_COLUMNS} — `ProjectValueService::revise()` only (INV-P1). Every change writes an
 *     append-only `project_value_revisions` row with a mandatory reason, so the value the spine pays
 *     commission against can always be proved. A plain `update()` that touches them throws.
 *   · {@see PROGRESS_COLUMNS} — `ProjectProgressService` only (INV-P8). In `auto` mode the number is
 *     derived by §6.3; in `manual` mode a human sets it with a reason, which `chk_projects_manual_progress`
 *     also enforces in the database.
 *   · {@see REFERRAL_COLUMNS} — `ProjectReferralService` only (INV-P13). Attribution moves freely until a
 *     `collaborator_referrals` row or a `project_payment` exists; after that it changes only through the
 *     spine's `ReferralService::change()`, and no ledger row is ever re-pointed.
 *
 * `code` is issued once by `ProjectNumberService` (a thin delegate to `DocumentNumberService`, D27) and is
 * immutable afterwards. `net_value`, `actual_minutes` and `actual_hours` are STORED generated columns: they
 * are absent from `$fillable`, the database refuses a write to them, and `$casts` treats them as read-only
 * decimals.
 *
 * `collaborator_id` is a **display snapshot only** (R5, D37) — no scope and no commission engine reads it.
 * The authority for attribution is an `active` `collaborator_referrals` row, which Phase 9/10 ships.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $client_id
 * @property int|null $lead_id
 * @property int|null $project_manager_id
 * @property int|null $service_id
 * @property int|null $collaborator_id
 * @property string|null $referral_code
 * @property Carbon|null $referral_date
 * @property CommissionCalculationType|null $commission_type
 * @property string|null $commission_rate decimal(8,4) as a string — arithmetic only through App\Support\Money
 * @property string|null $commission_fixed_amount
 * @property string|null $description
 * @property ProjectType $project_type
 * @property Priority $priority
 * @property ProjectStatus $status
 * @property Carbon|null $start_date
 * @property Carbon|null $deadline
 * @property Carbon|null $completed_on
 * @property string $currency
 * @property string $budget_amount
 * @property string $project_value
 * @property string $discount_amount
 * @property string $net_value generated STORED — project_value - discount_amount (INV-P2)
 * @property int $value_revision_count
 * @property string $progress_percent
 * @property ProgressMode $progress_mode
 * @property ProgressBasis $progress_basis
 * @property string|null $progress_reason
 * @property int|null $progress_set_by
 * @property Carbon|null $progress_updated_at
 * @property int $estimated_minutes
 * @property int $actual_seconds
 * @property int $actual_minutes generated STORED
 * @property string $actual_hours generated STORED (INV-P7)
 */
class Project extends Model
{
    use Blameable;
    use GuardsServiceOwnedColumns;
    use LogsActivityWithContext;
    use RelatesToLaterPhases;
    use SoftDeletes;

    /** Phase 8 (phase-08-09 §2.1). */
    public const COLLABORATOR_MODEL = 'App\\Models\\Collaborator\\Collaborator';

    /** The spine's referral model (phase-10-12 §2). */
    public const COLLABORATOR_REFERRAL_MODEL = 'App\\Models\\Collaborator\\CollaboratorReferral';

    /** The spine's project payment model (phase-10-12 §2). */
    public const PROJECT_PAYMENT_MODEL = 'App\\Models\\Finance\\ProjectPayment';

    /** INV-P1 — `ProjectValueService::revise()` owns these. */
    public const VALUE_COLUMNS = [
        'project_value',
        'discount_amount',
        'commission_type',
        'commission_rate',
        'commission_fixed_amount',
    ];

    /** INV-P8 — `ProjectProgressService` owns these. */
    public const PROGRESS_COLUMNS = [
        'progress_percent',
        'progress_mode',
        'progress_reason',
        'progress_set_by',
        'progress_updated_at',
    ];

    /** INV-P13 — `ProjectReferralService` owns these. */
    public const REFERRAL_COLUMNS = [
        'collaborator_id',
        'referral_code',
        'referral_date',
    ];

    public const GROUP_VALUE = 'value';

    public const GROUP_PROGRESS = 'progress';

    public const GROUP_REFERRAL = 'referral';

    protected $table = 'projects';

    /**
     * What a project form may carry. Everything else has its own service method or its own provenance —
     * see the class docblock — and is written with `forceFill()` inside that service.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'client_id',
        'lead_id',
        'project_manager_id',
        'service_id',
        'description',
        'project_type',
        'priority',
        'start_date',
        'deadline',
        'currency',
        'budget_amount',
        'progress_basis',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'planning',
        'progress_mode' => 'auto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'lead_id' => 'integer',
            'project_manager_id' => 'integer',
            'service_id' => 'integer',
            'collaborator_id' => 'integer',
            'referral_date' => 'date',
            'commission_type' => CommissionCalculationType::class,
            'commission_rate' => 'decimal:4',
            'commission_fixed_amount' => 'decimal:2',
            'project_type' => ProjectType::class,
            'priority' => Priority::class,
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'deadline' => 'date',
            'completed_on' => 'date',
            'budget_amount' => 'decimal:2',
            'project_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_value' => 'decimal:2',
            'value_revision_count' => 'integer',
            'progress_percent' => 'decimal:4',
            'progress_mode' => ProgressMode::class,
            'progress_basis' => ProgressBasis::class,
            'progress_set_by' => 'integer',
            'progress_updated_at' => 'datetime',
            'estimated_minutes' => 'integer',
            'actual_seconds' => 'integer',
            'actual_minutes' => 'integer',
            'actual_hours' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Project $project): void {
            if ($project->isDirty('code') && $project->getOriginal('code') !== null) {
                throw new LogicException(sprintf(
                    'Project #%s: code is immutable once issued (phase-06 §2.1, D27).',
                    (string) $project->getKey()
                ));
            }

            $project->refuseGuardedColumns(
                self::GROUP_VALUE,
                self::VALUE_COLUMNS,
                'ProjectValueService::revise()',
                'INV-P1'
            );

            $project->refuseGuardedColumns(
                self::GROUP_PROGRESS,
                self::PROGRESS_COLUMNS,
                'ProjectProgressService',
                'INV-P8'
            );

            $project->refuseGuardedColumns(
                self::GROUP_REFERRAL,
                self::REFERRAL_COLUMNS,
                'ProjectReferralService',
                'INV-P13'
            );
        });
    }

    public function moduleSlug(): string
    {
        return 'projects';
    }

    protected function activityModule(): ?string
    {
        return 'projects';
    }

    /**
     * The human reference used on screens and in notifications.
     */
    public function getReferenceAttribute(): string
    {
        return (string) $this->code;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (§2.12)
    |--------------------------------------------------------------------------
    */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function progressSetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'progress_set_by');
    }

    public function valueRevisions(): HasMany
    {
        return $this->hasMany(ProjectValueRevision::class, 'project_id')->orderBy('revision_no');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class, 'project_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class, 'project_id')->orderBy('sort_order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'project_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * The staff on this project, through the one people table (§1.3: there is no `project_user` pivot).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->using(ProjectMember::class)
            ->withPivot(['id', 'role', 'notes', 'deleted_at'])
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    /**
     * The collaborators on this project, through the **same** pivot table (§1.3, phase-08-09 §2.1).
     */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(
            $this->laterPhaseModel(self::COLLABORATOR_MODEL, 'Phase 8'),
            'project_members',
            'project_id',
            'collaborator_id'
        )
            ->withPivot(['id', 'role', 'notes', 'deleted_at'])
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    /**
     * The referring collaborator — a display snapshot, always read `withTrashed()` (§2.1, D37).
     */
    public function collaborator(): BelongsTo
    {
        return $this->belongsTo($this->laterPhaseModel(self::COLLABORATOR_MODEL, 'Phase 8'), 'collaborator_id');
    }

    /**
     * The authoritative attribution record (Phase 9/10) — never `collaborator_id` (F-8.2).
     */
    public function collaboratorReferrals(): HasMany
    {
        return $this->hasMany(
            $this->laterPhaseModel(self::COLLABORATOR_REFERRAL_MODEL, 'the Phase 10 spine set'),
            'project_id'
        );
    }

    /**
     * Client payments against this project (Phase 10) — the only thing that triggers project commission.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(
            $this->laterPhaseModel(self::PROJECT_PAYMENT_MODEL, 'the Phase 10 spine set'),
            'project_id'
        );
    }
}
