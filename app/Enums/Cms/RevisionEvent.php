<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * What produced one append-only content snapshot (`cms_revisions.event`, phase-03 §2.14/§3).
 *
 * `cms_revisions` carries no `deleted_at` — it is a revision table, category rule **D19**
 * (CLAUDE.md §3). Rows are pruned by count (`website.revision_keep`, default 20) **except**
 * published snapshots, which are never pruned: that is what `isPublishSnapshot()` marks, and it is
 * mirrored in the `is_published_snapshot` column the pruner reads.
 *
 * `reverted` and `unpublished` require a reason (§2.14), which is why both are discretionary acts in
 * the activity log too (INV-16).
 */
enum RevisionEvent: string
{
    use HasOptions;

    case Created = 'created';
    case DraftSaved = 'draft_saved';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Reverted = 'reverted';
    case Restored = 'restored';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::DraftSaved => 'Draft saved',
            self::Published => 'Published',
            self::Unpublished => 'Unpublished',
            self::Reverted => 'Reverted to an earlier revision',
            self::Restored => 'Restored from the recycle bin',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Created => 'indigo',
            self::DraftSaved => 'slate',
            self::Published => 'emerald',
            self::Unpublished => 'rose',
            self::Reverted => 'amber',
            self::Restored => 'cyan',
        };
    }

    /**
     * Is this revision the live snapshot of its moment — and therefore exempt from pruning?
     */
    public function isPublishSnapshot(): bool
    {
        return $this === self::Published;
    }

    /**
     * Does this event demand a reason before it may be written (§2.14)?
     */
    public function requiresReason(): bool
    {
        return $this === self::Unpublished || $this === self::Reverted;
    }
}
