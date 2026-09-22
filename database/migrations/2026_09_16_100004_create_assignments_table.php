<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19 · file 4 — `assignments` (phase-19-23 §2.6, §80).
 *
 * The gradable event, instantiated from Phase 14's `course_topic_assignments` blueprint when one exists
 * (phase-14-17 [D-IN-6]) — which pre-fills title, description, instructions and `total_marks` from
 * `estimated_marks`. The blueprint says what the syllabus intends; this row says what a batch was
 * actually set, and the two diverge the moment a teacher changes a deadline.
 *
 * **`deadline_at` and `late_cutoff_at` are two different things and the table needs both.** The deadline
 * is when work becomes late; the cutoff is when it stops being accepted at all. Collapsing them would
 * force a choice between "no late work ever" and "late work for ever", and §80 asks for neither.
 * `chk_as_cutoff` keeps the cutoff at or after the deadline, because a cutoff before the deadline would
 * close submissions while the assignment still claimed to be open.
 *
 * **`restrictOnDelete` from `assignment_submissions` is the rule that matters most here** (§2.7): an
 * assignment somebody has submitted to is closed or archived, never deleted. A cascade would take real
 * marked work with it, and `AssignmentStatus::Closed` exists precisely so that stopping collection never
 * requires hiding what everybody was marked on.
 *
 * `total_marks`, `deadline_at` and `submission_type` freeze once a graded submission exists; a later
 * correction needs `assignments.edit`, a mandatory reason, old-and-new logging and a cache recompute —
 * and INV-19-7 keeps already-decided lateness intact through it.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures every CHECK.
 */
return new class extends Migration
{
    private const TABLE = 'assignments';

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

            // Copied from the batch rather than asked for — §2.2 / D11.
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('course_id');
            // An assignment is always for exactly one batch (§80). A course-wide assignment is
            // several rows, which is what makes per-batch deadlines and per-batch caches possible.
            $table->unsignedBigInteger('batch_id');
            // The syllabus blueprint this came from, when it came from one.
            $table->unsignedBigInteger('course_topic_assignment_id')->nullable();
            $table->unsignedBigInteger('course_topic_id')->nullable();
            // restrictOnDelete: §80 puts a teacher's name on it, and a teacher row with live
            // assignments is not removed silently.
            $table->unsignedBigInteger('teacher_id')->nullable();

            $table->string('title', 180);
            // Rich text, sanitised on save.
            $table->longText('description')->nullable();
            $table->text('instructions')->nullable();

            // The brief, on the private disk. Four columns rather than the full StoredFile set: an
            // assignment carries at most one brief and no checksum is wanted for it.
            $table->string('attachment_path', 255)->nullable();
            $table->string('attachment_original_name', 255)->nullable();
            $table->string('attachment_mime_type', 150)->nullable();
            $table->unsignedBigInteger('attachment_size_bytes')->nullable();

            // The ceiling of every mark on this assignment (§80).
            $table->decimal('total_marks', 8, 2);
            // Null = no pass line, marks only.
            $table->decimal('passing_marks', 8, 2)->nullable();

            $table->string('submission_type', 16)->default('file_or_text');
            // Narrows `security.allowed_file_types`, never widens it.
            $table->json('allowed_extensions')->nullable();
            $table->unsignedSmallInteger('max_file_size_mb')->nullable();
            $table->unsignedTinyInteger('max_files')->default(3);

            $table->date('assigned_on');
            $table->dateTime('deadline_at');

            $table->boolean('late_submission_allowed')->default(true);
            // Hard stop. Null = no stop at all while late submission is allowed.
            $table->dateTime('late_cutoff_at')->nullable();
            // Percent of total_marks, deducted once (F-7.1). decimal(8,4) per CLAUDE.md §3.
            $table->decimal('late_penalty_percentage', 8, 4)->default('0.0000');

            $table->boolean('allow_resubmission')->default(true);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            // A teacher may mark a whole batch privately and release it in one go.
            $table->boolean('marks_visible_to_students')->default(true);

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // CACHES. Every one re-derivable, and the verifier re-derives them.
            $table->unsignedSmallInteger('expected_count')->default(0);
            $table->unsignedSmallInteger('submitted_count')->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedSmallInteger('graded_count')->default(0);
            $table->unsignedSmallInteger('missed_count')->default(0);
            $table->decimal('average_marks', 8, 2)->nullable();
            $table->decimal('highest_marks', 8, 2)->nullable();

            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['batch_id', 'status'], 'idx_as_batch');
            $table->index(['course_id', 'status'], 'idx_as_course');
            // The sweeper's query: everything published whose deadline has passed.
            $table->index(['status', 'deadline_at'], 'idx_as_due');
            $table->index(['teacher_id', 'status'], 'idx_as_teacher');
            $table->index('deadline_at', 'idx_as_deadline');
            $table->index('course_topic_assignment_id', 'idx_as_blueprint');
            $table->index(['branch_id', 'status'], 'idx_as_branch');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->restrictOnDelete();
            $table->foreign('course_topic_assignment_id', RawSchema::foreignKeyName(self::TABLE, 'course_topic_assignment_id'))
                ->references('id')->on('course_topic_assignments')->nullOnDelete();
            $table->foreign('course_topic_id', RawSchema::foreignKeyName(self::TABLE, 'course_topic_id'))
                ->references('id')->on('course_topics')->nullOnDelete();
            $table->foreign('teacher_id', RawSchema::foreignKeyName(self::TABLE, 'teacher_id'))
                ->references('id')->on('teachers')->restrictOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        // An assignment worth nothing is not gradable, and a percentage over it is undefined.
        $this->ensure('chk_as_total', '`total_marks` > 0');

        $this->ensure('chk_as_pass',
            '`passing_marks` IS NULL OR (`passing_marks` >= 0 AND `passing_marks` <= `total_marks`)');

        // A cutoff before the deadline would close submissions while the assignment still said it
        // was open.
        $this->ensure('chk_as_cutoff', '`late_cutoff_at` IS NULL OR `late_cutoff_at` >= `deadline_at`');

        $this->ensure('chk_as_penalty', '`late_penalty_percentage` BETWEEN 0 AND 100');

        $this->ensure('chk_as_attempts',
            '`max_attempts` BETWEEN 1 AND 10 AND `max_files` BETWEEN 1 AND 10');

        // The count columns are unsigned already; the CHECK is what the contract names, and it also
        // holds if a later migration ever widens one of them to a signed type.
        $this->ensure('chk_as_counts',
            '`expected_count` >= 0 AND `submitted_count` >= 0 AND `late_count` >= 0'
            .' AND `graded_count` >= 0 AND `missed_count` >= 0');
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
