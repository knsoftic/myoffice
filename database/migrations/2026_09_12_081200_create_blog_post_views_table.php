<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.17 — blog_post_views, append-only and privacy-preserving.
 *
 * One de-duplicated view per post, per visitor, per dedupe bucket. `visitor_hash` is an HMAC-SHA256 of
 * (IP + user agent) keyed by `APP_KEY`: **the raw IP is never stored here**. `referrer_host` is the host
 * only, never the query string.
 *
 * `UNIQUE uq_blog_post_view_daily(blog_post_id, visitor_hash, viewed_on)` **is** the refresh-spam
 * defence: `BlogViewCounter` inserts and treats a 1062 as "already counted", and only a real insert
 * increments `blog_posts.views_count` (§6.7.1). It also serves the `blog_post_id` foreign key.
 *
 * Audit / log category (decision D19): **no** `deleted_at`, **no** `updated_at`, no blameable pair. The
 * model refuses an Eloquent delete; rows leave only through the daily mass prune
 * (`website.blog_view_prune_days`) or the `cascadeOnDelete` of a force-deleted post.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_post_views')) {
            return;
        }

        Schema::create('blog_post_views', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('blog_post_id');
            $table->char('visitor_hash', 64);
            $table->date('viewed_on');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('referrer_host', 120)->nullable();
            // No updated_at: a view is never edited (§2.17).
            $table->timestamp('created_at')->nullable();

            // §2.17 Indexes. user_id gets its own index as every foreign key does (F-9.2).
            $table->unique(['blog_post_id', 'visitor_hash', 'viewed_on'], 'uq_blog_post_view_daily');
            $table->index(['blog_post_id', 'viewed_on']);
            $table->index('user_id');

            $table->foreign('blog_post_id')->references('id')->on('blog_posts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Nothing references blog_post_views; DROP TABLE removes the two keys it owns.
        Schema::dropIfExists('blog_post_views');
    }
};
