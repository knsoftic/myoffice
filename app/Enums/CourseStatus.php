<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a course stands in the catalogue (`courses.status`, requirement §62, phase-14-17 §2.30.1).
 *
 * **Three cases, and the middle one is the only one the public sees.** A draft is the institute
 * thinking out loud — it may have no outline, no fee and no category yet — so it is never listed,
 * never linked and never applied to. An archived course is one the institute has stopped selling; it
 * keeps every batch, admission and fee row that ever pointed at it, which is exactly why archiving
 * exists instead of deletion.
 *
 * There is no `coming_soon`: a course the institute wants to advertise before it runs is a published
 * course with `admission_open = false`, which is one fact in one column rather than two that can
 * disagree.
 */
enum CourseStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Published => 'emerald',
            self::Archived => 'amber',
        };
    }

    /**
     * Is it on the public site?
     *
     * The single test the catalogue, the landing page, the sitemap and the admission form all ask, so
     * none of them can answer it differently.
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /**
     * May the catalogue still sell it? An archived course is readable for ever and orderable never.
     */
    public function isSellable(): bool
    {
        return $this === self::Published;
    }
}
