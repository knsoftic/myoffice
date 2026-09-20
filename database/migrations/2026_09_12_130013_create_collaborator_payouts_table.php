<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 13 — `collaborator_payouts`: a withdrawal (spine §2.13).
 *
 * **The payout never computes a balance itself.** It consumes *named* ledger entries through
 * `collaborator_payout_allocations`, which is what makes "payout history preserved" and "a commission is
 * never paid twice" true at the same time. `amount` is `SUM(live allocations)` set by the service and
 * **never by the form** (INV-22) — a payout whose amount came from a request body is a payout somebody
 * can edit into anything.
 *
 * **`uq_cp_txn(method, transaction_id)`** is the database half of "mark paid is idempotent": the same
 * bank transaction can never be recorded against two payouts. NULL transaction ids do not collide, so a
 * payout that has not been sent yet is unaffected.
 *
 * `account_details_encrypted` is snapshotted **at payment time**, because the account row may change
 * afterwards and a voucher has to say where the money actually went.
 *
 * **No `deleted_at`** (D16): §120.9 requires payout history to survive. Cancellation is a status, and
 * cancelling releases the allocations rather than deleting them.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_payouts';

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

            $table->string('payout_no', 32);
            $table->string('idempotency_key', 64);
            $table->unsignedBigInteger('collaborator_id');

            // What was asked for, kept for the dispute trail even when the allocations came to less.
            $table->decimal('requested_amount', 15, 2)->nullable();
            // SUM(live allocations). Set by the service, never by the form (INV-22).
            $table->decimal('amount', 15, 2)->default('0.00');
            $table->unsignedInteger('entry_count')->default(0);

            $table->string('method', 32);
            $table->unsignedBigInteger('payout_account_id')->nullable();
            $table->string('account_title', 150)->nullable();
            $table->string('bank_name', 150)->nullable();
            // Snapshotted at payment time; never logged, and an activity diff shows [encrypted].
            $table->text('account_details_encrypted')->nullable();
            $table->string('account_last4', 8)->nullable();
            $table->string('transaction_id', 100)->nullable();

            $table->string('status', 32)->default('requested');

            // The rule and the figure in force when the request was made, so a later disagreement is
            // legible rather than a matter of memory.
            $table->decimal('minimum_payout_snapshot', 15, 2)->nullable();
            $table->decimal('available_at_request', 15, 2)->nullable();

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->dateTime('requested_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->dateTime('paid_at')->nullable();
            // The business date of the transfer.
            $table->date('paid_on')->nullable();

            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            // Also used for a returned bank transfer (§6.4.5).
            $table->string('cancellation_reason', 255)->nullable();

            // The window this payout settles, for the printed voucher.
            $table->date('statement_from')->nullable();
            $table->date('statement_to')->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('payout_no', 'uq_cp_number');
            $table->unique('idempotency_key', 'uq_cp_idem');
            $table->unique(['method', 'transaction_id'], 'uq_cp_txn');
            $table->index(['collaborator_id', 'status'], 'idx_cp_collaborator_status');
            $table->index(['status', 'requested_at'], 'idx_cp_queue');
            $table->index('paid_on', 'idx_cp_paid_on');
            $table->index('payout_account_id', 'idx_cp_account');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_cp_amount',
            '`amount` >= 0 AND (`requested_amount` IS NULL OR `requested_amount` > 0)');
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
