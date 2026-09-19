<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.5 — tasks: §22, one table for tasks and subtasks, exactly one level deep.
 *
 * `chk_tasks_depth` is INV-P12 as a database rule — `depth = 1` exactly when `parent_task_id` is set — so
 * a subtask of a subtask is impossible whatever the service does. `chk_tasks_assignee` is INV-P10: a task
 * has **at most one** assignee, either a staff `users.id` ([D-P6-2] / D32) or a `collaborator_id`, never
 * both.
 *
 * Three STORED generated columns (INV-P7, raw SQL, fail loudly): `estimated_hours` over
 * `estimated_minutes`, and `actual_minutes` / `actual_hours` over `actual_seconds`. §22's "actual hours"
 * therefore exists and is unwritable; the seconds cache underneath is recomputed by SUM under a row lock,
 * never incremented (INV-P6), which is what makes it self-healing and a replayed job harmless.
 *
 * `is_client_visible` defaults to **true** so the client panel is useful from day one, and the client rule
 * is the AND of two switches: `projects.client_can_see_tasks = 1 AND tasks.is_client_visible = 1` (§7.7,
 * §9, F-3.1). Without the per-row flag every internal task would leak the moment the project switch was on.
 *
 * `board_position` is `decimal(20,10)` for §6.5's fractional Kanban ordering: a drop between two cards
 * takes the midpoint and touches one row, and the column is renumbered only when the gap runs out.
 *
 * The task's history is `activity_log` filtered by subject (D13) — there is deliberately no per-task
 * history table.
 */
return new class extends Migration
{
    private const TABLE = 'tasks';

    /**
     * §2.5 / §2.14: name => [column definition, generation expression, position].
     */
    private const GENERATED = [
        'estimated_hours' => ['DECIMAL(10,2)', 'ROUND(`estimated_minutes` / 60, 2)', 'estimated_minutes'],
        'actual_minutes' => ['INT UNSIGNED', 'ROUND(`actual_seconds` / 60)', 'actual_seconds'],
        'actual_hours' => ['DECIMAL(10,2)', 'ROUND(`actual_seconds` / 3600, 2)', 'actual_minutes'],
    ];

    private const CHECKS = [
        'chk_tasks_assignee' => '(`assigned_user_id` is not null) + (`assigned_collaborator_id` is not null) <= 1',
        'chk_tasks_depth' => '(`parent_task_id` is null and `depth` = 0)'
            .' or (`parent_task_id` is not null and `depth` = 1)',
        'chk_tasks_progress' => '`progress_percent` >= 0 and `progress_percent` <= 100',
        'chk_tasks_dates' => '`start_date` is null or `due_date` is null or `due_date` >= `start_date`',
        'chk_tasks_checklist' => '`checklist_done` <= `checklist_total`',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('project_milestone_id')->nullable()
                    ->constrained('project_milestones')->nullOnDelete();
                $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->cascadeOnDelete();
                $table->unsignedTinyInteger('depth')->default(0);

                $table->string('title', 200);
                $table->text('description')->nullable();

                $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
                // FK promoted by Phase 8 ([D-P6-1]).
                $table->unsignedBigInteger('assigned_collaborator_id')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();

                $table->string('priority', 16)->default('medium');
                $table->string('status', 32)->default('todo');
                $table->date('start_date')->nullable();
                $table->date('due_date')->nullable();

                $table->unsignedInteger('estimated_minutes')->nullable();
                // estimated_hours (generated STORED) is added after the table exists.
                $table->unsignedBigInteger('actual_seconds')->default(0);
                // actual_minutes / actual_hours (generated STORED) are added after the table exists.

                $table->decimal('progress_percent', 8, 4)->default(0);
                $table->unsignedSmallInteger('checklist_total')->default(0);
                $table->unsignedSmallInteger('checklist_done')->default(0);
                $table->unsignedSmallInteger('subtask_total')->default(0);
                $table->unsignedSmallInteger('subtask_done')->default(0);
                $table->unsignedSmallInteger('comment_count')->default(0);
                $table->unsignedSmallInteger('attachment_count')->default(0);

                $table->boolean('is_client_visible')->default(true);
                $table->decimal('board_position', 20, 10)->default(0);
                $table->string('blocked_reason', 255)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.5 Keys.
                $table->index(['project_id', 'status', 'board_position']);
                $table->index(['assigned_user_id', 'status']);
                $table->index(['assigned_collaborator_id', 'status']);
                $table->index(['project_milestone_id', 'status']);
                $table->index('parent_task_id');
                $table->index(['due_date', 'status']);
                $table->index('reporter_id');
                $table->index('title');
                // The client panel's only task query (F-3.1).
                $table->index(['project_id', 'is_client_visible']);
                $table->index('deleted_at');
            });
        }

        foreach (self::GENERATED as $column => [$definition, $expression, $after]) {
            $this->addStoredColumn($column, $definition, $expression, $after);
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
     * §2.14: one STORED generated column, proved STORED or thrown (INV-P7, INV-P17).
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
                'phase-06 §2.14: `tasks`.`%s` must be a STORED generated column (INV-P7: §22 asks for '
                .'actual hours and they must be unwritable). The server did not create it as one; fix the '
                .'database server rather than skipping the guard.',
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
