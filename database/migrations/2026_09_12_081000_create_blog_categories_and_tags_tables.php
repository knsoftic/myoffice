<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.13 and §2.14 — blog_categories and blog_tags.
 *
 * `blog_categories` is a flat list (no `parent_id` — the requirement asks for no nesting); no SEO column
 * (decision D23); the image is a `media_assets` row (decision D24). `blog_tags.slug` is the
 * de-duplication key `BlogService::syncTags()` matches on (case-insensitively, trashed rows included).
 *
 * Both are mutable taxonomy tables → timestamps + softDeletes + blameable (CLAUDE.md §3). Created before
 * `blog_posts` and the `blog_post_blog_tag` pivot, which reference them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blog_categories')) {
            Schema::create('blog_categories', function (Blueprint $table): void {
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

                // §2.13 Indexes.
                $table->unique('slug');
                $table->index(['is_active', 'sort_order']);
                $table->index('image_media_id');

                $table->foreign('image_media_id')->references('id')->on('media_assets')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('blog_tags')) {
            Schema::create('blog_tags', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 180);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.14 Indexes.
                $table->unique('slug');
                $table->index('is_active');
            });
        }
    }

    public function down(): void
    {
        // Inbound keys (blog_posts.blog_category_id, blog_post_blog_tag.blog_tag_id) belong to the next
        // Phase 4 migration and are rolled back first.
        Schema::dropIfExists('blog_tags');
        Schema::dropIfExists('blog_categories');
    }
};
