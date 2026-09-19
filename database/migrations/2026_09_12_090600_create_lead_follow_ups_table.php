<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 · §2.3 — lead_follow_ups: §18's "follow-up date" made operational.
 *
 * `open_guard` is a STORED generated column (`1` while `status = 'pending'`, NULL otherwise) carrying
 * `UNIQUE uq_lfu_open(lead_id, open_guard)`: **at most one pending follow-up per lead**, enforced by the
 * database, so the `leads.follow_up_at` cache is always truthful ([D-P5-12], §11 tests 26-27). Completed,
 * missed, rescheduled and cancelled rows leave the guard NULL and stack freely. The column is raw SQL exactly as
 * the contract states and the migration **fails loudly** — it never skips — when the server cannot create it
 * (§11 test 3).
 *
 * `UNIQUE uq_lfu_previous(previous_follow_up_id)`: a follow-up has at most one successor in the reschedule chain.
 * `reminder_due_at = scheduled_at - remind_before_minutes` is computed in PHP on save (not a generated column:
 * MariaDB 10.4 interval arithmetic over two columns is not worth the risk, §2.3). The DB default of
 * `remind_before_minutes` is 60, the default of `crm.follow_up_reminder_minutes`; the service writes the resolved
 * setting. `idx_lfu_due` is the reminder sweep's only query.
 *
 * CHECK `chk_lfu_completed`: a completed follow-up always carries `completed_at` and an `outcome`.
 *
 * Mutable business table → timestamps + softDeletes + blameable (CLAUDE.md §3, D19).
 */
return new class extends Migration
{
    private const TABLE = 'lead_follow_ups';

    private const GUARD_COLUMN = 'open_guard';

    private const GUARD_EXPRESSION = "CASE WHEN `status` = 'pending' THEN 1 ELSE NULL END";

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 32);
                $table->dateTime('scheduled_at');
                $table->unsignedInteger('remind_before_minutes')->default(60);
                $table->dateTime('reminder_due_at')->nullable();
                $table->timestamp('reminder_sent_at')->nullable();
                $table->string('status', 24)->default('pending');
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('outcome', 32)->nullable();
                $table->string('outcome_note', 255)->nullable();
                $table->foreignId('previous_follow_up_id')->nullable()->constrained('lead_follow_ups')->nullOnDelete();
                $table->timestamp('rescheduled_at')->nullable();
                $table->string('cancel_reason', 255)->nullable();
                $table->string('notes', 255)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.3 Keys (uq_lfu_open is added with its generated column below).
                $table->unique('previous_follow_up_id', 'uq_lfu_previous');
                $table->index(['status', 'reminder_due_at', 'reminder_sent_at'], 'idx_lfu_due');
                $table->index(['assigned_to', 'status', 'scheduled_at'], 'idx_lfu_worklist');
                $table->index(['lead_id', 'scheduled_at']);
                $table->index('scheduled_at');
                $table->index('reminder_due_at');
                $table->index('completed_by');
            });
        }

        $this->addStoredGuard();

        if (! Schema::hasIndex(self::TABLE, 'uq_lfu_open')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['lead_id', self::GUARD_COLUMN], 'uq_lfu_open');
            });
        }

        $this->addCheck(
            self::TABLE,
            'chk_lfu_completed',
            "(`status` <> 'completed') or (`completed_at` is not null and `outcome` is not null)"
        );
    }

    public function down(): void
    {
        // Inbound: lead_activities.lead_follow_up_id (deferred migration, rolled back first) and this table's own
        // previous_follow_up_id. DROP TABLE removes every key, the generated column and chk_lfu_completed.
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * §2.3: the STORED generated column, as raw SQL. Any server error propagates (a QueryException), and a
     * column that exists but is not STORED is refused.
     */
    private function addStoredGuard(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::GUARD_COLUMN)) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` TINYINT AS (%s) STORED AFTER `status`',
                self::TABLE,
                self::GUARD_COLUMN,
                self::GUARD_EXPRESSION
            ));
        }

        $row = DB::selectOne(
            'SELECT IS_GENERATED AS is_generated, EXTRA AS extra FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), self::TABLE, self::GUARD_COLUMN]
        );

        $extra = strtoupper((string) ($row->extra ?? ''));

        if ($row === null
            || strtoupper((string) $row->is_generated) !== 'ALWAYS'
            || (! str_contains($extra, 'STORED') && ! str_contains($extra, 'PERSISTENT'))) {
            throw new RuntimeException(
                'phase-05 §2.3: `lead_follow_ups`.`open_guard` must be a STORED generated column (it carries '
                .'uq_lfu_open, the one-open-follow-up guarantee). The server did not create it as one; fix the '
                .'database server rather than skipping the guard.'
            );
        }
    }

    /**
     * Add a named CHECK constraint if MariaDB does not already carry it (idempotent re-run).
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        if (! Schema::hasTable($table) || $this->hasCheck($table, $name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $name, $expression));
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $name]
        ) !== [];
    }
};
