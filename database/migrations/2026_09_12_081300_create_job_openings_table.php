<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.18 — job_openings (module slug `jobs`, §16).
 *
 * **Never named `jobs`** (§12.1 R1): Laravel's database queue owns `jobs`, `job_batches` and
 * `failed_jobs`, and this project runs that queue. The module slug, the `/admin/jobs` URI and the
 * `admin.jobs.*` route names still say "jobs"; the table is `job_openings`. `up()` asserts that an
 * existing `jobs` table is the queue's and never touches it.
 *
 * `salary_min` / `salary_max` are money (`decimal(15,2)`), compared only through `Money::compare()` in
 * the Form Request. `employment_type` casts `App\Enums\EmploymentType` (owned by phase-07 §3, shipped
 * early by Phase 4 — build-order §3 row E9). `department_id` is a **deferred link** (§2.1) to Phase 7's
 * `departments`: indexed, nullable, no foreign key here. `applications_count` is a cached counter,
 * re-derivable as `count(job_applications)`. No SEO column (decision D23). `created_by` is indexed
 * explicitly: it is also the "openings I own" hiring-manager scope of §9.1.3 (F-9.2).
 *
 * Mutable content table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    /** The columns that identify Laravel's queue table. */
    private const QUEUE_COLUMNS = ['queue', 'payload', 'attempts'];

    public function up(): void
    {
        $this->assertJobsTableBelongsToTheQueue();

        if (Schema::hasTable('job_openings')) {
            return;
        }

        Schema::create('job_openings', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 180);
            $table->string('slug', 180);
            $table->string('department', 100)->nullable();
            // §2.1 deferred link → departments.id (Phase 7). No constraint here.
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('location', 150)->nullable();
            $table->string('work_mode', 32)->default('onsite');
            $table->string('employment_type', 32);
            $table->unsignedTinyInteger('experience_min_years')->nullable();
            $table->string('experience_note', 150)->nullable();
            $table->unsignedTinyInteger('openings_count')->default(1);
            $table->decimal('salary_min', 15, 2)->nullable();
            $table->decimal('salary_max', 15, 2)->nullable();
            $table->string('salary_period', 16)->default('monthly');
            $table->boolean('salary_visible')->default(true);
            $table->longText('description');
            $table->longText('requirements')->nullable();
            $table->longText('responsibilities')->nullable();
            $table->json('skills')->nullable();
            $table->date('deadline')->nullable();
            $table->string('status', 32)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('applications_count')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // §2.18 Indexes, plus the column-level indexes the table names.
            $table->unique('slug');
            $table->index(['status', 'deadline']);
            $table->index(['status', 'is_featured', 'sort_order']);
            $table->index(['employment_type', 'status']);
            $table->index('created_by');
            $table->index('department_id');
            $table->index('work_mode');
            $table->index('deadline');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // The inbound key job_applications.job_opening_id belongs to the next Phase 4 migration and is
        // rolled back first. The queue's `jobs` table is never touched in either direction.
        Schema::dropIfExists('job_openings');
    }

    /**
     * R1: if a `jobs` table exists it must be Laravel's queue table. Anything else means a previous
     * build created a careers table under the queue's name, which would corrupt queue processing — stop
     * loudly rather than migrate on top of it.
     */
    private function assertJobsTableBelongsToTheQueue(): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }

        if (! Schema::hasColumns('jobs', self::QUEUE_COLUMNS)) {
            throw new RuntimeException(
                'phase-04 R1: the `jobs` table exists but is not Laravel\'s queue table (missing '
                .implode(', ', self::QUEUE_COLUMNS).'). Careers data belongs in `job_openings`; '
                .'resolve the `jobs` table by hand before migrating. Nothing was changed.'
            );
        }
    }
};
