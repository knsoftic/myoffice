<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — promote Phase 6's five deferred collaborator columns to real foreign keys ([D-P6-1]).
 *
 * Phase 6 shipped `projects.collaborator_id`, `project_members.collaborator_id`,
 * `tasks.assigned_collaborator_id`, `time_entries.collaborator_id` and
 * `time_entry_segments.collaborator_id` as indexed, unconstrained columns two phases before
 * `collaborators` existed, and its R-9 names the exposure: until this migration runs, a typo could write
 * `collaborator_id = 999`.
 *
 * **`restrictOnDelete` for all five**: a collaborator with delivery history must not vanish, and a
 * cascade here would take somebody's time entries with them.
 *
 * `projects.collaborator_id` is a display snapshot under **D37** — no scope and no commission engine
 * reads it — so a dangling value there is nulled by the reported pre-pass. The other four name a real
 * worker, so an orphan among them would be a genuine data error; the same pre-pass reports it and nulls
 * it rather than refusing the migration, and prints the count so nothing is repaired silently.
 */
return new class extends Migration
{
    /** @var array<int, array{table: string, column: string, fk: string}> */
    private const PROMOTIONS = [
        ['table' => 'projects', 'column' => 'collaborator_id', 'fk' => 'projects_collaborator_id_foreign'],
        ['table' => 'project_members', 'column' => 'collaborator_id', 'fk' => 'project_members_collaborator_id_foreign'],
        ['table' => 'tasks', 'column' => 'assigned_collaborator_id', 'fk' => 'tasks_assigned_collaborator_id_foreign'],
        ['table' => 'time_entries', 'column' => 'collaborator_id', 'fk' => 'time_entries_collaborator_id_foreign'],
        ['table' => 'time_entry_segments', 'column' => 'collaborator_id', 'fk' => 'time_entry_segments_collaborator_id_foreign'],
    ];

    public function up(): void
    {
        foreach (self::PROMOTIONS as ['table' => $table, 'column' => $column, 'fk' => $fk]) {
            if (! $this->promotable($table, $column, $fk)) {
                continue;
            }

            $orphans = DB::table($table)
                ->whereNotNull($column)
                ->whereNotIn($column, DB::table('collaborators')->select('id'))
                ->update([$column => null]);

            if ($orphans > 0) {
                $message = sprintf(
                    'phase-08 [D-P6-1]: %d %s.%s value(s) pointed at a collaborator that does not exist '
                    .'and were nulled. Nothing was deleted; the count is printed so the repair is not silent.',
                    $orphans,
                    $table,
                    $column
                );

                logger()->warning($message);
                echo $message.PHP_EOL;
            }

            Schema::table($table, function ($blueprint) use ($column, $fk): void {
                $blueprint->foreign($column, $fk)
                    ->references('id')->on('collaborators')->restrictOnDelete();
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

    private function promotable(string $table, string $column, string $fk): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable('collaborators')) {
            logger()->warning(sprintf(
                'phase-08 [D-P6-1]: the %s.%s FK was skipped — one of the two tables does not exist yet.',
                $table,
                $column
            ));

            return false;
        }

        if (! Schema::hasColumn($table, $column)) {
            logger()->warning(sprintf('phase-08 [D-P6-1]: %s.%s does not exist; skipped.', $table, $column));

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
