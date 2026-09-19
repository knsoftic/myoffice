<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Who may read one task comment (phase-06 §2.7, `task_comments.visibility`).
 *
 * There is deliberately no `client` case: the client panel is read-only over projects, milestones, tasks
 * and files (§7.7) and never sees the task conversation. A comment is either staff-only or shared with
 * the collaborators on the project.
 */
enum CommentVisibility: string
{
    use HasOptions;

    case Internal = 'internal';
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal only',
            self::Team => 'Project team',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Internal => 'slate',
            self::Team => 'sky',
        };
    }

    /**
     * May a collaborator on the project read it? `team` only (§3).
     */
    public function visibleToCollaborator(): bool
    {
        return $this === self::Team;
    }

    /**
     * @return list<string>
     */
    public static function valuesVisibleToCollaborator(): array
    {
        return [self::Team->value];
    }
}
