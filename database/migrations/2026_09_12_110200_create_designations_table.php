<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.3 — designations: requirement §24's job titles, made dynamic.
 *
 * A null `department_id` means a title any department may use. `department_guard` (added with the rest of
 * the raw SQL in §2.27 step 18) carries `uq_desig_guard`, so one title exists once per department and
 * becomes reusable again after a soft delete — MariaDB treats NULL as distinct, which is what makes that
 * work ([D-HR-5]).
 *
 * `level` mirrors `roles.level`: lower is more senior, so an org list sorts without a second opinion.
 */
return new class extends Migration
{
    private const TABLE = 'designations';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('title', 150);
            $table->string('code', 32)->nullable();
            $table->unsignedSmallInteger('level')->default(50);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            // department_guard (generated STORED) and uq_desig_guard are added in step 18.
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.3 Keys.
            $table->unique('code', 'uq_desig_code');
            $table->index(['department_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
