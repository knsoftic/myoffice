<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.11 — student_reviews (§91).
 *
 * Moderated exactly like `testimonials`: `status` defaults to `pending`, only `approved` is public.
 * `student_id` (→ Phase 15 `students`) and `course_id` (→ Phase 14 `courses`) are **deferred links**
 * (§2.1): indexed, nullable, no foreign key here; the site renders the `student_name` / `course_name`
 * snapshots. `video_url` accepts YouTube/Vimeo hosts only (validated, §6.9). The photo is a
 * `media_assets` row (decision D24). Every foreign key has its own index (F-9.2).
 *
 * Mutable content table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_reviews')) {
            return;
        }

        Schema::create('student_reviews', function (Blueprint $table): void {
            $table->id();
            // §2.1 deferred link → students.id (Phase 15). No constraint here.
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('student_name', 150);
            $table->unsignedBigInteger('student_photo_media_id')->nullable();
            // §2.1 deferred link → courses.id (Phase 14). No constraint here.
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('course_name', 150)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('review');
            $table->string('video_url', 255)->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('source', 32)->default('admin');
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.11 Indexes. (course_id, status) leads with the deferred course id.
            $table->index(['status', 'is_featured', 'sort_order']);
            $table->index(['course_id', 'status']);
            $table->index('student_id');
            $table->index('approved_by');
            $table->index('submitted_by_user_id');
            $table->index('student_photo_media_id');
            $table->index('is_featured');
            $table->index('source');

            $table->foreign('student_photo_media_id')->references('id')->on('media_assets')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('submitted_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Nothing references student_reviews; DROP TABLE removes the five keys it owns.
        Schema::dropIfExists('student_reviews');
    }
};
