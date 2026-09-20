<?php

declare(strict_types=1);

namespace App\Services\Collaborator\Exceptions;

use LogicException;

/**
 * An attempt to edit a commission rule version (spine INV-17).
 *
 * A rate is never updated. Changing it is a **new version** with its own effective date, so "what was
 * this partner's rate on the day that payment arrived" stays answerable years later. Editing the rate on
 * the existing row would silently rewrite the meaning of every commission it has already produced.
 *
 * The columns that may move are the ones that *close* a version rather than change what it said.
 */
final class ImmutableRuleAttributeException extends LogicException
{
    /**
     * @param  list<string>  $touched
     * @param  list<string>  $allowed
     */
    public static function forColumns(string|int $id, array $touched, array $allowed): self
    {
        return new self(sprintf(
            'Commission rule version #%s: %s cannot change. A rate change is a new version with its own '
            .'effective date (CommissionRuleService::createVersion()) — editing this row would rewrite '
            .'the meaning of every commission it has already produced. Only these may move: %s.',
            (string) $id,
            implode(', ', $touched),
            implode(', ', $allowed),
        ));
    }
}
