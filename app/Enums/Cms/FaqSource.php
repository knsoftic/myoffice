<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * Which FAQs a `faq` section shows (the section type's `source` field, phase-03 §6.1/§6.13).
 *
 * **Additive to §3's enum list**, declared because the three values drive real behaviour rather than
 * presentation: `FaqService::forSection()` resolves each one differently (§6.13) and the answers are
 * folded into the section's `published_content` at publish time.
 *
 * | case       | resolves to                                                                   |
 * |------------|-------------------------------------------------------------------------------|
 * | `category` | published FAQs of the selected category, in `sort_order`                       |
 * | `selected` | the `faq_website_section` pivot rows, in pivot order (hand-picked)             |
 * | `featured` | published FAQs with `is_featured = true`                                       |
 */
enum FaqSource: string
{
    use HasOptions;

    case Category = 'category';
    case Selected = 'selected';
    case Featured = 'featured';

    public function label(): string
    {
        return match ($this) {
            self::Category => 'All questions in one category',
            self::Selected => 'Hand-picked questions',
            self::Featured => 'Featured questions',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Category => 'indigo',
            self::Selected => 'violet',
            self::Featured => 'amber',
        };
    }

    /**
     * Does this source need the section's `faq_category_ref` field filled in?
     */
    public function requiresCategory(): bool
    {
        return $this === self::Category;
    }

    /**
     * Does this source read the `faq_website_section` pivot (§2.11)?
     */
    public function usesPivot(): bool
    {
        return $this === self::Selected;
    }
}
