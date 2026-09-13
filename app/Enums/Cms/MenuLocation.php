<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * The layout slot one menu fills (`menus.location`, phase-03 §2.5/§3).
 *
 * `UNIQUE uq_menus_location` allows exactly one menu per slot ([D-W3-4]) — which is why the footer
 * has three locations rather than one menu with three columns.
 *
 * `mobile` is optional: when no `mobile` menu exists the off-canvas drawer renders the `header` menu
 * (§3, §8.14), which is what `fallback()` expresses so no Blade file has to know the rule.
 */
enum MenuLocation: string
{
    use HasOptions;

    case Header = 'header';
    case FooterPrimary = 'footer_primary';
    case FooterSecondary = 'footer_secondary';
    case FooterLegal = 'footer_legal';
    case Mobile = 'mobile';

    public function label(): string
    {
        return match ($this) {
            self::Header => 'Header navigation',
            self::FooterPrimary => 'Footer column 1',
            self::FooterSecondary => 'Footer column 2',
            self::FooterLegal => 'Footer legal links',
            self::Mobile => 'Mobile drawer',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Header => 'indigo',
            self::FooterPrimary => 'slate',
            self::FooterSecondary => 'slate',
            self::FooterLegal => 'cyan',
            self::Mobile => 'violet',
        };
    }

    /**
     * Is this one of the three footer slots?
     */
    public function isFooter(): bool
    {
        return $this === self::FooterPrimary
            || $this === self::FooterSecondary
            || $this === self::FooterLegal;
    }

    /**
     * The slot to render when this one has no menu, or null when an empty slot renders nothing.
     *
     * Only `mobile` falls back (to `header`, §3). A missing footer column is a deliberate empty
     * column, not an error.
     */
    public function fallback(): ?self
    {
        return $this === self::Mobile ? self::Header : null;
    }
}
