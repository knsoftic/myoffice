<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 · file 8 — the foreign keys Phase 10 could not create (phase-13 §2.10, spine [D-FS-1]).
 *
 * The spine declared `project_payments.invoice_id`, `project_payments.payment_method_id` and
 * `student_fee_payments.payment_method_id` as nullable columns whose constraints a guarded follow-up
 * would add. Phase 10 migrates **before** `invoices` and `payment_methods` exist, so those guards were
 * correctly skipped — and without this file the constraints would simply never be created, leaving three
 * columns that look like foreign keys and enforce nothing.
 *
 * Every add is guarded twice: the tables must both exist, and the constraint must be absent. That makes
 * the file safe on a fresh install, on an upgrade, and on a re-run after a half-applied batch (D70).
 * `down()` drops only what it added.
 */
return new class extends Migration
{
    /**
     * `[table, column, referenced table, constraint name]`.
     *
     * The names are explicit because MariaDB caps an identifier at 64 characters and Laravel composes
     * `table_column_foreign`, which overflows on these table names (D71). A stable name is also what
     * lets `down()` find the constraint `up()` created.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const KEYS = [
        ['project_payments', 'invoice_id', 'invoices', 'fk_pp_invoice'],
        ['project_payments', 'payment_method_id', 'payment_methods', 'fk_pp_payment_method'],
        ['student_fee_payments', 'payment_method_id', 'payment_methods', 'fk_sfp_payment_method'],
        ['expenses', 'payment_method_id', 'payment_methods', 'fk_exp_payment_method'],
        ['incomes', 'payment_method_id', 'payment_methods', 'fk_inc_payment_method'],
        ['invoice_items', 'invoice_id', 'invoices', 'fk_ii_invoice'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as [$table, $column, $references, $name]) {
            if (! Schema::hasTable($table) || ! Schema::hasTable($references)) {
                continue;
            }

            if (! Schema::hasColumn($table, $column) || $this->constraintExists($name)) {
                continue;
            }

            // `invoice_items` lines belong to their document: deleting a never-issued draft takes its
            // lines with it. Every other column is a nullable reference whose target may legitimately
            // be soft-deleted, so it nulls rather than blocking.
            $onDelete = $table === 'invoice_items' ? 'CASCADE' : 'SET NULL';

            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE %s',
                $table, $name, $column, $references, $onDelete,
            ));

            // Verify after writing, the way every other schema object in this project is verified:
            // MariaDB DDL is not transactional, so "the statement ran" and "the constraint exists" are
            // genuinely different facts (D70).
            if (! $this->constraintExists($name)) {
                throw new RuntimeException(sprintf(
                    'The foreign key %s on %s.%s was created and immediately could not be found.',
                    $name, $table, $column,
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::KEYS) as [$table, , , $name]) {
            if (! Schema::hasTable($table) || ! $this->constraintExists($name)) {
                continue;
            }

            DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $name));
        }
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
