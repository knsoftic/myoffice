<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 · file 6 — attach the four `course_id` keys earlier phases had to defer ([D-IN-1]).
 *
 * Phases 4 and 10 shipped columns that point at a table which did not exist yet, each with an index and
 * a manifest row from day one and no constraint. Their own migrations are idempotent and say "re-run me
 * once those phases have migrated" — but `migrate` runs a file once, so *something* has to run after
 * `courses` is created, and that something is the phase that creates it. Without this file the four
 * columns stay unconstrained for ever on an installation that has already migrated, and the guarantee
 * the deferred-key pattern promises is never actually delivered.
 *
 * All four are `nullOnDelete`, which is the same decision in four places: an enquiry, a review, a
 * success story and a fee row each record something that really happened, and deleting a course must
 * not take them with it. The course reference is how it was catalogued, not what it is.
 *
 * A row pointing at a course id that does not exist would make `ALTER TABLE` fail with a 1452 that names
 * nothing useful, so the orphans are counted first and reported by table and id.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private const KEYS = [
        // phase-04 §2: "swap the free-text course field for a picker" — the picker needs the key.
        ['contact_inquiries', 'course_id'],
        ['student_reviews', 'course_id'],
        ['success_stories', 'course_id'],
        // phase-10 file 20 listed this one as ['student_fees', 'course_id', 'courses', 'null'] and
        // skipped it because `courses` was three phases away.
        ['student_fees', 'course_id'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('courses')) {
            return;
        }

        foreach (self::KEYS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $name = RawSchema::foreignKeyName($table, $column);

            if ($this->exists($name)) {
                continue;
            }

            $this->assertNoOrphans($table, $column);

            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `courses` (`id`) ON DELETE SET NULL',
                $table,
                $name,
                $column,
            ));
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::KEYS) as [$table, $column]) {
            $name = RawSchema::foreignKeyName($table, $column);

            if (Schema::hasTable($table) && $this->exists($name)) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $name));
            }
        }
    }

    /**
     * Name the rows rather than let MariaDB raise a 1452 that says only "a foreign key constraint fails".
     */
    private function assertNoOrphans(string $table, string $column): void
    {
        $orphans = DB::table($table)
            ->whereNotNull($column)
            ->whereNotIn($column, DB::table('courses')->select('id'))
            ->limit(20)
            ->pluck($column)
            ->all();

        if ($orphans === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s.%s holds %d value(s) that are not a course id (%s). Clear or correct them before this '
            .'key can be attached — nulling them here would delete evidence this migration was not '
            .'asked to judge.',
            $table,
            $column,
            count($orphans),
            implode(', ', array_slice($orphans, 0, 20)),
        ));
    }

    private function exists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
