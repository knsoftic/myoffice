<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.10 — time_entries: §23, one row per work session (a timer run, however many pauses) or per
 * manual entry.
 *
 * **It holds no mutable running total** (INV-P5): `duration_seconds` is a cache recomputed as
 * `SUM(time_entry_segments.duration_seconds)` under a row lock, never incremented (INV-P6), so a replayed
 * job cannot inflate it and a wrong value heals itself on the next recompute.
 *
 * `running_guard` is the STORED generated column behind **`UNIQUE uq_te_running`** — the database's answer
 * to "only one timer may run per person" (INV-P4). It resolves to `u:{user_id}` or `c:{collaborator_id}`
 * while the entry is running and NULL otherwise, and MariaDB unique indexes ignore NULLs, so stopped
 * entries stack freely. A second `start` fails with 1062 and the service turns that into a domain error
 * naming the entry already running — the decision is an INSERT, never a SELECT-then-INSERT.
 *
 * **`started_at` / `ended_at` are `DATETIME`, not `TIMESTAMP` (D67).** The contract says "timestamp", but
 * this server runs `explicit_defaults_for_timestamp = OFF`, where the first `TIMESTAMP NOT NULL` column in
 * a table silently acquires `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` — which would rewrite
 * the start of a work session on any later UPDATE and quietly falsify every rollup. `DATETIME` carries no
 * such rule, and with the session timezone pinned to `+00:00` (D61) the two types store the same UTC
 * instant.
 *
 * `work_date` is the business date the worker's timezone produced, and **every daily/weekly rollup groups
 * on it**, never on a timestamp — that is what keeps a 23:50–00:10 session on the day the worker means.
 *
 * `chk_te_duration` caps one entry at 24 hours: whatever a clock change or a forgotten timer does, no
 * single entry can claim more. Soft delete = discarded, with a mandatory `discard_reason`: a wrong entry
 * is discarded and re-entered, never silently edited to zero.
 */
return new class extends Migration
{
    private const TABLE = 'time_entries';

    private const GUARD_COLUMN = 'running_guard';

    private const GUARD_EXPRESSION = "CASE WHEN `status` = 'running'"
        ." THEN COALESCE(CONCAT('u:', `user_id`), CONCAT('c:', `collaborator_id`)) ELSE NULL END";

    /**
     * §2.10 / §2.14: name => [column definition, generation expression, position].
     */
    private const GENERATED = [
        'duration_minutes' => ['INT UNSIGNED', 'ROUND(`duration_seconds` / 60)', 'duration_seconds'],
        'duration_hours' => ['DECIMAL(10,2)', 'ROUND(`duration_seconds` / 3600, 2)', 'duration_minutes'],
    ];

    private const CHECKS = [
        'chk_te_one_worker' => '(`user_id` is not null) + (`collaborator_id` is not null) = 1',
        'chk_te_window' => '`ended_at` is null or `ended_at` >= `started_at`',
        'chk_te_running_open' => "`status` <> 'running' or `ended_at` is null",
        'chk_te_manual_complete' => "`source` <> 'manual' or (`ended_at` is not null and `status` = 'stopped')",
        'chk_te_duration' => '`duration_seconds` >= 0 and `duration_seconds` <= 86400',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                // Denormalised from the task so "project hours" is one indexed GROUP BY.
                $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
                $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                // FK promoted by Phase 8 ([D-P6-1]).
                $table->unsignedBigInteger('collaborator_id')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

                $table->string('source', 16);
                $table->string('status', 16)->default('stopped');
                // DATETIME, not TIMESTAMP — see the class docblock (D67).
                $table->dateTime('started_at');
                $table->dateTime('ended_at')->nullable();
                $table->date('work_date');

                $table->unsignedInteger('duration_seconds')->default(0);
                // duration_minutes / duration_hours (generated STORED) are added after the table exists.

                $table->string('description', 500)->nullable();
                $table->string('manual_reason', 255)->nullable();
                $table->string('discard_reason', 255)->nullable();
                // running_guard (generated STORED) is added after the table exists.

                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.10 Keys (uq_te_running is added with its guard column below).
                $table->index(['user_id', 'work_date']);
                $table->index(['collaborator_id', 'work_date']);
                $table->index(['project_id', 'work_date']);
                $table->index(['task_id', 'work_date']);
                $table->index('work_date');
                $table->index('status');
                $table->index(['recorded_by', 'work_date']);
                $table->index('deleted_at');
            });
        }

        foreach (self::GENERATED as $column => [$definition, $expression, $after]) {
            $this->addStoredColumn($column, $definition, $expression, $after);
        }

        $this->addStoredColumn(self::GUARD_COLUMN, 'VARCHAR(40)', self::GUARD_EXPRESSION, 'discard_reason');

        if (! Schema::hasIndex(self::TABLE, 'uq_te_running')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(self::GUARD_COLUMN, 'uq_te_running');
            });
        }

        foreach (self::CHECKS as $name => $expression) {
            $this->addCheck($name, $expression);
        }
    }

    public function down(): void
    {
        // time_entry_segments.time_entry_id points here and is rolled back first.
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * §2.14: one STORED generated column, proved STORED or thrown. `running_guard` carries INV-P4 — a
     * VIRTUAL column here would silently drop the one-timer-per-person guarantee (INV-P17).
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
                'phase-06 §2.14: `time_entries`.`%s` must be a STORED generated column (uq_te_running and '
                .'with it INV-P4 rest on it). The server did not create it as one; fix the database server '
                .'rather than skipping the guard.',
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
