<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * The visual style of a CMS-authored button (phase-03 §3) — `cta_blocks.primary_style` /
 * `secondary_style` and every `link` field's `style` key (header Login / Contact / Admission / CTA,
 * hero buttons, footer badges).
 *
 * **`classes()` is the only place a public button's Tailwind classes are written** (§3). A site view
 * or partial never spells out a colour: the tokens below are the CSS variables Phase 2 publishes
 * from `branding.brand_color` / `accent_color` (`--brand-500`, exposed to Tailwind as `brand-*`), so
 * re-branding the panels re-brands the public buttons with no view edit (§8.14). Every utility has
 * its `dark:` counterpart, as CLAUDE.md §6 requires.
 */
enum ButtonStyle: string
{
    use HasOptions;

    case Primary = 'primary';
    case Secondary = 'secondary';
    case Outline = 'outline';
    case Ghost = 'ghost';
    case Link = 'link';

    /**
     * Shared shape, typography and focus ring — never a colour.
     */
    public const BASE = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg font-semibold tracking-tight transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-brand-400/40 dark:focus-visible:ring-offset-slate-950';

    /**
     * The size every solid site button shares. `link` opts out so it sits inside a sentence.
     */
    public const SIZE = 'h-11 px-6 text-sm';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary',
            self::Secondary => 'Secondary',
            self::Outline => 'Outline',
            self::Ghost => 'Ghost',
            self::Link => 'Text link',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Primary => 'indigo',
            self::Secondary => 'slate',
            self::Outline => 'cyan',
            self::Ghost => 'slate',
            self::Link => 'violet',
        };
    }

    /**
     * The complete class attribute for this style, base and size included.
     */
    public function classes(): string
    {
        return trim(self::BASE.' '.match ($this) {
            self::Primary => self::SIZE.' border border-transparent bg-brand-600 text-white shadow-sm hover:bg-brand-700 hover:shadow active:bg-brand-800 dark:bg-brand-500 dark:hover:bg-brand-400',
            self::Secondary => self::SIZE.' border border-transparent bg-slate-900 text-white shadow-sm hover:bg-slate-800 active:bg-slate-950 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100',
            self::Outline => self::SIZE.' border border-brand-600 bg-transparent text-brand-700 hover:bg-brand-50 active:bg-brand-100 dark:border-brand-400 dark:text-brand-300 dark:hover:bg-brand-500/10',
            self::Ghost => self::SIZE.' border border-transparent bg-transparent text-slate-700 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white',
            self::Link => 'border-transparent bg-transparent p-0 text-sm text-brand-600 underline-offset-4 hover:underline dark:text-brand-400',
        });
    }

    /**
     * The nearest `x-ui.button` variant, for the admin-side previews of §8.11.
     *
     * The admin shell's component set (Phase 1) ships `primary`, `secondary`, `ghost`, `danger`,
     * `success` and `link`; `outline` has no counterpart there and previews as `secondary`. This
     * mapping exists so the CTA editor can preview with the shared component instead of a second
     * class list.
     */
    public function uiVariant(): string
    {
        return match ($this) {
            self::Primary => 'primary',
            self::Secondary, self::Outline => 'secondary',
            self::Ghost => 'ghost',
            self::Link => 'link',
        };
    }
}
