<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 · file 6 — attach the nine keys earlier phases had to defer until `students` and
 * `student_admissions` existed ([D-IN-1]).
 *
 * Phases 4 and 10 shipped columns pointing at two tables that were five phases away, each with an index
 * and a manifest row from day one and no constraint. Their own migrations are idempotent and ask to be
 * re-run once the target exists — but `migrate` runs a file once, so the phase that creates the target
 * is what has to attach them. This is that file.
 *
 * **Two delete rules, and the difference is what the row is for.** The six financial and attribution
 * keys are `restrictOnDelete`: a student with a fee, a receipt, a referral or a commission entry is not
 * deletable, and the database is where that is decided rather than a policy somebody can forget to
 * call. The three marketing keys are `nullOnDelete`: a testimonial, a review and a success story are
 * published content that outlives the record of who wrote it, and losing the link is better than losing
 * the page.
 *
 * Orphans are counted and named first: `ALTER TABLE` would otherwise fail with a 1452 that says only
 * that a constraint failed, on a database where somebody then has to guess which row.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const KEYS = [
        // phase-10 file 20 listed all six and skipped them: "re-run once those phases have migrated".
        ['student_fees', 'student_id', 'students', 'restrict'],
        ['student_fees', 'student_admission_id', 'student_admissions', 'restrict'],
        ['student_fee_payments', 'student_id', 'students', 'restrict'],
        ['collaborator_referrals', 'student_id', 'students', 'restrict'],
        ['collaborator_commission_entitlements', 'student_admission_id', 'student_admissions', 'restrict'],
        ['collaborator_commission_ledger_entries', 'student_id', 'students', 'restrict'],

        // phase-04 §2.1's deferred trio, nullOnDelete by its own §2201.
        ['testimonials', 'student_id', 'students', 'null'],
        ['student_reviews', 'student_id', 'students', 'null'],
        ['success_stories', 'student_id', 'students', 'null'],
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
