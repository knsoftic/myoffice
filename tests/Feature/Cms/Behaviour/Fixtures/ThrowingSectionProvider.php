<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour\Fixtures;

use App\Contracts\Cms\SectionDataProvider;
use App\Models\Cms\WebsiteSection;
use RuntimeException;

/**
 * An `is_live` provider with a bug: one module's failure must never take the public page down (INV-2).
 *
 * Not named `*Test.php`, so PHPUnit does not run it.
 */
final class ThrowingSectionProvider implements SectionDataProvider
{
    public function resolve(WebsiteSection $section): array
    {
        throw new RuntimeException('The live feed is broken.');
    }
}
