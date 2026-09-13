<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * How a menu item resolves to a URL (`menu_items.link_type`, phase-03 §2.6/§3).
 *
 * A typed link means an internal link is never a hardcoded string: renaming a page slug fixes every
 * menu at once, because `MenuService::resolveUrl()` computes the href and never stores it (§6.3).
 *
 * `none` is a dropdown parent that is a label only. Later phases add `course`, `service` and
 * `blog_category` cases and fill `menu_items.linkable_type` / `linkable_id`; phase-03 writes nothing
 * there.
 */
enum MenuItemLinkType: string
{
    use HasOptions;

    case Page = 'page';
    case Route = 'route';
    case SectionAnchor = 'section_anchor';
    case Url = 'url';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Page => 'Custom page',
            self::Route => 'Application route',
            self::SectionAnchor => 'Section on the home page',
            self::Url => 'External URL',
            self::None => 'No link (dropdown label)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Page => 'indigo',
            self::Route => 'cyan',
            self::SectionAnchor => 'violet',
            self::Url => 'amber',
            self::None => 'slate',
        };
    }

    /**
     * Does this type need a target value before the item can be saved?
     *
     * Everything except `none`. `MenuService::storeItem()` validates the matching column of
     * `targetColumn()` (§6.3).
     */
    public function requiresTarget(): bool
    {
        return $this !== self::None;
    }

    /**
     * The `menu_items` column this type stores its target in, or null when it needs none.
     *
     * Declared here so the validation in `MenuService` and the form in the menu editor cannot
     * disagree about which column belongs to which link type.
     */
    public function targetColumn(): ?string
    {
        return match ($this) {
            self::Page => 'page_id',
            self::Route => 'route_name',
            self::SectionAnchor => 'anchor',
            self::Url => 'url',
            self::None => null,
        };
    }
}
