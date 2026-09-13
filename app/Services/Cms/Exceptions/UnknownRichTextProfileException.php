<?php

declare(strict_types=1);

namespace App\Services\Cms\Exceptions;

use InvalidArgumentException;

/**
 * A caller asked `App\Support\RichText::sanitize()` for a profile that is not in the closed map
 * (phase-03 §6.6, ND-5, decision **D25**).
 *
 * The map holds exactly two committed profiles — `cms` and `material`. The caller's string is
 * **never** forwarded to the sanitising engine as a config key, so a later phase cannot invent a
 * profile by passing one: adding a third is an edit to `RichText` plus a reviewed addition to
 * `config/purifier.php`, treated as a security change.
 *
 * Thrown (not returned as an empty string) on purpose: silently sanitising with the default profile
 * would hide the fact that a caller believes it has allowances it does not have.
 */
final class UnknownRichTextProfileException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $known
     */
    public static function for(string $profile, array $known): self
    {
        return new self(sprintf(
            'Unknown rich-text profile [%s]. The closed map holds exactly: %s. '
            .'Adding a profile is an edit to App\Support\RichText reviewed as a security change (D25).',
            $profile,
            implode(', ', $known)
        ));
    }
}
