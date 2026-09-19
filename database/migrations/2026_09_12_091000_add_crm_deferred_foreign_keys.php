<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 · [D-P5-2] — add_crm_deferred_foreign_keys: every CRM constraint whose target table is created later
 * than its column, plus the two Phase 4 client links Phase 5 promotes (phase-04 §13 "Phase 5 — CRM",
 * build-order §5).
 *
 * The promoter rule (build-order §5): this migration **creates no column and changes no definition**. For each
 * key it checks the table, the column and the target table, and checks the schema for an existing foreign key
 * on that column before adding one — so it is a no-op when a target is absent (§11 test 2), idempotent when run
 * twice (§11 test 1), and completes the graph when re-run after Phase 6 / 9 / 10 land. A later phase's own
 * promoter (Phase 6 `add_project_fks_to_crm_tables`, Phase 9's visit promotion, spine file 20) sees the key
 * this migration added and skips, and vice versa.
 *
 * Every key is `nullOnDelete`. A value that points at no row (possible only for a column that was writable
 * before its target existed) makes the migration **fail loudly** with the count and the query to inspect it;
 * it never nulls real data on its own.
 *
 * `down()` drops only the constraints this migration adds — the Laravel-named `{table}_{column}_foreign` —
 * so a constraint another phase's promoter added under its own name is left to that phase's rollback.
 */
return new class extends Migration
{
    /**
     * [table, column, target table] — all `ON DELETE SET NULL`.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const KEYS = [
        // §2.1 leads
        ['leads', 'service_id', 'services'],                                 // Phase 4
        ['leads', 'contact_inquiry_id', 'contact_inquiries'],                // Phase 4 (F-2.1)
        ['leads', 'lead_import_id', 'lead_imports'],                         // Phase 5, created after leads
        ['leads', 'referral_visit_id', 'collaborator_referral_visits'],      // Phase 9 (F-3.5)
        // §2.2 lead_activities
        ['lead_activities', 'lead_follow_up_id', 'lead_follow_ups'],         // Phase 5, created after lead_activities
        // §2.7 clients
        ['clients', 'lead_id', 'leads'],                                     // Phase 5, created after clients
        // §2.4 lead_conversions
        ['lead_conversions', 'project_id', 'projects'],                      // Phase 6
        ['lead_conversions', 'collaborator_referral_id', 'collaborator_referrals'], // Phase 10 spine set
        // phase-04 §2.1 deferred client links, promoted by Phase 5 (phase-04 §13, build-order §5)
        ['testimonials', 'client_id', 'clients'],
        ['portfolio_items', 'client_id', 'clients'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as [$table, $column, $target]) {
            if (! $this->canPromote($table, $column, $target) || $this->hasForeignKeyOn($table, $column)) {
                continue;
            }

            $this->assertNoOrphans($table, $column, $target);

            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $target): void {
                $blueprint->foreign($column, $this->constraintName($table, $column))
                    ->references('id')
                    ->on($target)
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::KEYS) as [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $name = $this->constraintName($table, $column);

            if (! $this->hasForeignKeyNamed($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropForeign($name);
            });
        }
    }

    private function canPromote(string $table, string $column, string $target): bool
    {
        return Schema::hasTable($table)
            && Schema::hasColumn($table, $column)
            && Schema::hasTable($target)
            && Schema::hasColumn($target, 'id');
    }

    /**
     * Any foreign key on the column, whoever added it and whatever it is called.
     */
    private function hasForeignKeyOn(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (array_map('strtolower', (array) $foreignKey['columns']) === [strtolower($column)]) {
                return true;
            }
        }

        return false;
    }

    private function hasForeignKeyNamed(string $table, string $name): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (strtolower((string) $foreignKey['name']) === strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    private function constraintName(string $table, string $column): string
    {
        return $table.'_'.$column.'_foreign';
    }

    /**
     * Refuse to promote over a dangling value rather than let MariaDB's 1452 surface without context, and never
     * repair it silently.
     */
    private function assertNoOrphans(string $table, string $column, string $target): void
    {
        $orphans = DB::table($table.' as child')
            ->leftJoin($target.' as parent', 'parent.id', '=', 'child.'.$column)
            ->whereNotNull('child.'.$column)
            ->whereNull('parent.id')
            ->count();

        if ($orphans === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'phase-05 [D-P5-2]: cannot add the foreign key %s.%s -> %s.id: %d row(s) point at no %s row. Inspect them '
            .'with "SELECT id, %s FROM %s WHERE %s IS NOT NULL AND %s NOT IN (SELECT id FROM %s)", correct or null the '
            .'values deliberately, then re-run the migration.',
            $table,
            $column,
            $target,
            $orphans,
            $target,
            $column,
            $table,
            $column,
            $column,
            $target
        ));
    }
};
