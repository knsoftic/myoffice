<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.9 — attendances: one row per employee per calendar date, weekends and holidays included
 * (requirement §26, HR-1).
 *
 * **The row snapshots its shift** (HR-2): `expected_in_at`, `expected_out_at`, `expected_minutes` and
 * both grace values are written once, at creation. Editing or deleting a `work_shift` afterwards can
 * therefore never change a past day's late minutes, early-leave minutes or hours — which is the whole
 * reason the snapshot exists rather than a join.
 *
 * **`payable_factor` is the only number payroll cares about** (HR-4). It is in `[0, 1]` by CHECK, and on
 * a working day `lop_days = 1 - payable_factor`. Every attendance state, every half day, every unpaid
 * holiday resolves into that one figure, so the bridge between attendance and pay is a single column
 * rather than a rule scattered across a calculator.
 *
 * Minutes are **integers** (HR-3), never floats and never money: they are derived by
 * `AttendanceService::resolve()` from the snapshot and from nothing else.
 *
 * `check_in_at` is the **first** punch of the day and is never overwritten; `check_out_at` is the last and
 * only ever moves forward. `is_manual` marks a row a correction has touched, after which `resolve()`
 * leaves it alone — otherwise the nightly pass would undo somebody's decision every night.
 *
 * `locked_by_payroll_run_id` is added in §2.27 step 16, once `payroll_runs` exists.
 */
return new class extends Migration
{
    private const TABLE = 'attendances';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            // Attendance is payroll evidence: an employee row cannot be deleted out from under it.
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('attendance_date');
            $table->foreignId('work_shift_id')->nullable()->constrained('work_shifts')->nullOnDelete();
            $table->string('day_type', 16)->default('working');
            $table->foreignId('holiday_id')->nullable()->constrained('holidays')->nullOnDelete();
            $table->string('status', 32)->default('absent');

            // The snapshot (HR-2) — written once, never re-read from the shift.
            $table->dateTime('expected_in_at')->nullable();
            $table->dateTime('expected_out_at')->nullable();
            $table->unsignedSmallInteger('expected_minutes')->default(0);
            $table->unsignedSmallInteger('grace_in_minutes')->default(0);
            $table->unsignedSmallInteger('grace_out_minutes')->default(0);
            $table->unsignedSmallInteger('break_minutes')->default(0);

            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->string('check_in_source', 16)->nullable();
            $table->string('check_out_source', 16)->nullable();
            $table->string('check_in_ip', 45)->nullable();
            $table->string('check_out_ip', 45)->nullable();

            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('payable_factor', 8, 4)->default(1);

            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->nullOnDelete();
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->nullOnDelete();

            $table->boolean('is_manual')->default(false);
            $table->boolean('requires_correction')->default(false);
            $table->string('remarks', 255)->nullable();
            $table->timestamp('locked_at')->nullable();
            // locked_by_payroll_run_id is added in step 16, once payroll_runs exists.
            // day_guard (generated STORED) and uq_att_day are added in step 18.

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.9 Keys.
            $table->index(['employee_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
            $table->index(['branch_id', 'attendance_date']);
            $table->index(['status', 'attendance_date']);
            $table->index('leave_request_id');
            $table->index(['requires_correction', 'attendance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
