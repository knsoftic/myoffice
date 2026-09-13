<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * The `<changefreq>` hint of one sitemap URL (`seo_meta.sitemap_changefreq`, phase-03 §2.12/§3).
 *
 * The values are the sitemaps.org vocabulary verbatim and are written into the XML unchanged by
 * `SitemapService::generate()` (§6.5). New `seo_meta` rows are stamped from
 * `seo.sitemap_changefreq_default` (§5.2), default `weekly`.
 */
enum SitemapChangeFrequency: string
{
    use HasOptions;

    case Always = 'always';
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Never = 'never';

    public function label(): string
    {
        return match ($this) {
            self::Always => 'Always',
            self::Hourly => 'Hourly',
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
            self::Never => 'Never',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Always, self::Hourly => 'rose',
            self::Daily => 'amber',
            self::Weekly => 'emerald',
            self::Monthly => 'cyan',
            self::Yearly => 'indigo',
            self::Never => 'slate',
        };
    }
}
