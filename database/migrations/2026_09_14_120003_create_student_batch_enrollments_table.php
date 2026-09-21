<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 · file 3 — `student_batch_enrollments`: the roster (phase-14-17 §2.21).
 *
 * **Not a bare pivot.** Attendance, progress and every teacher's student list join through this row,
 * and it carries the lifecycle, the transfer history and five attendance caches. A plain
 * `student_id`/`batch_id` pivot could not answer "was this student in the batch on the day of that
 * class", which is exactly what the register needs to know before it marks anybody absent.
 *
 * **`current_guard` is why a student can be re-enrolled in a batch they once dropped.** The generated
 * column is 1 only while the row is `active`, so `uq_sbe_active` forbids two live seats in one batch
 * and lets any number of finished ones sit beside them. The same guard is what makes
 * `batches.current_students` a count of exactly the active rows.
 *
 * **A transfer is two rows linked both ways.** `uq_sbe_transfer_to` allows an enrollment at most one
 * successor: a chain that forked would make "where did this student go" a question with two answers.
 * Attendance history stays with the row it was recorded against — moving it would rewrite the past.
 *
 * `restrictOnDelete` from students, batches and admissions: a seat that was ever taken is the record
 * that it was taken.
 */
return new class extends Migration
{
    private const TABLE = 'student_batch_enrollments';

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

            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('batch_id');
            // Denormalised from the batch and asserted equal by the service: the course-wise roster
            // and the progress rows read it without a join through `batches`.
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('student_admission_id')->nullable();

            $table->string('roll_number', 16)->nullable();
            $table->string('status', 16)->default('active');

            $table->date('enrolled_on');
            $table->date('completed_on')->nullable();
            $table->date('left_on')->nullable();
            $table->string('leave_reason', 255)->nullable();

            $table->unsignedBigInteger('transferred_from_id')->nullable();
            $table->unsignedBigInteger('transferred_to_id')->nullable();
            $table->string('transfer_reason', 255)->nullable();

            // INV-I6: capacity was deliberately exceeded, by somebody, for a reason.
            $table->boolean('is_overbooked')->default(false);
            $table->string('overbook_reason', 255)->nullable();

            $table->unsignedSmallInteger('sessions_expected_count')->default(0);
            $table->unsignedSmallInteger('present_count')->default(0);
            $table->unsignedSmallInteger('absent_count')->default(0);
            $table->unsignedSmallInteger('leave_count')->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->decimal('attendance_percentage', 8, 4)->default(0);
            $table->decimal('progress_percentage', 8, 4)->default(0);

            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['batch_id', 'roll_number'], 'uq_sbe_roll');
            // At most one successor: a forked transfer chain is two answers to one question.
            $table->unique('transferred_to_id', 'uq_sbe_transfer_to');

            $table->index(['batch_id', 'status'], 'idx_sbe_roster');
            $table->index(['student_id', 'status'], 'idx_sbe_student');
            $table->index(['course_id', 'status'], 'idx_sbe_course');
            $table->index('student_admission_id', 'idx_sbe_admission');
            $table->index('enrolled_on', 'idx_sbe_enrolled');
            $table->index('transferred_from_id', 'idx_sbe_transfer_from');

            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->restrictOnDelete();
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->restrictOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('student_admission_id', RawSchema::foreignKeyName(self::TABLE, 'student_admission_id'))
                ->references('id')->on('student_admissions')->restrictOnDelete();

            $table->foreign('transferred_from_id', RawSchema::foreignKeyName(self::TABLE, 'transferred_from_id'))
                ->references('id')->on(self::TABLE)->nullOnDelete();
            $table->foreign('transferred_to_id', RawSchema::foreignKeyName(self::TABLE, 'transferred_to_id'))
                ->references('id')->on(self::TABLE)->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });

        $this->guards();
    }

    private function guards(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'current_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'current_guard',
                'TINYINT',
                "CASE WHEN `status` = 'active' THEN 1 ELSE NULL END",
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_sbe_active', unique: true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_sbe_active', ['student_id', 'batch_id', 'current_guard']);
        }
    }

    private function constraints(): void
    {
        $this->ensure('chk_sbe_status',
            "`status` IN ('active', 'suspended', 'transferred_out', 'completed', 'dropped', 'cancelled')");
        $this->ensure('chk_sbe_counts',
            '`sessions_expected_count` >= 0 AND `present_count` >= 0 AND `absent_count` >= 0'
            .' AND `leave_count` >= 0 AND `late_count` >= 0');
        $this->ensure('chk_sbe_pct',
            '`attendance_percentage` BETWEEN 0 AND 100 AND `progress_percentage` BETWEEN 0 AND 100');
        // INV-I6: an overbooked seat without a reason is a capacity limit that quietly means nothing.
        $this->ensure('chk_sbe_overbook',
            "`is_overbooked` = 0 OR (`overbook_reason` IS NOT NULL AND `overbook_reason` <> '')");
        $this->ensure('chk_sbe_leave_reason',
            "`status` NOT IN ('dropped', 'suspended') OR (`leave_reason` IS NOT NULL AND `leave_reason` <> '')");
        $this->ensure('chk_sbe_transfer_reason',
            "`status` <> 'transferred_out' OR (`transfer_reason` IS NOT NULL AND `transfer_reason` <> '')");
        $this->ensure('chk_sbe_dates',
            '`left_on` IS NULL OR `left_on` >= `enrolled_on`');
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
