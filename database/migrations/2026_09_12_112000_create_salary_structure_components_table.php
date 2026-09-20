<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.20 — salary_structure_components: the lines of one structure version.
 *
 * Every descriptive column is a **snapshot** of the component as it was when the version was written.
 * Renaming "House Rent" to "Accommodation" next year must not change what last year's structure says it
 * paid — and because the snapshot is here, it does not.
 *
 * `cascadeOnDelete` is reachable only while the version is being built inside its own transaction: a
 * written structure is never deleted (HR-10).
 *
 * **No `deleted_at`**: the lines live and die with their version (D19).
 */
return new class extends Migration
{
    private const TABLE = 'salary_structure_components';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained('salary_structures')->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained('salary_components')->restrictOnDelete();
            // Snapshots: renaming a component must not rewrite what a past version says it paid.
            $table->string('component_code', 32);
            $table->string('component_name', 100);
            $table->string('component_group', 32);
            $table->string('side', 16);
            $table->string('calculation_type', 24);
            $table->decimal('rate', 8, 4)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('affects_gross')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            // No softDeletes(): the lines live and die with their version (D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.20 Keys.
            $table->unique(['salary_structure_id', 'salary_component_id'], 'uq_ssc');
            $table->index('salary_component_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
