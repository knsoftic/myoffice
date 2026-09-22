<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 · file 3 — `student_course_progress`: §83 at course level (phase-14-17 §2.26).
 *
 * **The grain is the enrolment, not the student** (`uq_scp_enrollment`). Somebody who takes the same
 * course twice — a repeat, or a transfer into a different batch of it — gets two rows, because the
 * second attempt does not start where the first left off. Keying on the student would silently merge
 * the two and make "how far through is she" a question with two answers and one row.
 *
 * **Every number in it is a cache (INV-I11).** `CourseProgressService::recompute()` is the only
 * writer; the four counters and the two weight totals are re-derivable from the topic rows, and
 * `progress:recompute` proves it nightly. The model makes nothing fillable, so a controller cannot
 * become a second writer by accident.
 *
 * **`weight_total` and `weight_completed` are integers, and the percentage is `decimal(8,4)`.** The
 * weights are whole numbers from the outline; only their ratio is fractional, and that ratio goes
 * through bcmath like every other percentage in the system (`CLAUDE.md` §3 — no exception for a
 * "reported" percentage).
 */
return new class extends Migration
{
    private const TABLE = 'student_course_progress';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            // The grain.
            $table->unsignedBigInteger('student_batch_enrollment_id');
            // Denormalised, so a student's report and a course's report are each one index scan.
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('batch_id');

            $table->string('status', 16)->default('pending');
            $table->decimal('completion_percentage', 8, 4)->default('0.0000');

            // The outline as it stood at the last recompute, so "9 of 12 topics" needs no curriculum
            // read — and the nightly recompute is what brings the snapshot forward when it changes.
            $table->unsignedSmallInteger('modules_total')->default(0);
            $table->unsignedSmallInteger('modules_completed')->default(0);
            $table->unsignedSmallInteger('topics_total')->default(0);
            $table->unsignedSmallInteger('topics_completed')->default(0);
            $table->unsignedInteger('weight_total')->default(0);
            $table->unsignedInteger('weight_completed')->default(0);

            $table->date('started_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('student_batch_enrollment_id', 'uq_scp_enrollment');

            $table->index(['student_id', 'status'], 'idx_scp_student');
            $table->index(['course_id', 'status'], 'idx_scp_course');
            $table->index(['batch_id', 'completion_percentage'], 'idx_scp_batch');

            // cascadeOnDelete: progress is a statement about an enrolment, and has nothing to say
            // without it. The enrolment itself is never hard-deleted — it is dropped or cancelled.
            $table->foreign('student_batch_enrollment_id', RawSchema::foreignKeyName(self::TABLE, 'student_batch_enrollment_id'))
                ->references('id')->on('student_batch_enrollments')->cascadeOnDelete();
            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->cascadeOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_scp_pct', '`completion_percentage` BETWEEN 0 AND 100');
        $this->ensure('chk_scp_status',
            "`status` IN ('pending', 'in_progress', 'completed', 'skipped')");
        // A cache that claims more finished than exist is a cache nobody should read.
        $this->ensure('chk_scp_counts',
            '`topics_completed` <= `topics_total` AND `modules_completed` <= `modules_total`'
            .' AND `weight_completed` <= `weight_total`');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
