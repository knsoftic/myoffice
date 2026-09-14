<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour\Fixtures;

use App\Contracts\Cms\SectionDataProvider;
use App\Models\Cms\WebsiteSection;
use App\Support\Modules;

/**
 * A later phase's `is_live` provider whose output depends on a module switch — the shape of a courses or
 * services strip under D26 ("a disabled feature leaves no trace"). It shows one card while the module named
 * by `MODULE` is on and nothing while it is off, and reads nothing else, so a stale page is the only way
 * the card can survive a switch.
 *
 * Not named `*Test.php`, so PHPUnit does not run it.
 */
final class ModuleGatedSectionProvider implements SectionDataProvider
{
    public const MODULE = 'leads';

    public const CARD = 'FT Module Gated Card';

    public function resolve(WebsiteSection $section): array
    {
        if (! Modules::enabled(self::MODULE)) {
            return ['items' => []];
        }

        return ['items' => [
            ['title' => self::CARD, 'excerpt' => null, 'url' => null, 'media' => null, 'icon' => null, 'meta' => null],
        ]];
    }
}
