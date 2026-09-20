<?php

declare(strict_types=1);

namespace App\Services\Collaborator\Exceptions;

use LogicException;

/**
 * Somebody tried to insert a commission entry without going through `LedgerWriter` (spine INV-21).
 *
 * **There is exactly one insert path, and this is what makes that true rather than intended.**
 * `LedgerWriter` composes the `dedupe_key`, takes the wallet row lock, writes the cache delta and the
 * activity row in the same transaction. A row inserted anywhere else would have none of that: no
 * duplicate guard it participates in, a wallet that no longer equals its ledger, and no audit trail —
 * and it would look completely normal in the table.
 *
 * Factories, seeders and a future historical import use `LedgerWriter::allowDirectWrites()`, which is a
 * single greppable escape hatch rather than a habit.
 */
final class DirectLedgerWriteException extends LogicException
{
    public static function make(): self
    {
        return new self(
            'A commission ledger entry can only be inserted by LedgerWriter (spine INV-21). It composes '
            .'the dedupe key, locks the wallet, writes the balance delta and the audit row in one '
            .'transaction — a row inserted any other way would have none of that and would look '
            .'perfectly normal. Use LedgerWriter::post(), or LedgerWriter::allowDirectWrites() in a '
            .'factory or seeder.'
        );
    }
}
