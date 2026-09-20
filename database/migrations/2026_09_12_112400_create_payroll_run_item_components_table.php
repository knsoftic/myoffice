<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.25 — payroll_run_item_components: the typed lines a slip prints and its totals sum.
 *
 * `amount` is the **stored, quantised figure** — the totals add these rows up rather than recomputing
 * anything (HR-13), so a printed slip always adds up even if a rate or a rule changed since.
 *
 * `calculation_note` carries the human formula — "34,000.00 / 31 x 2.5000 days" — which is the difference
 * between a slip somebody can check and one they have to trust.
 *
 * `run_type` is denormalised for `chk_pric_sign`: a negative line is legal only on a correction run,
 * where the component's side says which total it reduces (HR-17, [D-HR-4]).
 *
 * **No `deleted_at`** (HR-16). `cascadeOnDelete` fires only while the parent run is still a draft, which
 * is what "regenerate" means.
 */
return new class extends Migration
{
    private const TABLE = 'payroll_run_item_components';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_item_id')->constrained('payroll_run_items')->cascadeOnDelete();
            // Denormalised for chk_pric_sign (HR-17).
            $table->string('run_type', 24);
            $table->foreignId('salary_component_id')->nullable()->constrained('salary_components')->restrictOnDelete();

            // Snapshots — what the slip prints, whatever has been renamed since.
            $table->string('component_code', 32);
            $table->string('component_name', 100);
            $table->string('component_group', 32);
            $table->string('side', 16);
            $table->string('calculation_type', 24);

            $table->decimal('rate', 8, 4)->default(0);
            $table->decimal('base_amount', 15, 2)->default(0);
            $table->decimal('quantity', 8, 4)->default(0);
            $table->decimal('amount', 15, 2);
            $table->boolean('is_taxable')->default(true);

            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            // The human formula, so a slip can be checked rather than trusted.
            $table->string('calculation_note', 255)->nullable();
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            // No softDeletes() (HR-16).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.25 Keys.
            $table->unique(['payroll_run_item_id', 'component_code'], 'uq_pric_line');
            $table->index('salary_component_id');
            $table->index(['source_type', 'source_id']);
            $table->index('component_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
