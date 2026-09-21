<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 · file 5 — `demo_classes`: §87's trial class (phase-14-17 §2.16).
 *
 * **A demo is a real booking, so it takes part in clash detection.** It holds a teacher and a room for
 * an hour exactly as a class does, and a demo that could be booked into a room a batch is already in
 * would be discovered by two groups of people arriving at the same door. The two `active_guard` unique
 * indexes are the exact-duplicate backstop underneath Phase 16's `ScheduleClashDetector`: the detector
 * explains the overlap, the index makes the identical double-booking impossible even under a race.
 *
 * **Exactly one subject, enforced by a CHECK.** A demo is for an enquiry, an applicant or an existing
 * student, never two of them. `subject_type` names which, rather than leaving three `IS NOT NULL` tests
 * to whichever query asks — and the pair is kept honest by `chk_dc_one_subject`.
 *
 * **`attendee_name` and `attendee_phone` are snapshots, not a join.** The slip a receptionist prints
 * has to say who is coming; the enquiry behind it may be converted, merged or renamed by then, and a
 * printed slip that changed its own name after the fact would be a document nobody could rely on.
 *
 * `teacher_id`, `classroom_id` and `batch_id` carry no FK here: all three tables ship in Phase 16, and
 * the guarded migration attaches them then ([D-IN-1]).
 */
return new class extends Migration
{
    private const TABLE = 'demo_classes';

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
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('course_inquiry_id')->nullable();
            $table->unsignedBigInteger('student_application_id')->nullable();
            $table->unsignedBigInteger('student_id')->nullable();

            $table->string('attendee_name', 150);
            $table->string('attendee_phone', 32)->nullable();

            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->unsignedBigInteger('classroom_id')->nullable();

            $table->string('delivery_mode', 16)->default('physical');
            $table->string('meeting_url', 500)->nullable();

            $table->date('scheduled_on');
            $table->time('start_time');
            $table->time('end_time');

            $table->string('status', 16)->default('scheduled');
            $table->timestamp('attended_at')->nullable();
            $table->string('attendance_remarks', 500)->nullable();
            $table->unsignedBigInteger('converted_admission_id')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['scheduled_on', 'status'], 'idx_dc_day');
            $table->index(['teacher_id', 'scheduled_on'], 'idx_dc_teacher');
            $table->index(['classroom_id', 'scheduled_on'], 'idx_dc_room');
            $table->index(['course_id', 'status'], 'idx_dc_course');
            $table->index('status', 'idx_dc_status');
            $table->index(['branch_id', 'scheduled_on'], 'idx_dc_branch');
            $table->index('course_inquiry_id', 'idx_dc_inquiry');
            $table->index('student_application_id', 'idx_dc_application');
            $table->index('student_id', 'idx_dc_student');
            $table->index('batch_id', 'idx_dc_batch');
            $table->index('converted_admission_id', 'idx_dc_admission');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('course_inquiry_id', RawSchema::foreignKeyName(self::TABLE, 'course_inquiry_id'))
                ->references('id')->on('course_inquiries')->nullOnDelete();
            $table->foreign('student_application_id', RawSchema::foreignKeyName(self::TABLE, 'student_application_id'))
                ->references('id')->on('student_applications')->nullOnDelete();
            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->nullOnDelete();
            $table->foreign('converted_admission_id', RawSchema::foreignKeyName(self::TABLE, 'converted_admission_id'))
                ->references('id')->on('student_admissions')->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });

        $this->guards();
    }

    /**
     * `active_guard` is 1 only while the demo is `scheduled`, so a cancelled or finished demo leaves
     * the slot free and the same teacher can be booked into it again.
     */
    private function guards(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'active_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'active_guard',
                'TINYINT',
                "CASE WHEN `status` = 'scheduled' THEN 1 ELSE NULL END",
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_dc_teacher_slot', unique: true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_dc_teacher_slot',
                ['teacher_id', 'scheduled_on', 'start_time', 'active_guard']);
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_dc_room_slot', unique: true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_dc_room_slot',
                ['classroom_id', 'scheduled_on', 'start_time', 'active_guard']);
        }
    }

    private function constraints(): void
    {
        $this->ensure('chk_dc_status',
            "`status` IN ('scheduled', 'attended', 'missed', 'converted', 'cancelled')");
        $this->ensure('chk_dc_subject_type',
            "`subject_type` IN ('inquiry', 'application', 'student')");
        $this->ensure('chk_dc_mode',
            "`delivery_mode` IN ('physical', 'online', 'hybrid')");
        $this->ensure('chk_dc_times', '`end_time` > `start_time`');
        // Exactly one subject, and the type column agrees with which key is filled. Two halves of one
        // fact, so both are checked here rather than trusted to whichever service wrote the row.
        $this->ensure('chk_dc_one_subject',
            '((`course_inquiry_id` IS NOT NULL) + (`student_application_id` IS NOT NULL)'
            .' + (`student_id` IS NOT NULL)) = 1');
        $this->ensure('chk_dc_subject_matches',
            "(`subject_type` = 'inquiry' AND `course_inquiry_id` IS NOT NULL)"
            ." OR (`subject_type` = 'application' AND `student_application_id` IS NOT NULL)"
            ." OR (`subject_type` = 'student' AND `student_id` IS NOT NULL)");
        $this->ensure('chk_dc_cancel_reason',
            "`status` <> 'cancelled' OR (`cancellation_reason` IS NOT NULL AND `cancellation_reason` <> '')");
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
