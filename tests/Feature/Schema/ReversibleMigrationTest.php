<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An index a migration drops must actually be droppable (D112, D125).
 *
 * **This test exists because the same bug shipped three times.** Phase 18 left
 * `student_fee_reminders.student_fee_id` leaning on `uq_sfr_dedupe`, and the fix came with a decision
 * spelling out exactly why a composite UNIQUE is not a substitute for a foreign key's own index. Phase
 * 19 then did it twice more — `uq_cmt` and `uq_as_superseded` — because writing an index list from the
 * contract is not the same as checking the rule against it. A decision nobody rereads is a decision
 * that gets made again.
 *
 * **The rule.** InnoDB requires *an* index on every foreign key column, and will satisfy that with a
 * UNIQUE index that merely leads with the column. `DROP INDEX` on it is then error 1553. Most
 * migrations never notice, because `Schema::dropIfExists()` takes the table, its keys and its indexes
 * together — it only bites a `down()` that drops an index *first*, which is every migration carrying a
 * generated column, since a column cannot be dropped while an index reads it.
 *
 * **The scope is the index, not the table.** Checking every foreign key in every table that drops
 * *any* index would flag `attendance_monthly_summaries.employee_id`, which is perfectly safe: that
 * migration drops a different index entirely. So this walks the index names those migrations mention,
 * finds each one's leading column, and asks the question only about that column.
 *
 * The install-and-rollback test does catch this too — after ten minutes, at the far end of a full
 * suite run. This catches it in well under a second.
 */
final class ReversibleMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_migration_drops_an_index_its_own_foreign_key_depends_on(): void
    {
        $indexes = $this->indexesMentionedByMigrationsThatDropIndexes();

        $this->assertNotEmpty(
            $indexes,
            'The scan found no index names in any migration that drops one. Either a helper was '
            .'renamed or the naming convention changed, and this test has quietly stopped checking.',
        );

        $offenders = [];

        foreach ($indexes as $index => $migration) {
            $leading = $this->leadingColumnOf($index);

            if ($leading === null) {
                // Named in a migration but not in the schema — an index a later migration replaced.
                continue;
            }

            [$table, $column] = $leading;

            if (! $this->isForeignKey($table, $column) || $this->hasPlainLeadingIndex($table, $column)) {
                continue;
            }

            $offenders[] = sprintf(
                '  %s on %s leads with the foreign key %s, and no plain index covers it  [%s]',
                $index,
                $table,
                $column,
                $migration,
            );
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Dropping these indexes is error 1553, so the migration cannot roll back:'],
            $offenders,
            [
                '',
                'Give each column a plain index of its own, and ensure it on every up() so a database',
                'migrated before the fix heals itself rather than needing a hand-written repair.',
            ],
        )));
    }

    /**
     * Index names mentioned as literals in any migration that calls `dropIndex`.
     *
     * Matching on the `uq_` / `idx_` naming convention rather than on the call site, because the names
     * are as often in an array the loop walks as in the call itself — and a name is a name wherever it
     * is written. An index named here but dropped nowhere costs one harmless lookup.
     *
     * @return array<string, string> index name => migration basename
     */
    private function indexesMentionedByMigrationsThatDropIndexes(): array
    {
        $names = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'dropIndex')) {
                continue;
            }

            preg_match_all("/'((?:uq|idx)_[a-z0-9_]+)'/", $source, $matches);

            foreach ($matches[1] as $name) {
                $names[$name] = basename($path);
            }
        }

        return $names;
    }

    /**
     * @return array{0: string, 1: string}|null [table, first column]
     */
    private function leadingColumnOf(string $index): ?array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ? AND SEQ_IN_INDEX = 1',
            [$index],
        );

        return $rows === [] ? null : [(string) $rows[0]->TABLE_NAME, (string) $rows[0]->COLUMN_NAME];
    }

    private function isForeignKey(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
              LIMIT 1',
            [$table, $column],
        ) !== [];
    }

    /**
     * A non-unique index whose **first** column is this one. Leading position is what matters: InnoDB
     * accepts an index that merely starts with the column, and a plain one is what has to be left
     * behind when the unique one goes.
     */
    private function hasPlainLeadingIndex(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                AND SEQ_IN_INDEX = 1 AND NON_UNIQUE = 1
              LIMIT 1',
            [$table, $column],
        ) !== [];
    }
}
