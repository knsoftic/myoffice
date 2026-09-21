<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.11 — time_entry_segments: the append-only clock record, one row per start, closed on pause
 * or stop.
 *
 * **This is the only place elapsed time exists** (INV-P5). Every figure anywhere in the system — a task's
 * `actual_seconds`, a project's hours, a daily rollup — is a SUM over these rows. There is no mutable
 * running total anywhere: while a timer runs the open segment has `ended_at IS NULL` and its
 * `duration_seconds` generates to **zero**, and the live figure on screen is `cached seconds + (now −
 * open segment start)`, computed client-side and never persisted.
 *
 * `open_guard` carries **`UNIQUE uq_tes_open`**: at most one open segment per worker across every project
 * and task — the second, independent enforcement of INV-P4, so the guarantee survives even if
 * `time_entries.running_guard` were ever compromised.
 *
 * **No soft deletes** (D19): append-only. The model's `updating` hook permits only `ended_at` +
 * `end_reason`, and only while `ended_at` is still NULL; `deleting` throws unless the parent is being
 * force-deleted, which no UI route offers.
 *
 * `chk_tes_window` uses `>`, not `>=`: a zero-length segment is a bug, not data.
 *
 * **`started_at` / `ended_at` are `DATETIME`, not `TIMESTAMP` (D67)** — see
 * `create_time_entries_table`: on this server a first `TIMESTAMP NOT NULL` column silently acquires
 * `ON UPDATE CURRENT_TIMESTAMP`, which on an append-only clock record would rewrite history on any UPDATE
 * and change `duration_seconds` underneath every SUM already taken.
 */
return new class extends Migration
{
    private const TABLE = 'time_entry_segments';

    private const GUARD_COLUMN = 'open_guard';

    private const GUARD_EXPRESSION = 'CASE WHEN `ended_at` IS NULL'
        ." THEN COALESCE(CONCAT('u:', `user_id`), CONCAT('c:', `collaborator_id`)) ELSE NULL END";

    private const DURATION_EXPRESSION = 'CASE WHEN `ended_at` IS NULL THEN 0'
        .' ELSE TIMESTAMPDIFF(SECOND, `started_at`, `ended_at`) END';

    private const CHECKS = [
        'chk_tes_window' => '`ended_at` is null or `ended_at` > `started_at`',
        'chk_tes_one_worker' => '(`user_id` is not null) + (`collaborator_id` is not null) = 1',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
                // Denormalised worker — the open guard needs it on this row.
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                // FK promoted by Phase 8 ([D-P6-1]).
                $table->unsignedBigInteger('collaborator_id')->nullable();
                // DATETIME, not TIMESTAMP — see the class docblock (D67). Both written exactly once.
                $table->dateTime('started_at');
                $table->dateTime('ended_at')->nullable();
                // duration_seconds / open_guard (generated STORED) are added after the table exists.
                $table->string('end_reason', 16)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('device', 64)->nullable();
                $table->timestamps();
                // No softDeletes(): append-only clock record (D19, INV-P5).
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.11 Keys (uq_tes_open is added with its guard column below).
                $table->index(['time_entry_id', 'started_at']);
                $table->index('started_at');
                $table->index(['user_id', 'started_at']);
                $table->index(['collaborator_id', 'started_at']);
            });
        }

        $this->addStoredColumn('duration_seconds', 'INT', self::DURATION_EXPRESSION, 'ended_at');
        $this->addStoredColumn(self::GUARD_COLUMN, 'VARCHAR(40)', self::GUARD_EXPRESSION, 'device');

        if (! Schema::hasIndex(self::TABLE, 'uq_tes_open')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(self::GUARD_COLUMN, 'uq_tes_open');
            });
        }

        foreach (self::CHECKS as $name => $expression) {
            $this->addCheck($name, $expression);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * §2.14: one STORED generated column, proved STORED or thrown. Both of this table's generated columns
     * are load-bearing — `duration_seconds` is the only elapsed time in the system and `open_guard`
     * carries INV-P4 (INV-P17).
     */
    private function addStoredColumn(string $column, string $definition, string $expression, string $after): void
    {
        if (! Schema::hasColumn(self::TABLE, $column)) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` %s AS (%s) STORED AFTER `%s`',
                self::TABLE,
                $column,
                $definition,
                $expression,
                $after
            ));
        }

        $row = DB::selectOne(
            'SELECT IS_GENERATED AS is_generated, EXTRA AS extra FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), self::TABLE, $column]
        );

        $extra = strtoupper((string) ($row->extra ?? ''));

        if ($row === null
            || strtoupper((string) $row->is_generated) !== 'ALWAYS'
            || (! str_contains($extra, 'STORED') && ! str_contains($extra, 'PERSISTENT'))) {
            throw new RuntimeException(sprintf(
                'phase-06 §2.14: `time_entry_segments`.`%s` must be a STORED generated column (INV-P4 and '
                .'INV-P5 rest on it). The server did not create it as one; fix the database server rather '
                .'than skipping the guard.',
                $column
            ));
        }
    }

    private function addCheck(string $name, string $expression): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->hasCheck($name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', self::TABLE, $name, $expression));
    }

    private function hasCheck(string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), self::TABLE, $name]
        ) !== [];
    }
};
