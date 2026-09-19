<?php

declare(strict_types=1);

namespace App\Services\Project\Exceptions;

use LogicException;

/**
 * A `project_value_revisions` row was updated or deleted (phase-06 §2.2, INV-P3).
 *
 * The table is append-only: a wrong value is corrected by writing the **next** revision, never by editing or
 * removing the one that recorded it. `trg_pvr_no_delete` raises `SQLSTATE '45000'` underneath as the last
 * line of defence, but the model throws this first on purpose — the spine's R-5 lesson is that a raw SQL
 * error with no Eloquent explanation is how someone "fixes" the problem by dropping the trigger.
 */
final class ImmutableRevisionException extends LogicException
{
    public static function updated(int|string $id): self
    {
        return new self(sprintf(
            'project_value_revisions #%s cannot be updated (phase-06 INV-P3): correct a project value by '
            .'writing the next revision through ProjectValueService::revise().',
            (string) $id
        ));
    }

    public static function deleted(int|string $id): self
    {
        return new self(sprintf(
            'project_value_revisions #%s cannot be deleted (phase-06 INV-P3): the value history is the '
            .'evidence commission is paid against and it never shrinks.',
            (string) $id
        ));
    }
}
