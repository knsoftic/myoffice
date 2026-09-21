<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 · file 6 — attach every key that waited for `batches`, `teachers` and `classrooms` ([D-IN-1]).
 *
 * Phases 4, 10, 14 and 15 all shipped columns pointing at these three tables, each with an index and a
 * manifest row from day one and no constraint. `migrate` runs a file once, so the phase that creates
 * the target is what attaches them — the same job Phase 14 did for `courses` and Phase 15 for
 * `students`.
 *
 * **`teacher_id` is `restrictOnDelete` and `batch_id` / `classroom_id` are `nullOnDelete`, and the
 * asymmetry is the whole point.** A teacher who has ever appeared on a schedule is deactivated rather
 * than removed; a batch or a room disappearing must leave the enquiry, the application or the
 * admission standing, pointing at nothing in particular, rather than taking it with them.
 *
 * `student_fees.batch_id` is Phase 10's and is `nullOnDelete` by its own declaration: a batch transfer
 * repoints it and nothing financial moves (spine §2.2).
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const KEYS = [
        // phase-14: the catalogue's default trainer.
        ['courses', 'default_teacher_id', 'teachers', 'null'],

        // phase-15: the four `batch_id` columns and the demo's teacher and room.
        ['course_inquiries', 'batch_id', 'batches', 'null'],
        ['student_applications', 'batch_id', 'batches', 'null'],
        ['student_admissions', 'batch_id', 'batches', 'null'],
        ['demo_classes', 'batch_id', 'batches', 'null'],
        ['demo_classes', 'teacher_id', 'teachers', 'restrict'],
        ['demo_classes', 'classroom_id', 'classrooms', 'null'],

        // phase-10 file 20 listed this one and skipped it: `batches` was six phases away.
        ['student_fees', 'batch_id', 'batches', 'null'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as [$table, $column, $references, $onDelete]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (! Schema::hasTable($references)) {
                continue;
            }

            $name = RawSchema::foreignKeyName($table, $column);

            if ($this->exists($name)) {
                continue;
            }

            $this->assertNoOrphans($table, $column, $references);

            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE %s',
                $table,
                $name,
                $column,
                $references,
                $onDelete === 'restrict' ? 'RESTRICT' : 'SET NULL',
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

    private function assertNoOrphans(string $table, string $column, string $references): void
    {
        $orphans = DB::table($table)
            ->whereNotNull($column)
            ->whereNotIn($column, DB::table($references)->select('id'))
            ->limit(20)
            ->pluck($column)
            ->all();

        if ($orphans === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s.%s holds %d value(s) with no matching `%s` row (%s). Correct them before this key can '
            .'be attached — this migration will not guess whether they are typos or evidence.',
            $table,
            $column,
            count($orphans),
            $references,
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
