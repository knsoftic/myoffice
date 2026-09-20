<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `uq_cle_reversal_pair` forbade the one case the reversal algorithm is built around.
 *
 * As created it was `(payment_reversal_id, reverses_entry_id)` — "one reversal row can undo a given
 * original exactly once". But spine §2.19 says the opposite two paragraphs later, and it is right:
 * **one reversal legitimately produces two debits against the same original** when part of the
 * commission has already been paid out.
 *
 *   entry 1,000.00, of which 600.00 sits on a paid payout; the receipt is refunded in full
 *     -> reverse_now  = 400.00  (purpose `reversal`,  the unpaid part, taken straight back)
 *     -> clawback_now = 600.00  (purpose `clawback`,  money that already left the company)
 *
 * That is §6.6's "refund after the commission was paid out" — the headline row of the table — and with
 * the narrow index the second debit is a 1062 inside the reversal transaction. The whole reversal then
 * rolls back, so a refunded receipt keeps its commission and the wallet keeps money the business gave
 * back. `dedupe_key` was already designed for the split (the clawback carries a `:clawback` suffix) and
 * `uq_cle_source` already separates the two by `purpose`; this index alone disagreed.
 *
 * Adding `purpose` restores what the other two guards already say: at most one debit per reversal, per
 * original entry, **per purpose**.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_commission_ledger_entries';

    private const NAME = 'uq_cle_reversal_pair';

    private const WIDE = ['payment_reversal_id', 'reverses_entry_id', 'purpose'];

    private const NARROW = ['payment_reversal_id', 'reverses_entry_id'];

    public function up(): void
    {
        $this->rebuild(self::WIDE);
    }

    public function down(): void
    {
        $this->rebuild(self::NARROW);
    }

    /**
     * Idempotent in both directions: the index is rebuilt only when its column list is not already the
     * one being asked for. A half-applied migration set therefore re-runs cleanly (D70).
     *
     * @param  list<string>  $columns
     */
    private function rebuild(array $columns): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->columnsOf(self::NAME) === $columns) {
            return;
        }

        if (RawSchema::indexExists(self::TABLE, self::NAME, true)) {
            RawSchema::dropIndex(self::TABLE, self::NAME);
        }

        RawSchema::uniqueIndex(self::TABLE, self::NAME, $columns);
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $name): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [DB::getDatabaseName(), self::TABLE, $name]
        );

        return array_map(static fn (object $row): string => (string) $row->COLUMN_NAME, $rows);
    }
};
