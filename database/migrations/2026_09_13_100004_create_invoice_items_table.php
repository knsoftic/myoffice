<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 · file 4 — `invoice_items`: one billed line (§31, phase-13 §2.5).
 *
 * **No `deleted_at`** (D19, append-only child document line). A line belongs to its parent: the invoice's
 * own soft delete preserves the whole document, and only a never-issued draft may lose a line at all. A
 * nullable `deleted_at` here would accumulate orphaned draft lines that every totals query would have to
 * remember to exclude — and the first one that forgot would print a total nobody could reproduce.
 *
 * `gross_amount` is quantised **per line** (§2.7 step 1), so the printed total is always the sum of the
 * printed lines: 1.5 × 3,333.33 is 5,000.00, not 4,999.995 rounded at the end into a figure that
 * disagrees with the column above it.
 *
 * `allocated_discount_amount` is this line's share of the **invoice-level** discount, apportioned by
 * cumulative target so the shares sum to the discount exactly (§2.7 step 6).
 */
return new class extends Migration
{
    private const TABLE = 'invoice_items';

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

            $table->unsignedBigInteger('invoice_id');
            // The printed order, and also the apportionment order: the last line absorbs the residual,
            // so which line is last has to be a decision rather than whatever the database returns.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedBigInteger('project_milestone_id')->nullable();

            $table->string('description', 255);
            $table->text('details')->nullable();
            // "hour", "page", "month" — a label, never arithmetic.
            $table->string('unit', 24)->nullable();

            // Four decimals so 1.5 hours and 0.25 days are representable without a fiction.
            $table->decimal('quantity', 12, 4)->default('1.0000');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('gross_amount', 15, 2)->default('0.00');

            $table->string('discount_mode', 16)->default('none');
            $table->decimal('discount_rate', 8, 4)->nullable();
            $table->decimal('discount_fixed', 15, 2)->nullable();
            $table->decimal('discount_amount', 15, 2)->default('0.00');
            $table->decimal('net_amount', 15, 2)->default('0.00');
            $table->decimal('allocated_discount_amount', 15, 2)->default('0.00');

            $table->boolean('is_taxable')->default(true);
            $table->decimal('tax_rate', 8, 4)->default('0.0000');
            $table->decimal('taxable_amount', 15, 2)->default('0.00');
            $table->decimal('tax_amount', 15, 2)->default('0.00');
            $table->decimal('line_total', 15, 2)->default('0.00');

            // No softDeletes: D19.
            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['invoice_id', 'sort_order'], 'idx_ii_invoice_sort');
            $table->index('project_milestone_id', 'idx_ii_milestone');
        });
    }

    private function constraints(): void
    {
        // A zero-quantity line is not a line. It would print as a row with no amount and contribute
        // nothing but confusion to a document somebody is being asked to pay.
        $this->ensure('chk_ii_quantity', '`quantity` > 0');

        $this->ensure('chk_ii_nonneg',
            '`unit_price` >= 0 AND `gross_amount` >= 0 AND `discount_amount` >= 0 '
            .'AND `net_amount` >= 0 AND `allocated_discount_amount` >= 0 '
            .'AND `taxable_amount` >= 0 AND `tax_amount` >= 0');

        $this->ensure('chk_ii_discount_payload',
            "(`discount_mode` = 'none' AND `discount_rate` IS NULL AND `discount_fixed` IS NULL) "
            ."OR (`discount_mode` = 'percentage' AND `discount_rate` IS NOT NULL AND `discount_fixed` IS NULL) "
            ."OR (`discount_mode` = 'fixed' AND `discount_fixed` IS NOT NULL AND `discount_rate` IS NULL)");

        $this->ensure('chk_ii_discount_ceiling',
            '`discount_amount` + `allocated_discount_amount` <= `gross_amount`');

        $this->ensure('chk_ii_tax_rate', '`tax_rate` >= 0 AND `tax_rate` <= 100');

        // An exempt line can never carry tax. Without this, unticking "taxable" on a line that already
        // has tax on it would leave the amount behind and the total would stop matching the lines.
        $this->ensure('chk_ii_exempt',
            '`is_taxable` = 1 OR (`tax_amount` = 0 AND `taxable_amount` = 0)');
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
