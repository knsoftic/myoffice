<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 · file 5 — `student_topic_progress`: §83's only hand-marked grain (phase-14-17 §2.28).
 *
 * **`source` is a shield, not a label.** Marking a topic covered for a batch fans out to every active
 * enrolment — except rows whose source is `manual`. A teacher who recorded that one student has not
 * grasped something the rest of the class finished must not have that judgement silently overwritten
 * the next time the class-level mark runs, and this column is the only thing standing between the two.
 *
 * **No `deleted_at` ([D-IN-2]).** One row per (progress, topic) under `uq_stp`; a soft-deleted row
 * would sit under that index and turn the next mark into a 1062 nobody could explain. A topic that
 * should stop counting is `skipped`, which takes its weight out of both sides of the percentage —
 * so dropping an uncovered topic *raises* the number, which is what a coordinator expects.
 *
 * `course_module_id` is denormalised so the module roll-up groups without a join, and every recompute
 * touches every topic of an enrolment.
 */
return new class extends Migration
{
    private const TABLE = 'student_topic_progress';

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

            $table->unsignedBigInteger('student_course_progress_id');
            $table->unsignedBigInteger('course_topic_id');
            // Denormalised for the roll-up.
            $table->unsignedBigInteger('course_module_id');

            $table->string('status', 16)->default('pending');
            $table->decimal('completion_percentage', 8, 4)->default('0.0000');
            // How this row got its value — and whether a class-level mark may overwrite it.
            $table->string('source', 16)->default('batch_coverage');

            $table->unsignedBigInteger('marked_by')->nullable();
            $table->timestamp('marked_at')->nullable();
            $table->date('completed_on')->nullable();
            $table->string('remarks', 255)->nullable();

            $table->timestamps();

            $table->unique(['student_course_progress_id', 'course_topic_id'], 'uq_stp');
            $table->index(['course_topic_id', 'status'], 'idx_stp_topic');
            $table->index('status', 'idx_stp_status');
            $table->index('course_module_id', 'idx_stp_module');
            $table->index('marked_by', 'idx_stp_marker');

            $table->foreign('student_course_progress_id', RawSchema::foreignKeyName(self::TABLE, 'student_course_progress_id'))
                ->references('id')->on('student_course_progress')->cascadeOnDelete();
            $table->foreign('course_topic_id', RawSchema::foreignKeyName(self::TABLE, 'course_topic_id'))
                ->references('id')->on('course_topics')->cascadeOnDelete();
            $table->foreign('course_module_id', RawSchema::foreignKeyName(self::TABLE, 'course_module_id'))
                ->references('id')->on('course_modules')->cascadeOnDelete();
            $table->foreign('marked_by', RawSchema::foreignKeyName(self::TABLE, 'marked_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_stp_pct', '`completion_percentage` BETWEEN 0 AND 100');
        $this->ensure('chk_stp_status',
            "`status` IN ('pending', 'in_progress', 'completed', 'skipped')");
        $this->ensure('chk_stp_source',
            "`source` IN ('batch_coverage', 'manual', 'assessment')");
        // A row somebody set by hand says who and when: that is what protects it from the fan-out,
        // and a protected row nobody can attribute is a value with no author.
        $this->ensure('chk_stp_manual_marked',
            "`source` <> 'manual' OR `marked_at` IS NOT NULL");
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
