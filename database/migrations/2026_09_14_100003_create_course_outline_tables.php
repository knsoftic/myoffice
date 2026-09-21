<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 · file 3 — the outline tree: `course_modules`, `course_topics`, `course_lectures`
 * (§65, phase-14-17 §2.5–2.7).
 *
 * **Exactly three levels, and no `parent_id` anywhere** (INV-I12). A self-referencing tree would let
 * somebody nest a topic inside a topic, and every query in four later phases — coverage, progress,
 * attendance, certificates — would have to cope with a depth nobody designed for. Three tables are the
 * constraint, stated once, in the schema.
 *
 * **`course_id` is denormalised onto topics and lectures on purpose.** "How far through this course is
 * this student" is asked on every student screen and in every progress recount, and forcing it through
 * two joins to reach the course would make the commonest query in the institute the most expensive one.
 * `CourseOutlineService` sets it from the parent, never from the request, and asserts it matches — the
 * duplication is safe only because exactly one writer maintains it.
 *
 * **`cascadeOnDelete` down the tree, and that is not a licence to delete.** The cascade exists so that
 * force-deleting a *draft* course takes its outline with it. A course anybody has ever been admitted to
 * is refused deletion by `restrictOnDelete` from the selling tables, and a referenced node is refused by
 * policy and deactivated instead (INV-I13).
 *
 * **`is_active` rather than deletion** is the whole reason those columns exist: a topic a student has
 * been marked against is history, and removing it would silently move that student's percentage.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_modules')) {
            $this->createModules();
        }

        if (! Schema::hasTable('course_topics')) {
            $this->createTopics();
        }

        if (! Schema::hasTable('course_lectures')) {
            $this->createLectures();
        }

        $this->constraints();
    }

    private function createModules(): void
    {
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('course_id');

            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('is_active')->default(true);

            // CACHES, written only by CourseOutlineService.
            $table->unsignedSmallInteger('topics_count')->default(0);
            $table->unsignedSmallInteger('lectures_count')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['course_id', 'sort_order'], 'idx_cm_course_sort');
            $table->index(['course_id', 'is_active'], 'idx_cm_course_active');

            $table->foreign('course_id', RawSchema::foreignKeyName('course_modules', 'course_id'))
                ->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('created_by', RawSchema::foreignKeyName('course_modules', 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName('course_modules', 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createTopics(): void
    {
        Schema::create('course_topics', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('course_module_id');
            // Denormalised — see the class docblock. The service asserts it equals the module's course.
            $table->unsignedBigInteger('course_id');

            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Progress weighting (§6.10). 1 means "count this topic like any other"; a heavier topic
            // moves the percentage more when it is covered, which is what makes a 40-hour project worth
            // more than a 20-minute revision.
            $table->unsignedSmallInteger('weight')->default(1);
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedSmallInteger('lectures_count')->default(0);
            $table->unsignedSmallInteger('resources_count')->default(0);
            $table->unsignedSmallInteger('assignments_count')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['course_module_id', 'sort_order'], 'idx_ct_module_sort');
            $table->index(['course_id', 'is_active'], 'idx_ct_course_active');

            $table->foreign('course_module_id', RawSchema::foreignKeyName('course_topics', 'course_module_id'))
                ->references('id')->on('course_modules')->cascadeOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName('course_topics', 'course_id'))
                ->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('created_by', RawSchema::foreignKeyName('course_topics', 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName('course_topics', 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createLectures(): void
    {
        Schema::create('course_lectures', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('course_topic_id');
            $table->unsignedBigInteger('course_id');

            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('lecture_type', 16)->default('lecture');
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Feeds `courses.outline_minutes`, which is how the public page can say "about 36 hours"
            // without anybody typing that number twice.
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('video_url', 255)->nullable();
            // A free public preview on the landing page (§90) — the one lecture a visitor may watch
            // before applying.
            $table->boolean('is_preview')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['course_topic_id', 'sort_order'], 'idx_cl_topic_sort');
            $table->index(['course_id', 'is_preview'], 'idx_cl_course_preview');

            $table->foreign('course_topic_id', RawSchema::foreignKeyName('course_lectures', 'course_topic_id'))
                ->references('id')->on('course_topics')->cascadeOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName('course_lectures', 'course_id'))
                ->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('created_by', RawSchema::foreignKeyName('course_lectures', 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName('course_lectures', 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        // 1..100, so a weighting stays a weighting: a topic worth 5,000 would drown every other row in
        // the denominator and make the percentage meaningless rather than merely wrong.
        $this->ensure('course_topics', 'chk_ct_weight', '`weight` BETWEEN 1 AND 100');

        $this->ensure(
            'course_lectures',
            'chk_cl_type',
            "`lecture_type` IN ('lecture', 'lab', 'workshop', 'revision', 'assessment', 'project')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('course_lectures');
        Schema::dropIfExists('course_topics');
        Schema::dropIfExists('course_modules');
    }

    private function ensure(string $table, string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check($table, $name, $expression);
        }
    }
};
