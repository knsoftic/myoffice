<?php

declare(strict_types=1);

namespace App\Services\Cms\Exceptions;

use RuntimeException;

/**
 * A section type that `App\Support\Cms\SectionRegistry` does not declare was asked for by name
 * (phase-03 §6.2 `place()`, FT-01).
 *
 * This is the **write-side** answer to INV-2. The read side never throws: the public renderer calls
 * `SectionRegistry::exists()` first and skips an orphaned row with one `Log::warning`, because a
 * visitor must never see a 500 because a module was removed (FT-04).
 */
final class UnknownSectionTypeException extends RuntimeException
{
    /**
     * @param  list<string>  $known
     */
    public static function key(string $key, array $known): self
    {
        return new self(sprintf(
            'Unknown section type [%s]. Declared types: %s.',
            $key,
            implode(', ', $known) ?: 'none'
        ));
    }

    public static function notAllowedInPlacement(string $key, string $placement): self
    {
        return new self(sprintf(
            'Section type [%s] is not allowed in the [%s] placement.',
            $key,
            $placement
        ));
    }

    public static function alreadyPlaced(string $key, string $placement): self
    {
        return new self(sprintf(
            'Section type [%s] may only be placed once in [%s] and already is.',
            $key,
            $placement
        ));
    }
}
