<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 · file 2 — `certificates` (phase-19-23 §2.14, requirement §84).
 *
 * **INV-21-4: every printed field is a snapshot, and that is the whole design.** A certificate is a
 * document somebody is holding. If the student's name were read through a join, renaming them next
 * year would silently disagree with the paper in their hand — and the verification page, which exists
 * to confirm that paper, would confirm something different. So the name, the father's name, the code,
 * the course, the batch, the trainer, the branch and the QR payload are all columns on this row.
 *
 * **INV-21-1: nothing deletes a certificate.** The policy refuses `delete` and `forceDelete` for every
 * role — and the **model** refuses the act, because `Gate::before` waves a Super Admin past every
 * policy and a soft delete is an UPDATE that `restrictOnDelete` never sees. That is D124, learned in
 * Phase 19 and applied here from the start.
 *
 * **[D-21-3] `reissued` is a link, not a status.** A reissue is the original moving to `revoked` plus
 * a fresh `draft` carrying `reissue_of_id`, with `uq_ce_reissue` permitting exactly one successor. One
 * row is never simultaneously "the revoked one" and "the new one".
 *
 * **`live_guard` is generated STORED** — `CASE WHEN status = 'issued' THEN 1 ELSE NULL END` — so
 * `uq_ce_live` permits one issued certificate per enrolment while every draft and every revoked
 * predecessor coexists beside it. MariaDB's tolerance of NULLs in a unique index is the mechanism.
 *
 * **Every enum-backed column is string(32)** (D126). `CertificateStatus`'s longest case is seven
 * characters; the width is a convention nobody has to measure against, which is exactly what Phase 20
 * discovered by shipping a column that could not hold its own most important value.
 *
 * **`certificate_number` is nullable, which the contract's column table says it is not.** The table
 * says `not null`; the CHECK two lines below it says `status = 'draft' OR certificate_number IS NOT
 * NULL`, which is only expressible if a draft may carry none. The two contradict each other and the
 * CHECK is the more specific statement, so it wins: a draft has no number, a number is issued once at
 * the moment of issue (INV-21-1), and `uq_ce_number` tolerates the NULLs the drafts leave behind.
 * Recorded here rather than silently resolved, because a reader comparing the two will otherwise
 * assume the migration is wrong.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'certificates';

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

            // Issued once, by DocumentNumberService under a row lock (D27). Null while draft.
            $table->string('certificate_number', 40)->nullable();
            // The QR target: 16 characters of a 32-symbol alphabet, random (INV-21-2).
            $table->char('verification_code', 16);

            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            // The grain of INV-21-6: one issued certificate per enrolment, not per student.
            $table->unsignedBigInteger('student_batch_enrollment_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->unsignedBigInteger('print_template_id')->nullable();
            $table->unsignedBigInteger('grade_scale_id')->nullable();

            // ------------------------------------------------------------------ INV-21-4 snapshots
            $table->string('student_name_snapshot', 150);
            $table->string('father_name_snapshot', 150)->nullable();
            $table->string('student_code_snapshot', 32);
            $table->string('registration_number_snapshot', 40)->nullable();
            $table->string('course_name_snapshot', 180);
            $table->string('batch_name_snapshot', 150)->nullable();
            $table->string('teacher_name_snapshot', 150)->nullable();
            $table->string('branch_name_snapshot', 150)->nullable();

            $table->date('course_start_date')->nullable();
            $table->date('completion_date');

            $table->string('grade', 8)->nullable();
            $table->decimal('grade_point', 4, 2)->nullable();
            // decimal(8,4) per CLAUDE.md §3 — there is no "reported percentage" exception.
            $table->decimal('percentage', 8, 4)->nullable();
            $table->decimal('attendance_percentage', 8, 4)->nullable();
            $table->decimal('progress_percentage', 8, 4)->nullable();

            // The full EligibilityReport at issue time: every rule and its verdict. What makes
            // "why was this issued?" answerable a year later, when the rules have changed.
            $table->json('eligibility_snapshot')->nullable();

            $table->date('issued_on')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();

            $table->string('status', 32)->default('draft');

            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            // Mandatory on `revoked` — chk_ce_revoked — and shown on the public page.
            $table->string('revocation_reason', 500)->nullable();

            $table->unsignedBigInteger('reissue_of_id')->nullable();
            $table->string('reissue_reason', 255)->nullable();

            // Snapshotted too: the absolute URL printed on the paper. Changing the institute's
            // verification host later must not make an already-printed QR code resolve elsewhere.
            $table->string('qr_payload', 500)->nullable();

            // Private disk. A certificate PDF names a student and is never publicly served (D21).
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('pdf_generated_at')->nullable();

            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->unsignedBigInteger('last_printed_by')->nullable();

            // CACHE of certificate_verifications, re-derivable by counting that table.
            $table->unsignedInteger('verification_count')->default(0);
            $table->timestamp('last_verified_at')->nullable();

            // A privacy opt-out. False answers `not_found` to the public page — indistinguishable
            // from a code that was never issued, which is the point.
            $table->boolean('is_publicly_verifiable')->default(true);

            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('certificate_number', 'uq_ce_number');
            $table->unique('verification_code', 'uq_ce_code');
            // One successor per revoked certificate.
            $table->unique('reissue_of_id', 'uq_ce_reissue');

            $table->index(['student_id', 'status'], 'idx_ce_student');
            $table->index(['course_id', 'status'], 'idx_ce_course');
            $table->index(['batch_id', 'status'], 'idx_ce_batch');
            $table->index(['status', 'issued_on'], 'idx_ce_status');
            $table->index(['branch_id', 'issued_on'], 'idx_ce_branch');
            $table->index('completion_date', 'idx_ce_completed');
            $table->index('print_template_id', 'idx_ce_template');
            $table->index('grade_scale_id', 'idx_ce_scale');
            $table->index('teacher_id', 'idx_ce_teacher');
            // `student_batch_enrollment_id` leads uq_ce_live, which is UNIQUE — InnoDB would satisfy
            // the foreign key with it and then refuse to drop it (error 1553), so the column gets an
            // index of its own and `down()` stays reversible. D112, and D125 made it a test.
            $table->index('student_batch_enrollment_id', 'idx_ce_enrollment');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            // restrictOnDelete along the spine: a student, course, batch or enrolment with a
            // certificate against it is never removed out from under the document.
            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->restrictOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->restrictOnDelete();
            $table->foreign('student_batch_enrollment_id', RawSchema::foreignKeyName(self::TABLE, 'student_batch_enrollment_id'))
                ->references('id')->on('student_batch_enrollments')->restrictOnDelete();
            $table->foreign('teacher_id', RawSchema::foreignKeyName(self::TABLE, 'teacher_id'))
                ->references('id')->on('teachers')->nullOnDelete();
            // A template that printed a document is deactivated, never deleted, so the document can
            // be re-printed byte-identically.
            $table->foreign('print_template_id', RawSchema::foreignKeyName(self::TABLE, 'print_template_id'))
                ->references('id')->on('print_templates')->restrictOnDelete();
            $table->foreign('grade_scale_id', RawSchema::foreignKeyName(self::TABLE, 'grade_scale_id'))
                ->references('id')->on('grade_scales')->restrictOnDelete();
            $table->foreign('reissue_of_id', RawSchema::foreignKeyName(self::TABLE, 'reissue_of_id'))
                ->references('id')->on(self::TABLE)->nullOnDelete();

            foreach (['issued_by', 'revoked_by', 'last_printed_by', 'created_by', 'updated_by'] as $column) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    /**
     * One issued certificate per enrolment. See the class note on `live_guard`.
     *
     * **`student_batch_enrollment_id` is nullable, so `uq_ce_live` does not constrain a certificate
     * that names no enrolment** — MariaDB permits unlimited `(NULL, 1)` pairs, which is the same
     * tolerance the guard column relies on and cannot be had selectively. The contract makes the
     * column nullable, so the gap is closed a layer up instead: `CertificateService::draft()` takes
     * an enrolment as its argument and `issue()` refuses outright when the row has none. Recorded
     * here because reading the index alone would suggest a guarantee it does not give.
     */
    private function guard(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'live_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'live_guard',
                'tinyint',
                "CASE WHEN `status` = 'issued' THEN 1 ELSE NULL END",
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_ce_live', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_ce_live', ['student_batch_enrollment_id', 'live_guard']);
        }

        // Ensured on every run, not only on create — a database migrated before this index existed
        // still has its foreign key leaning on `uq_ce_live` and would fail to roll back (D112/D125).
        if (! RawSchema::indexExists(self::TABLE, 'idx_ce_enrollment')) {
            RawSchema::index(self::TABLE, 'idx_ce_enrollment', ['student_batch_enrollment_id']);
        }

        if (! RawSchema::indexExists(self::TABLE, 'idx_ce_reissue_fk')) {
            RawSchema::index(self::TABLE, 'idx_ce_reissue_fk', ['reissue_of_id']);
        }
    }

    private function constraints(): void
    {
        $this->ensure('chk_ce_dates', '`course_start_date` IS NULL OR `completion_date` >= `course_start_date`');
        $this->ensure(
            'chk_ce_pct',
            '(`percentage` IS NULL OR `percentage` BETWEEN 0 AND 100)'
            .' AND (`attendance_percentage` IS NULL OR `attendance_percentage` BETWEEN 0 AND 100)'
            .' AND (`progress_percentage` IS NULL OR `progress_percentage` BETWEEN 0 AND 100)',
        );
        $this->ensure('chk_ce_point', '`grade_point` IS NULL OR `grade_point` >= 0');
        $this->ensure('chk_ce_revoked', "`status` <> 'revoked' OR `revocation_reason` IS NOT NULL");
        $this->ensure(
            'chk_ce_issued',
            "`status` = 'draft' OR (`issued_on` IS NOT NULL AND `certificate_number` IS NOT NULL)",
        );
        $this->ensure('chk_ce_counts', '`print_count` >= 0 AND `verification_count` >= 0');
        $this->ensure('chk_ce_reissue', '`reissue_of_id` IS NULL OR `reissue_reason` IS NOT NULL');
    }

    public function down(): void
    {
        // Indexes before columns: dropping a column an index reads is error 1553, and the rollback
        // test runs this over a table holding rows. `idx_ce_enrollment` and `idx_ce_reissue_fk` are
        // what make dropping the two unique indexes legal at all.
        if (Schema::hasTable(self::TABLE)) {
            foreach (['uq_ce_live'] as $index) {
                if (RawSchema::indexExists(self::TABLE, $index, true)) {
                    RawSchema::dropIndex(self::TABLE, $index);
                }
            }

            if (Schema::hasColumn(self::TABLE, 'live_guard')) {
                RawSchema::dropColumn(self::TABLE, 'live_guard');
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
