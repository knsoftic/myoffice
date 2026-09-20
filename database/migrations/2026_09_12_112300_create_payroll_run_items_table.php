<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.24 — payroll_run_items: the salary slip itself (requirement §28).
 *
 * **Every figure a slip prints is stored here, and the totals are the SUM of the item's component rows**
 * (HR-13) — never a recomputation at render time. `PayrollRunService::lock()` asserts the identity before
 * it locks, so a slip that does not add up can never become immutable.
 *
 * `run_type` is **denormalised from the run** so `chk_pri_sign` can exist at all: a negative amount is
 * legal only on a correction run (HR-17), and a CHECK cannot reach across a foreign key to find out.
 *
 * The department, designation, employment type, joining date, basic salary and every day count are
 * **snapshots**: a slip must print what was true then, whatever has changed since.
 *
 * `attendance_monthly_summary_id` is the only attendance input (HR-5) — payroll never reads `attendances`.
 *
 * `calculation_snapshot` carries every input and intermediate of §6.6, which is what makes a three-year-old
 * slip explainable rather than merely present.
 *
 * **No `deleted_at`** (HR-16): after lock nothing is deleted, not even softly. A draft run's items may be
 * hard-deleted, which is what "regenerate" means.
 */
return new class extends Migration
{
    private const TABLE = 'payroll_run_items';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->restrictOnDelete();
            // Denormalised so chk_pri_sign can exist — a CHECK cannot follow a foreign key (HR-17).
            $table->string('run_type', 24);
            $table->string('slip_number', 32);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('salary_structure_id')->nullable()->constrained('salary_structures')->restrictOnDelete();
            // The only attendance input (HR-5).
            $table->foreignId('attendance_monthly_summary_id')->nullable()
                ->constrained('attendance_monthly_summaries')->restrictOnDelete();
            $table->foreignId('corrects_item_id')->nullable()->constrained('payroll_run_items')->restrictOnDelete();

            // Snapshots — the slip prints what was true then.
            $table->string('department_name', 150)->nullable();
            $table->string('designation_title', 150)->nullable();
            $table->string('employment_type', 32)->nullable();
            $table->date('joining_date')->nullable();
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->decimal('contracted_gross', 15, 2)->default(0);

            // Totals — the SUM of this item's component rows (HR-13).
            $table->decimal('gross_earnings', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('taxable_gross', 15, 2)->default(0);

            // Reporting handles, each the sum of one component group.
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('allowance_amount', 15, 2)->default(0);
            $table->decimal('bonus_amount', 15, 2)->default(0);
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->decimal('overtime_amount', 15, 2)->default(0);
            $table->decimal('advance_recovery_amount', 15, 2)->default(0);
            $table->decimal('unpaid_leave_deduction', 15, 2)->default(0);
            $table->decimal('late_deduction', 15, 2)->default(0);
            $table->decimal('net_salary', 15, 2)->default(0);

            // Attendance snapshots.
            $table->decimal('payable_days', 8, 4)->default(0);
            $table->decimal('lop_days', 8, 4)->default(0);
            $table->decimal('working_days', 8, 4)->default(0);
            $table->decimal('present_days', 8, 4)->default(0);
            $table->decimal('paid_leave_days', 8, 4)->default(0);
            $table->decimal('unpaid_leave_days', 8, 4)->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->decimal('day_divisor', 8, 4)->default(0);
            $table->decimal('per_day_amount', 15, 2)->default(0);
            $table->json('calculation_snapshot')->nullable();

            $table->string('status', 16)->default('draft');
            $table->string('hold_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 24)->nullable();
            $table->string('payment_reference', 64)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            // The only field editable after payment.
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            // No softDeletes(): after lock nothing is deleted, not even softly (HR-16).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.24 Keys.
            $table->unique('slip_number', 'uq_pri_slip');
            $table->unique(['payroll_run_id', 'employee_id'], 'uq_pri_employee');
            $table->index(['employee_id', 'created_at']);
            $table->index(['payroll_run_id', 'status']);
            $table->index(['status', 'paid_at']);
            $table->index('corrects_item_id');
            $table->index('salary_structure_id');
            $table->index('attendance_monthly_summary_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
