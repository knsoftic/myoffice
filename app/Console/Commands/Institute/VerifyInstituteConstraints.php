<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `institute:verify-constraints` — every unique index, CHECK and generated column of §2 still exists
 * (§10.4).
 *
 * Daily at 03:00, in the spine's discipline. **MariaDB DDL is not transactional**, so a migration
 * that failed halfway can leave a table created and a constraint missing (D70); a restore from an
 * older dump, or somebody working around a problem by hand, can do the same. Every guard this phase
 * relies on is therefore checked against `information_schema` rather than assumed, and a missing one
 * is a loud failure rather than a quiet gap that only shows up as a duplicate register months later.
 */
#[AsCommand(name: 'institute:verify-constraints')]
final class VerifyInstituteConstraints extends Command
{
    protected $signature = 'institute:verify-constraints';

    protected $description = 'Assert every institute unique index, CHECK and generated column still exists';

    /** The guards without which an invariant of phases 14–17 silently stops holding. */
    private const UNIQUE_INDEXES = [
        // phase-16
        'student_batch_enrollments' => ['uq_sbe_active', 'uq_sbe_transfer_to', 'uq_sbe_roll'],
        'timetable_entries' => ['uq_tte_batch', 'uq_tte_teacher', 'uq_tte_room'],
        'class_sessions' => ['uq_cs_generated', 'uq_cs_batch_slot', 'uq_cs_teacher_slot', 'uq_cs_room_slot', 'uq_cs_resched'],
        'teachers' => ['uq_te_code', 'uq_te_employee'],
        'classrooms' => ['uq_cr_code'],
        'batches' => ['uq_ba_code'],
        // phase-17
        'student_attendances' => ['uq_sa_session_student'],
        'batch_topic_coverage' => ['uq_btc'],
        'student_course_progress' => ['uq_scp_enrollment'],
        'student_module_progress' => ['uq_smp'],
        'student_topic_progress' => ['uq_stp'],
    ];

    /** table => column, each a STORED generated column a unique index is guarded on. */
    private const GENERATED = [
        'student_batch_enrollments' => ['current_guard'],
        'timetable_entries' => ['active_guard', 'room_guard'],
        'class_sessions' => ['active_guard', 'room_guard'],
    ];

    private const CHECKS = [
        'student_attendances' => ['chk_sa_status', 'chk_sa_via', 'chk_sa_amendment'],
        'batch_topic_coverage' => ['chk_btc_pct', 'chk_btc_status'],
        'student_course_progress' => ['chk_scp_pct', 'chk_scp_status', 'chk_scp_counts'],
        'student_module_progress' => ['chk_smp_pct', 'chk_smp_status', 'chk_smp_counts'],
        'student_topic_progress' => ['chk_stp_pct', 'chk_stp_status', 'chk_stp_source', 'chk_stp_manual_marked'],
    ];

    public function handle(): int
    {
        $schema = DB::getDatabaseName();
        $missing = [];
        $checked = 0;

        foreach (self::UNIQUE_INDEXES as $table => $names) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $missing[] = sprintf('table %s does not exist', $table);

                continue;
            }

            $present = [];

            foreach (DB::select('SHOW INDEX FROM `'.$table.'`') as $row) {
                if (((int) $row->Non_unique) === 0) {
                    $present[$row->Key_name] = true;
                }
            }

            foreach ($names as $name) {
                $checked++;

                if (! isset($present[$name])) {
                    $missing[] = sprintf('unique index %s.%s', $table, $name);
                }
            }
        }

        foreach (self::GENERATED as $table => $columns) {
            foreach ($columns as $column) {
                $checked++;

                $generated = DB::table('information_schema.COLUMNS')
                    ->where('TABLE_SCHEMA', $schema)
                    ->where('TABLE_NAME', $table)
                    ->where('COLUMN_NAME', $column)
                    ->where('EXTRA', 'like', '%GENERATED%')
                    ->exists();

                if (! $generated) {
                    $missing[] = sprintf('generated column %s.%s', $table, $column);
                }
            }
        }

        foreach (self::CHECKS as $table => $names) {
            $present = DB::table('information_schema.CHECK_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $schema)
                ->where('TABLE_NAME', $table)
                ->pluck('CONSTRAINT_NAME')
                ->all();

            foreach ($names as $name) {
                $checked++;

                if (! in_array($name, $present, true)) {
                    $missing[] = sprintf('CHECK %s.%s', $table, $name);
                }
            }
        }

        $this->info(sprintf('%d guard%s checked on %s.', $checked, $checked === 1 ? '' : 's', $schema));

        if ($missing === []) {
            $this->info('Every one is present.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d guard%s MISSING:', count($missing), count($missing) === 1 ? ' is' : 's are'));

        foreach ($missing as $line) {
            $this->line('  '.$line);
        }

        $this->newLine();
        $this->line('Each of these is what makes an invariant true in the data rather than only in the');
        $this->line('code. Re-run the migrations for the owning phase; they are idempotent by design.');

        return self::FAILURE;
    }
}
