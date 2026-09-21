<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 · file 6 — `incomes`: money in that is neither a project payment nor a student fee
 * (§29, phase-13 §2.6).
 *
 * Deliberately the mirror of `expenses`, down to the generated `net_amount` and the idempotency key, so
 * one mental model covers both sides of the books. The one real difference is approval: income needs
 * none, because the money either arrived or it did not — there is nothing for a second person to agree
 * with. Voiding is the only undoing, and it appends a `finance_reversals` row like every other undoing
 * in this system.
 *
 * It is **not** a place to record a project payment or a fee. Those belong to the spine's tables and fire
 * the commission engine; a row here fires nothing, which is exactly why putting one in the wrong table
 * would be a commission silently not paid.
 */
return new class extends Migration
{
    private const TABLE = 'incomes';

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

            $table->string('income_no', 32);
            $table->string('idempotency_key', 64);

            $table->unsignedBigInteger('finance_category_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('context', 24);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();

            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('received_from', 150)->nullable();

            $table->decimal('amount', 15, 2);
            $table->decimal('refunded_amount', 15, 2)->default('0.00');

            $table->date('received_on');
            $table->timestamp('recorded_at')->useCurrent();

            $table->string('payment_method', 32);
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('reference_no', 64)->nullable();
            $table->string('receipt_path', 255)->nullable();

            $table->string('status', 32)->default('recorded');
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->unsignedBigInteger('corrects_income_id')->nullable();
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('income_no', 'uq_inc_no');
            $table->unique('idempotency_key', 'uq_inc_idem');

            $table->index(['status', 'received_on'], 'idx_inc_status_date');
            $table->index(['finance_category_id', 'received_on'], 'idx_inc_category_date');
            $table->index('client_id', 'idx_inc_client');
            $table->index('project_id', 'idx_inc_project');
            $table->index('branch_id', 'idx_inc_branch');
            $table->index('received_on', 'idx_inc_date');
            $table->index('voided_by', 'idx_inc_voided_by');
            $table->index('corrects_income_id', 'idx_inc_corrects');
            $table->index('payment_method_id', 'idx_inc_payment_method');
        });

        RawSchema::generatedColumn(self::TABLE, 'net_amount', 'DECIMAL(15,2)',
            '`amount` - `refunded_amount`');
    }

    private function constraints(): void
    {
        $this->ensure('chk_inc_amount', '`amount` > 0');
        $this->ensure('chk_inc_refund_ceiling',
            '`refunded_amount` >= 0 AND `refunded_amount` <= `amount`');
        $this->ensure('chk_inc_void_reason',
            '`voided_at` IS NULL OR `void_reason` IS NOT NULL');
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
