<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Enums\CommissionCalculationType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use App\Services\Project\Exceptions\ImmutableRevisionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reasoned change to a project's value or commission override (phase-06 §2.2, requirement §107).
 *
 * **Append-only (INV-P3).** There is no `deleted_at`, `updating` and `deleting` both throw
 * {@see ImmutableRevisionException}, and `trg_pvr_no_delete` raises `SQLSTATE '45000'` underneath if code
 * ever reaches the database another way. A wrong value is corrected by writing the **next** revision.
 *
 * The row stands alone on purpose: `old_*` / `new_*` pairs, `delta_amount` (a STORED generated column,
 * signed), the mandatory `reason`, the business `effective_on` date, `changed_by_name` as a snapshot immune
 * to the user later being deleted, and the actor's IP. That is what lets the spine's §6.6 case 8 decide
 * what happens to an entitlement months later without re-reading the project.
 *
 * Nothing here is mass assignable: `ProjectValueService::revise()` builds the row explicitly, inside the
 * same transaction that updates the project.
 *
 * @property int $id
 * @property int $project_id
 * @property int $revision_no
 * @property string $old_project_value
 * @property string $new_project_value
 * @property string $old_discount_amount
 * @property string $new_discount_amount
 * @property string $old_net_value
 * @property string $new_net_value
 * @property string $delta_amount generated STORED, signed
 * @property CommissionCalculationType|null $old_commission_type
 * @property CommissionCalculationType|null $new_commission_type
 * @property string|null $old_commission_rate
 * @property string|null $new_commission_rate
 * @property string|null $old_commission_fixed_amount
 * @property string|null $new_commission_fixed_amount
 * @property string $reason
 * @property Carbon $effective_on
 * @property int|null $changed_by
 * @property string $changed_by_name
 * @property string|null $ip_address
 * @property string|null $notes
 */
class ProjectValueRevision extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    protected $table = 'project_value_revisions';

    /**
     * Deliberately empty: the service writes every column explicitly (§6.1).
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
            'project_id' => 'integer',
            'revision_no' => 'integer',
            'old_project_value' => 'decimal:2',
            'new_project_value' => 'decimal:2',
            'old_discount_amount' => 'decimal:2',
            'new_discount_amount' => 'decimal:2',
            'old_net_value' => 'decimal:2',
            'new_net_value' => 'decimal:2',
            'delta_amount' => 'decimal:2',
            'old_commission_type' => CommissionCalculationType::class,
            'new_commission_type' => CommissionCalculationType::class,
            'old_commission_rate' => 'decimal:4',
            'new_commission_rate' => 'decimal:4',
            'old_commission_fixed_amount' => 'decimal:2',
            'new_commission_fixed_amount' => 'decimal:2',
            'effective_on' => 'date',
            'changed_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (ProjectValueRevision $revision): void {
            throw ImmutableRevisionException::updated($revision->getKey() ?? 'new');
        });

        static::deleting(static function (ProjectValueRevision $revision): void {
            throw ImmutableRevisionException::deleted($revision->getKey() ?? 'new');
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
