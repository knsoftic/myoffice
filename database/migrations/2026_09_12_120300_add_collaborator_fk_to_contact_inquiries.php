<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 · §2.5a — promote `contact_inquiries.collaborator_id` to a real foreign key (RD-3).
 *
 * Phase 4 shipped the column three phases before `collaborators` existed: `unsignedBigInteger`, nullable
 * and indexed, with the constraint deliberately deferred to this phase (phase-04 §2.20, §13). This
 * migration adds **the constraint and nothing else** — it creates, renames, widens, backfills and
 * indexes nothing.
 *
 * Four rules, identical for all three promotions in §2.5a:
 *
 * 1. **Guarded both ways and loud when it skips**, so `migrate` and `migrate:fresh` are legal whatever
 *    order Phase 4 and Phase 8 land in ([D-FS-1]).
 * 2. **Idempotent**: an existing constraint of this name makes the run a no-op.
 * 3. `down()` drops the FK and nothing else — never the column, never the index.
 * 4. **Pre-existing orphans are nulled by a reported pre-pass, never deleted.** MariaDB refuses an FK
 *    over a dangling value (errno 1452). The column is a display snapshot under **D37**, re-derivable
 *    from the attribution rows, so nulling a dangling pointer loses no fact — but the count is printed
 *    and logged, because a silent repair of attribution data is what INV-R1 forbids.
 */
return new class extends Migration
{
    private const TABLE = 'contact_inquiries';

    private const COLUMN = 'collaborator_id';

    private const FK = 'contact_inquiries_collaborator_id_foreign';

    public function up(): void
    {
        if (! $this->promotable()) {
            return;
        }

        $orphans = DB::table(self::TABLE)
            ->whereNotNull(self::COLUMN)
            ->whereNotIn(self::COLUMN, DB::table('collaborators')->select('id'))
            ->update([self::COLUMN => null]);

        if ($orphans > 0) {
            $message = sprintf(
                'phase-08 §2.5a: %d contact_inquiries row(s) pointed at a collaborator that does not '
                .'exist; the snapshot column was nulled (D37) and nothing was deleted. Re-derive with '
                .'collaborators:sync-referral-snapshots.',
                $orphans
            );

            logger()->warning($message);
            echo $message.PHP_EOL;
        }

        Schema::table(self::TABLE, function ($table): void {
            $table->foreign(self::COLUMN, self::FK)
                ->references('id')->on('collaborators')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! $this->constraintExists()) {
            return;
        }

        Schema::table(self::TABLE, function ($table): void {
            $table->dropForeign(self::FK);
        });
    }

    private function promotable(): bool
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasTable('collaborators')) {
            logger()->warning(
                'phase-08 §2.5a: the contact_inquiries collaborator FK was skipped — one of the two '
                .'tables does not exist yet.'
            );

            return false;
        }

        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            logger()->warning('phase-08 §2.5a: contact_inquiries.collaborator_id does not exist; skipped.');

            return false;
        }

        return ! $this->constraintExists();
    }

    private function constraintExists(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', self::FK)
            ->exists();
    }
};
