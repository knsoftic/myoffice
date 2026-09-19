<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.16 and §2.15 — blog_posts and the blog_post_blog_tag pivot.
 *
 * `blog_posts.status` casts the shared `ContentStatus` (phase-03 §3, F-5.1 — `PostStatus` does not
 * exist); `published_at` is the future go-live moment for `scheduled` and the live moment for
 * `published`. `slug` is `string(200)` with a single-column UNIQUE. `views_count` is a cached counter,
 * always re-derivable as `count(blog_post_views)`, incremented atomically and never reduced by pruning;
 * it is indexed for the "top viewed" sort (F-9.3). No SEO column (decision D23); the featured image is a
 * `media_assets` row (decision D24).
 *
 * `blog_post_blog_tag` follows the alphabetical singular_singular rule (CLAUDE.md §3): composite primary
 * key, no timestamps, no soft deletes (decision D19 history pivot).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blog_posts')) {
            Schema::create('blog_posts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('blog_category_id')->nullable();
                $table->unsignedBigInteger('author_id')->nullable();
                $table->string('title', 200);
                $table->string('slug', 200);
                $table->string('excerpt', 500)->nullable();
                $table->longText('content');
                $table->unsignedBigInteger('featured_image_media_id')->nullable();
                $table->string('featured_image_alt', 180)->nullable();
                $table->string('status', 32)->default('draft');
                $table->timestamp('published_at')->nullable();
                $table->boolean('is_featured')->default(false);
                $table->unsignedTinyInteger('reading_minutes')->nullable();
                $table->unsignedBigInteger('views_count')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.16 Indexes. The composites lead with blog_category_id / author_id / is_featured, so
                // they serve those foreign keys; published_at gets its own index as its column note says.
                $table->unique('slug');
                $table->index(['status', 'published_at']);
                $table->index(['blog_category_id', 'status', 'published_at']);
                $table->index(['author_id', 'status']);
                $table->index(['is_featured', 'status']);
                $table->index('featured_image_media_id');
                $table->index('views_count');
                $table->index('published_at');

                $table->foreign('blog_category_id')->references('id')->on('blog_categories')->nullOnDelete();
                $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('featured_image_media_id')->references('id')->on('media_assets')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('blog_post_blog_tag')) {
            Schema::create('blog_post_blog_tag', function (Blueprint $table): void {
                $table->unsignedBigInteger('blog_post_id');
                $table->unsignedBigInteger('blog_tag_id');

                // §2.15: composite primary key (leads with blog_post_id, serving that foreign key).
                $table->primary(['blog_post_id', 'blog_tag_id']);
                $table->index('blog_tag_id');

                $table->foreign('blog_post_id')->references('id')->on('blog_posts')->cascadeOnDelete();
                $table->foreign('blog_tag_id')->references('id')->on('blog_tags')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Child first: the pivot owns its keys into blog_posts and blog_tags. The inbound key
        // blog_post_views.blog_post_id belongs to the next Phase 4 migration and is rolled back first.
        Schema::dropIfExists('blog_post_blog_tag');
        Schema::dropIfExists('blog_posts');
    }
};
