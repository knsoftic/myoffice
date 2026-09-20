<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.4 — employees: requirement §24's staff record.
 *
 * **`user_id` is nullable and UNIQUE** (D2, [D-HR-2]). An employee record exists without a login — a
 * labourer, an ex-employee whose account was closed — and a login maps to at most one employee. That
 * nullable-unique pair is the bridge behind **D32**: an organisational **duty** (department head,
 * reporting line, leave approver) points at `employees.id`, while **who performed an act or is assigned
 * work** points at `users.id`. Phases 6, 13 and 14-17 are bound by the same rule.
 *
 * `department_id` is `restrictOnDelete`: a department with staff cannot be deleted out from under them,
 * and the policy offers "deactivate instead" with the count.
 *
 * `is_attendance_exempt` is a pay decision and is documented as one on the employee screen — an exempt
 * employee is never marked absent or late and always has `payable_factor = 1.0000`. Silently exempting
 * somebody would be a raise nobody approved.
 *
 * `current_gross_salary` is a **cache** of the active structure's gross (HR-11), written only by
 * `SalaryStructureService` and shown only with `employees.view_financial`. **No payroll figure is ever
 * read from it** — payroll reads the structure version it actually used.
 *
 * `weekly_off_days` overrides the shift and `hr.weekend_days` for one person, which is how a business
 * gives a single employee a different weekend without inventing a shift for them.
 */
return new class extends Migration
{
    private const TABLE = 'employees';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('employee_code', 32);

            // Nullable + UNIQUE: an employee may have no login, and a login belongs to one employee (D2).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            // The reporting line the leave chain and the team scope both read — a self FK.
            $table->foreignId('reports_to_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('work_shift_id')->nullable()->constrained('work_shifts')->nullOnDelete();

            $table->string('name', 150);
            $table->string('photo_path', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();

            $table->date('joining_date');
            $table->string('employment_type', 32)->default('full_time');
            $table->string('status', 32)->default('active');
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->date('exit_date')->nullable();
            $table->string('exit_reason', 255)->nullable();

            $table->json('weekly_off_days')->nullable();
            $table->boolean('is_attendance_exempt')->default(false);
            $table->decimal('current_gross_salary', 15, 2)->default(0);

            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_relation', 64)->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->string('emergency_contact_alt_phone', 32)->nullable();
            $table->string('emergency_contact_address', 255)->nullable();

            $table->text('bio')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.4 Keys. NULLs are distinct in MariaDB, so the two nullable uniques still allow many
            // employees with no login and no email.
            $table->unique('employee_code', 'uq_emp_code');
            $table->unique('user_id', 'uq_emp_user');
            $table->unique('email', 'uq_emp_email');
            $table->index(['department_id', 'status']);
            $table->index('designation_id');
            $table->index(['status', 'employment_type']);
            $table->index('reports_to_id');
            $table->index(['branch_id', 'status']);
            $table->index('joining_date');
            $table->index('work_shift_id');
            $table->index('name');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
