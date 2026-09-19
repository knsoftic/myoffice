<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.12 — success_stories (§92).
 *
 * Staff-authored, so `status` is `ContentStatus` (default `draft`) with no approval queue. No slug and
 * no detail route: the requirement defines no success-story page. `student_id` (→ Phase 15) and
 * `course_id` (→ Phase 14) are **deferred links** (§2.1): indexed, nullable, no foreign key here. The
 * photo is a `media_assets` row (decision D24).
 *
 * Mutable content table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('success_stories')) {
            return;
        }

        Schema::create('success_stories', function (Blueprint $table): void {
            $table->id();
            // §2.1 deferred link → students.id (Phase 15). No constraint here.
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('student_name', 150);
            $table->unsignedBigInteger('photo_media_id')->nullable();
            // §2.1 deferred link → courses.id (Phase 14). No constraint here.
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('course_name', 150)->nullable();
            $table->string('headline', 180)->nullable();
            $table->longText('story');
            $table->string('achievement', 255)->nullable();
            $table->string('company_name', 150)->nullable();
            $table->string('platform', 100)->nullable();
            $table->string('video_url', 255)->nullable();
            $table->string('status', 32)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.12 Indexes, plus the column-level indexes the table names.
            $table->index(['status', 'is_featured', 'sort_order']);
            $table->index('photo_media_id');
            $table->index('student_id');
            $table->index('course_id');
            $table->index('is_featured');

            $table->foreign('photo_media_id')->references('id')->on('media_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Nothing references success_stories; DROP TABLE removes the three keys it owns.
        Schema::dropIfExists('success_stories');
    }
};
