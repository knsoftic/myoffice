<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 · file 5 — `expenses`: money the business spent (§30, phase-13 §2.6).
 *
 * **`net_amount` is generated**, not computed by a caller. Every expense report and the profit-and-loss
 * statement sum that column, so none of them can forget a refund — which is the failure mode a stored
 * `amount` alone invites, because the report that forgets is always the one nobody re-reads.
 *
 * **`uq_exp_source(source_type, source_id)`** is the guard, not a check-then-insert: a replayed
 * `PayrollRunPaid` event can never post a second salary expense for the same run (D44). MariaDB ignores
 * NULLs, so hand-entered expenses stack freely.
 *
 * `expense_date` is the **value date** and `recorded_at` the system date, and both are always kept — a
 * back-dated expense belongs in the period it was incurred, and the difference is what explains why a
 * closed month's report moved.
 */
return new class extends Migration
{
    private const TABLE = 'expenses';

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

            // An expense is never a draft, so the series has no gaps by construction.
            $table->string('expense_no', 32);
            // The spine's layer-0 pattern: a double-submitted form cannot record the same spend twice.
            $table->string('idempotency_key', 64);

            $table->unsignedBigInteger('finance_category_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            // §30's "project/institute", made a column reports can group by.
            $table->string('context', 24);
            $table->unsignedBigInteger('project_id')->nullable();

            $table->string('title', 150);
            $table->text('description')->nullable();
            // The payee, so an expense is identifiable without opening the receipt scan.
            $table->string('paid_to', 150)->nullable();

            $table->decimal('amount', 15, 2);
            $table->decimal('refunded_amount', 15, 2)->default('0.00');

            // The value date, then the clock. Both, always.
            $table->date('expense_date');
            $table->timestamp('recorded_at')->useCurrent();

            $table->string('payment_method', 32);
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('reference_no', 64)->nullable();
            // Private `local` disk under `expenses/`, streamed by a controller that re-runs the policy
            // check (D21). Never the public disk.
            $table->string('receipt_path', 255)->nullable();

            // The system object this was derived from, when a human did not type it. Today: payroll_run.
            // No FK — the source table may belong to another phase, and the pair is the identity.
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('status', 32)->default('pending');
            // Snapshot of the approval decision at creation, so a later settings change never rewrites
            // why a row was auto-approved.
            $table->boolean('approval_required')->default(true);

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            // The re-entry that replaces a voided row, so a correction is one story rather than two.
            $table->unsignedBigInteger('corrects_expense_id')->nullable();
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('expense_no', 'uq_exp_no');
            $table->unique('idempotency_key', 'uq_exp_idem');
            $table->unique(['source_type', 'source_id'], 'uq_exp_source');

            $table->index(['status', 'expense_date'], 'idx_exp_status_date');
            $table->index(['finance_category_id', 'expense_date'], 'idx_exp_category_date');
            $table->index(['created_by', 'status'], 'idx_exp_mine');
            $table->index('project_id', 'idx_exp_project');
            $table->index('branch_id', 'idx_exp_branch');
            $table->index('expense_date', 'idx_exp_date');
            $table->index('payment_method', 'idx_exp_method');
            $table->index('approved_by', 'idx_exp_approved_by');
            $table->index('rejected_by', 'idx_exp_rejected_by');
            $table->index('voided_by', 'idx_exp_voided_by');
            $table->index('corrects_expense_id', 'idx_exp_corrects');
            $table->index('payment_method_id', 'idx_exp_payment_method');
        });

        RawSchema::generatedColumn(self::TABLE, 'net_amount', 'DECIMAL(15,2)',
            '`amount` - `refunded_amount`');
    }

    private function constraints(): void
    {
        $this->ensure('chk_exp_amount', '`amount` > 0');

        // Cumulative refunds can never exceed what was spent — the database backstop behind the
        // conditional UPDATE the service uses.
        $this->ensure('chk_exp_refund_ceiling',
            '`refunded_amount` >= 0 AND `refunded_amount` <= `amount`');

        $this->ensure('chk_exp_reject_reason',
            '`rejected_at` IS NULL OR `rejection_reason` IS NOT NULL');
        $this->ensure('chk_exp_void_reason',
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
