<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `uq_cle_source` allowed each partner exactly **one** manual adjustment, for ever.
 *
 * The index is `(source_type, source_id, collaborator_id, purpose)`, and all four are NOT NULL by
 * design ([D-FS-8]): that is what makes a second commission for the same receipt impossible even if
 * somebody hand-crafts a different `dedupe_key`. For a receipt or a reversal, `source_id` is the id of
 * the row that caused it, so the tuple is genuinely one-per-transaction.
 *
 * **A manual adjustment has no causing row.** Spine §2.19 says so in the same breath as it gives that
 * case its own key shape — `manual:{ulid}`, a fresh key every time, because two write-offs on one day
 * for one partner are two separate decisions somebody made and collapsing them would lose the second.
 * With nothing to point at, `source_id` falls back to the collaborator, and the tuple then reads
 * `(manual_adjustment, 42, 42, manual_adjustment)` for every adjustment that partner will ever
 * receive. The second one is a 1062 — and it surfaces as a failed goodwill credit somebody has to
 * explain, not as a duplicate anything.
 *
 * The fix is the idiom this schema already uses five times over (`current_guard`, `open_guard`,
 * `active_guard`, `default_guard`, `document_key`): a generated column that is `1` where the guarantee
 * applies and NULL where it does not. MariaDB unique indexes ignore rows with a NULL in the tuple, so
 * manual rows drop out of the index entirely while every receipt and every reversal keeps exactly the
 * guarantee it had.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_commission_ledger_entries';

    private const GUARD = 'source_guard';

    private const INDEX = 'uq_cle_source';

    private const WIDE = ['source_type', 'source_id', 'collaborator_id', 'purpose', 'source_guard'];

    private const NARROW = ['source_type', 'source_id', 'collaborator_id', 'purpose'];

    private const EXPRESSION = "CASE WHEN `purpose` IN ('manual_adjustment', 'write_off') THEN NULL ELSE 1 END";

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, self::GUARD)) {
            RawSchema::generatedColumn(self::TABLE, self::GUARD, 'TINYINT', self::EXPRESSION);
        }

        $this->rebuild(self::WIDE);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->rebuild(self::NARROW);

        if (Schema::hasColumn(self::TABLE, self::GUARD)) {
            RawSchema::dropColumn(self::TABLE, self::GUARD);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function rebuild(array $columns): void
    {
        if ($this->columnsOf(self::INDEX) === $columns) {
            return;
        }

        if (RawSchema::indexExists(self::TABLE, self::INDEX, true)) {
            RawSchema::dropIndex(self::TABLE, self::INDEX);
        }

        RawSchema::uniqueIndex(self::TABLE, self::INDEX, $columns);
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
