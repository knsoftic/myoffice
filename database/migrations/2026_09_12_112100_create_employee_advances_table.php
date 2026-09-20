<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.21 — employee_advances: requirement §28's salary advance.
 *
 * **`recovered_amount + waived_amount <= amount` is a CHECK** (HR-19): an advance can never be recovered
 * for more than it was worth, whatever a calculation does. The three amount columns are caches over the
 * append-only repayment rows, recomputed rather than incremented.
 *
 * Waiving is a decision somebody recorded, not a disappearance — it counts against the same ceiling as a
 * real recovery and needs a note.
 *
 * `first_recovery_year` / `_month` say which payroll period recovery starts in, so an advance taken on
 * the 28th is not recovered from the salary being run that same week unless somebody meant it to be.
 *
 * **No `deleted_at`** (D16, D19): cancellation and write-off are statuses.
 */
return new class extends Migration
{
    private const TABLE = 'employee_advances';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('advance_number', 32);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            $table->date('requested_on');
            $table->unsignedTinyInteger('installment_count')->default(1);
            $table->decimal('installment_amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('first_recovery_year')->nullable();
            $table->unsignedTinyInteger('first_recovery_month')->nullable();

            // Caches over the append-only repayment rows — recomputed, never incremented.
            $table->decimal('recovered_amount', 15, 2)->default(0);
            $table->decimal('waived_amount', 15, 2)->default(0);
            $table->decimal('outstanding_amount', 15, 2)->default(0);

            $table->string('status', 16)->default('requested');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->date('disbursed_on')->nullable();
            $table->string('disbursement_method', 24)->nullable();
            $table->string('disbursement_reference', 64)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            // No softDeletes(): cancellation and write-off are statuses (D16, D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.21 Keys.
            $table->unique('advance_number', 'uq_adv_number');
            $table->index(['employee_id', 'status']);
            $table->index(['status', 'requested_on']);
            $table->index(['first_recovery_year', 'first_recovery_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
