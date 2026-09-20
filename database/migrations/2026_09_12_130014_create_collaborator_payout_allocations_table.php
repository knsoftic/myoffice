<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 14 — `collaborator_payout_allocations`: the pivot that names what was paid (spine §2.14).
 *
 * **The difference between "we paid him 20,000" and "we paid him exactly these commissions."** A payout
 * consumes named, already-available earnings rather than an abstract number, which is what makes a
 * 20,000 payout against one 50,000 entry expressible, what makes a clawback traceable to the transfer
 * that carried it, and what makes "a commission is never paid twice" a database guarantee.
 *
 * **An allocation is released, never deleted.** A rejected or cancelled payout hands the money back by
 * setting `is_released` with a reason; the row stays. Deleting it would make the wallet's arithmetic
 * come out right while destroying the only evidence that the money was ever reserved — and "my balance
 * moved and nobody can tell me why" is the exact failure this table exists to prevent.
 *
 * `entry_transaction_date` and `entry_status_at_allocation` are snapshots, so FIFO order and eligibility
 * are provable after the fact without replaying the ledger as it stood that afternoon.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_payout_allocations';

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

            $table->unsignedBigInteger('payout_id');
            $table->unsignedBigInteger('ledger_entry_id');
            // Denormalised so the collaborator global scope applies to the pivot too.
            $table->unsignedBigInteger('collaborator_id');

            // May be LESS than the entry: a partial withdrawal is normal.
            $table->decimal('amount', 15, 2);

            $table->date('entry_transaction_date');
            $table->string('entry_status_at_allocation', 32);

            $table->boolean('is_released')->default(false);
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 32)->nullable();
            $table->string('release_note', 255)->nullable();
            $table->unsignedBigInteger('released_by')->nullable();
            // `active_guard` is a STORED generated column added by file 16.

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();

            // The same entry can never be added to one payout twice, so a retried "build allocations"
            // step is idempotent.
            $table->unique(['payout_id', 'ledger_entry_id'], 'uq_cpa_pair');
            $table->index(['collaborator_id', 'is_released'], 'idx_cpa_collaborator');
            $table->index(['payout_id', 'is_released'], 'idx_cpa_payout');
            $table->index('ledger_entry_id', 'idx_cpa_entry');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_cpa_amount', '`amount` > 0');
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
