<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;
use Throwable;

/**
 * PRF-04 — the indexes the schema is required to have (phase-24-25 §2.5, §11.7).
 *
 * **A missing index is invisible until it is expensive.** Every query in this application answers in
 * milliseconds on the demo fixture whether or not it can use an index, because a full scan of forty
 * rows is instant; the same scan over sixty thousand receipts is the page that times out. There is no
 * runtime symptom to test for, so the schema itself is the thing asserted, against
 * `tests/Support/index-manifest.php` — which each phase appends to as it ships, and which §2.5 names
 * as **the** authority for "which indexes must exist".
 *
 * Four rules, each its own test so a failure names which one broke:
 *
 * 1. **Every manifest entry exists.** A listed column list must be the *leading* columns of some
 *    index; a longer index counts, because `(a, b, c)` serves `a` and `(a, b)` too. The reverse does
 *    not: `(b, a)` does not serve `a` and is not accepted.
 * 2. **Every foreign key column is indexed** — `KEY_COLUMN_USAGE` joined to `STATISTICS`, so the rule
 *    is checked against the live schema rather than against anyone's list of it.
 * 3. **Every `branch_id` is indexed** (D11). Branch scoping is on the hot path of every institute
 *    list, and it is the one column a table can acquire without anyone adding a row to the manifest.
 * 4. **Every table queried `withTrashed()` is indexed on `deleted_at`.** Laravel's soft-delete scope
 *    appends `deleted_at is null` to every ordinary query, so an unindexed column is being filtered
 *    on constantly and the `withTrashed()` screens — the archive views — are scanning.
 *
 * Failures print the exact `ALTER TABLE`, because §2.5 already says where it goes: Phase 24 owns one
 * index-only migration against other phases' tables, `add_audit_performance_indexes_table`, whose
 * every index is named `idx_p24_*` so its origin is greppable.
 *
 * The twelve-index rule of §2.5 is a **warning, not a failure**, and is reported as a skip.
 *
 * **`test_required_indexes_exist` is PRF-04's own name** and carries rule 1, the manifest comparison
 * §11.7 describes. Rules 2 to 4 keep descriptive names: they are the same id's further clauses, and a
 * failure that says which rule broke is worth more than four methods all called the same thing.
 *
 * The class carries `#[Group('perf')]` because `php artisan test --group=perf` (§11) is otherwise a
 * command that runs nothing and reports success.
 */
#[Group('perf')]
final class IndexCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables information_schema shows that are not ours to index.
     *
     * The framework's own plumbing: Laravel ships them, Laravel indexes them, and an entry here means
     * "not this project's schema" rather than "excused".
     *
     * @var list<string>
     */
    private const FRAMEWORK_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
        'personal_access_tokens',
    ];

    /** @var array<string, array<string, list<string>>>|null table => index => ordered columns */
    private ?array $indexes = null;

    /*
    |--------------------------------------------------------------------------
    | 1. The manifest
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_index_manifest_covers_a_real_schema(): void
    {
        $manifest = $this->manifest();
        $indexes = $this->indexes();

        $this->assertGreaterThan(
            40,
            count($manifest),
            'tests/Support/index-manifest.php has almost no tables in it — every assertion below would be vacuous.',
        );

        $this->assertGreaterThan(
            40,
            count($indexes),
            'information_schema returned almost no tables. The test database is not migrated, so PRF-04 '
            .'would pass by having nothing to check.',
        );

        $unknown = array_values(array_diff(array_keys($manifest), array_keys($indexes)));

        $this->assertSame(
            [],
            $unknown,
            "The manifest lists tables that do not exist in the schema. Either the migration was never "
            ."written or the table was renamed and the manifest was not:\n  ".implode("\n  ", $unknown)
        );
    }

    #[Test]
    public function test_required_indexes_exist(): void
    {
        $missing = [];

        foreach ($this->manifest() as $table => $columnLists) {
            if (! isset($this->indexes()[$table])) {
                continue; // Reported by the test above; not repeated as twenty missing indexes.
            }

            foreach ($columnLists as $columns) {
                if ($this->isLedBy($table, $columns)) {
                    continue;
                }

                $missing[] = $this->alterStatement($table, $columns);
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "These indexes are required by tests/Support/index-manifest.php (§2.5) and are not in the "
            ."schema. Add them in the owning phase's migration, or — when the owning phase has shipped — "
            ."in Phase 24's index-only migration add_audit_performance_indexes_table:\n  "
            .implode("\n  ", $missing)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Foreign keys
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_foreign_key_column_has_a_usable_index(): void
    {
        $keys = DB::select(
            'select TABLE_NAME as `table`, COLUMN_NAME as `column`, CONSTRAINT_NAME as `constraint`
             from information_schema.KEY_COLUMN_USAGE
             where TABLE_SCHEMA = ? and REFERENCED_TABLE_NAME is not null
             order by TABLE_NAME, COLUMN_NAME',
            [$this->schema()],
        );

        $this->assertGreaterThan(
            100,
            count($keys),
            'information_schema found almost no foreign keys — the schema is not migrated and this rule '
            .'is checking nothing.',
        );

        $missing = [];

        foreach ($keys as $key) {
            $table = (string) $key->table;
            $column = (string) $key->column;

            if (in_array($table, self::FRAMEWORK_TABLES, true) || $this->isLedBy($table, [$column])) {
                continue;
            }

            $missing[] = $this->alterStatement($table, [$column]).'  -- FK '.$key->constraint;
        }

        $missing = array_values(array_unique($missing));
        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "These foreign key columns have no index leading with them, so every join and every cascade "
            ."check against them scans:\n  ".implode("\n  ", $missing)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Branch scoping (D11)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_branch_id_column_is_indexed(): void
    {
        $tables = DB::select(
            'select TABLE_NAME as `table`
             from information_schema.COLUMNS
             where TABLE_SCHEMA = ? and COLUMN_NAME = ?
             order by TABLE_NAME',
            [$this->schema(), 'branch_id'],
        );

        $this->assertGreaterThan(
            5,
            count($tables),
            'No table carries branch_id, which cannot be right for a two-branch institute (D11) — the '
            .'query is wrong, not the schema.',
        );

        $missing = [];

        foreach ($tables as $row) {
            $table = (string) $row->table;

            if (! $this->isLedBy($table, ['branch_id'])) {
                $missing[] = $this->alterStatement($table, ['branch_id']);
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "Every branch-scoped table is filtered by branch_id on its hot path (D11), and these are not "
            ."indexed for it:\n  ".implode("\n  ", $missing)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Soft deletes read with withTrashed()
    |--------------------------------------------------------------------------
    */

    /**
     * The archive screens are the ones that need this index, and they are also the ones nobody
     * measures — a `withTrashed()` list is opened once a quarter, by an administrator, who assumes it
     * is slow because it is big.
     */
    #[Test]
    public function every_table_read_with_trashed_is_indexed_on_deleted_at(): void
    {
        $tables = $this->tablesReadWithTrashed();

        if ($tables === []) {
            $this->markTestSkipped(
                'No `Model::withTrashed()` call in app/ resolved to a model class. The scan only reads '
                .'static calls on an imported model — a relation closure ($q => $q->withTrashed()) cannot '
                .'be resolved to a table from source alone — so this rule currently covers nothing.'
            );
        }

        $missing = [];

        foreach ($tables as $table => $model) {
            if (! $this->isLedBy($table, ['deleted_at'])) {
                $missing[] = $this->alterStatement($table, ['deleted_at']).'  -- '.$model;
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "These tables are read with withTrashed() and have no index on deleted_at. Every ordinary "
            ."query on them also filters `deleted_at is null`, so the column is on the hot path twice "
            ."over:\n  ".implode("\n  ", $missing)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The §2.5 warning
    |--------------------------------------------------------------------------
    */

    /**
     * §2.5: "No table has more than 12 indexes without a written note — warning, not failure."
     *
     * A skip rather than an assertion, because that is what the contract says it is. Every index is a
     * write the database performs on every insert, so a table with twenty of them has an opinion
     * about reads that somebody should have written down.
     */
    #[Test]
    public function no_table_carries_more_indexes_than_it_should_need(): void
    {
        $heavy = [];

        foreach ($this->indexes() as $table => $indexes) {
            if (in_array($table, self::FRAMEWORK_TABLES, true)) {
                continue;
            }

            if (count($indexes) > 12) {
                $heavy[] = sprintf('%s (%d indexes)', $table, count($indexes));
            }
        }

        sort($heavy);

        if ($heavy !== []) {
            $this->markTestSkipped(
                "§2.5 warning — these tables carry more than 12 indexes. Each one is paid for on every "
                ."insert, so each needs a note in the owning phase's contract saying which query it is "
                ."for:\n  ".implode("\n  ", $heavy)
            );
        }

        $this->assertNotSame([], $this->indexes());
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Whether some index on the table starts with exactly these columns, in this order.
     *
     * A prefix match, not an equality: `(collaborator_id, transaction_date)` serves a lookup on
     * `collaborator_id`, so listing the shorter form in the manifest is satisfied by the longer index.
     * The order matters and is why this is not a set comparison — an index on `(b, a)` cannot answer
     * a query that only knows `a`.
     *
     * @param  list<string>  $columns
     */
    private function isLedBy(string $table, array $columns): bool
    {
        foreach ($this->indexes()[$table] ?? [] as $indexColumns) {
            if (array_slice($indexColumns, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * The fix, written out. §11.7: "the failure names the exact ALTER TABLE to add each missing one."
     *
     * @param  list<string>  $columns
     */
    private function alterStatement(string $table, array $columns): string
    {
        return sprintf(
            'ALTER TABLE `%s` ADD INDEX `idx_p24_%s_%s` (`%s`);',
            $table,
            substr(str_replace('_', '', $table), 0, 12),
            implode('_', array_map(static fn (string $column): string => str_replace('_id', '', $column), $columns)),
            implode('`, `', $columns),
        );
    }

    /**
     * table => index name => ordered column list.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function indexes(): array
    {
        if ($this->indexes !== null) {
            return $this->indexes;
        }

        $rows = DB::select(
            'select TABLE_NAME as `table`, INDEX_NAME as `index`, SEQ_IN_INDEX as `seq`, COLUMN_NAME as `column`
             from information_schema.STATISTICS
             where TABLE_SCHEMA = ?
             order by TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            [$this->schema()],
        );

        $indexes = [];

        foreach ($rows as $row) {
            // A functional index reports a null column; it cannot serve a leading-column lookup, so it
            // is carried as nothing rather than as a gap in the sequence.
            if ($row->column === null) {
                continue;
            }

            $indexes[(string) $row->table][(string) $row->index][] = (string) $row->column;
        }

        return $this->indexes = $indexes;
    }

    /**
     * @return array<string, list<string>>
     */
    private function manifest(): array
    {
        $path = base_path('tests/Support/index-manifest.php');

        $this->assertFileExists($path, '§2.5 names this file as the authority for which indexes must exist.');

        /** @var array<string, list<string>> $manifest */
        $manifest = require $path;

        return $manifest;
    }

    /**
     * table => model class, for every `SomeModel::withTrashed()` in `app/`.
     *
     * Static calls only. A relation closure (`fn ($q) => $q->withTrashed()`) names no class in its own
     * source, and guessing which relation it belongs to would produce a rule that fails on tables
     * nobody reads that way — which is how a schema check turns into noise.
     *
     * @return array<string, class-string<Model>>
     */
    private function tablesReadWithTrashed(): array
    {
        $tables = [];

        foreach ($this->phpFiles(app_path()) as $path) {
            $source = (string) file_get_contents($path);

            if (! preg_match_all('/\b([A-Z][A-Za-z0-9_]*)\s*::\s*withTrashed\s*\(/', $source, $matches)) {
                continue;
            }

            $imports = $this->importedClasses($source);

            foreach (array_unique($matches[1]) as $short) {
                $class = $imports[$short] ?? null;

                if ($class === null || ! is_subclass_of($class, Model::class)) {
                    continue;
                }

                if (! in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                    continue;
                }

                try {
                    /** @var Model $model */
                    $model = new $class;
                    $tables[$model->getTable()] = $class;
                } catch (Throwable) {
                    // A model that cannot be constructed without a container is not this test's problem.
                }
            }
        }

        ksort($tables);

        return $tables;
    }

    /**
     * Short name => fully qualified class, from a file's `use` statements.
     *
     * @return array<string, class-string>
     */
    private function importedClasses(string $source): array
    {
        preg_match_all(
            '/^use\s+(App\\\\Models\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $classes = [];

        foreach ($matches as $match) {
            $alias = $match[2] ?? '';
            $short = $alias !== '' ? $alias : (string) preg_replace('/^.*\\\\/', '', $match[1]);

            /** @var class-string $class */
            $class = $match[1];
            $classes[$short] = $class;
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function schema(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }
}
