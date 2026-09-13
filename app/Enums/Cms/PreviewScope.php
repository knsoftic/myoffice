<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * What a preview request is previewing (`PreviewService`, phase-03 §6.12/§3).
 *
 * No table backs this: preview is a signed URL plus the session ([D-W3-12]). A preview response is
 * never cached and never indexed — `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`, and
 * `SeoService` forces `noindex_nofollow` (INV-9). No preview route writes anything.
 *
 * `placement` previews a whole slot in order (the home page's sections, a page's sections) rather
 * than one row, which is how an editor checks that a reordered strip reads correctly before
 * publishing it.
 */
enum PreviewScope: string
{
    use HasOptions;

    case Section = 'section';
    case Page = 'page';
    case Placement = 'placement';

    public function label(): string
    {
        return match ($this) {
            self::Section => 'One section',
            self::Page => 'One page',
            self::Placement => 'A whole placement',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Section => 'indigo',
            self::Page => 'violet',
            self::Placement => 'cyan',
        };
    }
}
