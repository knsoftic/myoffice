<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.17 — leave_approvals: one row per level of the approval chain.
 *
 * The chain is **derived, not configured** ([D-HR-6]): `leave_types.approval_levels` decides its depth,
 * `employees.reports_to_id` fills level 1 and holders of `leaves.approve` fill level 2. A flow builder is
 * scope §27 does not ask for.
 *
 * `expected_approver_user_id` may be null, in which case `fallback_permission` means "any holder of this
 * permission" — which is what keeps the chain working when somebody has no reporting manager.
 *
 * **An employee may never approve their own leave**, whatever permissions they hold. When the resolved
 * approver is the requester the level falls through to the next named approver or to the fallback.
 *
 * **No `deleted_at`** (D19): the chain is the record of who decided what.
 */
return new class extends Migration
{
    private const TABLE = 'leave_approvals';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            // The post that owes the decision (an employee), and the login that may take it (a user).
            $table->foreignId('expected_approver_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('expected_approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fallback_permission', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->string('comment', 255)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            // No softDeletes(): the approval chain is the record of who decided what (D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.17 Keys.
            $table->unique(['leave_request_id', 'level'], 'uq_la_level');
            $table->index(['status', 'expected_approver_user_id']);
            $table->index(['expected_approver_employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
