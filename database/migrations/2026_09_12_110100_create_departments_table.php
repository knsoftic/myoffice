<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.2 — departments: requirement §25's dynamic departments.
 *
 * `head_employee_id` is **absent here on purpose**: it points at `employees`, which points back at
 * `departments`, so the circular edge is added by its own migration once both tables exist (§2.27 step 5).
 * Creating it inline would make `migrate:fresh` depend on an order that cannot exist.
 *
 * `employee_count` is a cache written only by `DepartmentService` — recomputed, never incremented, so a
 * replayed job cannot inflate it.
 *
 * The ten departments of §25 are **seeded, not hardcoded**: a business that works differently edits them.
 */
return new class extends Migration
{
    private const TABLE = 'departments';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32);
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            // head_employee_id is added by 2026_09_12_110500 — the circular edge.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedInteger('employee_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.2 Keys. uq_dept_name includes deleted_at so a name is reusable after a soft delete.
            $table->unique('code', 'uq_dept_code');
            $table->unique(['name', 'deleted_at'], 'uq_dept_name');
            $table->index(['is_active', 'sort_order']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
