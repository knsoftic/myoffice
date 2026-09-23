<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 · file 3 — `exam_results` (phase-19-23 §2.12, requirement §82).
 *
 * **INV-20-1: `total_marks` is a snapshot, and that is what makes the ceiling a database fact.**
 * Copying the exam's total onto the row lets `chk_er_marks` compare two columns of the *same* row, so a
 * mark above what the paper was out of is impossible rather than merely validated against. It also
 * means editing the exam later cannot retroactively invalidate a mark already given, and that a result
 * card printed last term still reproduces exactly.
 *
 * **The grade is a snapshot too, and so is the scale.** `grade`, `grade_point` and `percentage` are the
 * row's own columns, not a join — so renaming a band from `A` to `A1` next year cannot rewrite what a
 * student was told, and deactivating a scale cannot blank a printed card (INV-20-4). The foreign keys
 * to the scale and the band are `restrictOnDelete` for the same reason: they are provenance, and
 * provenance that can vanish is not provenance.
 *
 * **`attendance_status` exists because "absent" and "scored zero" are different facts.** A zero is a
 * mark somebody earned; an absence is the lack of one. `chk_er_appeared` and `chk_er_absent` between
 * them say it at the database — marks exist for `appeared` and for nothing else — so an absence can
 * never be stored as a zero that drags the class average down with a number nobody scored.
 *
 * **`uq_er_exam_student` is the hard guard against a double-entered sheet** (INV-20-6). A marker who
 * submits twice upserts on it rather than producing two rows for one student.
 *
 * **Nothing deletes a result.** The policy refuses `delete` and `forceDelete` for every role, and the
 * model refuses the act outright — because `Gate::before` waves a Super Admin past every policy, which
 * is a lesson Phase 19 learned the expensive way (D124). A wrong row is amended with a reason.
 */
return new class extends Migration
{
    private const TABLE = 'exam_results';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->widen();
        $this->constraints();
    }

    /**
     * Converge a table built by an earlier version of this file onto the width `create()` now writes.
     *
     * Nothing is broken at 16 — `debarred` is eight characters — so this is not a repair but a refusal
     * to let a fresh install and an upgraded one disagree about their own schema. The sibling migration
     * needed exactly this and needed it urgently; a divergence that only matters later is one nobody
     * finds until it does.
     */
    private function widen(): void
    {
        $column = DB::selectOne(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, 'attendance_status'],
        );

        if ($column !== null && (int) $column->len < 32) {
            DB::statement('ALTER TABLE `'.self::TABLE.'` MODIFY `attendance_status` VARCHAR(32) NOT NULL DEFAULT \'appeared\'');
        }
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('student_id');
            // Proves the roster: this student was on this batch when they sat it.
            $table->unsignedBigInteger('student_batch_enrollment_id');
            // Denormalised so the batch and course reports are one index scan each.
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('course_id');

            // 32 per CLAUDE.md §3, though the longest case is `appeared` at eight. The sibling
            // migration shipped `exams.status` at 16 and made `results_published` — seventeen
            // characters — unwritable; the convention is a width nobody has to measure against.
            $table->string('attendance_status', 32)->default('appeared');

            // Null for absent / exempt / debarred. See the class note — this is not a zero.
            $table->decimal('obtained_marks', 8, 2)->nullable();
            // INV-20-1's snapshot.
            $table->decimal('total_marks', 8, 2);
            // decimal(8,4) per CLAUDE.md §3, written at the contract's two decimals.
            $table->decimal('percentage', 8, 4)->nullable();

            // Provenance, kept alive by restrictOnDelete.
            $table->unsignedBigInteger('grade_scale_id')->nullable();
            $table->unsignedBigInteger('grade_scale_band_id')->nullable();

            // Snapshot labels: a later band rename never rewrites a printed card.
            $table->string('grade', 8)->nullable();
            $table->decimal('grade_point', 4, 2)->nullable();
            $table->boolean('is_passed')->nullable();

            // Ranked on publish. Ties share a rank and the next rank skips.
            $table->unsignedSmallInteger('position_in_batch')->nullable();
            $table->string('remarks', 500)->nullable();

            $table->unsignedBigInteger('entered_by')->nullable();
            $table->timestamp('entered_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('published_at')->nullable();

            // INV-20-5: a mark changed after publication says who and why.
            $table->timestamp('amended_at')->nullable();
            $table->unsignedBigInteger('amended_by')->nullable();
            $table->string('amendment_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['exam_id', 'student_id'], 'uq_er_exam_student');

            $table->index(['student_id', 'published_at'], 'idx_er_student');
            $table->index(['batch_id', 'exam_id'], 'idx_er_batch');
            // Ranking reads this one.
            $table->index(['exam_id', 'percentage'], 'idx_er_rank');
            $table->index(['exam_id', 'attendance_status'], 'idx_er_attendance');
            $table->index(['course_id', 'is_passed'], 'idx_er_course');
            $table->index('student_batch_enrollment_id', 'idx_er_enrollment');
            $table->index('grade_scale_id', 'idx_er_scale');
            $table->index('grade_scale_band_id', 'idx_er_band');
            $table->index('entered_by', 'idx_er_enterer');
            $table->index('verified_by', 'idx_er_verifier');
            $table->index('amended_by', 'idx_er_amender');

            // restrictOnDelete throughout the spine of the row: an exam, a student, an enrolment, a
            // batch or a course with results against it is never removed out from under them.
            $table->foreign('exam_id', RawSchema::foreignKeyName(self::TABLE, 'exam_id'))
                ->references('id')->on('exams')->restrictOnDelete();
            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->restrictOnDelete();
            $table->foreign('student_batch_enrollment_id', RawSchema::foreignKeyName(self::TABLE, 'student_batch_enrollment_id'))
                ->references('id')->on('student_batch_enrollments')->restrictOnDelete();
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->restrictOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();

            // INV-20-4: the scale and the band behind a result stay alive. They are deactivated, never
            // removed, because the row's own snapshot columns are only half the provenance.
            $table->foreign('grade_scale_id', RawSchema::foreignKeyName(self::TABLE, 'grade_scale_id'))
                ->references('id')->on('grade_scales')->restrictOnDelete();
            $table->foreign('grade_scale_band_id', RawSchema::foreignKeyName(self::TABLE, 'grade_scale_band_id'))
                ->references('id')->on('grade_scale_bands')->restrictOnDelete();

            foreach (['entered_by', 'verified_by', 'amended_by', 'created_by', 'updated_by'] as $column) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    private function constraints(): void
    {
        // §20's "marks never exceed the total", as a database fact rather than a promise.
        $this->ensure('chk_er_marks',
            '`obtained_marks` IS NULL OR (`obtained_marks` >= 0 AND `obtained_marks` <= `total_marks`)');

        $this->ensure('chk_er_total', '`total_marks` > 0');

        // The two halves of "an absence is not a zero": appeared must have marks, nothing else may.
        $this->ensure('chk_er_appeared', "`attendance_status` <> 'appeared' OR `obtained_marks` IS NOT NULL");
        $this->ensure('chk_er_absent', "`attendance_status` = 'appeared' OR `obtained_marks` IS NULL");

        $this->ensure('chk_er_pct', '`percentage` IS NULL OR `percentage` BETWEEN 0 AND 100');
        $this->ensure('chk_er_point', '`grade_point` IS NULL OR `grade_point` >= 0');

        $this->ensure('chk_er_attendance',
            "`attendance_status` IN ('appeared', 'absent', 'exempt', 'debarred')");

        // An amendment says who and why, or it is not an amendment (INV-20-5).
        $this->ensure('chk_er_amendment',
            '`amended_at` IS NULL OR (`amended_by` IS NOT NULL AND `amendment_reason` IS NOT NULL)');
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
