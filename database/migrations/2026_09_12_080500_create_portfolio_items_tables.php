<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.7 and §2.8 — portfolio_items, portfolio_item_technology and portfolio_item_media.
 *
 * `portfolio_items` is one case study (§12). `client_id` is a **deferred link** (§2.1): an indexed,
 * nullable `unsignedBigInteger` with **no foreign key** — `clients` arrives in Phase 5, whose own guarded
 * migration adds the constraint. The public site renders the `client_name` snapshot and never joins.
 * No SEO column (decision D23). The cover is `cover_media_id`, a `media_assets` row that must also be one
 * of the item's gallery attachments (enforced by `PortfolioService::setCover()`, §6.3).
 *
 * `portfolio_item_technology` mirrors `service_technology` (§2.5). `portfolio_item_media` replaces the
 * deleted `portfolio_images` table (F-2.4, D24): a gallery image is a `media_assets` row attached here,
 * `restrictOnDelete` so an image in use cannot vanish from under an item, `UNIQUE uq_pim` so a double
 * attach creates no second row. Both pivots are history pivots (decision D19): no `deleted_at`;
 * `portfolio_item_media` keeps `created_by` (who attached it) but no `updated_by` (F-9.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_items')) {
            Schema::create('portfolio_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('portfolio_category_id')->nullable();
                $table->string('title', 180);
                $table->string('slug', 180);
                $table->string('client_name', 150)->nullable();
                // §2.1 deferred link → clients.id (Phase 5). No constraint here, by contract.
                $table->unsignedBigInteger('client_id')->nullable();
                $table->string('summary', 500)->nullable();
                $table->longText('description')->nullable();
                $table->string('technologies_note', 255)->nullable();
                $table->unsignedBigInteger('cover_media_id')->nullable();
                $table->string('project_url', 255)->nullable();
                $table->date('completion_date')->nullable();
                $table->string('status', 32)->default('draft');
                $table->boolean('is_featured')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.7 Indexes.
                $table->unique('slug');
                $table->index(['status', 'is_featured', 'sort_order']);
                $table->index(['portfolio_category_id', 'status']);
                $table->index('completion_date');
                $table->index('cover_media_id');
                $table->index('client_id');
                $table->index('is_featured');

                $table->foreign('portfolio_category_id')->references('id')->on('portfolio_categories')->nullOnDelete();
                $table->foreign('cover_media_id')->references('id')->on('media_assets')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('portfolio_item_technology')) {
            Schema::create('portfolio_item_technology', function (Blueprint $table): void {
                $table->unsignedBigInteger('portfolio_item_id');
                $table->unsignedBigInteger('technology_id');
                $table->unsignedSmallInteger('sort_order')->default(0);

                // Identical in shape to service_technology (§2.5).
                $table->primary(['portfolio_item_id', 'technology_id']);
                $table->index('technology_id');

                $table->foreign('portfolio_item_id')->references('id')->on('portfolio_items')->cascadeOnDelete();
                $table->foreign('technology_id')->references('id')->on('technologies')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('portfolio_item_media')) {
            Schema::create('portfolio_item_media', function (Blueprint $table): void {
                $table->unsignedBigInteger('portfolio_item_id');
                $table->unsignedBigInteger('media_asset_id');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('caption', 255)->nullable();
                $table->timestamps();
                $table->unsignedBigInteger('created_by')->nullable();

                // §2.8 Indexes. uq_pim leads with portfolio_item_id, so it also serves that foreign key.
                $table->unique(['portfolio_item_id', 'media_asset_id'], 'uq_pim');
                $table->index(['portfolio_item_id', 'sort_order']);
                $table->index('media_asset_id');
                $table->index('created_by');

                $table->foreign('portfolio_item_id')->references('id')->on('portfolio_items')->cascadeOnDelete();
                $table->foreign('media_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Children first: both pivots own their keys into portfolio_items (and technologies /
        // media_assets / users); DROP TABLE removes a table's own keys in the same statement.
        Schema::dropIfExists('portfolio_item_media');
        Schema::dropIfExists('portfolio_item_technology');
        Schema::dropIfExists('portfolio_items');
    }
};
