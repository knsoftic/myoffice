<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 · file 5 — `course_topic_assignments`: the assignment **blueprint** of §65
 * (phase-14-17 §2.9, [D-IN-6]).
 *
 * **It is never graded and has no submissions.** This row says "this topic has a practice assignment,
 * worth about these marks, taking about these hours" — it is curriculum, authored once, public, and the
 * same for every batch that ever runs the course. Phase 19's `assignments` instantiates one *for a
 * batch* with a deadline, and that is where submissions, marks and feedback live (§80).
 *
 * Two tables rather than one because the two things have different lifetimes and different owners: a
 * blueprint changes when the syllabus is revised, an assignment changes when a teacher moves a deadline.
 * Merging them would mean every syllabus edit touched rows students had already submitted against.
 *
 * `estimated_marks` is `decimal(8,2)` — the default Phase 19 pre-fills — because half marks are real and
 * an integer column would quietly round somebody's course out of shape.
 */
return new class extends Migration
{
    private const TABLE = 'course_topic_assignments';

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

            $table->unsignedBigInteger('course_topic_id');
            $table->unsignedBigInteger('course_id');

            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();

            $table->decimal('estimated_marks', 8, 2)->nullable();
            $table->decimal('estimated_hours', 10, 2)->nullable();
            $table->string('attachment_path', 255)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['course_topic_id', 'sort_order'], 'idx_cta_topic_sort');
            $table->index('course_id', 'idx_cta_course');

            $table->foreign('course_topic_id', RawSchema::foreignKeyName(self::TABLE, 'course_topic_id'))
                ->references('id')->on('course_topics')->cascadeOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        // A negative estimate is not a smaller assignment, it is a typo — and it would propagate into
        // every Phase 19 assignment pre-filled from it.
        $this->ensure('chk_cta_marks', '`estimated_marks` IS NULL OR `estimated_marks` >= 0');
        $this->ensure('chk_cta_hours', '`estimated_hours` IS NULL OR `estimated_hours` >= 0');
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
