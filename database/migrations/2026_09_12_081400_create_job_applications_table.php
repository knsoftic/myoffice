<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.19 — job_applications (§16 candidate + the six-stage pipeline state).
 *
 * `cv_path` is the only bare path column of the whole website domain: a **private** artefact on the
 * `local` disk (decision D21), streamed exclusively by `admin.job-applications.cv`. `email` is stored
 * lower-cased and `UNIQUE uq_job_application_per_job(job_opening_id, email)` allows one application per
 * address per opening (the service turns the 1062 into a field error; §12.1 R4). `expected_salary` is
 * money (`decimal(15,2)`). `assigned_to` points at `users.id` — assignment of work (D32). `employee_id`
 * is a **deferred link** (§2.1, F-3.12) to Phase 7's `employees`: indexed, nullable, no foreign key here.
 *
 * `job_opening_id` is `cascadeOnDelete` only as the last-resort net: a force-delete of an opening runs
 * through `JobOpeningService::purge()` so the CV files are removed first. Every foreign key has its own
 * index (F-9.2).
 *
 * Mutable document table → timestamps + softDeletes + blameable (CLAUDE.md §3); a soft delete keeps the
 * CV file so a restore is lossless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_applications')) {
            return;
        }

        Schema::create('job_applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('job_opening_id');
            $table->string('applicant_name', 150);
            $table->string('email', 150);
            $table->string('phone', 32);
            $table->string('whatsapp', 32)->nullable();
            $table->string('city', 100)->nullable();
            $table->unsignedTinyInteger('experience_years')->nullable();
            $table->decimal('expected_salary', 15, 2)->nullable();
            $table->text('cover_letter')->nullable();
            $table->string('portfolio_url', 255)->nullable();
            $table->string('linkedin_url', 255)->nullable();
            $table->string('cv_path', 255);
            $table->string('cv_original_name', 255);
            $table->string('cv_mime', 100);
            $table->unsignedInteger('cv_size');
            $table->string('status', 32)->default('new');
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedBigInteger('status_changed_by')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            // §2.1 deferred link → employees.id (Phase 7's guarded migration). No constraint here.
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->text('internal_notes')->nullable();
            $table->dateTime('interview_at')->nullable();
            $table->string('interview_mode', 32)->nullable();
            $table->string('interview_location', 255)->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->string('source', 32)->default('website');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.19 Indexes. uq_job_application_per_job leads with job_opening_id (serving that foreign
            // key) and (assigned_to, status) serves the reviewer foreign key.
            $table->unique(['job_opening_id', 'email'], 'uq_job_application_per_job');
            $table->index(['status', 'created_at']);
            $table->index(['assigned_to', 'status']);
            $table->index(['job_opening_id', 'status']);
            $table->index('status_changed_by');
            $table->index('employee_id');
            $table->index('email');
            $table->index('source');

            $table->foreign('job_opening_id')->references('id')->on('job_openings')->cascadeOnDelete();
            $table->foreign('status_changed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Nothing references job_applications until Phase 7 (which drops its own key on rollback);
        // DROP TABLE removes the five keys this table owns.
        Schema::dropIfExists('job_applications');
    }
};
