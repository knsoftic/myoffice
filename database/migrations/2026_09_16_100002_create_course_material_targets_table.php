<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19 · file 2 — `course_material_targets` (phase-19-23 §2.4, §79).
 *
 * **[D-19-2] Three real foreign keys, not a polymorphic `target_id`.** §109 asks for normalised tables
 * with real foreign keys, and a `target_type` + `target_id` pair cannot be constrained: nothing stops it
 * pointing at a batch that was deleted, or at a student id that happens to collide with a course id. So
 * the row carries three nullable FKs and `chk_cmt_one` asserting that exactly one is set **and** agrees
 * with `target_type`. The database, not a service, is what makes "a target always resolves" true.
 *
 * One material carries several targets, which is the point — one uploaded file reaches three batches
 * without three copies of the bytes.
 *
 * **`target_key` is a generated STORED column and it exists because MariaDB permits unlimited NULLs in
 * a unique index.** The natural key is `(course_material_id, target_type, whichever id is set)`, but
 * indexing the three nullable columns directly would let the same batch be targeted twice — two rows
 * whose other two columns are both NULL are never "duplicates" to MariaDB. `COALESCE` of the three
 * collapses them into one non-null number, and `uq_cmt` then actually bites. The guard is an INSERT
 * that fails, never a SELECT that looks.
 *
 * **No soft deletes and no `updated_by`** (§2.4, D19): a target is added or removed, never edited, and
 * who did either is in `activity_log`. `notified_at` is what stops re-targeting re-notifying an
 * audience that already heard.
 */
return new class extends Migration
{
    private const TABLE = 'course_material_targets';

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

            $table->unsignedBigInteger('course_material_id');
            $table->string('target_type', 16);

            // Exactly one of the three is set; chk_cmt_one says which, given target_type.
            $table->unsignedBigInteger('target_course_id')->nullable();
            $table->unsignedBigInteger('target_batch_id')->nullable();
            $table->unsignedBigInteger('target_student_id')->nullable();

            // Set when MaterialShared notified this audience. Re-targeting a material that already
            // reached a batch must not tell that batch again, and this is the record that says so.
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            // `course_material_id` gets its own index even though `uq_cmt` leads with it. InnoDB
            // requires *an* index on a foreign key column and will happily use the unique one — which
            // then cannot be dropped while the FK exists (error 1553), so `down()` fails and the
            // migration stops being reversible. This is **D112**, and it is here because writing the
            // index list from the contract is not the same as checking the rule.
            $table->index('course_material_id', 'idx_cmt_material');
            // The (target_type, target_key) lookup index is added in guard(), once the generated
            // column it reads exists.
            $table->index('target_batch_id', 'idx_cmt_batch');
            $table->index('target_student_id', 'idx_cmt_student');
            $table->index('target_course_id', 'idx_cmt_course');

            $table->foreign('course_material_id', RawSchema::foreignKeyName(self::TABLE, 'course_material_id'))
                ->references('id')->on('course_materials')->cascadeOnDelete();
            // cascadeOnDelete throughout: a target whose audience is gone describes nobody, and
            // keeping it would leave a material claiming a reach it no longer has.
            $table->foreign('target_course_id', RawSchema::foreignKeyName(self::TABLE, 'target_course_id'))
                ->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('target_batch_id', RawSchema::foreignKeyName(self::TABLE, 'target_batch_id'))
                ->references('id')->on('batches')->cascadeOnDelete();
            $table->foreign('target_student_id', RawSchema::foreignKeyName(self::TABLE, 'target_student_id'))
                ->references('id')->on('students')->cascadeOnDelete();
        });
    }

    /**
     * The generated column and the unique index that needs it. Raw SQL, and it **fails loudly** if the
     * server rejects it (spine R-3) — there is deliberately no fallback to a nullable guard, because a
     * unique index that silently does not apply is worse than none: nobody goes looking for it.
     */
    private function guard(): void
    {
        // Ensured on every run, not only on create: a database migrated before this index existed
        // still has its foreign key leaning on `uq_cmt`, and would fail to roll back.
        if (! RawSchema::indexExists(self::TABLE, 'idx_cmt_material')) {
            RawSchema::index(self::TABLE, 'idx_cmt_material', ['course_material_id']);
        }

        if (! Schema::hasColumn(self::TABLE, 'target_key')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'target_key',
                'bigint unsigned',
                'COALESCE(`target_course_id`, `target_batch_id`, `target_student_id`)',
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_cmt', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_cmt', [
                'course_material_id', 'target_type', 'target_key',
            ]);
        }

        // The student/batch lookup reads (target_type, target_key) — the generated column, so it can
        // only be added once that column exists.
        if (! RawSchema::indexExists(self::TABLE, 'idx_cmt_key')) {
            RawSchema::index(self::TABLE, 'idx_cmt_key', ['target_type', 'target_key']);
        }
    }

    private function constraints(): void
    {
        // Exactly one id, and it is the one target_type names. Anything else is a row that claims to
        // aim at a batch while holding a student id.
        $this->ensure('chk_cmt_one',
            "(`target_type` = 'course'  AND `target_course_id` IS NOT NULL AND `target_batch_id` IS NULL AND `target_student_id` IS NULL)"
            ." OR (`target_type` = 'batch'   AND `target_batch_id` IS NOT NULL AND `target_course_id` IS NULL AND `target_student_id` IS NULL)"
            ." OR (`target_type` = 'student' AND `target_student_id` IS NOT NULL AND `target_course_id` IS NULL AND `target_batch_id` IS NULL)");
    }

    public function down(): void
    {
        // The indexes read the generated column, so they go first: dropping a column an index depends
        // on is error 1553, and the rollback test runs this over a table holding rows.
        // `idx_cmt_material` is what makes dropping `uq_cmt` legal at all — without it the
        // `course_material_id` foreign key would be left with no index (D112).
        if (Schema::hasTable(self::TABLE)) {
            if (RawSchema::indexExists(self::TABLE, 'uq_cmt', true)) {
                RawSchema::dropIndex(self::TABLE, 'uq_cmt');
            }

            if (RawSchema::indexExists(self::TABLE, 'idx_cmt_key')) {
                RawSchema::dropIndex(self::TABLE, 'idx_cmt_key');
            }

            if (Schema::hasColumn(self::TABLE, 'target_key')) {
                RawSchema::dropColumn(self::TABLE, 'target_key');
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
