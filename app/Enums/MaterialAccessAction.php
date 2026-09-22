<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What somebody did with a material (phase-19-23 §3.1, §2.5, INV-19-4).
 *
 * Three actions rather than a boolean, because they answer different questions. "How many students
 * opened this at all" is a `view`; "how many took a copy away" is a `download`; and a `link` material
 * has no bytes to serve, so `link_open` is the only thing there is to record for it.
 *
 * `course_materials.download_count` caches `download` **and** `link_open` — leaving link opens out
 * would make every link material look as though nobody had ever touched it.
 */
enum MaterialAccessAction: string
{
    use HasOptions;

    /** Opened inline — a PDF or an image rendered in the browser rather than saved. */
    case View = 'view';

    /** The bytes were sent as an attachment. */
    case Download = 'download';

    /** A `link` material: the redirect to its external URL was followed. */
    case LinkOpen = 'link_open';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Viewed',
            self::Download => 'Downloaded',
            self::LinkOpen => 'Opened the link',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::View => 'slate',
            self::Download => 'emerald',
            self::LinkOpen => 'sky',
        };
    }

    /**
     * Counts towards `download_count`. A link open does: it is the only way a link material can be
     * consumed, and excluding it would leave every link looking untouched.
     */
    public function countsAsDownload(): bool
    {
        return $this !== self::View;
    }
}
