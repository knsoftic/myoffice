<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 · file 5 — `class_sessions`: the dated occurrence (phase-14-17 §2.23).
 *
 * **[D-IN-10] Attendance, topic coverage and cancellations attach to a session, not to a recurring
 * rule.** §71 describes only the weekly pattern, but §75 needs daily attendance, §83 needs "which
 * class covered which topic", and real institutes cancel, reschedule and substitute. A pure
 * recurrence model cannot record any of those without lying about the past.
 *
 * **`uq_cs_generated` is what makes generation idempotent.** The scheduled job and a manual run can
 * overlap, or the job can run twice after a retry, and the second insert is a 1062 the generator
 * ignores rather than a duplicate class on somebody's timetable. It is guarded on `active_guard`, so
 * a cancelled session frees its slot and a replacement can be generated.
 *
 * **`teacher_id` is who actually takes it; `original_teacher_id` is who was supposed to.** A
 * substitution that overwrote one column would make "how many classes did this teacher miss" a
 * question with no answer, which is exactly what the §99 report is asked.
 *
 * `attendance_marked_at` being NULL is the definition of "unmarked" and drives the daily sweep. It is
 * a timestamp rather than a boolean because "when" is the part somebody follows up on.
 */
return new class extends Migration
{
    private const TABLE = 'class_sessions';

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

            $table->unsignedBigInteger('branch_id')->nullable();
            // Null = a one-off extra class that belongs to no weekly rule.
            $table->unsignedBigInteger('timetable_entry_id')->nullable();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->unsignedBigInteger('original_teacher_id')->nullable();
            $table->unsignedBigInteger('classroom_id')->nullable();

            $table->date('session_date');
            $table->time('start_time');
            $table->time('end_time');
            // "Class 12 of 40" — counted at generation from the batch's prior sessions.
            $table->unsignedSmallInteger('sequence_no')->nullable();

            $table->string('delivery_mode', 16)->default('physical');
            $table->string('meeting_url', 500)->nullable();
            $table->string('title', 180)->nullable();

            $table->unsignedBigInteger('course_topic_id')->nullable();
            $table->unsignedBigInteger('course_lecture_id')->nullable();

            $table->string('status', 16)->default('scheduled');
            $table->string('cancellation_reason', 24)->nullable();
            $table->string('cancellation_detail', 255)->nullable();
            $table->unsignedBigInteger('rescheduled_to_id')->nullable();
            $table->unsignedBigInteger('rescheduled_from_id')->nullable();

            $table->unsignedSmallInteger('expected_count')->default(0);
            $table->unsignedSmallInteger('present_count')->default(0);
            $table->unsignedSmallInteger('absent_count')->default(0);
            $table->unsignedSmallInteger('leave_count')->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);

            $table->timestamp('attendance_marked_at')->nullable();
            $table->unsignedBigInteger('attendance_marked_by')->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('rescheduled_to_id', 'uq_cs_resched');

            $table->index(['session_date', 'status'], 'idx_cs_day');
            $table->index(['batch_id', 'session_date'], 'idx_cs_batch');
            $table->index(['teacher_id', 'session_date'], 'idx_cs_teacher');
            $table->index(['classroom_id', 'session_date'], 'idx_cs_room');
            // The unmarked sweep: a held session with no `attendance_marked_at`.
            $table->index(['status', 'attendance_marked_at'], 'idx_cs_unmarked');
            $table->index(['branch_id', 'session_date'], 'idx_cs_branch');
            $table->index('course_topic_id', 'idx_cs_topic');
            $table->index('course_id', 'idx_cs_course');
            $table->index('timetable_entry_id', 'idx_cs_entry');
            $table->index('original_teacher_id', 'idx_cs_original_teacher');
            $table->index('course_lecture_id', 'idx_cs_lecture');
            $table->index('attendance_marked_by', 'idx_cs_marker');
            $table->index('rescheduled_from_id', 'idx_cs_resched_from');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            // nullOnDelete, not cascade: ending a weekly rule must not delete the classes it produced.
            $table->foreign('timetable_entry_id', RawSchema::foreignKeyName(self::TABLE, 'timetable_entry_id'))
                ->references('id')->on('timetable_entries')->nullOnDelete();
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->restrictOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('teacher_id', RawSchema::foreignKeyName(self::TABLE, 'teacher_id'))
                ->references('id')->on('teachers')->restrictOnDelete();
            $table->foreign('original_teacher_id', RawSchema::foreignKeyName(self::TABLE, 'original_teacher_id'))
                ->references('id')->on('teachers')->nullOnDelete();
            $table->foreign('classroom_id', RawSchema::foreignKeyName(self::TABLE, 'classroom_id'))
                ->references('id')->on('classrooms')->nullOnDelete();
            $table->foreign('course_topic_id', RawSchema::foreignKeyName(self::TABLE, 'course_topic_id'))
                ->references('id')->on('course_topics')->nullOnDelete();
            $table->foreign('course_lecture_id', RawSchema::foreignKeyName(self::TABLE, 'course_lecture_id'))
                ->references('id')->on('course_lectures')->nullOnDelete();

            $table->foreign('rescheduled_to_id', RawSchema::foreignKeyName(self::TABLE, 'rescheduled_to_id'))
                ->references('id')->on(self::TABLE)->nullOnDelete();
            $table->foreign('rescheduled_from_id', RawSchema::foreignKeyName(self::TABLE, 'rescheduled_from_id'))
                ->references('id')->on(self::TABLE)->nullOnDelete();

            $table->foreign('attendance_marked_by', RawSchema::foreignKeyName(self::TABLE, 'attendance_marked_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });

        $this->guards();
    }

    private function guards(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'active_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'active_guard',
                'TINYINT',
                "CASE WHEN `status` IN ('scheduled', 'held') THEN 1 ELSE NULL END",
            );
        }

        // Same exemption as the timetable's: an online class occupies no room, so two of them may
        // name the same meeting link at the same hour.
        if (! Schema::hasColumn(self::TABLE, 'room_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'room_guard',
                'TINYINT',
                "CASE WHEN `status` IN ('scheduled', 'held') AND `delivery_mode` <> 'online' THEN 1 ELSE NULL END",
            );
        }

        foreach ([
            // The one that makes generation idempotent.
            'uq_cs_generated' => ['timetable_entry_id', 'session_date', 'active_guard'],
            'uq_cs_batch_slot' => ['batch_id', 'session_date', 'start_time', 'active_guard'],
            'uq_cs_teacher_slot' => ['teacher_id', 'session_date', 'start_time', 'active_guard'],
            'uq_cs_room_slot' => ['classroom_id', 'session_date', 'start_time', 'room_guard'],
        ] as $name => $columns) {
            if (! RawSchema::indexExists(self::TABLE, $name, unique: true)) {
                RawSchema::uniqueIndex(self::TABLE, $name, $columns);
            }
        }
    }

    private function constraints(): void
    {
        $this->ensure('chk_cs_times', '`end_time` > `start_time`');
        $this->ensure('chk_cs_counts',
            '`expected_count` >= 0 AND `present_count` >= 0 AND `absent_count` >= 0'
            .' AND `leave_count` >= 0 AND `late_count` >= 0');
        $this->ensure('chk_cs_status',
            "`status` IN ('scheduled', 'held', 'cancelled', 'rescheduled')");
        $this->ensure('chk_cs_mode', "`delivery_mode` IN ('physical', 'online', 'hybrid')");
        $this->ensure('chk_cs_cancel_reason',
            "`cancellation_reason` IS NULL OR `cancellation_reason` IN ('holiday', 'teacher_unavailable',"
            ." 'classroom_unavailable', 'low_attendance', 'technical', 'batch_on_hold', 'other')");
        // §2.30.9 makes the detail mandatory in the service; here it is mandatory of the data.
        $this->ensure('chk_cs_cancel_detail',
            "`status` <> 'cancelled' OR (`cancellation_detail` IS NOT NULL AND `cancellation_detail` <> '')");
        // A rescheduled session names its successor, or it is simply a class that vanished.
        $this->ensure('chk_cs_resched',
            "`status` <> 'rescheduled' OR `rescheduled_to_id` IS NOT NULL");
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
