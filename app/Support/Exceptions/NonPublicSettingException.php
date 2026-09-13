<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * A public view asked for a setting whose registry definition is not `public => true` (phase-03 INV-10).
 *
 * The message names the key and nothing else: never its value.
 */
final class NonPublicSettingException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('The setting [%s] is not public and cannot be read by the public website.', $key));
    }
}
