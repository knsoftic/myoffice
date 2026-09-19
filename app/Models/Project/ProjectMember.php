<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Enums\ProjectMemberRole;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Concerns\RelatesToLaterPhases;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * One person's membership of one project (phase-06 §2.3, requirement §20).
 *
 * **[D-P6-4]** This is the only project-people table in the system — there is no `project_user` and no
 * `collaborator_project`. It extends `Pivot` because {@see Project::users()} and
 * {@see Project::collaborators()} both run through it, but it is a first-class record: its own
 * auto-incrementing id, timestamps, soft deletes, blameable pair, policy and audit trail.
 *
 * **Exactly one party** (INV-P10): a staff member is `user_id` ([D-P6-2] / D32), a collaborator is
 * `collaborator_id`, never both and never neither — `chk_pm_one_party` says so in the database and the
 * `saving` hook says so in Eloquent, with a message a developer can act on.
 *
 * **At most one active membership** per person per project (INV-P11), decided by
 * `uq_pm_user_active` / `uq_pm_collaborator_active` over the generated `active_guard` column. Removal is a
 * soft delete, so "who was on this project in March" stays answerable and the slot is freed at the same
 * time.
 *
 * A collaborator is never given `manager` ({@see ProjectMemberRole::canBeCollaborator()}) — the manager is
 * the staff member accountable for delivery.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $user_id
 * @property int|null $collaborator_id
 * @property ProjectMemberRole $role
 * @property string|null $notes
 * @property int|null $active_guard generated STORED — carries the two unique indexes
 */
class ProjectMember extends Pivot
{
    use Blameable;
    use LogsActivityWithContext;
    use RelatesToLaterPhases;
    use SoftDeletes;

    /** Phase 8 (phase-08-09 §2.1). */
    public const COLLABORATOR_MODEL = 'App\\Models\\Collaborator\\Collaborator';

    public $incrementing = true;

    public $timestamps = true;

    protected $table = 'project_members';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'user_id',
        'collaborator_id',
        'role',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'member',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'user_id' => 'integer',
            'collaborator_id' => 'integer',
            'role' => ProjectMemberRole::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (ProjectMember $member): void {
            $hasUser = $member->getAttribute('user_id') !== null;
            $hasCollaborator = $member->getAttribute('collaborator_id') !== null;

            if ($hasUser === $hasCollaborator) {
                throw new LogicException(sprintf(
                    'ProjectMember #%s: a membership belongs to exactly one of a user or a collaborator '
                    .'(phase-06 INV-P10, chk_pm_one_party); this row has %s.',
                    (string) ($member->getKey() ?? 'new'),
                    $hasUser ? 'both' : 'neither'
                ));
            }

            $role = $member->getAttribute('role');
            $role = $role instanceof ProjectMemberRole ? $role : ProjectMemberRole::tryFrom((string) $role);

            if ($hasCollaborator && $role !== null && ! $role->canBeCollaborator()) {
                throw new LogicException(sprintf(
                    'ProjectMember #%s: a collaborator may not hold the %s role (phase-06 §3) — that is an '
                    .'accountable staff position.',
                    (string) ($member->getKey() ?? 'new'),
                    $role->value
                ));
            }
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
     * Is this membership live (as opposed to a historical record of someone who was removed)?
     */
    public function isActive(): bool
    {
        return $this->deleted_at === null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo($this->laterPhaseModel(self::COLLABORATOR_MODEL, 'Phase 8'), 'collaborator_id');
    }
}
