<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.27 step 18 — every guarantee Laravel's schema builder cannot express, in one place.
 *
 * Nine STORED generated columns, the seven UNIQUE guards they carry, the CHECK constraints of §2, and the
 * five `BEFORE DELETE` triggers that defend the append-only tables. All raw SQL, and **every one of them
 * fails loudly** if the server rejects it (HR-12, HR-16, the spine's R-3): a guarantee that silently did
 * not get created is worse than none, because everything above it carries on assuming it holds.
 *
 * **[D-HR-5] Why guards rather than plain uniques.** MariaDB treats NULL as distinct in a unique index, so
 * every "only one of these at a time" rule is expressed as a column that is NULL when the rule does not
 * apply. One default shift per branch, one active holiday per branch per date, one attendance row per
 * employee per day, one counted leave day per employee per date, one open salary version per employee,
 * one live regular payroll run per branch per month — each is a unique index over a generated column, so
 * the database decides rather than a service that could be bypassed.
 *
 * **[D-HR-4] Why signed columns are generated.** `days` and `amount` are positive magnitudes and the
 * direction lives in `entry_type`. The only column anything sums is `signed_days` / `signed_amount`, so
 * "how much was credited" is a SUM rather than a query with a sign filter — and no code path can write a
 * signed value that disagrees with its own entry type.
 *
 * **D68 — `CAST(date AS CHAR)` rather than `DATE_FORMAT()` in the three date guards.** §2.8, §2.9 and
 * §2.16 spell the guards with `DATE_FORMAT(col, '%Y-%m-%d')`, which this server refuses outright inside a
 * generated column: MariaDB classes it as locale-dependent and answers
 * `1901 Function or expression 'date_format()' cannot be used in the GENERATED ALWAYS AS clause`. Casting
 * a DATE to CHAR is deterministic, is accepted, and produces the identical `YYYY-MM-DD` string — verified
 * on this server before the change was made, both forms side by side.
 *
 * The delete triggers on `payroll_run_items` and `payroll_run_item_components` check the **parent run's
 * status**: a draft run's items may be hard-deleted, which is what "regenerate" means, and after the run
 * locks nothing is deleted at all (HR-16).
 */
return new class extends Migration
{
    /**
     * name => [table, column definition, expression, position].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const GENERATED = [
        // One title per department, reusable after a soft delete.
        'designations.department_guard' => [
            'designations',
            'VARCHAR(200)',
            "CASE WHEN `deleted_at` IS NULL THEN CONCAT(COALESCE(`department_id`, 0), ':', `title`) ELSE NULL END",
            'sort_order',
        ],
        // One default shift per branch.
        'work_shifts.default_guard' => [
            'work_shifts',
            'VARCHAR(16)',
            "CASE WHEN `is_default` = 1 AND `deleted_at` IS NULL THEN CAST(COALESCE(`branch_id`, 0) AS CHAR) ELSE NULL END",
            'sort_order',
        ],
        // One active holiday per branch per date.
        'holidays.holiday_guard' => [
            'holidays',
            'VARCHAR(40)',
            "CASE WHEN `is_active` = 1 AND `deleted_at` IS NULL"
                ." THEN CONCAT(COALESCE(`branch_id`, 0), ':', CAST(`holiday_date` AS CHAR)) ELSE NULL END",
            'is_active',
        ],
        // HR-1: exactly one attendance row per employee per calendar date.
        'attendances.day_guard' => [
            'attendances',
            'VARCHAR(40)',
            "CASE WHEN `deleted_at` IS NULL"
                ." THEN CONCAT(`employee_id`, ':', CAST(`attendance_date` AS CHAR)) ELSE NULL END",
            'locked_at',
        ],
        // HR-8: at most one counted leave day per employee per date.
        'leave_request_days.day_guard' => [
            'leave_request_days',
            'VARCHAR(40)',
            "CASE WHEN `is_active` = 1 AND `is_counted` = 1"
                ." THEN CONCAT(`employee_id`, ':', CAST(`leave_date` AS CHAR)) ELSE NULL END",
            'attendance_id',
        ],
        // [D-HR-4]: the only column anything sums.
        'leave_balance_transactions.signed_days' => [
            'leave_balance_transactions',
            'DECIMAL(8,4)',
            "CASE WHEN `entry_type` = 'credit' THEN `days` ELSE (0 - `days`) END",
            'days',
        ],
        // HR-10: at most one open salary version per employee.
        'salary_structures.open_guard' => [
            'salary_structures',
            'VARCHAR(24)',
            "CASE WHEN `effective_to` IS NULL AND `status` IN ('scheduled', 'active')"
                .' THEN CAST(`employee_id` AS CHAR) ELSE NULL END',
            'approved_at',
        ],
        // One live regular run per branch per month.
        'payroll_runs.regular_guard' => [
            'payroll_runs',
            'VARCHAR(40)',
            "CASE WHEN `run_type` = 'regular' AND `status` <> 'cancelled'"
                ." THEN CONCAT(COALESCE(`branch_id`, 0), ':', `period_year`, ':', `period_month`) ELSE NULL END",
            'notes',
        ],
        // [D-HR-4].
        'employee_advance_repayments.signed_amount' => [
            'employee_advance_repayments',
            'DECIMAL(15,2)',
            "CASE WHEN `entry_type` = 'debit' THEN `amount` ELSE (0 - `amount`) END",
            'amount',
        ],
    ];

    /**
     * index name => [table, columns].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const GUARD_INDEXES = [
        'uq_desig_guard' => ['designations', ['department_guard']],
        'uq_ws_default' => ['work_shifts', ['default_guard']],
        'uq_hol_guard' => ['holidays', ['holiday_guard']],
        'uq_att_day' => ['attendances', ['day_guard']],
        'uq_lrd_day' => ['leave_request_days', ['day_guard']],
        'uq_ss_open' => ['salary_structures', ['open_guard']],
        'uq_pr_regular' => ['payroll_runs', ['regular_guard']],
    ];

    /**
     * table => [name => expression].
     *
     * @var array<string, array<string, string>>
     */
    private const CHECKS = [
        'employees' => [
            'chk_emp_salary_nonneg' => '`current_gross_salary` >= 0',
            'chk_emp_exit' => '`exit_date` is null or `exit_date` >= `joining_date`',
        ],
        'work_shifts' => [
            'chk_ws_minutes' => '`break_minutes` >= 0 and `grace_in_minutes` >= 0'
                .' and `grace_out_minutes` >= 0 and `expected_minutes` > 0',
            'chk_ws_day_thresholds' => '`min_half_day_minutes` <= `min_full_day_minutes`',
        ],
        'employee_documents' => [
            'chk_empdoc_dates' => '`expires_on` is null or `issued_on` is null or `expires_on` >= `issued_on`',
        ],
        'attendances' => [
            'chk_att_times' => '`check_out_at` is null or `check_in_at` is null or `check_out_at` >= `check_in_at`',
            // HR-4: the single bridge between attendance and pay.
            'chk_att_factor' => '`payable_factor` >= 0 and `payable_factor` <= 1',
            'chk_att_minutes' => '`worked_minutes` >= 0 and `late_minutes` >= 0'
                .' and `early_leave_minutes` >= 0 and `overtime_minutes` >= 0',
        ],
        'attendance_monthly_summaries' => [
            'chk_ams_month' => '`period_month` between 1 and 12',
            'chk_ams_nonneg' => '`working_days` >= 0 and `present_days` >= 0 and `absent_days` >= 0'
                .' and `paid_leave_days` >= 0 and `unpaid_leave_days` >= 0 and `payable_days` >= 0'
                .' and `lop_days` >= 0 and `attendance_percentage` >= 0',
        ],
        'leave_types' => [
            'chk_lt_nonneg' => '`annual_quota_days` >= 0 and `accrual_days_per_month` >= 0'
                .' and `max_carry_forward_days` >= 0',
            'chk_lt_levels' => '`approval_levels` between 1 and 2',
        ],
        'leave_balances' => [
            // adjusted_days and available_days may be negative; nothing else may.
            'chk_lb_nonneg' => '`entitled_days` >= 0 and `carried_forward_days` >= 0 and `accrued_days` >= 0'
                .' and `consumed_days` >= 0 and `pending_days` >= 0 and `encashed_days` >= 0'
                .' and `expired_days` >= 0',
        ],
        'leave_balance_transactions' => [
            'chk_lbt_days' => '`days` > 0',
        ],
        'leave_requests' => [
            'chk_lr_dates' => '`to_date` >= `from_date`',
            'chk_lr_days' => '`total_days` > 0 and `paid_days` >= 0 and `unpaid_days` >= 0'
                .' and `paid_days` + `unpaid_days` = `total_days`',
        ],
        'salary_components' => [
            'chk_sc_defaults' => '`default_amount` >= 0 and `default_rate` >= 0 and `default_rate` <= 100',
        ],
        'salary_structures' => [
            'chk_ss_money' => '`basic_salary` >= 0 and `gross_salary` >= `basic_salary`'
                .' and `total_deduction_amount` >= 0',
            'chk_ss_dates' => '`effective_to` is null or `effective_to` >= `effective_from`',
        ],
        'salary_structure_components' => [
            'chk_ssc_money' => '`amount` >= 0 and `rate` >= 0 and `rate` <= 100',
        ],
        'employee_advances' => [
            'chk_adv_amount' => '`amount` > 0 and `installment_count` > 0',
            // HR-19: an advance can never be recovered for more than it was worth.
            'chk_adv_ceiling' => '`recovered_amount` >= 0 and `waived_amount` >= 0'
                .' and `recovered_amount` + `waived_amount` <= `amount`',
        ],
        'employee_advance_repayments' => [
            'chk_aar_amount' => '`amount` > 0',
        ],
        'payroll_runs' => [
            'chk_pr_month' => '`period_month` between 1 and 12',
            // total_net may be negative on a correction run.
            'chk_pr_totals' => '`total_gross` >= 0 and `total_deductions` >= 0 and `total_paid` >= 0',
        ],
        'payroll_run_items' => [
            // HR-14 + HR-17: a negative slip is legal only on a correction run.
            'chk_pri_sign' => "`run_type` = 'correction'"
                .' or (`gross_earnings` >= 0 and `total_deductions` >= 0 and `net_salary` >= 0)',
            'chk_pri_days' => '`payable_days` >= 0 and `lop_days` >= 0 and `working_days` >= 0',
        ],
        'payroll_run_item_components' => [
            'chk_pric_sign' => "`run_type` = 'correction' or `amount` >= 0",
            'chk_pric_rate' => '`rate` >= 0 and `rate` <= 100',
        ],
    ];

    /**
     * trigger name => [table, message, extra condition wrapping the SIGNAL].
     *
     * @var array<string, array{0: string, 1: string, 2: ?string}>
     */
    private const TRIGGERS = [
        'trg_ac_no_delete' => [
            'attendance_corrections',
            'attendance_corrections is append-only (phase-07 HR-6): it is the evidence of why an attendance figure changed.',
            null,
        ],
        'trg_lbt_no_delete' => [
            'leave_balance_transactions',
            'leave_balance_transactions is append-only (phase-07 HR-7): correct a balance with an opposite entry.',
            null,
        ],
        'trg_aar_no_delete' => [
            'employee_advance_repayments',
            'employee_advance_repayments is append-only (phase-07 HR-19): reverse a recovery with a credit entry.',
            null,
        ],
        // HR-16: a draft run's items may be regenerated; after lock nothing is deleted.
        'trg_pri_no_delete' => [
            'payroll_run_items',
            'A payroll item can only be removed while its run is still a draft (phase-07 HR-16): after lock, correct it with a correction run.',
            "IF (SELECT `status` FROM `payroll_runs` WHERE `id` = OLD.`payroll_run_id`) NOT IN ('draft', 'generated') THEN",
        ],
        'trg_pric_no_delete' => [
            'payroll_run_item_components',
            'A payroll component can only be removed while its run is still a draft (phase-07 HR-16).',
            "IF (SELECT r.`status` FROM `payroll_runs` r"
                .' JOIN `payroll_run_items` i ON i.`payroll_run_id` = r.`id`'
                ." WHERE i.`id` = OLD.`payroll_run_item_id`) NOT IN ('draft', 'generated') THEN",
        ],
    ];

    public function up(): void
    {
        foreach (self::GENERATED as $key => [$table, $definition, $expression, $after]) {
            $this->addStoredColumn($table, explode('.', $key)[1], $definition, $expression, $after);
        }

        foreach (self::GUARD_INDEXES as $name => [$table, $columns]) {
            $this->addUnique($table, $name, $columns);
        }

        foreach (self::CHECKS as $table => $checks) {
            foreach ($checks as $name => $expression) {
                $this->addCheck($table, $name, $expression);
            }
        }

        foreach (self::TRIGGERS as $name => [$table, $message, $condition]) {
            $this->addNoDeleteTrigger($name, $table, $message, $condition);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TRIGGERS) as $name) {
            DB::unprepared(sprintf('DROP TRIGGER IF EXISTS `%s`', $name));
        }

        foreach (self::CHECKS as $table => $checks) {
            foreach (array_keys($checks) as $name) {
                if ($this->hasCheck($table, $name)) {
                    DB::statement(sprintf('ALTER TABLE `%s` DROP CONSTRAINT `%s`', $table, $name));
                }
            }
        }

        foreach (self::GUARD_INDEXES as $name => [$table, $columns]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $name));
            }
        }

        foreach (self::GENERATED as $key => [$table, $definition, $expression, $after]) {
            $column = explode('.', $key)[1];

            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
            }
        }
    }

    /**
     * Add one STORED generated column, then prove the server really made it STORED.
     *
     * A VIRTUAL column here would still compute, but it could not carry a unique index — so the guard
     * would silently stop guarding. That is why this throws rather than returning quietly (HR-12).
     */
    private function addStoredColumn(string $table, string $column, string $definition, string $expression, string $after): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException(sprintf('phase-07 §2.27: table `%s` is missing; the migration order is wrong.', $table));
        }

        if (! Schema::hasColumn($table, $column)) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` %s AS (%s) STORED AFTER `%s`',
                $table,
                $column,
                $definition,
                $expression,
                $after
            ));
        }

        $row = DB::selectOne(
            'SELECT IS_GENERATED AS is_generated, EXTRA AS extra FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), $table, $column]
        );

        $extra = strtoupper((string) ($row->extra ?? ''));

        if ($row === null
            || strtoupper((string) $row->is_generated) !== 'ALWAYS'
            || (! str_contains($extra, 'STORED') && ! str_contains($extra, 'PERSISTENT'))) {
            throw new RuntimeException(sprintf(
                'phase-07 §2.27 step 18: `%s`.`%s` must be a STORED generated column — a VIRTUAL one cannot '
                .'carry the unique index that makes the guarantee real. Fix the database server rather than '
                .'skipping the guard.',
                $table,
                $column
            ));
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addUnique(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE INDEX `%s` (%s)',
            $table,
            $name,
            implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', $columns))
        ));

        if (! Schema::hasIndex($table, $name)) {
            throw new RuntimeException(sprintf(
                'phase-07 §2.27 step 18: unique index `%s` on `%s` was not created ([D-HR-5]).',
                $name,
                $table
            ));
        }
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (! Schema::hasTable($table) || $this->hasCheck($table, $name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $name, $expression));

        if (! $this->hasCheck($table, $name)) {
            throw new RuntimeException(sprintf(
                'phase-07 §2.27 step 18: CHECK `%s` on `%s` was not created. The server accepted the statement '
                .'but did not keep the constraint; fix the database rather than the code that trusts it.',
                $name,
                $table
            ));
        }
    }

    private function addNoDeleteTrigger(string $name, string $table, string $message, ?string $condition): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if ($this->hasTrigger($name, $table)) {
            return;
        }

        $signal = sprintf("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s';", addslashes($message));

        $body = $condition === null
            ? $signal
            : sprintf("%s\n    %s\n  END IF;", $condition, $signal);

        DB::unprepared(sprintf(
            "CREATE TRIGGER `%s` BEFORE DELETE ON `%s` FOR EACH ROW\nBEGIN\n  %s\nEND",
            $name,
            $table,
            $body
        ));

        if (! $this->hasTrigger($name, $table)) {
            throw new RuntimeException(sprintf(
                'phase-07 §2.27 step 18: trigger `%s` on `%s` was not created; the append-only guarantee '
                .'rests on it (HR-16).',
                $name,
                $table
            ));
        }
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $name]
        ) !== [];
    }

    private function hasTrigger(string $name, string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TRIGGERS'
            .' WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ? AND TRIGGER_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $name]
        ) !== [];
    }
};
