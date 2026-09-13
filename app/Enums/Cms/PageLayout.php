<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * How a custom page builds its body (`pages.layout`, phase-03 §2.7/§3).
 *
 * `content` is one sanitised rich-text body (the four seeded policy pages of §6.14 are these).
 * `sections` hosts `website_sections` rows with `placement = page`, so a landing page is composed
 * from the same registry-declared section types as the home page and gains the same enable /
 * disable / reorder / publish behaviour (§7, §100).
 *
 * This is not the same thing as `pages.template`, which chooses the allowlisted Blade wrapper
 * (`site.pages.default`, `site.pages.wide`, `site.pages.legal`) the body is rendered inside.
 */
enum PageLayout: string
{
    use HasOptions;

    case Content = 'content';
    case Sections = 'sections';

    public function label(): string
    {
        return match ($this) {
            self::Content => 'Rich text content',
            self::Sections => 'Composed of sections',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Content => 'slate',
            self::Sections => 'indigo',
        };
    }

    /**
     * Does this page get a placement tab in the section manager (§8.4) and a `sections` body?
     */
    public function usesSections(): bool
    {
        return $this === self::Sections;
    }
}
