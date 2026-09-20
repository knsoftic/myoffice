<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\LedgerDelta;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorWallet;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * The wallet cache (spine §6.5, §117). **Phase 10 ships `applyDelta()` and the row itself; Phase 12
 * adds `derive()`, `recalculate()`, `freeze()`, `assertConsistent()` and `payoutsPaidTotal()`** — one
 * class, built in two instalments, never two classes with similar names ([D-P10-1]).
 *
 * The split is forced by the build order and is the honest way to resolve it: phase-10-12 §1.3 assigns
 * this class to Phase 12, while §6.1's wiring has `LedgerWriter` — a Phase 10 class — applying the
 * wallet delta **inside the same transaction as the ledger insert**. It has to: a committed ledger row
 * whose wallet write happens later is a window in which `wallet != SUM(ledger)`, and that window is
 * precisely what INV-26 and the reconciler exist to make impossible. So the delta half is built now
 * and the reporting half is built when there are rows to report on.
 *
 * **The wallet is a cache and nothing more.** Every figure it holds must be re-derivable by summing the
 * ledger, and Phase 12's reconciler asserts exactly that. Nothing here ever invents a balance: it
 * applies the difference one row makes, computed from the canonical SQL's own predicates
 * ({@see LedgerDelta}).
 */
final class CollaboratorWalletService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The collaborator's wallet row, opening it on first use, **locked**.
     *
     * A wallet is not created at onboarding: a partner who never earned anything has no balance to
     * cache, and a table full of zero rows is a table somebody eventually sums. It appears the first
     * time money does.
     */
    public function lockFor(Collaborator $collaborator): CollaboratorWallet
    {
        $this->assertInTransaction('lockFor');

        $wallet = $this->lock($collaborator);

        if ($wallet !== null) {
            return $wallet;
        }

        try {
            (new CollaboratorWallet)->forceFill([
                'collaborator_id' => $collaborator->getKey(),
                'currency' => Money::currencyCode(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // `uq_cw_collaborator` did its job against a racing worker; theirs is as good as ours.
        }

        $wallet = $this->lock($collaborator);

        if ($wallet === null) {
            throw new LogicException(sprintf(
                'The wallet for collaborator #%s was created and immediately could not be locked.',
                (string) $collaborator->getKey(),
            ));
        }

        return $wallet;
    }

    /**
     * Move the cache by what one ledger row just did.
     *
     * Called only from {@see LedgerWriter} and (from Phase 12) `PayoutService`, always inside their
     * transaction and under this row's lock. Every column moves by a **relative** expression rather
     * than being assigned a computed absolute: two workers crediting two entries to one partner in the
     * same instant then sum correctly even if one of them read the row a moment before the other wrote
     * it, and `version` is what tells a reader the row moved underneath them.
     */
    public function applyDelta(CollaboratorWallet $wallet, LedgerDelta $delta): void
    {
        $this->assertInTransaction('applyDelta');

        $updates = [
            'version' => $this->db->raw('version + 1'),
            'updated_at' => now(),
        ];

        foreach ($delta->columns() as $column => $change) {
            $updates[$column] = $this->db->raw(sprintf('%s + %s', $column, $this->literal($change)));
        }

        if ($delta->isNew) {
            $updates['ledger_entry_count'] = $this->db->raw('ledger_entry_count + 1');
        }

        // `last_entry_*` only ever moves forward. A status change on an old entry is a real wallet
        // movement, but it is not a newer entry, and a reader using these two columns to ask "has
        // anything happened since I last looked" would be told the wrong thing.
        if ($delta->isNew && $delta->entryId !== null) {
            $updates['last_entry_id'] = $delta->entryId;
            $updates['last_entry_at'] = $delta->entryAt ?? now();
        }

        $this->db->table($wallet->getTable())->where('id', $wallet->getKey())->update($updates);

        $wallet->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function lock(Collaborator $collaborator): ?CollaboratorWallet
    {
        return CollaboratorWallet::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * A money string this process produced, safe to inline in a `SET x = x + …` fragment. The pattern
     * is the assertion: anything that is not a signed decimal at money scale never reaches SQL.
     */
    private function literal(string $amount): string
    {
        if (preg_match('/^-?\d{1,15}\.\d{2}$/', $amount) !== 1) {
            throw new LogicException(sprintf('[%s] is not a money value this class produced.', $amount));
        }

        return $amount;
    }

    private function assertInTransaction(string $method): void
    {
        if ($this->db->connection()->transactionLevel() < 1) {
            throw new LogicException(sprintf(
                'CollaboratorWalletService::%s() must run inside the transaction that wrote the ledger '
                .'row. A committed entry whose wallet write lands separately leaves a window where the '
                .'cache disagrees with the ledger, which is the one thing the reconciler exists to '
                .'prove cannot happen.',
                $method,
            ));
        }
    }
}
