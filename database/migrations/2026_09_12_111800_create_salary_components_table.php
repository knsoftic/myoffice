<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.18 — salary_components: requirement §28's typed allowances and deductions.
 *
 * **The group decides the side.** `side` is written from `component_group->side()` and never by hand, so
 * a deduction can never be configured in a way that makes it sum as an earning — the one mistake that
 * would make every slip add up to the wrong number while looking perfectly reasonable.
 *
 * `is_system` marks the six components payroll produces itself (basic, loss of pay, late deduction,
 * advance recovery, tax, rounding). They cannot be renamed, re-sided or deleted: each is the output of a
 * calculation, and a second hand-editable source under the same total would make the figure impossible to
 * explain.
 *
 * `affects_gross = false` exists for a reimbursement — paying somebody back what they already spent is
 * not pay and must not inflate gross or the tax base.
 */
return new class extends Migration
{
    private const TABLE = 'salary_components';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32);
            $table->string('name', 100);
            $table->string('component_group', 32);
            // Written from component_group->side(), never by hand.
            $table->string('side', 16);
            $table->string('calculation_type', 24)->default('fixed');
            $table->decimal('default_amount', 15, 2)->default(0);
            $table->decimal('default_rate', 8, 4)->default(0);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('affects_gross')->default(true);
            $table->boolean('is_statutory')->default(false);
            $table->boolean('is_attendance_dependent')->default(false);
            $table->boolean('is_system')->default(false);
            $table->string('print_label', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.18 Keys.
            $table->unique('code', 'uq_sc_code');
            $table->index(['side', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
