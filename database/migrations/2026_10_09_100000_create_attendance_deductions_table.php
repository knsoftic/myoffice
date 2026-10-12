<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance-based salary deductions — one row per employee, rule and attendance date.
 *
 * Additive only: no existing table is touched. `uq_attd_day` is the whole duplicate guard — a
 * recalculation, a second job or a double-clicked button can only ever find this row again, never
 * write a second one. A rule that stops applying moves the row to `reversed`; nothing is deleted
 * (D16/D19 — money promised keeps its record), so there is no `deleted_at`.
 */
return new class extends Migration
{
    private const TABLE = 'attendance_deductions';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();

            $table->string('deduction_type', 32);
            $table->date('deduction_date');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            // The figures, as they stood when the row was last (re)calculated.
            $table->decimal('daily_salary', 15, 2)->default(0);
            $table->decimal('rate_percentage', 8, 4)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 8)->nullable();
            // Excess absence only: this was the Nth chargeable absence of the month, with A allowed.
            $table->unsignedTinyInteger('absence_sequence')->nullable();
            $table->unsignedTinyInteger('allowance_days')->nullable();
            $table->string('reason', 255)->nullable();
            $table->json('calculation')->nullable();

            $table->string('status', 16)->default('pending');

            // Set when a locked payroll slip took the deduction.
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->foreignId('payroll_run_item_id')->nullable()->constrained('payroll_run_items')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();

            // Set when it stopped applying; for an applied row, the correction slip that refunded it.
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 255)->nullable();
            $table->foreignId('correction_item_id')->nullable()->constrained('payroll_run_items')->nullOnDelete();

            // What the employee was last told, so the same state is never notified twice.
            $table->string('notified_state', 64)->nullable();
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['employee_id', 'deduction_type', 'deduction_date'], 'uq_attd_day');
            $table->index(['employee_id', 'period_year', 'period_month', 'status'], 'ix_attd_employee_period');
            $table->index(['status', 'period_year', 'period_month'], 'ix_attd_status_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
