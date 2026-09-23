<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 · file 4 — `student_id_cards` (phase-19-23 §2.16, requirement §85).
 *
 * **A card is a physical object, and the schema is shaped by that.** It expires, it gets lost, it goes
 * through the wash, it is replaced, and occasionally it is withdrawn — five of `IdCardStatus`'s six
 * cases are reasons the card is not in use, because collapsing them into `inactive` would lose exactly
 * the fact the office needs: whether to charge for a replacement, whether to expect the old card back,
 * and whether the old number should still open a door.
 *
 * **`photo_path` is a copy, not a pointer.** The student's photograph is duplicated onto the private
 * disk at issue time, so updating their profile picture next year does not alter a card already in
 * somebody's wallet. Same reasoning as every `*_snapshot` column beside it (INV-21-4).
 *
 * **`live_guard` is generated STORED** — `CASE WHEN status = 'active' THEN 1 ELSE NULL END` — so
 * `uq_sic_live` permits exactly one active card per student while every expired, lost, damaged and
 * replaced predecessor stays on the record. MariaDB's tolerance of NULLs in a unique index is the
 * mechanism, deliberately used rather than worked around.
 *
 * **A replacement is a new row**, linked by `replacement_of_id` with `uq_sic_replacement` permitting
 * one successor — the same shape as a certificate reissue [D-21-3]. A single card is never
 * simultaneously "the lost one" and "the new one".
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'student_id_cards';

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

            $table->string('card_number', 32);
            // The QR target. Resolves to the same public endpoint as a certificate, card variant.
            $table->char('verification_code', 16);

            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('student_batch_enrollment_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('print_template_id')->nullable();

            // ------------------------------------------------------------------ INV-21-4 snapshots
            $table->string('student_name_snapshot', 150);
            $table->string('father_name_snapshot', 150)->nullable();
            $table->string('student_code_snapshot', 32);
            $table->string('registration_number_snapshot', 40)->nullable();
            $table->string('course_name_snapshot', 180)->nullable();
            $table->string('batch_name_snapshot', 150)->nullable();
            $table->date('joining_date_snapshot')->nullable();
            // Printed only when the template's token set asks for it.
            $table->string('guardian_phone_snapshot', 32)->nullable();

            // A copy on the private disk — see the class note.
            $table->string('photo_path', 255)->nullable();

            $table->date('issued_on');
            // issued_on + institute.id_card_validity_months. Null = no expiry.
            $table->date('valid_until')->nullable();

            // string(32) per CLAUDE.md §3 and D126.
            $table->string('status', 32)->default('active');

            $table->unsignedBigInteger('replacement_of_id')->nullable();
            // Mandatory when replacement_of_id is set — chk_sic_replacement.
            $table->string('replacement_reason', 255)->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revocation_reason', 255)->nullable();

            // Snapshotted absolute URL: a later change of verification host must not redirect a code
            // already printed on a card.
            $table->string('qr_payload', 500);

            $table->string('pdf_path', 255)->nullable();

            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->unsignedBigInteger('last_printed_by')->nullable();

            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('card_number', 'uq_sic_number');
            $table->unique('verification_code', 'uq_sic_code');
            $table->unique('replacement_of_id', 'uq_sic_replacement');

            $table->index(['student_id', 'status'], 'idx_sic_student');
            $table->index(['batch_id', 'status'], 'idx_sic_batch');
            // The expiry sweep.
            $table->index(['status', 'valid_until'], 'idx_sic_expiry');
            $table->index(['branch_id', 'issued_on'], 'idx_sic_branch');
            $table->index('print_template_id', 'idx_sic_template');
            $table->index('course_id', 'idx_sic_course');
            $table->index('student_batch_enrollment_id', 'idx_sic_enrollment');
            // `replacement_of_id` leads uq_sic_replacement, which is UNIQUE — InnoDB satisfies the
            // foreign key with it and then refuses to drop it (error 1553). Its own index keeps
            // `down()` reversible. D112, and D125 made it a test.
            $table->index('replacement_of_id', 'idx_sic_replacement_fk');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            // The student is the one restrict: a student holding a card is never removed.
            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->restrictOnDelete();
            $table->foreign('student_batch_enrollment_id', RawSchema::foreignKeyName(self::TABLE, 'student_batch_enrollment_id'))
                ->references('id')->on('student_batch_enrollments')->nullOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->nullOnDelete();
            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->nullOnDelete();
            // A template that printed a card is deactivated, never deleted, so the card can be
            // re-printed byte-identically.
            $table->foreign('print_template_id', RawSchema::foreignKeyName(self::TABLE, 'print_template_id'))
                ->references('id')->on('print_templates')->restrictOnDelete();
            $table->foreign('replacement_of_id', RawSchema::foreignKeyName(self::TABLE, 'replacement_of_id'))
                ->references('id')->on(self::TABLE)->nullOnDelete();

            foreach (['revoked_by', 'last_printed_by', 'created_by', 'updated_by'] as $column) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    /** One active card per student. See the class note on `live_guard`. */
    private function guard(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'live_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'live_guard',
                'tinyint',
                "CASE WHEN `status` = 'active' THEN 1 ELSE NULL END",
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_sic_live', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_sic_live', ['student_id', 'live_guard']);
        }

        // Ensured on every run: a database migrated before this index existed has its foreign key
        // leaning on the unique one and would fail to roll back (D112/D125).
        if (! RawSchema::indexExists(self::TABLE, 'idx_sic_replacement_fk')) {
            RawSchema::index(self::TABLE, 'idx_sic_replacement_fk', ['replacement_of_id']);
        }
    }

    private function constraints(): void
    {
        $this->ensure('chk_sic_valid', '`valid_until` IS NULL OR `valid_until` >= `issued_on`');
        $this->ensure('chk_sic_replacement', '`replacement_of_id` IS NULL OR `replacement_reason` IS NOT NULL');
        $this->ensure('chk_sic_print', '`print_count` >= 0');
        $this->ensure('chk_sic_revoked', "`status` <> 'revoked' OR `revocation_reason` IS NOT NULL");
    }

    public function down(): void
    {
        // Indexes before columns: dropping a column an index reads is error 1553, and the rollback
        // test runs this over a table holding rows.
        if (Schema::hasTable(self::TABLE)) {
            if (RawSchema::indexExists(self::TABLE, 'uq_sic_live', true)) {
                RawSchema::dropIndex(self::TABLE, 'uq_sic_live');
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
