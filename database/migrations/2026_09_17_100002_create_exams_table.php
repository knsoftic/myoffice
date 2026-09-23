<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 · file 2 — `exams` (phase-19-23 §2.11, requirement §81).
 *
 * **`active_guard` is a generated STORED column and it does two jobs at once.** `uq_ex_batch_slot`
 * stops a batch sitting two exams in the same slot — but a *cancelled* exam must release its slot for
 * the replacement, and it must do so without being deleted, because "what happened to the exam I sat"
 * is a question a student asks. The guard is `1` for every live status and **NULL** for `cancelled`,
 * and MariaDB's tolerance of NULLs in a unique index is what lets the cancelled row keep its date, its
 * reason and any results already entered while the slot reads as free.
 *
 * **Publication freezes four columns**, and the model hook is what enforces it: once
 * `results_published_at` is set, `total_marks`, `passing_marks`, `grade_scale_id` and `scheduled_date`
 * cannot move. A result card that was printed and handed over has to stay reproducible, and every one
 * of those four is an input to it. A genuine correction is `unpublish()` with a reason, then an
 * amendment — never an edit that silently restates what a class was measured against.
 *
 * **Double-booking is not checked here.** `ScheduleClashDetector::check(SlotCandidate)` does it in one
 * generic call against `class_sessions`, `demo_classes` and other exams (phase-14-17 §6.7, D47) — a
 * CHECK cannot see other tables, and three subject-specific checks would be three places to forget.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures everything else.
 */
return new class extends Migration
{
    private const TABLE = 'exams';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->guard();
        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            // Copied from the batch rather than asked for — §2.2 / D11.
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('batch_id');

            // §81's six kinds, no seventh.
            $table->string('exam_type', 24);
            $table->string('name', 180);

            // What it assesses — and the hook for §83's progress marking (§6.12).
            $table->unsignedBigInteger('course_topic_id')->nullable();
            // restrictOnDelete: an examiner with live exams is not removed silently.
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->unsignedBigInteger('classroom_id')->nullable();

            $table->string('delivery_mode', 16)->default('physical');
            $table->string('meeting_url', 500)->nullable();

            $table->date('scheduled_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            $table->decimal('total_marks', 8, 2);
            $table->decimal('passing_marks', 8, 2);

            // Share of the course's aggregate grade; null = weighted equally with its peers. Read by
            // CertificateService when the grade source is `weighted_average`. decimal(8,4) per §3.
            $table->decimal('weight_percentage', 8, 4)->nullable();

            // Null = fall back to `institute.default_grade_scale_id`. restrictOnDelete keeps a scale
            // alive while an exam still points at it (INV-20-4).
            $table->unsignedBigInteger('grade_scale_id')->nullable();

            $table->text('instructions')->nullable();

            // 32, per CLAUDE.md §3 — and not because the convention says so in the abstract.
            // `ExamStatus::ResultsPublished` is `results_published`, which is **seventeen characters**:
            // at varchar(16) every other status fitted and the one that matters most was refused by
            // the database with "Data too long", so an institute could mark, check and then never
            // publish. The convention exists because nobody measures their enum's longest case.
            $table->string('status', 32)->default('draft');

            $table->timestamp('results_published_at')->nullable();
            $table->unsignedBigInteger('results_published_by')->nullable();
            // The §2.28.4 four-eyes step, when `institute.result_publish_requires_verification` is on.
            $table->timestamp('results_verified_at')->nullable();
            $table->unsignedBigInteger('results_verified_by')->nullable();

            // CACHES. Every one re-derivable, and the verifier re-derives them.
            $table->unsignedSmallInteger('expected_count')->default(0);
            $table->unsignedSmallInteger('results_entered_count')->default(0);
            $table->unsignedSmallInteger('appeared_count')->default(0);
            $table->unsignedSmallInteger('absent_count')->default(0);
            $table->unsignedSmallInteger('passed_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->decimal('highest_marks', 8, 2)->nullable();
            $table->decimal('lowest_marks', 8, 2)->nullable();
            $table->decimal('average_marks', 8, 2)->nullable();
            $table->decimal('average_percentage', 8, 4)->nullable();

            // Mandatory on `cancelled` — chk_ex_cancelled says so.
            $table->string('cancellation_reason', 255)->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['batch_id', 'scheduled_date'], 'idx_ex_batch');
            $table->index(['course_id', 'exam_type'], 'idx_ex_course');
            // The exam calendar.
            $table->index(['status', 'scheduled_date'], 'idx_ex_calendar');
            $table->index(['teacher_id', 'scheduled_date'], 'idx_ex_teacher');
            $table->index(['classroom_id', 'scheduled_date'], 'idx_ex_room');
            $table->index(['branch_id', 'scheduled_date'], 'idx_ex_branch');
            $table->index('course_topic_id', 'idx_ex_topic');
            $table->index('results_published_at', 'idx_ex_published');
            $table->index('grade_scale_id', 'idx_ex_scale');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->restrictOnDelete();
            $table->foreign('course_topic_id', RawSchema::foreignKeyName(self::TABLE, 'course_topic_id'))
                ->references('id')->on('course_topics')->nullOnDelete();
            $table->foreign('teacher_id', RawSchema::foreignKeyName(self::TABLE, 'teacher_id'))
                ->references('id')->on('teachers')->restrictOnDelete();
            $table->foreign('classroom_id', RawSchema::foreignKeyName(self::TABLE, 'classroom_id'))
                ->references('id')->on('classrooms')->nullOnDelete();
            $table->foreign('grade_scale_id', RawSchema::foreignKeyName(self::TABLE, 'grade_scale_id'))
                ->references('id')->on('grade_scales')->restrictOnDelete();

            $table->foreign('results_published_by', RawSchema::foreignKeyName(self::TABLE, 'results_published_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('results_verified_by', RawSchema::foreignKeyName(self::TABLE, 'results_verified_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /** See the class note: NULL for a cancelled exam is what frees the batch's slot. */
    private function guard(): void
    {
        // Widened on every run, not only on create. A database migrated while `status` was
        // varchar(16) can hold every case except `results_published`, and would fail on the one write
        // that matters — so the repair has to reach an existing table, not just a new one. The
        // generated column below reads `status`, so the widening goes first.
        if ($this->statusIsTooNarrow()) {
            DB::statement('ALTER TABLE `'.self::TABLE.'` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT \'draft\'');
        }

        if (! Schema::hasColumn(self::TABLE, 'active_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'active_guard',
                'tinyint',
                "CASE WHEN `status` <> 'cancelled' THEN 1 ELSE NULL END",
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_ex_batch_slot', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_ex_batch_slot', [
                'batch_id', 'scheduled_date', 'start_time', 'active_guard',
            ]);
        }
    }

    /**
     * Asked of the live schema rather than assumed, because `guard()` runs on databases built by an
     * earlier version of this same file. A MODIFY on a table that is already wide enough is harmless
     * but rewrites the table, which on a large `exams` is not free.
     */
    private function statusIsTooNarrow(): bool
    {
        $length = DB::selectOne(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, 'status'],
        );

        return $length !== null && (int) $length->len < 32;
    }

    private function constraints(): void
    {
        $this->ensure('chk_ex_total', '`total_marks` > 0');
        $this->ensure('chk_ex_pass', '`passing_marks` >= 0 AND `passing_marks` <= `total_marks`');
        $this->ensure('chk_ex_times', '`start_time` IS NULL OR `end_time` IS NULL OR `end_time` > `start_time`');
        $this->ensure('chk_ex_duration', '`duration_minutes` IS NULL OR `duration_minutes` > 0');
        $this->ensure('chk_ex_weight', '`weight_percentage` IS NULL OR `weight_percentage` BETWEEN 0 AND 100');

        // An exam called off without a reason leaves the batch with no answer to the only question
        // they will ask about it.
        $this->ensure('chk_ex_cancelled', "`status` <> 'cancelled' OR `cancellation_reason` IS NOT NULL");

        $this->ensure('chk_ex_counts',
            '`expected_count` >= 0 AND `results_entered_count` >= 0 AND `appeared_count` >= 0'
            .' AND `absent_count` >= 0 AND `passed_count` >= 0 AND `failed_count` >= 0');

        $this->ensure('chk_ex_avg_pct', '`average_percentage` IS NULL OR `average_percentage` BETWEEN 0 AND 100');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            if (RawSchema::indexExists(self::TABLE, 'uq_ex_batch_slot', true)) {
                RawSchema::dropIndex(self::TABLE, 'uq_ex_batch_slot');
            }

            if (Schema::hasColumn(self::TABLE, 'active_guard')) {
                RawSchema::dropColumn(self::TABLE, 'active_guard');
            }
        }

        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
