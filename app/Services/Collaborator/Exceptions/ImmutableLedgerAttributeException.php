<?php

declare(strict_types=1);

namespace App\Services\Collaborator\Exceptions;

use LogicException;

/**
 * An attempt to move a column on a commission entry that is not allowed to move (spine INV-4).
 *
 * A ledger row records what somebody earned, from which receipt, under which rule, at which rate, on
 * which date. **None of that is ever corrected by an edit** — a wrong commission is corrected by a
 * reversing negative row that references the original (`CLAUDE.md` rule 3). The short whitelist is the
 * bookkeeping that happens *around* the entry: its status, its approval, how much a payout has claimed,
 * how much has been undone.
 */
final class ImmutableLedgerAttributeException extends LogicException
{
    /**
     * @param  list<string>  $touched
     * @param  list<string>  $allowed
     */
    public static function forColumns(string|int $id, array $touched, array $allowed): self
    {
        return new self(sprintf(
            'Commission entry CLE-%s: %s cannot change once the entry exists. A wrong commission is '
            .'corrected by a reversing entry that references it, never by an edit, because the reversal '
            .'is what the partner can be shown. Only these may move: %s.',
            (string) $id,
            implode(', ', $touched),
            implode(', ', $allowed),
        ));
    }
}
