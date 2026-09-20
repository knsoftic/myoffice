<?php

declare(strict_types=1);

namespace App\Services\Hr\Exceptions;

use LogicException;

/**
 * A salary structure version was edited (phase-07 HR-10).
 *
 * **A raise is never an UPDATE.** The open version is closed at `effective_from - 1 day` and a successor
 * carries `version + 1`. That is what lets a payroll run from last March still point at the numbers that
 * were true last March; editing the row in place would rewrite the past silently, and every slip that
 * referenced it would start lying.
 *
 * Only `status`, `effective_to`, `superseded_by_id`, the approval columns and the blameable pair may move.
 */
final class ImmutableSalaryStructureException extends LogicException
{
    /**
     * @param  list<string>  $touched
     */
    public static function version(int|string $id, array $touched): self
    {
        return new self(sprintf(
            'Salary structure #%s is a version, not a record to edit (phase-07 HR-10): %s cannot change. '
            .'Raise the salary by creating the next version, which closes this one.',
            (string) $id,
            implode(', ', $touched),
        ));
    }
}
