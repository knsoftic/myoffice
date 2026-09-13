<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * How one statistic item gets its number (`website_section_items.value_mode`, phase-03 §2.3).
 *
 * **Additive to §3's enum list**, declared because CLAUDE.md §1.8 forbids a status as a string and
 * because the pair is already a database fact: CHECK `chk_wsi_value` is
 * `value_mode IN ('manual','auto') AND (value_mode = 'manual' OR metric IS NOT NULL)`. The two cases
 * here are exactly that IN list, so the form, the service and the constraint cannot disagree.
 *
 * `App\Services\Cms\StatisticsProvider::valueFor()` is the single rule (§6.11): `auto` resolves the
 * item's `StatisticMetric` and falls back to `manual_value` when the metric cannot be resolved;
 * `manual` uses `manual_value` alone. **A null result renders nothing, never `0`** (INV-12).
 *
 * `manual_value` is `decimal(15,2)` and is read and written as a **string** — CLAUDE.md §1.4 applies
 * to every number, not only to money.
 */
enum StatisticValueMode: string
{
    use HasOptions;

    case Manual = 'manual';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Typed in',
            self::Auto => 'Counted live',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Manual => 'slate',
            self::Auto => 'emerald',
        };
    }

    /**
     * Must the item name a `StatisticMetric` (the second half of CHECK `chk_wsi_value`)?
     */
    public function requiresMetric(): bool
    {
        return $this === self::Auto;
    }
}
