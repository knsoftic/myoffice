<?php

declare(strict_types=1);

namespace App\Console\Commands\Project;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Assert that every §2.14 guarantee is still in the database (phase-06 INV-P17, §10.4).
 *
 * Three of this phase's guarantees rest on MariaDB features Laravel's schema builder cannot express, and
 * a guarantee nobody checks is a guarantee that quietly stops holding — a restored dump, a migration run
 * against the wrong server, a well-meant `ALTER`. This command fails loudly and names what vanished.
 */
final class VerifyConstraints extends Command
{
    protected $signature = 'projects:verify-constraints';

    protected $description = 'Check that every Phase 6 generated column, CHECK, unique index and trigger still exists.';

    /** §2.14: table => columns that must be STORED generated. */
    private const GENERATED = [
        'projects' => ['net_value', 'actual_minutes', 'actual_hours'],
        'project_value_revisions' => ['delta_amount'],
        'project_members' => ['active_guard'],
        'tasks' => ['estimated_hours', 'actual_minutes', 'actual_hours'],
        'time_entries' => ['duration_minutes', 'duration_hours', 'running_guard'],
        'time_entry_segments' => ['duration_seconds', 'open_guard'],
    ];

    private const UNIQUE_INDEXES = [
        'projects' => ['uq_projects_code'],
        'project_value_revisions' => ['uq_pvr_revision'],
        'project_members' => ['uq_pm_user_active', 'uq_pm_collaborator_active'],
        'task_comment_mentions' => ['uq_tcm_pair'],
        'time_entries' => ['uq_te_running'],
        'time_entry_segments' => ['uq_tes_open'],
    ];

    private const CHECK_COUNTS = [
        'projects' => 6,
        'project_value_revisions' => 2,
        'project_members' => 1,
        'project_milestones' => 4,
        'tasks' => 5,
        'task_checklist_items' => 1,
        'attachments' => 1,
        'time_entries' => 5,
        'time_entry_segments' => 2,
    ];

    public function handle(): int
    {
        $schema = DB::connection()->getDatabaseName();
        $missing = [];

        foreach (self::GENERATED as $table => $columns) {
            foreach ($columns as $column) {
                $row = DB::selectOne(
                    'SELECT EXTRA AS extra, IS_GENERATED AS gen FROM information_schema.COLUMNS'
                    .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$schema, $table, $column]
                );

                $extra = strtoupper((string) ($row->extra ?? ''));

                if ($row === null
                    || strtoupper((string) $row->gen) !== 'ALWAYS'
                    || (! str_contains($extra, 'STORED') && ! str_contains($extra, 'PERSISTENT'))) {
                    $missing[] = sprintf('%s.%s is not a STORED generated column', $table, $column);
                }
            }
        }

        foreach (self::UNIQUE_INDEXES as $table => $names) {
            foreach ($names as $name) {
                $exists = DB::select(
                    'SELECT 1 FROM information_schema.STATISTICS'
                    .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0 LIMIT 1',
                    [$schema, $table, $name]
                ) !== [];

                if (! $exists) {
                    $missing[] = sprintf('unique index %s on %s', $name, $table);
                }
            }
        }

        foreach (self::CHECK_COUNTS as $table => $expected) {
            $actual = count(DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS'
                .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ?',
                [$schema, $table]
            ));

            if ($actual < $expected) {
                $missing[] = sprintf('%s has %d CHECK constraint(s), expected %d', $table, $actual, $expected);
            }
        }

        $trigger = DB::select(
            'SELECT 1 FROM information_schema.TRIGGERS'
            .' WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ? AND TRIGGER_NAME = ? LIMIT 1',
            [$schema, 'project_value_revisions', 'trg_pvr_no_delete']
        ) !== [];

        if (! $trigger) {
            $missing[] = 'trigger trg_pvr_no_delete on project_value_revisions';
        }

        if ($missing !== []) {
            foreach ($missing as $item) {
                $this->components->error('Missing: '.$item);
            }

            $this->components->error(sprintf(
                '%d Phase 6 database guarantee(s) are gone. INV-P17: fix the database rather than the code that trusted it.',
                count($missing)
            ));

            return self::FAILURE;
        }

        $this->components->info('All Phase 6 generated columns, CHECKs, unique indexes and the append-only trigger are present.');

        return self::SUCCESS;
    }
}
