<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · §2.7 — pages.
 *
 * Custom pages at their own slug (§101): about, privacy policy, terms, refund policy,
 * course policy and anything else. Snapshot-published: `content` -> `published_content`
 * (decision D22), with `has_unpublished_changes` as a STORED generated column so the
 * badge can never lie (INV-4).
 *
 * Created before menus/menu_items (menu_items.page_id) and before website_sections
 * (website_sections.page_id).
 *
 * Mutable content table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    /**
     * INV-4: `has_unpublished_changes` is derived, never set.
     *
     * MariaDB 10.4 supports STORED (PERSISTENT) generated columns and allows an index
     * on one; the expression is deterministic and references only columns declared above it.
     */
    private const UNPUBLISHED_EXPRESSION =
        'case when `published_hash` is null or `content_hash` <> `published_hash` then 1 else 0 end';

    public function up(): void
    {
        if (Schema::hasTable('pages')) {
            return;
        }

        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 200);
            $table->string('layout', 16)->default('content');
            $table->string('excerpt', 500)->nullable();
            $table->longText('content')->nullable();
            $table->longText('published_content')->nullable();
            $table->char('content_hash', 40)->nullable();
            $table->char('published_hash', 40)->nullable();
            $table->boolean('has_unpublished_changes')->storedAs(self::UNPUBLISHED_EXPRESSION);
            $table->boolean('show_banner')->default(true);
            $table->foreignId('banner_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('banner_heading', 200)->nullable();
            $table->string('banner_subheading', 300)->nullable();
            $table->string('template', 64)->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unpublished_reason', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.7 Keys. A plain UNIQUE(slug), deliberately NOT (slug, deleted_at):
            // MariaDB treats NULLs as distinct, so the composite form would silently
            // allow two live pages on one slug ([D-W3-5], R-5).
            $table->unique('slug', 'uq_pages_slug');
            $table->index(['status', 'published_at']);
            $table->index('is_system');
            $table->index('layout');
        });
    }

    public function down(): void
    {
        // Dropping the table drops the four foreign keys it owns (banner_media_id,
        // published_by, created_by, updated_by). Inbound foreign keys —
        // menu_items.page_id and website_sections.page_id — belong to later Phase 3
        // migrations and are dropped by their own rollbacks first.
        Schema::dropIfExists('pages');
    }
};
