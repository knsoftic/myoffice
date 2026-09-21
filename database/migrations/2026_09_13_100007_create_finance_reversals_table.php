<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 · file 7 — `finance_reversals`: the un-doing of an expense or an other-income row
 * (phase-13 §2.6).
 *
 * **It is not the spine's `payment_reversals`, and the two must never be confused.** That table's
 * `chk_pr_one_target` allows only a student-fee or a project payment, so it structurally cannot hold
 * these — which is why this one exists rather than a nullable fourth column over there. The other
 * difference is the important one: no commission engine is involved. An expense has never produced a
 * ledger entry, so a row here dispatches **no** commission job, and nothing about a partner's balance
 * moves when the business gets money back from a supplier.
 *
 * `reversal_no` draws from the **same counter** as the spine's payment reversals
 * (`finance.payment_reversal_*`), so an auditor follows one voucher sequence rather than two that look
 * alike.
 *
 * **No `deleted_at`** (D16, D19): this is the record of money moving back, and a `BEFORE DELETE` trigger
 * refuses the deletion the model hook has already refused with a readable message.
 */
return new class extends Migration
{
    private const TABLE = 'finance_reversals';

    public function up(): void
    {
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
            // A double-clicked Refund cannot refund twice.
            $table->string('idempotency_key', 64);

            $table->unsignedBigInteger('expense_id')->nullable();
            $table->unsignedBigInteger('income_id')->nullable();

            $table->string('type', 32);
            // A positive magnitude; the direction is implied by the table, so no caller can get a sign
            // wrong and reverse the reversal.
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255);

            $table->string('refund_method', 32)->nullable();
            $table->string('reference_no', 64)->nullable();

            $table->date('occurred_on');
            $table->timestamp('recorded_at')->useCurrent();

            $table->unsignedBigInteger('performed_by')->nullable();
            // Snapshot: a deleted user must not make a reversal unattributable.
            $table->string('performed_by_name', 150);
            $table->string('attachment_path', 255)->nullable();
            $table->string('notes', 255)->nullable();

            // No softDeletes: D16.
            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('reversal_no', 'uq_fr_no');
            $table->unique('idempotency_key', 'uq_fr_idem');
            $table->index('expense_id', 'idx_fr_expense');
            $table->index('income_id', 'idx_fr_income');
            $table->index(['occurred_on', 'type'], 'idx_fr_date_type');
        });
    }

    private function constraints(): void
    {
        // Exactly one target: a reversal can never dangle, and can never take money back off two rows.
        $this->ensure('chk_fr_one_target',
            '(`expense_id` IS NOT NULL) + (`income_id` IS NOT NULL) = 1');

        $this->ensure('chk_fr_amount', '`amount` > 0');

        $this->ensure('chk_fr_type',
            "`type` IN ('full_refund', 'partial_refund', 'void', 'correction')");

        if (! RawSchema::triggerExists('trg_fr_no_delete')) {
            RawSchema::noDeleteTrigger(self::TABLE, 'trg_fr_no_delete',
                'Deleting a finance reversal would make money that came back disappear from every report '
                .'that already counted it. It is corrected by a further row, never removed.');
        }
    }

    public function down(): void
    {
        RawSchema::dropTrigger('trg_fr_no_delete');

        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
