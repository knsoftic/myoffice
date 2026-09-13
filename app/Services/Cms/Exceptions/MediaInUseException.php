<?php

declare(strict_types=1);

namespace App\Services\Cms\Exceptions;

use RuntimeException;

/**
 * A delete was attempted on a `media_assets` row that something still references
 * (phase-03 §2.13, §6.8, FT-38, INV-3).
 *
 * Two guards say the same thing on purpose: `website_section_media.media_asset_id` is
 * `restrictOnDelete` at the database level, and `MediaService::delete()` refuses first so the user
 * gets a sentence naming what uses the image instead of an integrity-constraint stack trace.
 *
 * `$usage` carries that list for the refusal message (§8.13's usage popover).
 */
final class MediaInUseException extends RuntimeException
{
    /**
     * Human rows describing what still points at the asset.
     *
     * @var list<array<string, mixed>>
     */
    public array $usage = [];

    /**
     * @param  list<array<string, mixed>>  $usage
     */
    public static function asset(int $id, string $name, int $count, array $usage = []): self
    {
        $exception = new self(sprintf(
            '"%s" is still used in %d %s and cannot be deleted. Remove it there first.',
            $name,
            $count,
            $count === 1 ? 'place' : 'places'
        ));

        $exception->usage = $usage;

        return $exception;
    }
}
