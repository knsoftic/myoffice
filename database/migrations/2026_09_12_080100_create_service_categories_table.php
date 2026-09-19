<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.2 — service_categories.
 *
 * The taxonomy of the public service catalogue (§11). No SEO column: SEO is the `seo_meta` morph
 * (decision D23). The category image is a `media_assets` row (decision D24) — a nullable, indexed
 * `image_media_id` with `nullOnDelete`, never a path string.
 *
 * First Phase 4 migration: `services.service_category_id` references it. It references only Phase 1's
 * `users` and Phase 3's `media_assets`, both of which migrate earlier.
 *
 * Mutable catalogue table → timestamps + softDeletes + blameable (CLAUDE.md §3). The slug carries a
 * single-column UNIQUE so a trashed row keeps its permalink (§6.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_categories')) {
            return;
        }

        Schema::create('service_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 180);
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->unsignedBigInteger('image_media_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.2 Indexes. Declared before the media foreign key so MariaDB reuses them instead of
            // creating a second, implicit index for the constraint.
            $table->unique('slug');
            $table->index(['is_active', 'sort_order']);
            $table->index('sort_order');
            $table->index('image_media_id');

            $table->foreign('image_media_id')->references('id')->on('media_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // DROP TABLE removes the three foreign keys this table owns. The only inbound key,
        // services.service_category_id, belongs to a later Phase 4 migration and is rolled back first.
        Schema::dropIfExists('service_categories');
    }
};
