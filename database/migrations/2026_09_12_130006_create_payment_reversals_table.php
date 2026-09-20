<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 06 — `payment_reversals`: every un-doing of received money (spine §2.7).
 *
 * **One table for both sides.** A student refund and a client refund are the same event with a different
 * target, and two tables would mean two reversal engines that eventually disagree about what a partial
 * refund does to commission.
 *
 * **It is itself a commission trigger**, so a reversal gets exactly the idempotency guarantees an
 * earning does: its own `idempotency_key`, its own `commission_state`, and its own row in
 * `uq_cle_source`. A double-clicked Refund button cannot claw back twice.
 *
 * `amount` is a **positive magnitude** — the direction is implied by the table, not by a sign. That
 * keeps every CHECK and every SUM in this table readable, and the ledger is where signs live.
 */
return new class extends Migration
{
    private const TABLE = 'payment_reversals';

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

            $table->string('reversal_no', 32);
            $table->string('idempotency_key', 64);

            $table->unsignedBigInteger('student_fee_payment_id')->nullable();
            $table->unsignedBigInteger('project_payment_id')->nullable();

            $table->string('type', 32);
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255);
            $table->string('refund_method', 32)->nullable();
            $table->string('reference_no', 64)->nullable();

            // The business date, which becomes the reversal entry's transaction_date.
            $table->date('occurred_on');
            $table->dateTime('recorded_at')->nullable();

            // Commission is not clawed back until this allows it: a refund somebody entered and one
            // somebody authorised are different facts, and taking money off a partner on the strength
            // of the first would be a debt raised by a data-entry error.
            $table->string('approval_status', 16)->default('not_required');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->unsignedBigInteger('performed_by')->nullable();
            // Snapshot beside the FK: the performer's user row may be deleted, and "performed by" with
            // nothing after it is the sentence a dispute turns on.
            $table->string('performed_by_name', 150);
            $table->string('attachment_path', 255)->nullable();

            $table->string('commission_state', 24)->default('queued');
            $table->string('commission_skip_reason', 48)->nullable();
            $table->string('commission_skip_detail', 191)->nullable();
            $table->unsignedSmallInteger('commission_attempts')->default(0);
            $table->dateTime('commission_processed_at')->nullable();

            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('reversal_no', 'uq_pr_number');
            $table->unique('idempotency_key', 'uq_pr_idem');
            $table->index('student_fee_payment_id');
            $table->index('project_payment_id');
            $table->index(['commission_state', 'id']);
            $table->index(['occurred_on', 'type']);
            $table->index('approval_status');
        });
    }

    private function constraints(): void
    {
        // Exactly one target: a reversal can never dangle, and can never take money back off two
        // receipts at once.
        $this->ensure('chk_pr_one_target',
            '(`student_fee_payment_id` IS NOT NULL) + (`project_payment_id` IS NOT NULL) = 1');
        $this->ensure('chk_pr_amount', '`amount` > 0');
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
