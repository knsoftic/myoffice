<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 · file 4 — `timetable_entries`: §71's recurring weekly rule (phase-14-17 §2.22).
 *
 * **One row is "this batch, this weekday, this time, from this date until that one".** It is not a
 * dated event; that is `class_sessions`. Keeping the rule and the occurrence apart is what lets a
 * class be cancelled, moved or taught by a substitute without rewriting the pattern it came from — a
 * pure recurrence model has to lie about the past to do any of those.
 *
 * **The three unique indexes are backstops, not the clash check.** Overlap is a range condition and
 * MariaDB cannot express it as a unique index, so `ScheduleClashDetector` does the real work under a
 * row lock and these three catch the cheap case: an identical submission arriving twice. They are
 * guarded on `active_guard`, so ending a rule frees its slot.
 *
 * `effective_to` being NULL means "until the batch ends", which is why every date-window predicate
 * coalesces it to the far future rather than treating NULL as "no window".
 */
return new class extends Migration
{
    private const TABLE = 'timetable_entries';

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
            $table->unsignedBigInteger('batch_id');
            // Denormalised for the course-wise view of §71, asserted equal to the batch's by the service.
            $table->unsignedBigInteger('course_id');
            // Null inherits the batch teacher at generation time, so changing the batch's teacher
            // moves every slot that never named one of its own.
            $table->unsignedBigInteger('teacher_id')->nullable();

            $table->string('day_of_week', 9);
            $table->time('start_time');
            $table->time('end_time');

            $table->unsignedBigInteger('classroom_id')->nullable();
            $table->string('delivery_mode', 16)->default('physical');
            $table->string('meeting_url', 500)->nullable();
            $table->string('notes', 500)->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['teacher_id', 'day_of_week', 'is_active'], 'idx_tte_teacher');
            $table->index(['classroom_id', 'day_of_week', 'is_active'], 'idx_tte_room');
            $table->index(['batch_id', 'day_of_week'], 'idx_tte_batch');
            $table->index(['effective_from', 'effective_to'], 'idx_tte_window');
            $table->index(['branch_id', 'day_of_week'], 'idx_tte_branch');
            $table->index('course_id', 'idx_tte_course');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            // cascadeOnDelete: the rule belongs to the batch and has no meaning without it.
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->cascadeOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('teacher_id', RawSchema::foreignKeyName(self::TABLE, 'teacher_id'))
                ->references('id')->on('teachers')->restrictOnDelete();
            $table->foreign('classroom_id', RawSchema::foreignKeyName(self::TABLE, 'classroom_id'))
                ->references('id')->on('classrooms')->nullOnDelete();

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
                'CASE WHEN `is_active` = 1 THEN 1 ELSE NULL END',
            );
        }

        // The room backstop must exempt exactly what the detector exempts, or it refuses bookings the
        // rule permits: `room_guard` is 1 only while the slot is live AND its mode needs a room.
        if (! Schema::hasColumn(self::TABLE, 'room_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'room_guard',
                'TINYINT',
                "CASE WHEN `is_active` = 1 AND `delivery_mode` <> 'online' THEN 1 ELSE NULL END",
            );
        }

        foreach ([
            'uq_tte_batch' => ['batch_id', 'day_of_week', 'start_time', 'effective_from', 'active_guard'],
            'uq_tte_teacher' => ['teacher_id', 'day_of_week', 'start_time', 'effective_from', 'active_guard'],
            'uq_tte_room' => ['classroom_id', 'day_of_week', 'start_time', 'effective_from', 'room_guard'],
        ] as $name => $columns) {
            if (! RawSchema::indexExists(self::TABLE, $name, unique: true)) {
                RawSchema::uniqueIndex(self::TABLE, $name, $columns);
            }
        }
    }

    private function constraints(): void
    {
        $this->ensure('chk_tte_times', '`end_time` > `start_time`');
        $this->ensure('chk_tte_dates', '`effective_to` IS NULL OR `effective_to` >= `effective_from`');
        $this->ensure('chk_tte_day',
            "`day_of_week` IN ('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday')");
        $this->ensure('chk_tte_mode', "`delivery_mode` IN ('physical', 'online', 'hybrid')");
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
