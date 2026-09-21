<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 · file 2 — `classrooms` and `batches` (§70, §71, phase-14-17 §2.19–§2.20).
 *
 * **A room is `nullOnDelete` everywhere it is referenced.** Losing a classroom must never delete a
 * schedule: it must make the schedule roomless and visible in the "unassigned room" filter, where
 * somebody can give it a new one. A cascade here would silently empty a week of the timetable.
 *
 * **`batches.current_students` is a CACHE and nothing else (INV-I7).** It is written only by
 * `BatchEnrollmentService`, which recounts `status = 'active'` rows under a batch row lock rather
 * than incrementing — an increment that races is how a full batch comes to show a free seat. The
 * nightly `batches:recount` rebuilds it, and a test asserts it equals the count after every scenario.
 *
 * **Three more caches live here for the same reason**: `syllabus_completion_percentage` (Phase 17),
 * `sessions_planned_count` and `sessions_held_count`. None of them decides anything — every rule
 * counts the rows.
 *
 * `teacher_id` is `restrictOnDelete` and `classroom_id` is `nullOnDelete`, and the asymmetry is the
 * point: a teacher who has taught is deactivated rather than removed, while a room is just furniture.
 */
return new class extends Migration
{
    private const CLASSROOMS = 'classrooms';

    private const BATCHES = 'batches';

    public function up(): void
    {
        if (! Schema::hasTable(self::CLASSROOMS)) {
            $this->createClassrooms();
        }

        if (! Schema::hasTable(self::BATCHES)) {
            $this->createBatches();
        }

        $this->constraints();
    }

    private function createClassrooms(): void
    {
        Schema::create(self::CLASSROOMS, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('code', 32);
            $table->string('name', 150);
            $table->string('type', 16)->default('classroom');
            // The SECOND capacity ceiling when a batch is physical (§6.6): a room that seats twenty
            // does not seat twenty-five because somebody typed a bigger number on the batch.
            $table->unsignedSmallInteger('capacity');
            $table->string('location', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('code', 'uq_cr_code');
            $table->index(['branch_id', 'is_active'], 'idx_cr_branch');
            $table->index(['type', 'is_active'], 'idx_cr_type');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::CLASSROOMS, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('created_by', RawSchema::foreignKeyName(self::CLASSROOMS, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::CLASSROOMS, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createBatches(): void
    {
        Schema::create(self::BATCHES, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('code', 32);
            $table->string('name', 150);
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('teacher_id')->nullable();

            $table->date('start_date');
            $table->date('end_date')->nullable();
            // The default weekly pattern `TimetableService::seedFromBatch()` expands into one entry
            // per weekday. The entries are the schedule; this is the shorthand somebody typed.
            $table->json('days')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->unsignedBigInteger('classroom_id')->nullable();
            $table->string('delivery_mode', 16)->default('physical');
            $table->string('meeting_url', 500)->nullable();

            $table->unsignedSmallInteger('student_capacity');
            $table->unsignedSmallInteger('current_students')->default(0);

            $table->string('status', 16)->default('planned');

            $table->decimal('syllabus_completion_percentage', 8, 4)->default(0);
            $table->unsignedSmallInteger('sessions_planned_count')->default(0);
            $table->unsignedSmallInteger('sessions_held_count')->default(0);

            $table->date('completed_on')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('code', 'uq_ba_code');

            $table->index(['course_id', 'status'], 'idx_ba_course');
            $table->index(['teacher_id', 'status'], 'idx_ba_teacher');
            // The public "upcoming batches" strip of §89.
            $table->index(['status', 'start_date'], 'idx_ba_upcoming');
            $table->index(['branch_id', 'status'], 'idx_ba_branch');
            $table->index('classroom_id', 'idx_ba_classroom');
            $table->index('start_date', 'idx_ba_start');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::BATCHES, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::BATCHES, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('teacher_id', RawSchema::foreignKeyName(self::BATCHES, 'teacher_id'))
                ->references('id')->on('teachers')->restrictOnDelete();
            $table->foreign('classroom_id', RawSchema::foreignKeyName(self::BATCHES, 'classroom_id'))
                ->references('id')->on(self::CLASSROOMS)->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::BATCHES, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::BATCHES, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure(self::CLASSROOMS, 'chk_cr_capacity', '`capacity` > 0');
        $this->ensure(self::CLASSROOMS, 'chk_cr_type',
            "`type` IN ('classroom', 'lab', 'hall', 'virtual')");

        $this->ensure(self::BATCHES, 'chk_ba_capacity', '`student_capacity` > 0');
        $this->ensure(self::BATCHES, 'chk_ba_dates', '`end_date` IS NULL OR `end_date` >= `start_date`');
        $this->ensure(self::BATCHES, 'chk_ba_times',
            '`start_time` IS NULL OR `end_time` IS NULL OR `end_time` > `start_time`');
        $this->ensure(self::BATCHES, 'chk_ba_current', '`current_students` >= 0');
        $this->ensure(self::BATCHES, 'chk_ba_status',
            "`status` IN ('planned', 'enrolling', 'running', 'on_hold', 'completed', 'cancelled')");
        $this->ensure(self::BATCHES, 'chk_ba_mode',
            "`delivery_mode` IN ('physical', 'online', 'hybrid')");
        $this->ensure(self::BATCHES, 'chk_ba_cancel_reason',
            "`status` <> 'cancelled' OR (`cancellation_reason` IS NOT NULL AND `cancellation_reason` <> '')");
        $this->ensure(self::BATCHES, 'chk_ba_pct',
            '`syllabus_completion_percentage` BETWEEN 0 AND 100');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::BATCHES);
        Schema::dropIfExists(self::CLASSROOMS);
    }

    private function ensure(string $table, string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check($table, $name, $expression);
        }
    }
};
