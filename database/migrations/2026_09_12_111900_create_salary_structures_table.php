<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.19 — salary_structures: requirement §28's salary structure, as **immutable versions**
 * (HR-10).
 *
 * **A raise is never an UPDATE.** The open version is closed at `effective_from - 1 day` and a successor
 * carries `version + 1` and `supersedes_id`. That is what lets a payroll run from last March still point
 * at the numbers that were true last March, and it is why `open_guard` (step 18) carries `uq_ss_open`:
 * at most one open version per employee, decided by the database rather than by a service somebody could
 * bypass.
 *
 * `change_reason` is **mandatory**. A salary that changed with no recorded reason is the thing an audit
 * cannot resolve a year later.
 *
 * `net_salary_estimate` is display only — **no slip ever reads it**. A slip's net is the sum of its own
 * stored component rows (HR-13).
 *
 * **No `deleted_at`** (D16, D19): a version is superseded, never removed.
 */
return new class extends Migration
{
    private const TABLE = 'salary_structures';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('salary_structures')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('salary_structures')->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->decimal('gross_salary', 15, 2)->default(0);
            $table->decimal('total_deduction_amount', 15, 2)->default(0);
            // Display only — no slip ever reads this (HR-13).
            $table->decimal('net_salary_estimate', 15, 2)->default(0);
            $table->string('currency', 3)->default('PKR');
            $table->string('pay_frequency', 16)->default('monthly');
            $table->string('status', 16)->default('scheduled');
            // Mandatory: a salary that changed for no recorded reason cannot be audited.
            $table->string('change_reason', 255);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // open_guard (generated STORED) and uq_ss_open are added in step 18.

            $table->timestamps();
            // No softDeletes(): a version is superseded, never removed (D16, D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.19 Keys.
            $table->unique(['employee_id', 'version'], 'uq_ss_version');
            $table->index(['employee_id', 'effective_from']);
            $table->index(['status', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
