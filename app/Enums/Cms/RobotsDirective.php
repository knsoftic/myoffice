<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * The indexing directive of one SEO target (`seo_meta.robots`, phase-03 §2.12/§3) — requirement §105
 * "index / noindex".
 *
 * `toHeader()` is the exact string emitted both as the `<meta name="robots">` content and as the
 * `X-Robots-Tag` response header, so the two can never drift apart.
 *
 * The resolved value is not always the stored one: `SeoService` forces `noindex_nofollow` when
 * `seo.robots_indexable` is false, when `maintenance.maintenance_mode` is on, or when the response
 * is a preview (§6.5, INV-9). **The strictest wins, never the loosest.**
 */
enum RobotsDirective: string
{
    use HasOptions;

    case IndexFollow = 'index_follow';
    case IndexNofollow = 'index_nofollow';
    case NoindexFollow = 'noindex_follow';
    case NoindexNofollow = 'noindex_nofollow';

    public function label(): string
    {
        return match ($this) {
            self::IndexFollow => 'Index, follow links',
            self::IndexNofollow => 'Index, do not follow links',
            self::NoindexFollow => 'Do not index, follow links',
            self::NoindexNofollow => 'Do not index, do not follow links',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::IndexFollow => 'emerald',
            self::IndexNofollow => 'amber',
            self::NoindexFollow => 'amber',
            self::NoindexNofollow => 'slate',
        };
    }

    /**
     * May this target appear in search results — and therefore in `sitemap.xml` (§6.5)?
     */
    public function isIndexable(): bool
    {
        return $this === self::IndexFollow || $this === self::IndexNofollow;
    }

    /**
     * May crawlers follow the links on this target?
     */
    public function isFollowable(): bool
    {
        return $this === self::IndexFollow || $this === self::NoindexFollow;
    }

    /**
     * The directive as a crawler reads it: "noindex, nofollow".
     */
    public function toHeader(): string
    {
        return match ($this) {
            self::IndexFollow => 'index, follow',
            self::IndexNofollow => 'index, nofollow',
            self::NoindexFollow => 'noindex, follow',
            self::NoindexNofollow => 'noindex, nofollow',
        };
    }
}
