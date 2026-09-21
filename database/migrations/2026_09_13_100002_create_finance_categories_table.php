<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 · file 2 — `finance_categories`: one table for both sides of the books (§30, phase-13 §2.3).
 *
 * Expense categories and income categories are separated by `type` rather than by two tables. Two
 * near-identical tables would double the CRUD, the policy and the seeder to express a difference that is
 * one word long, and a report that had to union them would be the first place they drifted apart.
 *
 * `code` is the stable half: a report groups by it and an admin may rename `name` freely, so "Utilities"
 * can become "Utilities & Power" without breaking a year of history.
 *
 * **The expense category `salaries` is reserved (D44).** `RecordPayrollExpense` posts every paid payroll
 * run into it, and the policy refuses both deletion and deactivation — a missing category would silently
 * drop payroll out of the profit-and-loss statement.
 */
return new class extends Migration
{
    private const TABLE = 'finance_categories';

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

            $table->string('type', 16);
            $table->string('code', 32);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            // Null means "usable anywhere": most categories are, and forcing a choice would make every
            // shared overhead pick a side it does not belong to.
            $table->string('context', 24)->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // Scoped by type: an expense "Consulting" and an income "Consulting" are different things
            // and both are legitimate.
            $table->unique(['type', 'code'], 'uq_fc_code');
            $table->index(['type', 'is_active', 'sort_order'], 'idx_fc_type_active');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_fc_type', "`type` IN ('expense', 'income')");
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
