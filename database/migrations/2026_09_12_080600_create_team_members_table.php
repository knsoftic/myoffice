<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.9 — team_members.
 *
 * One public team profile (§13) — website content, **not** an account: there is no `user_id` (D32).
 * `department_id` and `employee_id` are **deferred links** (§2.1) to Phase 7's `departments` and
 * `employees`: indexed, nullable, no foreign key until Phase 7's guarded migration. `employee_id` is a
 * source of defaults only (F-3.13), never a publication trigger. The public page renders the
 * `department` snapshot label. The photo is a `media_assets` row (decision D24).
 *
 * Mutable content table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('team_members')) {
            return;
        }

        Schema::create('team_members', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 180);
            $table->unsignedBigInteger('photo_media_id')->nullable();
            $table->string('designation', 150);
            $table->string('department', 100)->nullable();
            // §2.1 deferred links → departments.id / employees.id (Phase 7). No constraint here.
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->text('bio')->nullable();
            $table->json('skills')->nullable();
            $table->unsignedTinyInteger('experience_years')->nullable();
            $table->string('experience_label', 100)->nullable();
            $table->json('social_links')->nullable();
            $table->string('portfolio_url', 255)->nullable();
            $table->boolean('is_public')->default(true);
            $table->string('status', 32)->default('draft');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.9 Indexes, plus the column-level indexes the table names (is_public, both deferred ids).
            $table->unique('slug');
            $table->index(['status', 'is_public', 'sort_order']);
            $table->index('photo_media_id');
            $table->index('is_public');
            $table->index('department_id');
            $table->index('employee_id');

            $table->foreign('photo_media_id')->references('id')->on('media_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Nothing references team_members; DROP TABLE removes the three keys it owns.
        Schema::dropIfExists('team_members');
    }
};
