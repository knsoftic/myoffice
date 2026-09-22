<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 · file 4 — `student_module_progress`: §83 at module level (phase-14-17 §2.27).
 *
 * **Entirely derived, so it carries no blameable columns and no `deleted_at` ([D-IN-2]).** Nobody ever
 * marks a module: `CourseProgressService` computes it from the topic rows underneath. Recording who
 * "changed" a row that is only ever recomputed would be recording the recompute, which the course
 * row's activity log already says — and a soft-deleted row would sit under `uq_smp` and make the next
 * recompute a 1062.
 *
 * It is a table rather than a query because the §8.17 board draws a roll-up bar per module for every
 * student in a batch: a class of thirty with eight modules is 240 bars, and recomputing each from its
 * topic rows on every render is the difference between a screen that opens and one that thinks about
 * it.
 *
 * A module with no active topics is hand-markable and then stands for itself with weight 1 (§6.10) —
 * which is why `topics_total = 0` is a legitimate state here and not a sign of a missing recompute.
 */
return new class extends Migration
{
    private const TABLE = 'student_module_progress';

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

            $table->unsignedBigInteger('student_course_progress_id');
            $table->unsignedBigInteger('course_module_id');

            $table->string('status', 16)->default('pending');
            $table->decimal('completion_percentage', 8, 4)->default('0.0000');
            $table->unsignedSmallInteger('topics_total')->default(0);
            $table->unsignedSmallInteger('topics_completed')->default(0);
            $table->date('completed_on')->nullable();

            $table->timestamps();

            $table->unique(['student_course_progress_id', 'course_module_id'], 'uq_smp');
            $table->index(['course_module_id', 'status'], 'idx_smp_module');

            $table->foreign('student_course_progress_id', RawSchema::foreignKeyName(self::TABLE, 'student_course_progress_id'))
                ->references('id')->on('student_course_progress')->cascadeOnDelete();
            $table->foreign('course_module_id', RawSchema::foreignKeyName(self::TABLE, 'course_module_id'))
                ->references('id')->on('course_modules')->cascadeOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_smp_pct', '`completion_percentage` BETWEEN 0 AND 100');
        $this->ensure('chk_smp_status',
            "`status` IN ('pending', 'in_progress', 'completed', 'skipped')");
        $this->ensure('chk_smp_counts', '`topics_completed` <= `topics_total`');
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
