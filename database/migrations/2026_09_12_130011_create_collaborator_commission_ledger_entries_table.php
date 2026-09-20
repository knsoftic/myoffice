<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 11 — `collaborator_commission_ledger_entries`: **the spine** (spine §2.11).
 *
 * Every movement of entitlement — earning, reversal, clawback, adjustment, write-off — is one immutable
 * row that permanently records which transaction caused it, which rule and rate applied, and the exact
 * bcmath trace. Nothing in this system is ever corrected by editing a row here; a wrong commission is
 * corrected by a reversing negative row that references the original (`CLAUDE.md` rule 3).
 *
 * **Two unique indexes, and they are not redundant.**
 *
 * `uq_cle_dedupe` is the canonical guard: the INSERT **is** the duplicate test, so concurrency, HTTP
 * retries and replayed queue jobs all collapse onto one row without anybody writing a SELECT-then-INSERT
 * race.
 *
 * `uq_cle_source(source_type, source_id, collaborator_id, purpose)` is the semantic restatement, and all
 * four columns are NOT NULL **on purpose**. The obvious-looking alternative — a guard over
 * `(student_fee_payment_id, project_payment_id, collaborator_id, source_type)` — would let **every**
 * project commission through, because they all share `student_fee_payment_id = NULL` and MariaDB unique
 * indexes ignore NULLs. This index makes a second commission for the same receipt impossible even if
 * somebody hand-crafts a different dedupe key.
 *
 * **`signed_amount` is the only column anything ever sums.** `amount` is a positive magnitude with
 * `CHECK > 0`; the direction lives in `entry_type`, and the generated column (file 16) combines them.
 * A sum that has to know about direction is a sum somebody eventually writes without it.
 *
 * `chk_cle_debit_clean` says a debit is never allocated or reversed: undoing a debit is a new credit
 * adjustment, not an edit. `chk_cle_allocation_ceiling` (INV-11) is what makes "no double payout, and no
 * payout of a reversed amount" structural rather than hopeful.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_commission_ledger_entries';

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
            // The human reference is the accessor `reference` = 'CLE-' . id (§51).
            $table->id();

            // THE duplicate guard, composed only inside LedgerWriter.
            $table->string('dedupe_key', 191);

            $table->unsignedBigInteger('collaborator_id');
            // Makes every wallet aggregate a single-table index scan.
            $table->unsignedBigInteger('collaborator_wallet_id');
            // Null only for manual adjustments and write-offs.
            $table->unsignedBigInteger('entitlement_id')->nullable();
            $table->unsignedBigInteger('collaborator_referral_id')->nullable();
            // The rule that produced money can never be deleted — RESTRICT in file 18.
            $table->unsignedBigInteger('commission_setting_id')->nullable();
            $table->string('rule_source', 24)->nullable();

            $table->string('entry_type', 8);
            $table->string('purpose', 32);

            $table->string('source_type', 32);
            // NOT NULL so the composite unique index cannot be defeated by NULLs.
            $table->unsignedBigInteger('source_id');

            $table->unsignedBigInteger('student_fee_payment_id')->nullable();
            $table->unsignedBigInteger('project_payment_id')->nullable();
            $table->unsignedBigInteger('payment_reversal_id')->nullable();
            // §44: "a reference to the original commission".
            $table->unsignedBigInteger('reverses_entry_id')->nullable();

            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('student_fee_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('project_milestone_id')->nullable();

            // The source transaction's full amount.
            $table->decimal('gross_amount', 15, 2)->default('0.00');
            // Which base rule applied at that moment, and the figure it produced.
            $table->string('commission_base', 32);
            $table->decimal('base_amount', 15, 2)->default('0.00');

            $table->string('calculation_type', 16);
            $table->decimal('commission_rate', 8, 4)->nullable();
            $table->decimal('fixed_amount', 15, 2)->nullable();
            $table->decimal('entitlement_total', 15, 2)->nullable();
            // Cumulative released before this row — what makes the proportional arithmetic auditable
            // from the row alone, without replaying every sibling.
            $table->decimal('released_before', 15, 2)->nullable();

            $table->decimal('amount', 15, 2);
            // `signed_amount` is a STORED generated column added by file 16.

            // The frozen rule row, every global setting used, and the calculation trace.
            $table->json('rule_snapshot');

            $table->string('status', 32)->default('pending');
            // Snapshot, so a later settings change never rewrites the meaning of a past row.
            $table->string('approval_mode', 16);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();

            // Null or past = immediately available.
            $table->date('hold_until')->nullable();
            $table->dateTime('available_at')->nullable();
            $table->dateTime('paid_at')->nullable();

            $table->decimal('allocated_amount', 15, 2)->default('0.00');
            // Magnitude reversed while still unpaid.
            $table->decimal('reversed_amount', 15, 2)->default('0.00');
            $table->dateTime('reversed_at')->nullable();
            // Magnitude reversed AFTER it was paid out — a different fact, and a different conversation.
            $table->decimal('clawed_back_amount', 15, 2)->default('0.00');

            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            // The BUSINESS date (paid_on / occurred_on). Statements and effective dating use this.
            $table->date('transaction_date');
            // System time — what explains why a closed month's statement changed.
            $table->dateTime('posted_at')->nullable();

            // A constant column today, so a future multi-currency split is additive rather than a
            // migration of every historical row.
            $table->char('currency', 3)->default('PKR');
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('dedupe_key', 'uq_cle_dedupe');
            $table->unique(['source_type', 'source_id', 'collaborator_id', 'purpose'], 'uq_cle_source');
            // One reversal row can undo a given original exactly once.
            $table->unique(['payment_reversal_id', 'reverses_entry_id'], 'uq_cle_reversal_pair');

            $table->index(['collaborator_wallet_id', 'status'], 'idx_cle_wallet_status');
            $table->index(['collaborator_id', 'status', 'transaction_date'], 'idx_cle_collab_status_date');
            $table->index(['collaborator_id', 'purpose', 'transaction_date'], 'idx_cle_collab_purpose_date');
            // FIFO payout allocation.
            $table->index(['collaborator_id', 'status', 'id'], 'idx_cle_fifo');
            // The release-held job.
            $table->index(['status', 'hold_until'], 'idx_cle_hold');
            $table->index('student_fee_payment_id', 'idx_cle_fee_payment');
            $table->index('project_payment_id', 'idx_cle_project_payment');
            $table->index('payment_reversal_id', 'idx_cle_reversal');
            $table->index('reverses_entry_id', 'idx_cle_reverses');
            $table->index('entitlement_id', 'idx_cle_entitlement');
            $table->index(['source_type', 'source_id'], 'idx_cle_source');
            $table->index('student_id', 'idx_cle_student');
            $table->index('project_id', 'idx_cle_project');
            $table->index('collaborator_referral_id', 'idx_cle_referral');
            $table->index('commission_setting_id', 'idx_cle_setting');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_cle_amount', '`amount` > 0');

        // Purpose and direction can never disagree.
        $this->ensure('chk_cle_sign',
            "(`purpose` IN ('student_commission','project_commission','write_off') AND `entry_type` = 'credit') "
            ."OR (`purpose` IN ('reversal','clawback') AND `entry_type` = 'debit') "
            ."OR `purpose` = 'manual_adjustment'");

        $this->ensure('chk_cle_reverses',
            "`purpose` NOT IN ('reversal','clawback') OR `reverses_entry_id` IS NOT NULL");

        // A debit is never allocated or reversed; undoing a debit is a new credit adjustment.
        $this->ensure('chk_cle_debit_clean',
            "`entry_type` = 'credit' "
            .'OR (`allocated_amount` = 0 AND `reversed_amount` = 0 AND `clawed_back_amount` = 0)');

        // INV-11: no double payout, and no payout of a reversed amount.
        $this->ensure('chk_cle_allocation_ceiling',
            '`allocated_amount` >= 0 AND `allocated_amount` + `reversed_amount` <= `amount`');

        // INV-10.
        $this->ensure('chk_cle_undo_ceiling',
            '`reversed_amount` >= 0 AND `clawed_back_amount` >= 0 '
            .'AND `reversed_amount` + `clawed_back_amount` <= `amount`');

        $this->ensure('chk_cle_rate',
            "`calculation_type` <> 'percentage' OR `commission_rate` IS NOT NULL");

        $this->ensure('chk_cle_base',
            '`base_amount` >= 0 AND `gross_amount` >= 0');
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
