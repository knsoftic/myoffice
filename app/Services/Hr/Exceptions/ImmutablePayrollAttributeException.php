<?php

declare(strict_types=1);

namespace App\Services\Hr\Exceptions;

use LogicException;

/**
 * Something tried to change money on a locked payroll row (phase-07 HR-15).
 *
 * Once a run is locked, every money column, every component row and every snapshot on its items is frozen;
 * once an item is paid, its payment columns freeze too. Only `status`, `hold_reason`, `paid_at`, the
 * payment fields before payment, `notes` and the blameable pair may ever move.
 *
 * There is deliberately **no unlock** ([D-HR-6]): an unlock is an edit of paid money with extra steps. A
 * wrong slip is corrected by a new item on a correction run that references the original (HR-17), which
 * leaves both the mistake and the fix on the record.
 */
final class ImmutablePayrollAttributeException extends LogicException
{
    /**
     * @param  list<string>  $touched
     */
    public static function locked(string $model, int|string $id, array $touched): self
    {
        return new self(sprintf(
            '%s #%s is locked (phase-07 HR-15): %s cannot change. Correct a locked slip with a correction '
            .'run that references it — there is no unlock, because an unlock is an edit of paid money.',
            $model,
            (string) $id,
            implode(', ', $touched),
        ));
    }
}
