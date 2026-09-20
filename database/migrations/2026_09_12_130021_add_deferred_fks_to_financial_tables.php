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

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $this->exists($name)) {
                continue;
            }

            if (! Schema::hasTable($references)) {
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
            echo $message.PHP_EOL;
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

    private function exists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }
};
