<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 05 — `project_payments`: one client payment (spine §2.6).
 *
 * **The only project-side commission trigger**, and deliberately the same shape as
 * `student_fee_payments`: two dates, three duplicate layers, the same commission columns. One mental
 * model and one set of guarantees across both engines is worth more than a table tailored to each side,
 * because the thing that goes wrong is always the difference nobody remembered.
 *
 * `is_advance` records money that arrived before any invoice existed — normal on a project and the
 * reason `invoice_id` is nullable here and attached later by Phase 13's `InvoiceService` (D43).
 */
return new class extends Migration
{
    private const TABLE = 'project_payments';

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

            $table->string('payment_no', 32);
            $table->string('idempotency_key', 64);
            $table->string('duplicate_fingerprint', 64);

            $table->unsignedBigInteger('project_id');
            // Denormalised for client-panel scoping.
            $table->unsignedBigInteger('client_id');
            // Required when the commission base is `milestone`; the service enforces that, not the DB,
            // because the base is a property of the rule rather than of this row.
            $table->unsignedBigInteger('project_milestone_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();

            $table->decimal('amount', 15, 2);
            $table->decimal('refunded_amount', 15, 2)->default('0.00');
            // `net_received_amount` is a STORED generated column added by file 16.

            $table->boolean('is_advance')->default(false);

            $table->string('payment_method', 32);
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('reference_no', 64)->nullable();
            $table->string('gateway_txn_id', 100)->nullable();

            $table->date('paid_on');
            $table->dateTime('recorded_at')->nullable();
            $table->string('status', 32)->default('cleared');

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

            $table->unique('payment_no', 'uq_pp_number');
            $table->unique('idempotency_key', 'uq_pp_idem');
            $table->unique('gateway_txn_id', 'uq_pp_gateway');
            $table->index(['project_id', 'status']);
            $table->index(['client_id', 'paid_on']);
            $table->index('project_milestone_id');
            // Phase 13 derives invoices.paid_amount from here (D40).
            $table->index('invoice_id');
            $table->index(['commission_state', 'id']);
            $table->index(['collaborator_id', 'paid_on']);
            $table->index('paid_on');
            $table->index('duplicate_fingerprint');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_pp_amount', '`amount` > 0');
        $this->ensure('chk_pp_refund_ceiling',
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
