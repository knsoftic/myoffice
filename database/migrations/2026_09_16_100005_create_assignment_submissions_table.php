<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19 · file 5 — `assignment_submissions` (phase-19-23 §2.7, §80, INV-19-5, INV-19-7).
 *
 * **A resubmission never overwrites its predecessor.** The old attempt keeps its files, its marks, its
 * timestamps and its own row, and is marked `superseded`. That is what makes "the student says they
 * submitted on time" answerable a term later, and it is why `superseded_by_id` points forward rather
 * than a `version` column pointing at nothing.
 *
 * **INV-19-5 is enforced by `current_guard`, a generated STORED column, for the usual MariaDB reason.**
 * The rule is one *live* submission per (assignment, student) — but the natural way to write that,
 * a partial index, does not exist here, and a plain unique on `(assignment_id, student_id)` would forbid
 * the history the table is built to keep. `current_guard` is `1` for every live status and **NULL** for
 * `superseded`; MariaDB permits unlimited NULLs in a unique index, so `uq_as_live` lets the history
 * stack while allowing exactly one live row. The property that makes NULLs a hazard everywhere else is
 * the property that makes them work here — which is why the expression is written to produce NULL
 * deliberately rather than as a convenience.
 *
 * **`final_marks` is generated, not written.** `GREATEST(obtained - penalty, 0)` computed in the database
 * cannot drift from its inputs and cannot be edited into disagreeing with them. A late penalty that made
 * a mark negative would also be a mark below the floor every report assumes, so the clamp lives here
 * rather than in whichever service happened to write the row.
 *
 * **`total_marks` is a snapshot, and `chk_asub_marks` is §80's ceiling as a database fact.** Copying the
 * assignment's total onto the row is the same device as INV-20-1: it lets the CHECK compare two columns
 * of the *same* row, so a mark above the ceiling is impossible rather than merely validated against. It
 * also means a later correction to the assignment's total cannot retroactively invalidate marks already
 * given — the row still knows what it was marked out of.
 *
 * **`is_late` is decided once, at insert (INV-19-7)**, never recomputed. A teacher extending a deadline
 * afterwards is not a statement that nobody was ever late; it is a new deadline for whoever has not
 * submitted yet. Recomputing would quietly rewrite history, and the late count with it.
 *
 * **No route ever deletes a submission** (§2.7): the policy returns false for `delete` and `forceDelete`
 * for every role, and `restrictOnDelete` on both parents backs it at the database. A student may
 * `withdraw` only while the row is still a draft.
 */
return new class extends Migration
{
    private const TABLE = 'assignment_submissions';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->guards();
        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('student_id');
            // Proves the roster: this student was on this batch when they submitted (INV-19-5).
            // Without it, an un-enrolled student's old submission would have no evidence of ever
            // having been entitled to submit.
            $table->unsignedBigInteger('student_batch_enrollment_id');
            // Denormalised from the assignment so the batch report is one index scan.
            $table->unsignedBigInteger('batch_id');

            $table->unsignedTinyInteger('attempt_no')->default(1);

            // §80 "or text". Sanitised on write and rendered as text, never as HTML.
            $table->longText('submission_text')->nullable();
            $table->unsignedTinyInteger('files_count')->default(0);

            $table->string('status', 16)->default('draft');
            // Null while draft — chk_asub_submitted is what keeps those two in step.
            $table->timestamp('submitted_at')->nullable();

            // Decided once, at insert. Never recomputed (INV-19-7).
            $table->boolean('is_late')->default(false);
            $table->unsignedInteger('minutes_late')->nullable();

            // One name across the system: the same column name as exam_results.obtained_marks
            // (F-5.10), so "what did they score" is one question with one answer everywhere.
            $table->decimal('obtained_marks', 8, 2)->nullable();
            // The late deduction actually applied — not the rule, the amount.
            $table->decimal('penalty_marks', 8, 2)->default('0.00');

            // Snapshot of the assignment's total at submission/grading time. See the class note:
            // this is what lets chk_asub_marks compare two columns of one row.
            $table->decimal('total_marks', 8, 2);
            // Written only by AssignmentGradeCalculator (F-7.1). decimal(8,4) per CLAUDE.md §3.
            $table->decimal('percentage', 8, 4)->nullable();
            // Null when the assignment has no pass line at all.
            $table->boolean('is_passed')->nullable();

            $table->text('feedback')->nullable();
            $table->string('feedback_file_path', 255)->nullable();
            $table->string('feedback_file_original_name', 255)->nullable();

            $table->unsignedBigInteger('graded_by')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            // When the student may see the marks. Separate from graded_at so a batch can be marked
            // over a week and released in one moment.
            $table->timestamp('marks_released_at')->nullable();

            // The resubmission that replaced this row. Forward-pointing, and unique: one predecessor
            // per successor, so the chain cannot fork.
            $table->unsignedBigInteger('superseded_by_id')->nullable();

            // A post-release mark change carries its reason and its author — INV-20-5's discipline,
            // applied to assignments. A mark a student has already seen does not change quietly.
            $table->timestamp('amended_at')->nullable();
            $table->unsignedBigInteger('amended_by')->nullable();
            $table->string('amendment_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['assignment_id', 'status'], 'idx_asub_assignment');
            $table->index(['student_id', 'status'], 'idx_asub_student');
            $table->index(['batch_id', 'status'], 'idx_asub_batch');
            $table->index('graded_by', 'idx_asub_grader');
            $table->index('submitted_at', 'idx_asub_submitted');
            $table->index(['status', 'is_late'], 'idx_asub_late');
            $table->index('student_batch_enrollment_id', 'idx_asub_enrollment');
            $table->index('amended_by', 'idx_asub_amender');
            // `superseded_by_id` gets its own index even though `uq_as_superseded` covers it. InnoDB
            // satisfies the foreign key with the unique one, and `down()` then cannot drop that index
            // (error 1553) — so the migration stops being reversible. This is **D112** again, in the
            // phase after the one that learned it.
            $table->index('superseded_by_id', 'idx_asub_superseded');

            // restrictOnDelete on both parents: an assignment or a student with submitted work is
            // never removed out from under it. §2.7 states it for the assignment; the same reason
            // holds for the student, whose marks are part of somebody's record.
            $table->foreign('assignment_id', RawSchema::foreignKeyName(self::TABLE, 'assignment_id'))
                ->references('id')->on('assignments')->restrictOnDelete();
            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->restrictOnDelete();
            $table->foreign('student_batch_enrollment_id', RawSchema::foreignKeyName(self::TABLE, 'student_batch_enrollment_id'))
                ->references('id')->on('student_batch_enrollments')->restrictOnDelete();
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->restrictOnDelete();

            $table->foreign('graded_by', RawSchema::foreignKeyName(self::TABLE, 'graded_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('amended_by', RawSchema::foreignKeyName(self::TABLE, 'amended_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('superseded_by_id', RawSchema::foreignKeyName(self::TABLE, 'superseded_by_id'))
                ->references('id')->on(self::TABLE)->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * The two generated columns and the three unique indexes. Raw SQL, and it **fails loudly** if the
     * server rejects it (spine R-3): a `uq_as_live` that silently did not apply would let one student
     * hold two live submissions, which is the single thing this table exists to prevent.
     */
    private function guards(): void
    {
        // Ensured on every run: a database migrated before this index existed still has its foreign
        // key leaning on `uq_as_superseded`, and would fail to roll back.
        if (! RawSchema::indexExists(self::TABLE, 'idx_asub_superseded')) {
            RawSchema::index(self::TABLE, 'idx_asub_superseded', ['superseded_by_id']);
        }

        // NULL for `superseded` is deliberate — see the class note. MariaDB's tolerance of NULLs in a
        // unique index is the mechanism, not an accident being worked around.
        if (! Schema::hasColumn(self::TABLE, 'current_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'current_guard',
                'tinyint',
                "CASE WHEN `status` <> 'superseded' THEN 1 ELSE NULL END",
            );
        }

        if (! Schema::hasColumn(self::TABLE, 'final_marks')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'final_marks',
                'decimal(8,2)',
                'CASE WHEN `obtained_marks` IS NULL THEN NULL'
                .' ELSE GREATEST(`obtained_marks` - COALESCE(`penalty_marks`, 0), 0) END',
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_as_live', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_as_live', ['assignment_id', 'student_id', 'current_guard']);
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_as_attempt', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_as_attempt', ['assignment_id', 'student_id', 'attempt_no']);
        }

        // One predecessor per successor: without this the chain could fork, and "which attempt did
        // this replace" would stop having a single answer.
        if (! RawSchema::indexExists(self::TABLE, 'uq_as_superseded', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_as_superseded', ['superseded_by_id']);
        }
    }

    private function constraints(): void
    {
        // §80's ceiling as a database fact, comparing two columns of the same row.
        $this->ensure('chk_asub_marks',
            '`obtained_marks` IS NULL OR (`obtained_marks` >= 0 AND `obtained_marks` <= `total_marks`)');

        $this->ensure('chk_asub_penalty', '`penalty_marks` >= 0 AND `penalty_marks` <= `total_marks`');
        $this->ensure('chk_asub_total', '`total_marks` > 0');

        // Graded means marked. A `graded` row with no mark is a status claiming something that did
        // not happen.
        $this->ensure('chk_asub_graded', "`status` <> 'graded' OR `obtained_marks` IS NOT NULL");

        // Anything past draft has been handed in, so it has a time.
        $this->ensure('chk_asub_submitted', "`status` = 'draft' OR `submitted_at` IS NOT NULL");

        $this->ensure('chk_asub_pct', '`percentage` IS NULL OR `percentage` BETWEEN 0 AND 100');
        $this->ensure('chk_asub_attempt', '`attempt_no` BETWEEN 1 AND 10');
    }

    public function down(): void
    {
        // Indexes before columns: dropping a column an index reads is error 1553, and the rollback
        // test runs this over a table holding rows. `idx_asub_superseded` is what makes dropping
        // `uq_as_superseded` legal — the foreign key on that column needs an index of its own (D112).
        if (Schema::hasTable(self::TABLE)) {
            foreach (['uq_as_live', 'uq_as_attempt', 'uq_as_superseded'] as $index) {
                if (RawSchema::indexExists(self::TABLE, $index, true)) {
                    RawSchema::dropIndex(self::TABLE, $index);
                }
            }

            foreach (['current_guard', 'final_marks'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    RawSchema::dropColumn(self::TABLE, $column);
                }
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
