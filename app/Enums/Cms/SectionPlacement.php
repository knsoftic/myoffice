<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * Where a placed section lives (`website_sections.placement`, phase-03 §2.2/§3).
 *
 * A placement is a slot in the public layout, not a page: `global_header` and `global_footer` render
 * on every request, `home` is the ordered body of the home page, and `page` is the ordered body of
 * one `pages` row with `layout = sections`.
 *
 * **Later phases add cases** (`courses_index`, `services_index`). A new placement is a new case here
 * plus a registry entry in `App\Support\Cms\SectionRegistry` — never a migration, because
 * `website_sections.placement` is a `string(32)` cast to this enum.
 *
 * The CHECK `chk_ws_page_placement` keeps `page_id` and this column honest: `page` always carries a
 * `page_id`, every other placement never does.
 */
enum SectionPlacement: string
{
    use HasOptions;

    case Home = 'home';
    case GlobalHeader = 'global_header';
    case GlobalFooter = 'global_footer';
    case Page = 'page';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Home page',
            self::GlobalHeader => 'Site header',
            self::GlobalFooter => 'Site footer',
            self::Page => 'Custom page',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Home => 'indigo',
            self::GlobalHeader => 'cyan',
            self::GlobalFooter => 'slate',
            self::Page => 'violet',
        };
    }

    /**
     * Does this placement render on every public request?
     *
     * The header and the footer are site chrome: one instance each, no `page_id`, and their
     * published snapshots are read from the version-stamped cache on every page (§6.9).
     */
    public function isGlobal(): bool
    {
        return $this === self::GlobalHeader || $this === self::GlobalFooter;
    }

    /**
     * Does this placement require a `pages` row (`website_sections.page_id`)?
     *
     * True only for `page` — the other half of CHECK `chk_ws_page_placement`.
     */
    public function allowsPage(): bool
    {
        return $this === self::Page;
    }
}
