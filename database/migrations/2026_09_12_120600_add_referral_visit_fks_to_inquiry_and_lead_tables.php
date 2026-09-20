<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 · §2.5a — promote the two deferred `referral_visit_id` columns to real foreign keys (RD-3).
 *
 * Phase 4 shipped `contact_inquiries.referral_visit_id` and Phase 5 shipped `leads.referral_visit_id`
 * before `collaborator_referral_visits` existed, each nullable and indexed with the constraint
 * deliberately deferred here (phase-04 §13, phase-05 §13.1).
 *
 * The same four rules as §2.5a's first promotion: guarded both ways and loud when it skips, idempotent,
 * `down()` drops the constraint and nothing else, and pre-existing orphans are **nulled by a reported
 * pre-pass, never deleted** — both columns are display snapshots under **D37**, re-derivable from the
 * attribution rows, so nulling a dangling pointer loses no fact. The count is printed, because a silent
 * repair of attribution data is what INV-R1 forbids.
 */
return new class extends Migration
{
    /** @var array<int, array{table: string, fk: string}> */
    private const PROMOTIONS = [
        ['table' => 'contact_inquiries', 'fk' => 'contact_inquiries_referral_visit_id_foreign'],
        ['table' => 'leads', 'fk' => 'leads_referral_visit_id_foreign'],
    ];

    private const COLUMN = 'referral_visit_id';

    public function up(): void
    {
        foreach (self::PROMOTIONS as ['table' => $table, 'fk' => $fk]) {
            if (! $this->promotable($table, $fk)) {
                continue;
            }

            $orphans = DB::table($table)
                ->whereNotNull(self::COLUMN)
                ->whereNotIn(self::COLUMN, DB::table('collaborator_referral_visits')->select('id'))
                ->update([self::COLUMN => null]);

            if ($orphans > 0) {
                $message = sprintf(
                    'phase-09 §2.5a: %d %s row(s) pointed at a referral visit that does not exist; the '
                    .'snapshot column was nulled (D37) and nothing was deleted.',
                    $orphans,
                    $table
                );

                logger()->warning($message);
                echo $message.PHP_EOL;
            }

            Schema::table($table, function ($blueprint) use ($fk): void {
                $blueprint->foreign(self::COLUMN, $fk)
                    ->references('id')->on('collaborator_referral_visits')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::PROMOTIONS as ['table' => $table, 'fk' => $fk]) {
            if (! Schema::hasTable($table) || ! $this->constraintExists($table, $fk)) {
                continue;
            }

            Schema::table($table, function ($blueprint) use ($fk): void {
                $blueprint->dropForeign($fk);
            });
        }
    }

    private function promotable(string $table, string $fk): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable('collaborator_referral_visits')) {
            logger()->warning(sprintf(
                'phase-09 §2.5a: the %s referral-visit FK was skipped — one of the two tables does not '
                .'exist yet.',
                $table
            ));

            return false;
        }

        if (! Schema::hasColumn($table, self::COLUMN)) {
            logger()->warning(sprintf('phase-09 §2.5a: %s.%s does not exist; skipped.', $table, self::COLUMN));

            return false;
        }

        return ! $this->constraintExists($table, $fk);
    }

    private function constraintExists(string $table, string $fk): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $fk)
            ->exists();
    }
};
