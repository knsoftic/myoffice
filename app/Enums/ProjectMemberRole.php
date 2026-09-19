<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * A person's role **on one project** (phase-06 §3, `project_members.role`).
 *
 * These are project roles, not job titles and not RBAC roles: the job title lives on the Phase 7 employee
 * record and what the account may do in the application is governed by its spatie role. A collaborator is
 * never given `manager` ({@see canBeCollaborator()}), because the manager is the staff member accountable
 * for delivery.
 */
enum ProjectMemberRole: string
{
    use HasOptions;

    case Manager = 'manager';
    case Lead = 'lead';
    case Member = 'member';
    case Reviewer = 'reviewer';
    case Observer = 'observer';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Manager',
            self::Lead => 'Lead',
            self::Member => 'Member',
            self::Reviewer => 'Reviewer',
            self::Observer => 'Observer',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Manager => 'indigo',
            self::Lead => 'violet',
            self::Member => 'sky',
            self::Reviewer => 'amber',
            self::Observer => 'slate',
        };
    }

    /**
     * May this role manage the project's team, milestones and tasks from inside the project?
     */
    public function canManage(): bool
    {
        return $this === self::Manager || $this === self::Lead;
    }

    /**
     * May a member in this role log time against the project? Everything but `observer` (§3).
     *
     * `TaskService::assign()` additionally requires this of a **collaborator** assignee.
     */
    public function canLogTime(): bool
    {
        return $this !== self::Observer;
    }

    /**
     * May a collaborator hold this role? Never `manager`, and never `lead` — both are accountable staff
     * positions (§3 lists `member`, `reviewer`, `observer`).
     */
    public function canBeCollaborator(): bool
    {
        return in_array($this, [self::Member, self::Reviewer, self::Observer], true);
    }

    /**
     * The roles a collaborator may be given, for the §8.6 team picker.
     *
     * @return list<self>
     */
    public static function forCollaborator(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $role): bool => $role->canBeCollaborator()));
    }
}
