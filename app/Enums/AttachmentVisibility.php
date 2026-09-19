<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Who may see one attachment (phase-06 §2.9, `attachments.visibility`).
 *
 * This column is the **only** place client visibility of a file is recorded — never a boolean on another
 * table (CLAUDE.md §3). Phase 22 reuses it when it extends `attachments` to tickets, meetings and
 * messages, and Phase 5's `client_documents` keeps its own `visible_to_client` flag because it is a
 * different, named store.
 */
enum AttachmentVisibility: string
{
    use HasOptions;

    case Internal = 'internal';
    case Team = 'team';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal only',
            self::Team => 'Project team',
            self::Client => 'Visible to client',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Internal => 'slate',
            self::Team => 'sky',
            self::Client => 'emerald',
        };
    }

    /**
     * May a collaborator on the project see it? `team` and `client` (§3).
     */
    public function visibleToCollaborator(): bool
    {
        return $this !== self::Internal;
    }

    /**
     * May the client see it in their panel? `client` only (§3).
     */
    public function visibleToClient(): bool
    {
        return $this === self::Client;
    }

    /**
     * The visibilities a given panel may read, for scoping a query rather than filtering in PHP.
     *
     * @return list<string>
     */
    public static function valuesVisibleToClient(): array
    {
        return [self::Client->value];
    }

    /**
     * @return list<string>
     */
    public static function valuesVisibleToCollaborator(): array
    {
        return [self::Team->value, self::Client->value];
    }
}
