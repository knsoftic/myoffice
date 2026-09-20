<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 21 — the three keys whose targets arrive in phases 9 and 13 (phase-10-12 §2.2).
 *
 * `collaborator_referral_visits` is Phase 9's and exists as of this release; `invoices` and
 * `payment_methods` are Phase 13's and do not. All three are handled the same way — guarded and
 * re-runnable — so Phase 13 closes its two by simply running `migrate` again.
 *
 * Kept separate from file 20 because these are **deferred by design** rather than merely out of order:
 * a payment genuinely may exist with no invoice at all (D43) and with a free-text method rather than a
 * row in a configurable list (D32), so the columns are legal and useful long before the constraints can
 * be added.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const KEYS = [
        // Phase 9's, and present from this release onwards.
        ['collaborator_referrals', 'referral_visit_id', 'collaborator_referral_visits'],
        // Phase 13's.
        ['student_fee_payments', 'payment_method_id', 'payment_methods'],
        ['project_payments', 'payment_method_id', 'payment_methods'],
        ['project_payments', 'invoice_id', 'invoices'],
    ];

    public function up(): void
    {
        $waiting = [];

        foreach (self::KEYS as [$table, $column, $references]) {
            $name = RawSchema::foreignKeyName($table, $column);

            if (! $this->hasTable($table) || ! $this->hasColumn($table, $column) || $this->exists($name)) {
                continue;
            }

            if (! $this->hasTable($references)) {
                $waiting[] = $table.'.'.$column.' -> '.$references;

                continue;
            }

            // All three are `nullOnDelete`: none of them is financial evidence. A deleted invoice must
            // not take a received payment with it (D43), and a retired payment-method row must not take
            // the receipts that used it — which is exactly why the method is also snapshotted as a
            // string on the row beside this key (D32).
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE SET NULL',
                $table,
                $name,
                $column,
                $references,
            ));

            $this->remember($name);
        }

        if ($waiting !== []) {
            $message = sprintf(
                'phase-10 file 21: %d deferred foreign key(s) are still waiting for their table. '
                .'Re-run `php artisan migrate` after that phase lands — this migration is idempotent. '
                .'Waiting: %s',
                count($waiting),
                implode(', ', $waiting),
            );

            logger()->warning($message);

            // Printed for a person running `migrate`, but not for the test runner: every suite that
            // refreshes the database would otherwise repeat it, and a warning that appears forty times
            // an hour is one nobody reads.
            if (! app()->runningUnitTests()) {
                echo $message.PHP_EOL;
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::KEYS) as [$table, $column]) {
            $name = RawSchema::foreignKeyName($table, $column);

            if ($this->hasTable($table) && $this->exists($name)) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $name));
            }
        }
    }

    /**
     * Existing tables, columns and constraint names, read **once**.
     *
     * The straightforward version asks `Schema::hasTable()`, `Schema::hasColumn()` and an
     * `information_schema` existence query per key. At seventy-odd keys that is two hundred metadata
     * round trips, and it made a full `migrate` nineteen seconds slower — which every test class that
     * refreshes the database then pays again. Three queries answer the same questions.
     *
     * @var array{tables: array<string, true>, columns: array<string, true>, constraints: array<string, true>}|null
     */
    private ?array $catalogue = null;

    private function catalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }

        $schema = DB::getDatabaseName();

        $tables = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $schema)->pluck('TABLE_NAME')->all();

        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $schema)
            ->selectRaw('CONCAT(TABLE_NAME, ".", COLUMN_NAME) as k')
            ->pluck('k')->all();

        $constraints = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $schema)->pluck('CONSTRAINT_NAME')->all();

        return $this->catalogue = [
            'tables' => array_fill_keys($tables, true),
            'columns' => array_fill_keys($columns, true),
            'constraints' => array_fill_keys($constraints, true),
        ];
    }

    private function hasTable(string $table): bool
    {
        return isset($this->catalogue()['tables'][$table]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return isset($this->catalogue()['columns'][$table.'.'.$column]);
    }

    private function exists(string $name): bool
    {
        return isset($this->catalogue()['constraints'][$name]);
    }

    /**
     * Remember a key this run just created, so a later lookup in the same run sees it without another
     * round trip.
     */
    private function remember(string $name): void
    {
        $this->catalogue();
        $this->catalogue['constraints'][$name] = true;
    }
};
