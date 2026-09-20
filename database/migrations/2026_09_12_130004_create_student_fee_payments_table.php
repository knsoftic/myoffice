<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 04 — `student_fee_payments`: one physical receipt (spine §2.5).
 *
 * **The only student-side commission trigger.** Not an admission, not a registration, not a fee charge —
 * money actually received (`CLAUDE.md` rule 5). Append-only: a mistake is voided or refunded through
 * `payment_reversals`, never edited.
 *
 * **Two dates, always both.** `paid_on` is the **value date** — it may be back-dated, and it is what
 * selects the commission rule and the referral in force. `recorded_at` is when the system heard about
 * it. Keeping only one of them is how a back-dated receipt ends up earning at this year's rate.
 *
 * **Three layers of duplicate protection, and they are not the same thing** (spine §2.19):
 *   · `uq_sfp_idem` on a per-modal ULID — a replayed POST is idempotent end to end;
 *   · `uq_sfp_gateway` — one gateway capture can never become two receipts;
 *   · `duplicate_fingerprint`, deliberately **non-unique** — it warns a second cashier that an identical
 *     receipt already exists, because two students genuinely can pay the same amount the same way on the
 *     same day and refusing that would be worse than the duplicate.
 *
 * `commission_state` is a **progress hint and a sweeper index**, never the duplicate guard. The guard is
 * `uq_cle_source` on the ledger, because a state column can be stale and a unique index cannot.
 */
return new class extends Migration
{
    private const TABLE = 'student_fee_payments';

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

            $table->string('receipt_no', 32);
            $table->string('idempotency_key', 64);
            $table->string('duplicate_fingerprint', 64);

            $table->unsignedBigInteger('student_fee_id');
            $table->unsignedBigInteger('student_fee_installment_id')->nullable();
            // Denormalised for panel scoping; the service asserts it equals the charge's student.
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->decimal('amount', 15, 2);
            $table->decimal('refunded_amount', 15, 2)->default('0.00');
            // `net_received_amount` is a STORED generated column added by file 16.

            // The snapshot of record (§32): the method as it was at the time, beside the optional FK to
            // Phase 13's configurable list. A renamed method never rewrites an old receipt.
            $table->string('payment_method', 32);
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('reference_no', 64)->nullable();
            $table->string('gateway_txn_id', 100)->nullable();

            $table->date('paid_on');
            $table->dateTime('recorded_at')->nullable();
            $table->string('status', 32)->default('cleared');

            // The collaborator actually resolved at posting time — null means nobody, which is a
            // different fact from "not looked up yet".
            $table->unsignedBigInteger('collaborator_id')->nullable();
            $table->unsignedBigInteger('collaborator_referral_id')->nullable();

            $table->string('commission_state', 24)->default('queued');
            $table->string('commission_skip_reason', 48)->nullable();
            $table->string('commission_skip_detail', 191)->nullable();
            $table->unsignedSmallInteger('commission_attempts')->default(0);
            $table->dateTime('commission_processed_at')->nullable();

            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('received_by_name', 150)->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('receipt_no', 'uq_sfp_receipt');
            $table->unique('idempotency_key', 'uq_sfp_idem');
            $table->unique('gateway_txn_id', 'uq_sfp_gateway');
            $table->index(['student_fee_id', 'status']);
            $table->index(['student_id', 'paid_on']);
            $table->index('student_fee_installment_id');
            // The sweeper must never scan the table.
            $table->index(['commission_state', 'id']);
            $table->index(['collaborator_id', 'paid_on']);
            $table->index('paid_on');
            $table->index('duplicate_fingerprint');
            $table->index('branch_id');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_sfp_amount', '`amount` > 0');
        // INV-9: the sum of reversals can never exceed what was received.
        $this->ensure('chk_sfp_refund_ceiling',
            '`refunded_amount` >= 0 AND `refunded_amount` <= `amount`');
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
