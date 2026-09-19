<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Enums\MilestoneStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Concerns\RelatesToLaterPhases;
use App\Models\Project\Concerns\GuardsServiceOwnedColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One milestone of a project (phase-06 §2.4, requirement §21).
 *
 * `amount` is nullable and that is a fact, not a gap: NULL means "not a payment milestone", which is
 * different from `0.00`. When Phase 10 ships, a `project_payment` may reference this row and the
 * `milestone` commission base reads `amount` — which is why deleting a referenced milestone is refused
 * outright and why the soft delete keeps `project_payments.project_milestone_id` resolvable.
 *
 * `weight` (`CHECK > 0`) is the §6.3 progress weighting, so a three-week milestone can outweigh a one-day
 * one. `progress_percent` is derived and is written by `ProjectProgressService` alone (INV-P8).
 *
 * A cancelled milestone is **excluded** from the project's progress denominator, never counted as 0 %
 * (INV-P9, {@see MilestoneStatus::countsTowardProgress()}).
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $start_date
 * @property Carbon|null $deadline
 * @property string|null $amount
 * @property string $weight
 * @property string $progress_percent
 * @property MilestoneStatus $status
 * @property int $sort_order
 * @property Carbon|null $completed_on
 */
class ProjectMilestone extends Model
{
    use Blameable;
    use GuardsServiceOwnedColumns;
    use LogsActivityWithContext;
    use RelatesToLaterPhases;
    use SoftDeletes;

    /** The spine's project payment model (phase-10-12 §2). */
    public const PROJECT_PAYMENT_MODEL = 'App\\Models\\Finance\\ProjectPayment';

    /** INV-P8 — `ProjectProgressService` owns this. */
    public const PROGRESS_COLUMNS = ['progress_percent'];

    public const GROUP_PROGRESS = 'progress';

    protected $table = 'project_milestones';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'name',
        'description',
        'start_date',
        'deadline',
        'amount',
        'weight',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'start_date' => 'date',
            'deadline' => 'date',
            'amount' => 'decimal:2',
            'weight' => 'decimal:4',
            'progress_percent' => 'decimal:4',
            'status' => MilestoneStatus::class,
            'sort_order' => 'integer',
            'completed_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (ProjectMilestone $milestone): void {
            $milestone->refuseGuardedColumns(
                self::GROUP_PROGRESS,
                self::PROGRESS_COLUMNS,
                'ProjectProgressService',
                'INV-P8'
            );
        });
    }

    public function moduleSlug(): string
    {
        return 'project_milestones';
    }

    protected function activityModule(): ?string
    {
        return 'project_milestones';
    }

    /**
     * Is this milestone a payment milestone — one the spine's `milestone` commission base can read?
     */
    public function isPayable(): bool
    {
        return $this->amount !== null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_milestone_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Client payments booked against this milestone (Phase 10) — what makes deleting it refusable.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(
            $this->laterPhaseModel(self::PROJECT_PAYMENT_MODEL, 'the Phase 10 spine set'),
            'project_milestone_id'
        );
    }
}
