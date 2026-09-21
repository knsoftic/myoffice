<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 · file 3 — `invoices`: the billing document (§31, phase-13 §2.4).
 *
 * **It holds no cash.** Every rupee against an invoice is a `project_payments` row owned by the spine,
 * and `paid_amount` here is a **cache** of one canonical query (§2.8) that nothing may `increment()`.
 * An independent counter is how an invoice and its receipts start disagreeing, and by the time anybody
 * notices there is no way to tell which one was right.
 *
 * **`invoice_number` is NULL while the invoice is a draft**, and `uq_inv_number` ignores NULLs — so
 * unlimited drafts coexist while two accountants can never share an issued number. That is what makes
 * the series gap-free: an abandoned draft consumes nothing. Assignment happens once, inside the issue
 * transaction, and a cancelled invoice **keeps** its number, because a reused number makes two documents
 * answer to one reference in a dispute.
 *
 * `tax_label`, `tax_rate`, `footer_note` and `bank_details` are **snapshots**: changing a setting today
 * never rewrites an invoice the client is already holding.
 */
return new class extends Migration
{
    private const TABLE = 'invoices';

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

            // NULL while draft. The screens show `draft_reference` = 'DRAFT-{id}' instead.
            $table->string('invoice_number', 32)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('client_id');
            // Null means a non-project invoice: a retainer, an ad-hoc service.
            $table->unsignedBigInteger('project_id')->nullable();
            // Set when this invoice replaces a cancelled one, so a correction is traceable rather than
            // looking like two unrelated documents for the same work.
            $table->unsignedBigInteger('replaces_invoice_id')->nullable();

            $table->string('title', 150)->nullable();
            $table->string('reference', 64)->nullable();
            $table->char('currency', 3)->default('PKR');

            $table->date('issue_date');
            $table->date('due_date');
            $table->unsignedSmallInteger('payment_terms_days')->default(0);

            // Derived. Written only by InvoiceService::recomputeStatus().
            $table->string('status', 32)->default('draft');

            // Snapshots taken at creation: a later settings change never alters an issued document.
            $table->string('tax_label', 32)->nullable();
            $table->decimal('tax_rate', 8, 4)->default('0.0000');

            $table->string('discount_mode', 16)->default('none');
            $table->decimal('discount_rate', 8, 4)->nullable();
            $table->decimal('discount_fixed', 15, 2)->nullable();

            $table->decimal('subtotal_amount', 15, 2)->default('0.00');
            $table->decimal('item_discount_amount', 15, 2)->default('0.00');
            $table->decimal('discount_amount', 15, 2)->default('0.00');
            $table->decimal('taxable_amount', 15, 2)->default('0.00');
            $table->decimal('tax_amount', 15, 2)->default('0.00');
            // Signed: rounding a total down makes this negative, and hiding the sign would mean the
            // printed figures no longer add up.
            $table->decimal('round_off_amount', 15, 2)->default('0.00');
            $table->decimal('total_amount', 15, 2)->default('0.00');

            // Caches of the §2.8 canonical query. Nothing may increment these.
            $table->decimal('paid_amount', 15, 2)->default('0.00');
            $table->decimal('refunded_amount', 15, 2)->default('0.00');
            // May be negative: a client who overpaid is holding a credit, and the register shows it.
            $table->decimal('balance_amount', 15, 2)->default('0.00');

            $table->unsignedBigInteger('payment_method_id')->nullable();

            // Client-visible; `internal_notes` never reaches a client or a PDF.
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('footer_note')->nullable();
            $table->text('bank_details')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();

            // NULL means the client has never been sent it, which is what `draft` means.
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('sent_count')->default(0);
            $table->string('last_sent_to', 255)->nullable();

            $table->timestamp('last_reminder_at')->nullable();
            $table->unsignedSmallInteger('reminder_count')->default(0);
            $table->timestamp('viewed_at')->nullable();

            // Regenerating it revokes every link already emailed.
            $table->char('public_token', 40)->nullable();
            // The private `local` disk, streamed by a controller that re-runs the permission chain (D21).
            $table->string('pdf_path', 255)->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('invoice_number', 'uq_inv_number');
            $table->unique('public_token', 'uq_inv_public_token');
            $table->index(['client_id', 'status'], 'idx_inv_client_status');
            $table->index(['project_id', 'status'], 'idx_inv_project_status');
            $table->index(['status', 'due_date'], 'idx_inv_status_due');
            $table->index('issue_date', 'idx_inv_issue_date');
            $table->index('due_date', 'idx_inv_due_date');
            $table->index('branch_id', 'idx_inv_branch');
            $table->index('replaces_invoice_id', 'idx_inv_replaces');
            $table->index('payment_method_id', 'idx_inv_payment_method');
            $table->index('issued_by', 'idx_inv_issued_by');
            $table->index('cancelled_by', 'idx_inv_cancelled_by');
        });

        // The single column §31's "discount" prints and every report sums, so no aggregate can add one
        // half and forget the other.
        RawSchema::generatedColumn(self::TABLE, 'total_discount_amount', 'DECIMAL(15,2)',
            '`item_discount_amount` + `discount_amount`');
    }

    private function constraints(): void
    {
        $this->ensure('chk_inv_nonneg',
            '`subtotal_amount` >= 0 AND `item_discount_amount` >= 0 AND `discount_amount` >= 0 '
            .'AND `taxable_amount` >= 0 AND `tax_amount` >= 0 AND `total_amount` >= 0 '
            .'AND `paid_amount` >= 0 AND `refunded_amount` >= 0');

        // A row can never be saved without the number its discount mode needs, which is what stops
        // "10% off" quietly meaning PKR 10.
        $this->ensure('chk_inv_discount_payload',
            "(`discount_mode` = 'none' AND `discount_rate` IS NULL AND `discount_fixed` IS NULL) "
            ."OR (`discount_mode` = 'percentage' AND `discount_rate` IS NOT NULL AND `discount_fixed` IS NULL) "
            ."OR (`discount_mode` = 'fixed' AND `discount_fixed` IS NOT NULL AND `discount_rate` IS NULL)");

        $this->ensure('chk_inv_discount_ceiling',
            '`item_discount_amount` + `discount_amount` <= `subtotal_amount`');

        $this->ensure('chk_inv_rates',
            '(`discount_rate` IS NULL OR (`discount_rate` >= 0 AND `discount_rate` <= 100)) '
            .'AND `tax_rate` >= 0 AND `tax_rate` <= 100');

        $this->ensure('chk_inv_dates', '`due_date` >= `issue_date`');

        // A non-draft invoice always carries its number: the database half of the gap-free rule.
        $this->ensure('chk_inv_issued_number', "`status` = 'draft' OR `invoice_number` IS NOT NULL");

        $this->ensure('chk_inv_cancel_reason',
            '`cancelled_at` IS NULL OR `cancellation_reason` IS NOT NULL');
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
