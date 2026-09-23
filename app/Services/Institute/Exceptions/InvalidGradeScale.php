<?php

declare(strict_types=1);

namespace App\Services\Institute\Exceptions;

/**
 * A grade scale's bands do not hold together (phase-19-23 §6.9, INV-20-3).
 *
 * A 422 on the bands field. The message always names the offending pair — "B (up to 79.99) and A (from
 * 79.50) overlap" — because the person reading it is looking at a table of eight rows and a sentence
 * saying "the bands are invalid" leaves them to find which two themselves.
 */
final class InvalidGradeScale extends CourseRuleException
{
    public static function because(string $message): self
    {
        return self::refuse('bands', $message);
    }
}
