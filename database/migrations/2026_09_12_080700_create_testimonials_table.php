<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.10 — testimonials (clients **and** students, §14).
 *
 * Moderated: `status` defaults to `pending` and only `approved` is ever public (§9.2). `rating` is an
 * unsigned tinyint 1–5 (not a percentage), validated in the Form Request. `client_id` and `student_id`
 * are **deferred links** (§2.1) to Phase 5's `clients` and Phase 15's `students`: indexed, nullable, no
 * foreign key here. The author photo is a `media_assets` row (decision D24). Every foreign key has its
 * own index (F-9.2).
 *
 * Mutable content table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('testimonials')) {
            return;
        }

        Schema::create('testimonials', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32)->default('client');
            $table->string('author_name', 150);
            $table->unsignedBigInteger('author_photo_media_id')->nullable();
            $table->string('author_designation', 150)->nullable();
            $table->string('author_company', 150)->nullable();
            $table->string('course_name', 150)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('review');
            $table->date('review_date')->nullable();
            // §2.1 deferred links → clients.id (Phase 5) / students.id (Phase 15). No constraint here.
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('student_id')->nullable();
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

            // §2.10 Indexes.
            $table->index(['status', 'type', 'sort_order']);
            $table->index(['status', 'is_featured']);
            $table->index(['type', 'client_id']);
            $table->index(['type', 'student_id']);
            $table->index('approved_by');
            $table->index('submitted_by_user_id');
            $table->index('author_photo_media_id');
            // Column-level indexes the table names (the deferred ids lead their own index so the
            // owning phase's constraint needs no new one; is_featured and source are filters).
            $table->index('client_id');
            $table->index('student_id');
            $table->index('is_featured');
            $table->index('source');

            $table->foreign('author_photo_media_id')->references('id')->on('media_assets')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('submitted_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Nothing references testimonials; DROP TABLE removes the five keys it owns.
        Schema::dropIfExists('testimonials');
    }
};
