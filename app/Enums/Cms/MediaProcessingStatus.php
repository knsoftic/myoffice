<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * Where derivative generation got to (`media_assets.derivatives_status`, phase-03 §2.13/§3).
 *
 * `skipped` is not a failure: a background video (§9) is stored with `skipped` because there is
 * nothing to resize, and it is perfectly usable — hence `isUsable()` covering `ready` **and**
 * `skipped`. That distinction is what lets `<x-site.image>` fall back to the original with real
 * `width`/`height` instead of rendering a broken `srcset` (§6.8).
 */
enum MediaProcessingStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Processing => 'Processing',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
            self::Skipped => 'Not applicable',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Processing => 'amber',
            self::Ready => 'emerald',
            self::Failed => 'rose',
            self::Skipped => 'cyan',
        };
    }

    /**
     * May the renderer use this asset's variants, or must it fall back to the original?
     *
     * `ready` has variants; `skipped` never will and does not need them. Everything else falls back.
     */
    public function isUsable(): bool
    {
        return $this === self::Ready || $this === self::Skipped;
    }

    /**
     * Is the pipeline still expected to act on this asset? Drives the "Regenerate derivatives"
     * action and the media library's status filter (§8.13).
     */
    public function isPending(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
