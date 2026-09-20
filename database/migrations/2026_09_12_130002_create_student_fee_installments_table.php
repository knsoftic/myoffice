<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 02 — `student_fee_installments`: a schedule line (spine §2.3).
 *
 * **A schedule line is a promise, not money.** The commission engine never reads this table; receipts
 * point *at* it, which is what makes "commission follows the actual installment payment" (§77) provable
 * rather than asserted.
 *
 * **`paid_amount <= amount` is deliberately NOT enforced.** Over-allocating a line is legal — the excess
 * lands on the charge as an advance — and a CHECK here would refuse a payment the business genuinely
 * took. `PaymentService` caps the allocation instead, which is a decision about where money goes rather
 * than a refusal to record that it arrived.
 */
return new class extends Migration
{
    private const TABLE = 'student_fee_installments';

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

            $table->unsignedBigInteger('student_fee_id');
            $table->unsignedTinyInteger('installment_no');

            $table->decimal('amount', 15, 2);
            $table->date('due_date');
            // CACHE of the receipts allocated here, net of reversals.
            $table->decimal('paid_amount', 15, 2)->default('0.00');
            $table->decimal('waived_amount', 15, 2)->default('0.00');
            $table->date('paid_on')->nullable();

            $table->string('status', 32)->default('pending');
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // A retried "generate plan" cannot create installment 2 twice, and "the third installment"
            // becomes an unambiguous phrase in a dispute.
            $table->unique(['student_fee_id', 'installment_no'], 'uq_sfi_no');
            $table->index(['due_date', 'status']);
            $table->index(['student_fee_id', 'status']);
            $table->index('deleted_at');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_sfi_amount',
            '`amount` > 0 AND `paid_amount` >= 0 AND `waived_amount` >= 0');
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
