<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · §2.8 — cta_blocks.
 *
 * Reusable call-to-action content (§100). Referenced by website_sections.cta_block_id,
 * never copied, so changing a button once changes it everywhere (INV-3).
 * Status-gated and live — CTA blocks are not snapshot-published (§2.15).
 *
 * Created before website_sections, which carries the FK to this table.
 *
 * Mutable content table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cta_blocks')) {
            return;
        }

        Schema::create('cta_blocks', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64);
            $table->string('name', 150);
            $table->string('variant', 24)->default('banner');
            $table->string('heading', 200);
            $table->string('subheading', 300)->nullable();
            $table->text('description')->nullable();
            $table->string('primary_label', 60)->nullable();
            $table->string('primary_url', 500)->nullable();
            $table->string('primary_style', 16)->default('primary');
            $table->boolean('primary_new_tab')->default(false);
            $table->string('secondary_label', 60)->nullable();
            $table->string('secondary_url', 500)->nullable();
            $table->string('secondary_style', 16)->default('outline');
            $table->boolean('secondary_new_tab')->default(false);
            $table->foreignId('background_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('background_color', 16)->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.8 Keys. `background_media_id` is indexed by its own foreign key.
            $table->unique('key', 'uq_cta_key');
            $table->index('status');
        });
    }

    public function down(): void
    {
        // Dropping the table drops the three foreign keys it owns
        // (background_media_id, created_by, updated_by) in one statement.
        // website_sections.cta_block_id points at this table and is created by a later
        // Phase 3 migration, so that constraint is dropped before this rollback runs.
        Schema::dropIfExists('cta_blocks');
    }
};
