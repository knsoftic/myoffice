<?php

declare(strict_types=1);

namespace App\Services\Hr\Exceptions;

use LogicException;

/**
 * An append-only row was updated in a way it does not allow, or deleted (phase-07 D19, HR-6, HR-7, HR-16).
 *
 * A `LogicException`, not a validation error: reaching it means code took a path that should not exist, so
 * it belongs in the log with a stack trace rather than in a 422 to somebody filling in a form.
 *
 * The message names the columns that were touched **and** the ones that were allowed, because the useful
 * question at that point is not "what went wrong" but "what was I supposed to change instead".
 */
final class AppendOnlyRowException extends LogicException
{
    /**
     * @param  list<string>  $touched
     * @param  list<string>  $allowed
     */
    public static function cannotUpdate(string $model, int|string $id, array $touched, array $allowed): self
    {
        return new self(sprintf(
            '%s #%s is append-only: %s cannot be changed. Only %s may be updated after the row exists; '
            .'correct the rest by writing a new row that references this one.',
            $model,
            (string) $id,
            implode(', ', $touched),
            implode(', ', $allowed),
        ));
    }

    public static function cannotDelete(string $model, int|string $id): self
    {
        return new self(sprintf(
            '%s #%s is append-only and cannot be deleted — it is the evidence behind a figure somebody will '
            .'one day question. Reverse it with an opposite entry, or change its status.',
            $model,
            (string) $id,
        ));
    }
}
