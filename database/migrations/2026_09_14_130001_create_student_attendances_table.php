<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 · file 1 — `student_attendances`: §75's register (phase-14-17 §2.24).
 *
 * **`uq_sa_session_student` is the whole guard against a double-marked roster.** The marking screen is
 * used on a phone at a classroom door, where a slow response and an impatient thumb produce two
 * submissions of the same register; the service upserts on this index, so the second one updates the
 * first rather than doubling everybody's attendance.
 *
 * **`student_batch_enrollment_id` is proof, not convenience (INV-I9).** It says this student was on
 * that batch's roster; without it a row could pair a student and a session that never had anything to
 * do with each other, and the enrolment counters would have no honest way to find their own rows.
 *
 * **There is a `deleted_at` and nothing may use it.** The column exists because `CLAUDE.md` §3 asks
 * for it on a table that is not append-only by category, but INV-I10 says attendance is corrected and
 * never deleted: the model refuses the delete and every policy answers false. A register row that
 * disappears takes a percentage with it and leaves nothing to explain the change.
 *
 * `batch_id` is denormalised so the monthly matrix and the batch summary are one index scan. The
 * register is read far more often than it is written.
 */
return new class extends Migration
{
    private const TABLE = 'student_attendances';

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

            $table->unsignedBigInteger('class_session_id');
            $table->unsignedBigInteger('student_id');
            // The roster membership this mark stands on (INV-I9).
            $table->unsignedBigInteger('student_batch_enrollment_id');
            // Denormalised: the batch reports read this table far more than the service writes it.
            $table->unsignedBigInteger('batch_id');

            $table->string('status', 16);
            $table->time('check_in_time')->nullable();
            // Computed from `check_in_time` and `institute.attendance_grace_minutes`.
            $table->unsignedSmallInteger('minutes_late')->nullable();
            $table->string('remarks', 255)->nullable();

            $table->string('marked_via', 16)->default('manual');
            $table->unsignedBigInteger('marked_by')->nullable();
            $table->timestamp('marked_at');

            // INV-I10: a correction after the lock window leaves all three of these behind.
            $table->timestamp('amended_at')->nullable();
            $table->unsignedBigInteger('amended_by')->nullable();
            $table->string('amendment_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['class_session_id', 'student_id'], 'uq_sa_session_student');

            $table->index(['student_id', 'status'], 'idx_sa_student');
            $table->index(['batch_id', 'status'], 'idx_sa_batch');
            $table->index('student_batch_enrollment_id', 'idx_sa_enrollment');
            $table->index('marked_at', 'idx_sa_marked');
            $table->index('status', 'idx_sa_status');
            $table->index('marked_by', 'idx_sa_marker');
            $table->index('amended_by', 'idx_sa_amender');

            // restrictOnDelete throughout: a register points at things that must still be there for it
            // to mean anything. A session, a student or an enrolment that could be removed underneath
            // an attendance row would leave the row describing nobody.
            $table->foreign('class_session_id', RawSchema::foreignKeyName(self::TABLE, 'class_session_id'))
                ->references('id')->on('class_sessions')->restrictOnDelete();
            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->restrictOnDelete();
            $table->foreign('student_batch_enrollment_id', RawSchema::foreignKeyName(self::TABLE, 'student_batch_enrollment_id'))
                ->references('id')->on('student_batch_enrollments')->restrictOnDelete();
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->restrictOnDelete();

            $table->foreign('marked_by', RawSchema::foreignKeyName(self::TABLE, 'marked_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('amended_by', RawSchema::foreignKeyName(self::TABLE, 'amended_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_sa_status', "`status` IN ('present', 'absent', 'leave', 'late')");
        $this->ensure('chk_sa_via', "`marked_via` IN ('manual', 'bulk', 'import', 'system')");
        // An amendment that names no reason is the thing INV-I10 exists to prevent; the service
        // enforces the window, and this enforces that the three stamps arrive together.
        $this->ensure('chk_sa_amendment',
            "`amended_at` IS NULL OR (`amendment_reason` IS NOT NULL AND `amendment_reason` <> '')");
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
