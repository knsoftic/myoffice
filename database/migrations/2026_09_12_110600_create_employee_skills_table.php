<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.5 — employee_skills: requirement §24's skills, kept filterable.
 *
 * **[D-HR-6] deliberately not a dictionary.** §24 asks for skills on an employee, not a taxonomy to
 * maintain. Rows with `UNIQUE(employee_id, name, deleted_at)` answer "who knows Laravel" today through
 * `INDEX (name)`, and a dictionary can be introduced later by adding a nullable `skill_id` without moving
 * a single row.
 */
return new class extends Migration
{
    private const TABLE = 'employee_skills';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('level', 16)->nullable();
            $table->decimal('years_experience', 4, 1)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.5 Keys.
            $table->unique(['employee_id', 'name', 'deleted_at'], 'uq_eskill');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
