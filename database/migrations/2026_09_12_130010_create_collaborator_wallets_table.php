<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 10 — `collaborator_wallets`: a pure cache of the ledger (spine §2.12).
 *
 * **It holds no truth of its own.** Every column here is defined by an exact SQL expression over
 * `collaborator_commission_ledger_entries` (§6.5) and is proven nightly; the table exists so a dashboard
 * does not aggregate a million rows to draw one number. `CLAUDE.md` §5 states the rule the whole design
 * rests on: the balances must always be re-derivable by summing the ledger.
 *
 * **The single row is also the serialisation point.** `SELECT ... FOR UPDATE` on it is what orders two
 * commissions landing at the same instant, and `uq_cw_collaborator` is what stops two concurrent first
 * commissions creating two wallets — the loser re-reads instead.
 *
 * **There is deliberately no CHECK on `available_balance`.** A negative available balance is a
 * legitimate and required state after a clawback: the partner genuinely owes money back. A CHECK there
 * would turn a recoverable accounting position into a 500 in the middle of a refund. The payout guard
 * refuses to *spend* a negative balance, which is a different thing from refusing to record one.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_wallets';

    public function up(): void
    {
        // Guards the CREATE only. The constraints below are ensured on every run, so a table left
        // behind by a half-applied migration cannot end up looking complete without them.
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            // Created by Phase 8's observer in the same transaction as the collaborator, so no payment
            // path ever has to create a wallet lazily while holding a lock it took for something else.
            $table->unsignedBigInteger('collaborator_id');
            $table->char('currency', 3)->default('PKR');

            // Earned but not yet payable: pending + approved.
            $table->decimal('pending_balance', 15, 2)->default('0.00');
            // Spendable. **May be negative** after a clawback — see the class docblock.
            $table->decimal('available_balance', 15, 2)->default('0.00');
            // Allocated to payouts still in flight.
            $table->decimal('reserved_balance', 15, 2)->default('0.00');
            $table->decimal('paid_balance', 15, 2)->default('0.00');

            // Net of reversals and clawbacks.
            $table->decimal('lifetime_earned', 15, 2)->default('0.00');
            $table->decimal('total_student_commission', 15, 2)->default('0.00');
            $table->decimal('total_project_commission', 15, 2)->default('0.00');
            $table->decimal('total_adjustments', 15, 2)->default('0.00');
            // A positive-magnitude memo.
            $table->decimal('total_reversed', 15, 2)->default('0.00');
            // Cross-checked against SUM(paid payouts.amount) by the reconciler.
            $table->decimal('total_paid_out', 15, 2)->default('0.00');

            // Catches a row that vanished: the sums can agree while the count does not.
            $table->unsignedBigInteger('ledger_entry_count')->default(0);
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->dateTime('last_entry_at')->nullable();
            // Bumped on every write; the optimistic-lock witness.
            $table->unsignedBigInteger('version')->default(0);

            // Blocks new payouts without blocking earning.
            $table->boolean('is_frozen')->default(false);
            $table->string('frozen_reason', 255)->nullable();

            $table->dateTime('recalculated_at')->nullable();
            // The last time it was *proven* equal, which is not the same as the last time it was written.
            $table->dateTime('last_reconciled_at')->nullable();
            $table->string('reconciliation_status', 16)->default('ok');
            $table->decimal('drift_amount', 15, 2)->default('0.00');

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('collaborator_id', 'uq_cw_collaborator');
            $table->index(['reconciliation_status', 'last_reconciled_at'], 'idx_cw_reconciliation');
            $table->index('available_balance', 'idx_cw_available');
            $table->index('last_entry_id', 'idx_cw_last_entry');
        });
    }

    private function constraints(): void
    {
        // Money already sent cannot be negative.
        $this->ensure('chk_cw_paid_nonneg', '`paid_balance` >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
