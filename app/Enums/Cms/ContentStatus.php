<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * The publication state of every CMS content row (phase-03 §3).
 *
 * ONE status enum serves the whole content side of the system (F-5.1, resolutions §2.2): phase-03's
 * `website_sections.status`, `pages.status`, `cta_blocks.status` and `faqs.status`, and phase-04's
 * `services`, `portfolio_items`, `team_members`, `success_stories` and `blog_posts` statuses.
 * **`PostStatus` does not exist** — a former `PostStatus::*` reference is `ContentStatus::*` 1:1, the
 * backing strings being identical.
 *
 * `scheduled` is harmless where nothing schedules (a service simply never reaches it) and load-bearing
 * where something does: `pages.published_at` in the future plus `scheduled` publishes itself (§10.4),
 * and phase-04's `blog:publish-scheduled` command does the same for posts.
 *
 * Status is **not** the enable/disable switch. `website_sections.is_enabled` is independent of
 * `status` (§2.2): a published section that is disabled stops rendering without losing its snapshot.
 * Only `published` is public (INV-1), and only through `published_content` — never `content`.
 */
enum ContentStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Scheduled => 'amber',
            self::Published => 'emerald',
            self::Archived => 'rose',
        };
    }

    /**
     * May an anonymous visitor see this row?
     *
     * True for `published` only. A `scheduled` row is not public until the scheduler flips it, which
     * is the whole point of the case; `archived` is history.
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /**
     * May an editor still write a draft against this row?
     *
     * Everything except `archived`. Editing a `published` row writes `content` and leaves
     * `published_content` untouched (INV-1), which is why publishing is a separate ability
     * (`change_status`, D-W3-10) rather than a side effect of saving.
     */
    public function isEditable(): bool
    {
        return $this !== self::Archived;
    }
}
