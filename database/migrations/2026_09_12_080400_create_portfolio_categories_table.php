<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.6 — portfolio_categories.
 *
 * Identical in shape to `service_categories` (§2.2): a separate table on purpose (§12.2 Q3). No SEO
 * column (decision D23); the image is a `media_assets` row (decision D24).
 *
 * Mutable catalogue table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_categories')) {
            return;
        }

        Schema::create('portfolio_categories', function (Blueprint $table): void {
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

            // §2.6 Indexes (as §2.2).
            $table->unique('slug');
            $table->index(['is_active', 'sort_order']);
            $table->index('sort_order');
            $table->index('image_media_id');

            $table->foreign('image_media_id')->references('id')->on('media_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // The inbound key portfolio_items.portfolio_category_id is rolled back first by its own migration.
        Schema::dropIfExists('portfolio_categories');
    }
};
