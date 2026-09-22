<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19 · file 1 — `course_materials` (phase-19-23 §2.3, §79).
 *
 * **[D-19-1] This is not `course_topic_resources`.** Phase 14's table is the *published syllabus*:
 * authored once, public on the landing page, part of what the course is. This one is an *act of
 * distribution* — targeted, time-windowed, enrollment-gated, download-tracked and teacher-owned. A
 * material may point at a topic for organisation, but it never replaces the syllabus row, and the two
 * answer different questions: "what does this course teach" versus "what did this teacher hand out".
 *
 * **`chk_cm_payload` is the table's spine: a material is a file or a link, never both and never
 * neither.** Without it the `type = link` rows and the uploaded rows drift into a third state — a row
 * with neither — which every screen would then have to defend against individually. One CHECK is
 * cheaper than eleven `if`s, and it is the only version of the rule that also holds for a row written
 * by a seeder, an import or a hand-run SQL fix.
 *
 * **Five cache columns, and the contract says plainly that they are caches.** `audience_scope` is the
 * broadest live target, `targets_count` counts the target rows, and the three access counts derive from
 * `course_material_downloads`. Every one is re-derivable, and the verifier re-derives them: a cache that
 * cannot be rebuilt from its source is just a second source of truth that disagrees more slowly.
 *
 * `storage_disk` defaults to `private` and **is never `public` for a file material** (INV-19-1). It is
 * recorded per row rather than assumed so that a future disk move is provable rather than believed.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards only the CREATE and re-ensures every CHECK
 * on each run — a half-applied migration heals on the next pass.
 */
return new class extends Migration
{
    private const TABLE = 'course_materials';

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
            // restrictOnDelete: a course holding handed-out material is not deleted out from under it.
            $table->unsignedBigInteger('course_id');
            // Optional placement in the outline, for "materials by topic" on the student screen.
            $table->unsignedBigInteger('course_topic_id')->nullable();
            // Who shared it, when a teacher did. Staff uploads leave it null.
            $table->unsignedBigInteger('teacher_id')->nullable();

            $table->string('title', 180);
            $table->string('description', 1000)->nullable();
            // Phase 14's CourseResourceType, reused rather than re-declared (§3): the eight kinds
            // §79 lists, plus `link`.
            $table->string('type', 24);

            // --- the file half of chk_cm_payload -------------------------------------------------
            $table->string('storage_disk', 32)->default('private');
            $table->string('file_path', 255)->nullable();
            // The client's name, used only in Content-Disposition — never on disk (INV-19-2).
            $table->string('original_name', 255)->nullable();
            // Server-decided from the sniffed MIME, not from what the client called the file.
            $table->string('extension', 16)->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            // A duplicate is a warning on the upload screen, never a block: two batches legitimately
            // receive the same handout, and refusing the second would be refusing the normal case.
            $table->char('checksum_sha256', 64)->nullable();

            // --- the link half -------------------------------------------------------------------
            // `http`/`https` only; `javascript:` and `data:` are refused by the Form Request.
            $table->string('external_url', 500)->nullable();

            // False = inline view only. §6.5 says outright that this is deterrence, not DRM.
            $table->boolean('is_downloadable')->default(true);

            // Timed release: a teacher uploads ahead of the class and it appears on the day.
            $table->dateTime('available_from')->nullable();
            $table->dateTime('available_until')->nullable();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();

            // CACHE of the broadest live target — an index badge, never an authorisation input.
            // The decision of who may read a material is made by resolving the target rows (INV-19-3).
            $table->string('audience_scope', 16)->default('course');
            $table->unsignedSmallInteger('targets_count')->default(0);

            // CACHES over course_material_downloads.
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('unique_students_count')->default(0);

            $table->integer('sort_order')->default(0);
            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['course_id', 'status'], 'idx_cm_course');
            $table->index(['course_id', 'course_topic_id'], 'idx_cm_topic');
            // The student query: published, inside its window, right now.
            $table->index(['status', 'available_from', 'available_until'], 'idx_cm_window');
            $table->index(['branch_id', 'status'], 'idx_cm_branch');
            $table->index('type', 'idx_cm_type');
            $table->index('teacher_id', 'idx_cm_teacher');
            $table->index('checksum_sha256', 'idx_cm_checksum');
            $table->index('published_at', 'idx_cm_published');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('course_topic_id', RawSchema::foreignKeyName(self::TABLE, 'course_topic_id'))
                ->references('id')->on('course_topics')->nullOnDelete();
            $table->foreign('teacher_id', RawSchema::foreignKeyName(self::TABLE, 'teacher_id'))
                ->references('id')->on('teachers')->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        // A file or a link. Never both — which would leave "which one do I serve" undecided — and
        // never neither, which is a material that distributes nothing.
        $this->ensure('chk_cm_payload',
            '(`file_path` IS NOT NULL AND `external_url` IS NULL)'
            .' OR (`file_path` IS NULL AND `external_url` IS NOT NULL)');

        $this->ensure('chk_cm_window',
            '`available_from` IS NULL OR `available_until` IS NULL OR `available_until` > `available_from`');

        $this->ensure('chk_cm_size', '`file_size_bytes` IS NULL OR `file_size_bytes` > 0');

        $this->ensure('chk_cm_counts',
            '`targets_count` >= 0 AND `view_count` >= 0'
            .' AND `download_count` >= 0 AND `unique_students_count` >= 0');
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
