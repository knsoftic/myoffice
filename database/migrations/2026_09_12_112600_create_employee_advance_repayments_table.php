<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.22 — employee_advance_repayments: the append-only history of an advance coming back.
 *
 * Created **after** `payroll_run_items` (§2.27 step 17), because a payroll recovery points at the slip
 * that took the money.
 *
 * `amount` is a positive magnitude and `entry_type` carries the direction ([D-HR-4]); the only column
 * anything sums is the generated `signed_amount`. A `credit` gives money back — which is what a correction
 * run does when it finds that a recovery should not have been taken.
 *
 * `uq_aar_item` allows **one payroll recovery per advance per slip**. NULLs are distinct in MariaDB, so
 * manual repayments and waivers are unconstrained by it — a person may pay back an advance twice in a
 * month, but payroll may not take it twice from one slip.
 *
 * **Append-only** (D16, D19): only `notes` may be updated, `deleting` throws and a `BEFORE DELETE` trigger
 * raises underneath it.
 */
return new class extends Migration
{
    private const TABLE = 'employee_advance_repayments';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_advance_id')->constrained('employee_advances')->restrictOnDelete();
            $table->foreignId('payroll_run_item_id')->nullable()->constrained('payroll_run_items')->restrictOnDelete();
            $table->string('entry_type', 8)->default('debit');
            $table->string('recovery_type', 16)->default('payroll');
            // Positive magnitude; signed_amount (generated STORED) is added in step 18.
            $table->decimal('amount', 15, 2);
            $table->date('recovered_on');
            $table->string('reference', 64)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            // No softDeletes(): append-only recovery history (D16, D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.22 Keys. NULLs are distinct, so manual rows are unconstrained by uq_aar_item.
            $table->unique(['employee_advance_id', 'payroll_run_item_id'], 'uq_aar_item');
            $table->index(['employee_advance_id', 'recovered_on'], 'idx_aar_employee_recovere');
            $table->index('payroll_run_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
