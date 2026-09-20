<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.11 — attendance_monthly_summaries: requirement §26's monthly summary, and **the only
 * input payroll reads** (HR-5).
 *
 * Payroll never touches `attendances`. It takes a summary row, which means there is exactly one place
 * attendance is aggregated and exactly one number to check when a slip looks wrong. `PayrollCalculator`
 * takes a summary in its signature, so the rule is enforced by the type system rather than by discipline.
 *
 * `payable_days` is `SUM(payable_factor)` over every row in the period; `lop_days` is
 * `SUM(1 - payable_factor)` over **working** rows only (HR-4) — a weekend is not a loss of pay.
 *
 * `period_start` / `period_end` are stored rather than derived from year and month, so a business on a
 * 26th-to-25th payroll cycle needs no migration later.
 *
 * `AttendanceSummaryService::rebuild()` is idempotent and **refuses a locked period**, naming the run
 * that locked it (HR-18).
 *
 * **No `deleted_at`**: a derived cache is rebuilt, never removed.
 */
return new class extends Migration
{
    private const TABLE = 'attendance_monthly_summaries';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('period_start');
            $table->date('period_end');

            $table->unsignedTinyInteger('calendar_days')->default(0);
            $table->decimal('working_days', 8, 4)->default(0);
            $table->unsignedTinyInteger('weekly_off_days')->default(0);
            $table->unsignedTinyInteger('holiday_days')->default(0);
            $table->decimal('present_days', 8, 4)->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedSmallInteger('early_leave_count')->default(0);
            $table->unsignedSmallInteger('half_day_count')->default(0);
            $table->decimal('absent_days', 8, 4)->default(0);
            $table->decimal('paid_leave_days', 8, 4)->default(0);
            $table->decimal('unpaid_leave_days', 8, 4)->default(0);
            $table->decimal('payable_days', 8, 4)->default(0);
            $table->decimal('lop_days', 8, 4)->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('expected_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('attendance_percentage', 8, 4)->default(0);

            // DATETIME, not TIMESTAMP (D67) — see create_attendance_corrections_table.
            $table->dateTime('generated_at');
            $table->boolean('is_final')->default(false);
            $table->timestamp('locked_at')->nullable();
            // locked_by_payroll_run_id is added in step 16, once payroll_runs exists.

            $table->timestamps();
            // No softDeletes(): a derived cache is rebuilt, never removed (D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.11 Keys.
            $table->unique(['employee_id', 'period_year', 'period_month'], 'uq_ams_period');
            $table->index(['period_year', 'period_month']);
            $table->index(['branch_id', 'period_year', 'period_month'], 'idx_ams_branch_periodye_periodmo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
