<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · §2.14 — cms_revisions.
 *
 * One append-only content snapshot per target (decision D22): sections, pages, and any
 * later draft/publish entity, through the `revisionable` morph. Published snapshots
 * (`is_published_snapshot = true`) are never pruned; `website.revision_keep` bounds the rest.
 *
 * Append-only category → **no `deleted_at`**, no `updated_at` and no `updated_by`, per
 * decision D19 (CLAUDE.md §3) and §2.14 / §12.2 Q1: a revision is never edited and
 * never deleted, so the model carries a `deleting` guard instead. It keeps only
 * `created_by` and `created_at`.
 *
 * Depends on `users` only, so its position in the batch is free; it is placed late so a
 * rollback removes it before the tables it snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cms_revisions')) {
            return;
        }

        Schema::create('cms_revisions', function (Blueprint $table): void {
            $table->id();
            // Written out rather than declared with morphs() so idx_rev_target below is
            // the only index on the pair: morphs() would add a second index on
            // (revisionable_type, revisionable_id), which is already the leading prefix
            // of idx_rev_target. There is deliberately no FK — the morph spans tables.
            $table->string('revisionable_type', 255);
            $table->unsignedBigInteger('revisionable_id');
            $table->string('event', 24);
            $table->longText('snapshot');
            $table->char('content_hash', 40);
            $table->boolean('is_published_snapshot')->default(false);
            $table->string('label', 150)->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // No `updated_at`: a revision is never edited (§2.14).
            $table->timestamp('created_at')->nullable();

            // §2.14 Keys.
            $table->index(['revisionable_type', 'revisionable_id', 'id'], 'idx_rev_target');
            $table->index('is_published_snapshot');
        });
    }

    public function down(): void
    {
        // DROP TABLE removes the one foreign key this table owns (created_by) in the
        // same statement. Nothing references cms_revisions.
        Schema::dropIfExists('cms_revisions');
    }
};
