<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.13 — leave_balances: a cache over the ledger, with **no truth of its own** (HR-7).
 *
 * Every column here equals the sum of its `leave_balance_transactions`, and
 * `available_days = entitled + carried_forward + accrued + adjusted - consumed - pending - encashed
 * - expired`. The test helper re-derives all of it from the ledger after every leave test, so a drift is
 * a failing test rather than a number somebody notices a year later.
 *
 * `pending_days` is what makes two overlapping requests impossible to fit into one quota: a pending
 * request **reserves** days, and only approval consumes them.
 *
 * `adjusted_days` is the one column that may be negative — a manual correction can go either way.
 *
 * Only `LeaveBalanceService` writes this table, always under `lockForUpdate()`, always in the same
 * transaction as the ledger row that justifies the change.
 */
return new class extends Migration
{
    private const TABLE = 'leave_balances';

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
            $table->date('period_start');
            $table->date('period_end');

            $table->decimal('entitled_days', 8, 4)->default(0);
            $table->decimal('carried_forward_days', 8, 4)->default(0);
            $table->decimal('accrued_days', 8, 4)->default(0);
            // The one column that may be negative: a correction can go either way.
            $table->decimal('adjusted_days', 8, 4)->default(0);
            $table->decimal('consumed_days', 8, 4)->default(0);
            $table->decimal('pending_days', 8, 4)->default(0);
            $table->decimal('encashed_days', 8, 4)->default(0);
            $table->decimal('expired_days', 8, 4)->default(0);
            $table->decimal('available_days', 8, 4)->default(0);
            $table->timestamp('last_recalculated_at')->nullable();

            $table->timestamps();
            // No softDeletes(): a cache, rebuilt rather than removed (D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.13 Keys.
            $table->unique(['employee_id', 'leave_type_id', 'leave_year'], 'uq_lb');
            $table->index(['leave_type_id', 'leave_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
