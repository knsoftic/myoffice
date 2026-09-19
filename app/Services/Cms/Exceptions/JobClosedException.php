<?php

declare(strict_types=1);

namespace App\Services\Cms\Exceptions;

/**
 * An application was sent to an opening that is not accepting any (phase-04 §6.8 `apply()` invariant 1).
 *
 * Refused unless `website.careers_enabled` is on, the `jobs` module is enabled, the status accepts
 * applications (only `open`) and the deadline is null or today or later. Rendered as a 422 with a clear
 * message — never a silent success, and never a row or a stored CV.
 */
final class JobClosedException extends ContentRuleException
{
    public static function forOpening(string $title): static
    {
        return self::refuse('job', sprintf('Applications for "%s" are closed.', $title));
    }

    public static function careersDisabled(): static
    {
        return self::refuse('job', 'The careers page is not accepting applications right now.');
    }
}
