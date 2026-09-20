<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.14 — leave_balance_transactions: the append-only day ledger every balance is derived from
 * (HR-7).
 *
 * `days` is a **positive magnitude** and `entry_type` carries the direction ([D-HR-4]); the only column
 * anything sums is the generated `signed_days`. Storing a signed number directly would make "how many
 * days were credited this year" a query with a sign filter instead of a SUM.
 *
 * `occurred_on` is the **value date**, never `now()`: a grant back-dated to the start of the leave year
 * has to sum into that year even if somebody entered it in March.
 *
 * `balance_after_days` snapshots the available balance after the write, so the statement reads like a
 * bank statement rather than a list somebody has to add up to understand.
 *
 * **Append-only** (D19): nothing may update `entry_type`, `days`, the ids, the year or `occurred_on`, a
 * `deleting` hook throws, and a `BEFORE DELETE` trigger raises underneath it. A wrong entry is corrected
 * by an opposite entry with a mandatory note.
 */
return new class extends Migration
{
    private const TABLE = 'leave_balance_transactions';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->unsignedSmallInteger('leave_year');
            $table->string('entry_type', 8);
            $table->string('reason', 32);
            // Positive magnitude; signed_days (generated STORED) is added in step 18.
            $table->decimal('days', 8, 4);
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->nullOnDelete();
            $table->decimal('balance_after_days', 8, 4)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            // The value date, never now(): a back-dated grant belongs to the year it was granted for.
            $table->date('occurred_on');

            $table->timestamps();
            // No softDeletes(): append-only ledger (D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.14 Keys.
            $table->index(['employee_id', 'leave_type_id', 'leave_year'], 'idx_lbt_employee_leavetyp_leaveyea');
            $table->index('leave_request_id');
            $table->index(['reason', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
