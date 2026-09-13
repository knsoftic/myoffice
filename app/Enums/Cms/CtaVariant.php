<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * How a reusable CTA block renders (`cta_blocks.variant`, phase-03 §2.8/§3) — requirement §100.
 *
 * A CTA block is referenced, never copied, so changing the variant once changes it everywhere it is
 * used. `view()` names the public partial `<x-site.cta>` includes; the partials live under
 * `resources/views/site/cta/` and receive the published CTA array, never a model (§8.14).
 */
enum CtaVariant: string
{
    use HasOptions;

    case Banner = 'banner';
    case Card = 'card';
    case Inline = 'inline';
    case Split = 'split';
    case FullWidth = 'full_width';

    public function label(): string
    {
        return match ($this) {
            self::Banner => 'Banner',
            self::Card => 'Card',
            self::Inline => 'Inline',
            self::Split => 'Split (text and buttons side by side)',
            self::FullWidth => 'Full width',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Banner => 'indigo',
            self::Card => 'cyan',
            self::Inline => 'slate',
            self::Split => 'violet',
            self::FullWidth => 'amber',
        };
    }

    /**
     * The Blade partial that renders this variant.
     */
    public function view(): string
    {
        return 'site.cta.'.$this->value;
    }
}
