<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.15 — leave_requests: requirement §27's application.
 *
 * `total_days` is the **counted** days after weekends and holidays are excluded per the type, and
 * `paid_days + unpaid_days = total_days` is a CHECK — a quota overrun approved under
 * `allow_negative_balance` lands in `unpaid_days` rather than quietly becoming free leave.
 *
 * `balance_snapshot_days` records what the balance was when the request was filed. Months later, when
 * somebody argues about a number, that snapshot is the difference between an answer and a guess.
 *
 * `attachment_path` is on the **private** disk. [D-HR-6] keeps it a single column rather than a table:
 * §27 says "attachment", singular, and when Phase 22 ships the polymorphic file store the column moves
 * there — a table now would be dead weight carrying one row.
 *
 * Created **before** `attendances` (§2.27 step 9), because `attendances.leave_request_id` points here.
 */
return new class extends Migration
{
    private const TABLE = 'leave_requests';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('request_number', 32);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->date('from_date');
            $table->date('to_date');
            $table->string('day_portion', 16)->default('full_day');
            $table->decimal('total_days', 8, 4);
            $table->decimal('paid_days', 8, 4)->default(0);
            $table->decimal('unpaid_days', 8, 4)->default(0);

            $table->text('reason');
            $table->string('attachment_path', 255)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('contact_during_leave', 64)->nullable();

            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('current_approval_level')->default(1);
            $table->decimal('balance_snapshot_days', 8, 4)->nullable();
            $table->date('applied_on');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamp('attendance_applied_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.15 Keys.
            $table->unique('request_number', 'uq_lr_number');
            $table->index(['employee_id', 'status']);
            $table->index(['status', 'from_date']);
            $table->index(['leave_type_id', 'from_date']);
            $table->index(['from_date', 'to_date']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
