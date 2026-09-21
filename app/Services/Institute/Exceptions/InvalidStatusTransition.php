<?php

declare(strict_types=1);

namespace App\Services\Institute\Exceptions;

/**
 * A status was asked to move somewhere §2.30's transition table does not allow.
 *
 * Every lifecycle in phases 14–17 is an explicit table of permitted moves rather than a set of
 * scattered `if` statements, so "can this go from archived straight to published" has one answer in one
 * place. This is what the table throws when the answer is no — naming both ends, because the caller is
 * usually a screen that has to explain the refusal to somebody.
 */
final class InvalidStatusTransition extends CourseRuleException
{
    public static function between(string $field, string $from, string $to, string $subject = 'This'): self
    {
        return self::refuse($field, sprintf(
            '%s cannot go from %s to %s. That move is not one the lifecycle allows.',
            $subject,
            $from,
            $to,
        ));
    }
}
